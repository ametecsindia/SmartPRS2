<?php

namespace App\Services;

use App\Http\Controllers\AppDataController;
use App\Http\Controllers\PayrollGenController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 30 Sep 2026 (Ejaz) — monthly EMI / salary-advance deduction reminder.
 *
 * Three days before each company's PAY DATE, every employee who will have a
 * Loan EMI or a Salary Advance recovered from that month's salary gets an email
 * (and a bell notification): "Loan EMI of ₹X will be deducted from your salary
 * for <Month>". It keeps coming every month until the loan is settled.
 *
 * The amounts come from the payroll engine itself (PayrollGenController::
 * loanEmiDue / advanceRecovery), so the reminder can never promise a different
 * figure than the payslip, and it stops exactly when payroll stops deducting
 * (installments_paid reaches the tenure, outstanding hits 0, status closed).
 * Pay date = AppDataController::payDateFor — the same one the payslip prints.
 *
 * Idempotent: one reminder per employee per payroll month (dedupe key).
 */
class EmiReminderService
{
    public const DAYS_BEFORE = 3;

    /** @return array{sent:int, checked:int, lines:array} */
    public static function run(?int $tenantId = null, ?string $asOf = null, bool $dryRun = false): array
    {
        $today = $asOf ? Carbon::parse($asOf)->startOfDay() : Carbon::today();
        $target = $today->copy()->addDays(self::DAYS_BEFORE)->toDateString();
        $out = ['sent' => 0, 'checked' => 0, 'lines' => []];
        if (! Schema::hasTable('employees')) {
            return $out;
        }
        // Only people who currently have something to repay.
        $ids = [];
        foreach (['loans' => ['approved', 'active'], 'advances' => ['approved']] as $t => $st) {
            if (Schema::hasTable($t)) {
                $ids = array_merge($ids, DB::table($t)->whereIn('status', $st)
                    ->when($tenantId && Schema::hasColumn($t, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->pluck('employee_id')->all());
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return $out;
        }
        $pg = app(PayrollGenController::class);
        $companies = Schema::hasTable('companies') ? DB::table('companies')->pluck('name', 'id') : collect();
        $payDates = [];   // tid|company|month => date
        $months = [$today->format('Y-m'), $today->copy()->subMonthNoOverflow()->format('Y-m')];

        $emps = DB::table('employees')->whereIn('id', $ids)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhereRaw('LOWER(status) NOT IN (?, ?)', ['inactive', 'exited']))->get();
        foreach ($emps as $e) {
            $out['checked']++;
            $tid = $e->tenant_id ?? null;
            $co = (string) ($companies[$e->company_id ?? 0] ?? '');
            foreach ($months as $m) {
                $k = $tid.'|'.$co.'|'.$m;
                $payDates[$k] ??= AppDataController::payDateFor($tid, $co, $m);
                if ($payDates[$k] !== $target) {
                    continue;
                }
                $loans = $pg->loanEmiDue((int) $e->id, $tid, $m);
                $adv = $pg->advanceRecovery((int) $e->id, $tid, $m);
                $loanTotal = array_sum(array_column($loans, 'emi'));
                if ($loanTotal <= 0 && $adv <= 0) {
                    continue;
                }
                $monthLabel = Carbon::createFromFormat('Y-m-d', $m.'-01')->format('F Y');
                $payLabel = Carbon::parse($payDates[$k])->format('d-m-Y');
                $lines = [];
                $parts = [];
                foreach ($loans as $ln) {
                    $row = DB::table('loans')->where('id', $ln['id'])->first();
                    $total = (int) (($row->installments_total ?? null) ?: ($row->tenure_months ?? 0));
                    $paid = (int) ($row->installments_paid ?? 0);
                    $what = $ln['type'] === 'advance' ? 'Advance EMI' : 'Loan EMI';
                    $lines[$what.' (#'.$ln['id'].')'] = 'Rs '.number_format($ln['emi'], 2)
                        .($total > 0 ? ' · instalment '.min($paid + 1, $total).' of '.$total.($total - $paid - 1 > 0 ? ' ('.($total - $paid - 1).' more after this)' : ' (last instalment)') : '');
                }
                if ($loanTotal > 0) {
                    $parts[] = 'Loan EMI of Rs '.number_format($loanTotal, 2);
                }
                if ($adv > 0) {
                    $lines['Salary Advance recovery'] = 'Rs '.number_format($adv, 2);
                    $parts[] = 'Salary Advance of Rs '.number_format($adv, 2);
                }
                $total = round($loanTotal + $adv, 2);
                $lines['Total to be deducted'] = 'Rs '.number_format($total, 2);
                $lines['Salary month'] = $monthLabel;
                $lines['Pay date'] = $payLabel;
                $subject = 'Salary deduction reminder: '.implode(' + ', $parts).' for '.$monthLabel;
                $body = implode(' and ', $parts).' will be deducted from your salary for the month of '.$monthLabel
                    .' (pay date '.$payLabel.'). Please contact HR if anything looks wrong.';
                $out['lines'][] = ($e->emp_code ?? $e->id).' '.($e->name ?? '').' — '.$subject;
                if ($dryRun) {
                    continue;
                }
                $uid = self::userFor($e);
                if (NotificationService::sendOnce('emi:'.$e->id.':'.$m, [
                    'tenant_id' => $tid,
                    'company_id' => $e->company_id ?? null,
                    'user_id' => $uid,
                    'employee_id' => (int) $e->id,
                    'kind' => 'payroll.emi_reminder',
                    'title' => $subject,
                    'body' => $body,
                    'url' => 'payslip',
                    'in_app' => $uid !== null,
                    'email' => true,
                    'email_to' => ! empty($e->email) ? $e->email : null,
                    'email_to_name' => $e->name ?? '',
                    'email_subject' => $subject,
                    'email_lines' => $lines,
                    'meta' => ['month' => $m, 'loan' => $loanTotal, 'advance' => $adv],
                ])) {
                    $out['sent']++;
                }
            }
        }

        return $out;
    }

    private static function userFor(object $e): ?int
    {
        try {
            if (Schema::hasColumn('users', 'employee_id')) {
                $u = DB::table('users')->where('employee_id', $e->id)->value('id');
                if ($u) {
                    return (int) $u;
                }
            }
            if (! empty($e->email)) {
                $u = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower(trim($e->email))])->value('id');

                return $u ? (int) $u : null;
            }
        } catch (\Throwable $ex) {
            Log::warning('EmiReminderService::userFor '.$ex->getMessage());
        }

        return null;
    }
}
