<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Increment / Appraisal paperwork + application (rev 83b, Ejaz 5 Jun 2026).
 *
 * The increment REQUEST itself rides the generic approval engine
 * (RequestController + `increments` table). This controller adds:
 *
 *   - On APPROVAL, sendLetter() emails the employee the formal Increment
 *     Letter PDF (merged from the tenant's saved Increment template if one
 *     exists, else a built-in default body). Ejaz: auto-email on approval.
 *   - GET /app/increments/{id}/letter — the same PDF, downloadable from the
 *     register anytime (managers, or the employee themself).
 *   - POST /app/increments/{id}/apply — HR's ONE-CLICK manual application
 *     (Ejaz explicitly chose NO scheduled auto-apply): updates the employee's
 *     CTC (+ designation when it is a promotion) and stamps applied_at/by.
 */
class IncrementController extends Controller
{
    /** Built-in letter body when no Increment template has been saved. */
    private const DEFAULT_BODY = 'Dear {{employee_name}},

In recognition of your performance and contribution, we are pleased to revise your annual CTC from {{old_ctc}} to {{ctc}} ({{pct}} increase), with effect from {{date}}.

{{promotion_line}}All other terms of your employment remain unchanged. We appreciate your efforts and look forward to your continued success.

Warm regards,
HR Department, {{company}}';

    /** Tenant-scoped fetch + access: managers, or the employee themself. */
    private function row(Request $request, int $id): ?object
    {
        $tid = $request->user()->tenant_id;
        $r = DB::table('increments')->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $r) {
            return null;
        }
        if ($request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager'])) {
            return $r;
        }
        $emp = DB::table('employees')->where('id', $r->employee_id)->first();
        $u = $request->user();
        $mine = $emp && (
            (! empty($u->employee_id) && (int) $u->employee_id === (int) $emp->id)
            || (! empty($emp->email) && strtolower($emp->email) === strtolower($u->email))
        );

        return $mine ? $r : null;
    }

    /** Build the branded Increment Letter PDF. Returns ['pdf','file'] or null. */
    public static function buildLetter(object $r): ?array
    {
        $emp = DB::table('employees')->where('id', $r->employee_id)->first();
        if (! $emp) {
            return null;
        }
        $company = '';
        if (! empty($emp->company_id)) {
            $company = (string) DB::table('companies')->where('id', $emp->company_id)->value('name');
        }

        // Prefer the tenant's saved Increment template (HR Letters module).
        $tplBody = null;
        try {
            if (Schema::hasTable('letters') && Schema::hasColumn('letters', 'body')) {
                $tplBody = DB::table('letters')
                    ->where('is_template', 1)->where('letter_type', 'increment')
                    ->when(! empty($r->tenant_id) && Schema::hasColumn('letters', 'tenant_id'), fn ($q) => $q->where('tenant_id', $r->tenant_id))
                    ->orderByDesc('id')->value('body');
            }
        } catch (\Throwable $e) {
            $tplBody = null;
        }

        // DomPDF's default font lacks the ₹ glyph — use "Rs" in the PDF body.
        $money = fn ($n) => 'Rs '.number_format((float) $n, 2);
        $eff = ! empty($r->effective) ? Carbon::parse($r->effective)->format('d M Y') : now()->format('d M Y');
        $pct = (float) ($r->pct ?? 0);
        if ($pct <= 0 && (float) ($r->old_ctc ?? 0) > 0 && (float) ($r->new_ctc ?? 0) > 0) {
            $pct = round(((float) $r->new_ctc - (float) $r->old_ctc) / (float) $r->old_ctc * 100, 2);
        }
        $promo = ! empty($r->new_designation)
            ? 'Further, we are pleased to promote you to the role of '.$r->new_designation.' with effect from the same date. '
            : '';

        $repl = [
            '{{employee_name}}' => (string) $emp->name,
            '{{emp_code}}' => (string) ($emp->emp_code ?? ''),
            '{{designation}}' => (string) (($r->new_designation ?? '') ?: ($emp->designation ?? '')),
            '{{department}}' => (string) ($emp->department ?? ''),
            '{{company}}' => $company ?: 'the Company',
            '{{date}}' => $eff,
            '{{ctc}}' => $money($r->new_ctc ?? 0),
            '{{old_ctc}}' => $money($r->old_ctc ?? 0),
            '{{pct}}' => rtrim(rtrim(number_format($pct, 2), '0'), '.').'%',
            '{{promotion_line}}' => $promo,
        ];
        $body = strtr((string) ($tplBody ?: self::DEFAULT_BODY), $repl);
        // Custom templates may carry placeholders we don't fill here — blank them.
        $body = (string) preg_replace('/\{\{\w+\}\}/', '', $body);

        try {
            $brand = ConfigController::brandFor($r->tenant_id ?? null, $emp->company_id ?? null);
        } catch (\Throwable $e) {
            $brand = [];
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('letter-pdf', [
            'title' => 'Increment Letter',
            'body' => $body,
            'brand' => $brand,
            'company' => $company ?: 'SmartPRS',
            'companyAddress' => '',
            'date' => now()->format('d M Y'),
            'empName' => $emp->name,
            'empCode' => $emp->emp_code,
        ]);

        return ['pdf' => $pdf, 'file' => 'Increment-Letter-'.($emp->emp_code ?: $emp->id).'.pdf'];
    }

    /**
     * Email the Increment Letter (PDF attached) to the employee. Called from
     * RequestController::decide on APPROVAL (Ejaz: auto-email). Fail-soft:
     * the approval stands even if the email fails.
     */
    public static function sendLetter(int $id): void
    {
        $r = DB::table('increments')->where('id', $id)->first();
        if (! $r) {
            return;
        }
        $emp = DB::table('employees')->where('id', $r->employee_id)->first();
        if (! $emp || empty($emp->email)) {
            return;
        }
        $b = self::buildLetter($r);
        $eff = ! empty($r->effective) ? Carbon::parse($r->effective)->format('d M Y') : 'the date on the letter';

        \App\Services\MailService::queue([
            'tenant_id' => $r->tenant_id,
            'company_id' => $emp->company_id,
            'to' => $emp->email,
            'to_name' => $emp->name,
            'subject' => 'Increment Letter — effective '.$eff,
            'heading' => 'Congratulations — your increment is approved',
            'intro' => 'Please find your formal Increment Letter attached.',
            'lines' => array_filter([
                'Revised annual CTC' => 'Rs '.number_format((float) ($r->new_ctc ?? 0), 2),
                'Effective from' => $eff,
                'New designation' => ! empty($r->new_designation) ? $r->new_designation : null,
            ]),
            'body' => 'All other terms of your employment remain unchanged.',
            'kind' => 'increment.letter',
            'attach_b64' => $b ? base64_encode($b['pdf']->output()) : null,
            'attach_name' => $b ? $b['file'] : null,
        ]);

        DB::table('increments')->where('id', $id)->update(ApprovalService::safeRow('increments', [
            'letter_sent_at' => now(), 'updated_at' => now(),
        ]));
    }

    /** Stream the Increment Letter PDF (register download / employee copy). */
    public function letter(Request $request, int $id)
    {
        $r = $this->row($request, $id);
        if (! $r) {
            return response('Increment not found.', 404)->header('Content-Type', 'text/plain');
        }
        $b = self::buildLetter($r);
        if (! $b) {
            return response('Employee not found for this increment.', 404)->header('Content-Type', 'text/plain');
        }

        return $b['pdf']->stream($b['file']);
    }

    /**
     * rev 84b (Ejaz): EDIT an increment. Pending/rejected — free edit.
     * Approved — still editable (his rule) and the corrected letter re-emails.
     * APPLIED — frozen (the CTC change already happened; raise a fresh one).
     */
    public function update(Request $request, int $id)
    {
        try {
            $user = $request->user();
            $me = null;
            try {
                $me = ! empty($user->employee_id)
                    ? DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first()
                    : DB::table('employees')
                        ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
                        ->whereNull('deleted_at')
                        ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
                        ->first();
            } catch (\Throwable $e) {
            }
            ApprovalService::ensureAuditCols('increments');
            $tid = $user->tenant_id;
            $r = DB::table('increments')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $r) {
                return response()->json(['ok' => false, 'error' => 'Increment not found'], 404);
            }
            if (! empty($r->applied_at)) {
                return response()->json(['ok' => false, 'error' => 'Already APPLIED to the employee record on '.Carbon::parse($r->applied_at)->format('d M Y').' — it can no longer be edited. Raise a fresh increment instead.'], 422);
            }
            $isManager = $user->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
            $isApprover = $me && (int) ($r->approver_id ?? 0) === (int) $me->id;
            if (! $isManager && ! $isApprover) {
                return response()->json(['ok' => false, 'error' => 'Only Admin / HR or the approver can edit.'], 403);
            }

            $v = $request->validate([
                'cycle' => ['nullable', 'string', 'max:60'],
                'old_ctc' => ['nullable', 'numeric', 'min:0'],
                'new_ctc' => ['nullable', 'numeric', 'min:0'],
                'pct' => ['nullable', 'numeric'],
                'new_designation' => ['nullable', 'string', 'max:120'],
                'effective' => ['nullable', 'string', 'max:20'],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);
            $upd = [];
            foreach (['cycle', 'new_designation', 'reason'] as $k) {
                if (array_key_exists($k, $v)) {
                    $upd[$k] = $v[$k] !== '' ? $v[$k] : null;
                }
            }
            if (! empty($v['effective'])) {
                try {
                    $upd['effective'] = Carbon::parse((string) $v['effective'])->toDateString();
                } catch (\Throwable $e) {
                }
            }
            // Cross-compute the three figures so they can never disagree.
            $old = isset($v['old_ctc']) && is_numeric($v['old_ctc']) && (float) $v['old_ctc'] > 0 ? (float) $v['old_ctc'] : (float) ($r->old_ctc ?? 0);
            $new = isset($v['new_ctc']) && is_numeric($v['new_ctc']) && (float) $v['new_ctc'] > 0 ? (float) $v['new_ctc'] : (float) ($r->new_ctc ?? 0);
            $pct = isset($v['pct']) && is_numeric($v['pct']) ? (float) $v['pct'] : 0.0;
            if ($new <= 0 && $old > 0 && $pct != 0.0) {
                $new = round($old * (1 + $pct / 100), 2);
            }
            if ($new > 0 && $old > 0) {
                $pct = round(($new - $old) / $old * 100, 2);
            }
            $moneyChanged = round($new, 2) !== round((float) ($r->new_ctc ?? 0), 2)
                || round($old, 2) !== round((float) ($r->old_ctc ?? 0), 2);
            $upd['old_ctc'] = round($old, 2);
            $upd['new_ctc'] = round($new, 2);
            $upd['pct'] = $pct;
            $upd['updated_at'] = now();
            DB::table('increments')->where('id', $id)->update(ApprovalService::safeRow('increments', $upd));

            // Approved + meaningful change → the employee holds a stale letter:
            // re-email the corrected one automatically (fail-soft).
            $resent = false;
            $desigChanged = array_key_exists('new_designation', $upd) && (string) ($upd['new_designation'] ?? '') !== (string) ($r->new_designation ?? '');
            $effChanged = array_key_exists('effective', $upd) && substr((string) ($upd['effective'] ?? ''), 0, 10) !== substr((string) ($r->effective ?? ''), 0, 10);
            if (($r->status ?? '') === 'approved' && ($moneyChanged || $desigChanged || $effChanged)) {
                try {
                    self::sendLetter($id);
                    $resent = true;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Increment letter re-send failed (#'.$id.'): '.$e->getMessage());
                }
            }

            return response()->json(['ok' => true, 'message' => 'Increment updated.'.($resent ? ' Corrected letter re-emailed to the employee.' : '')]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 84b (Ejaz): DELETE — allowed ONLY before approval (pending/rejected).
     * Approved or applied increments can never be deleted.
     */
    public function destroy(Request $request, int $id)
    {
        try {
            if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
                return $deny;
            }
            $tid = $request->user()->tenant_id;
            $r = DB::table('increments')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $r) {
                return response()->json(['ok' => false, 'error' => 'Increment not found'], 404);
            }
            if (($r->status ?? '') === 'approved' || ! empty($r->applied_at)) {
                return response()->json(['ok' => false, 'error' => 'An APPROVED increment cannot be deleted — it can only be edited.'], 422);
            }
            DB::table('increments')->where('id', $id)->delete();

            return response()->json(['ok' => true, 'message' => 'Increment deleted.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * ONE-CLICK manual application (Ejaz: no scheduled auto-apply — HR decides
     * when). Updates employees.ctc (+ designation on promotion); idempotent.
     */
    public function applyCtc(Request $request, int $id)
    {
        try {
            $user = $request->user();
            if (! $user->hasAnyRole(['super_admin', 'admin', 'hr_manager'])) {
                return response()->json(['ok' => false, 'error' => 'Only Admin / HR can apply an increment to the employee record.'], 403);
            }
            ApprovalService::ensureAuditCols('increments');
            $tid = $user->tenant_id;
            $r = DB::table('increments')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $r) {
                return response()->json(['ok' => false, 'error' => 'Increment not found'], 404);
            }
            if (($r->status ?? '') !== 'approved') {
                return response()->json(['ok' => false, 'error' => 'Only an APPROVED increment can be applied.'], 422);
            }
            if (! empty($r->applied_at)) {
                return response()->json(['ok' => false, 'error' => 'Already applied on '.Carbon::parse($r->applied_at)->format('d M Y').' by '.($r->applied_by ?: 'HR').'.'], 422);
            }
            if ((float) ($r->new_ctc ?? 0) <= 0) {
                return response()->json(['ok' => false, 'error' => 'This increment has no New CTC recorded.'], 422);
            }

            $upd = ['ctc' => (float) $r->new_ctc, 'updated_at' => now()];
            if (! empty($r->new_designation) && Schema::hasColumn('employees', 'designation')) {
                $upd['designation'] = $r->new_designation;
            }
            DB::table('employees')->where('id', $r->employee_id)->update($upd);

            $by = trim((string) ($user->name ?? '')) ?: (string) ($user->email ?? '');
            DB::table('increments')->where('id', $id)->update(ApprovalService::safeRow('increments', [
                'applied_at' => now(), 'applied_by' => $by, 'updated_at' => now(),
            ]));

            // rev180 (Gap 6) — BACKDATED ARREARS. If the increment's effective
            // date is in a PAST month, the salary difference for the elapsed
            // months has never been paid. Create ONE approved 'Salary Arrears'
            // entry (the single money channel) that folds into the NEXT payroll
            // as its own earning line. Months counted: effective month through
            // the month BEFORE the current one (the current month already pays
            // the new CTC); a mid-month effective date prorates its first month
            // by calendar days. Best-effort — never blocks the apply.
            $arrearsMsg = '';
            try {
                $oldCtc = (float) ($r->old_ctc ?? 0);
                $newCtc = (float) $r->new_ctc;
                $effRaw = trim((string) ($r->effective ?? ''));
                if ($oldCtc > 0 && $newCtc > $oldCtc && $effRaw !== '' && Schema::hasTable('commissions')) {
                    $eff = Carbon::parse(substr($effRaw, 0, 10));
                    $curMonth = now()->format('Y-m');
                    if ($eff->format('Y-m') < $curMonth) {
                        $diffMonthly = ($newCtc - $oldCtc) / 12;
                        $months = 0.0;
                        $cursor = $eff->copy()->startOfMonth();
                        $firstFraction = ($eff->daysInMonth - $eff->day + 1) / $eff->daysInMonth;
                        $isFirst = true;
                        while ($cursor->format('Y-m') < $curMonth) {
                            $months += $isFirst ? $firstFraction : 1.0;
                            $isFirst = false;
                            $cursor->addMonth();
                        }
                        $arrears = round($diffMonthly * $months, 2);
                        if ($arrears >= 1) {
                            FinYearController::stamp('commissions', $tid);
                            $cid = DB::table('employees')->where('id', $r->employee_id)->value('company_id');
                            // Target the first month whose payroll can still pick it
                            // up: if the CURRENT month's run is already past draft,
                            // the arrears ride NEXT month's payslip instead of
                            // stranding on a locked month.
                            $targetMonth = $curMonth;
                            try {
                                $runNow = DB::table('payroll_runs')
                                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                                    ->where('company_id', $cid)->where('cycle_label', $curMonth)
                                    ->orderByDesc('id')->first();
                                if ($runNow && ($runNow->status ?? '') !== 'draft') {
                                    $targetMonth = Carbon::parse($curMonth.'-01')->addMonth()->format('Y-m');
                                }
                            } catch (\Throwable $eRun) {
                                // keep current month
                            }
                            DB::table('commissions')->insert(ApprovalService::safeRow('commissions', [
                                'tenant_id' => $tid,
                                'company_id' => $cid,
                                'employee_id' => $r->employee_id,
                                'purpose' => 'Salary Arrears',
                                'description' => 'Increment arrears: CTC '.number_format($oldCtc).' -> '.number_format($newCtc)
                                    .' w.e.f. '.$eff->format('d M Y').' · '.rtrim(rtrim(number_format($months, 2), '0'), '.').' month(s) @ Rs '
                                    .number_format($diffMonthly, 2).'/month (increment #'.$id.')',
                                'gross_amount' => $arrears,
                                'tds_rate' => 0,
                                'tds_amount' => 0,
                                'amount' => $arrears,
                                'cycle_month' => $targetMonth,
                                'month' => $targetMonth,
                                'fin_year' => FinYearController::fyOf($targetMonth),
                                'payout_method' => 'with_salary',
                                'status' => 'approved',
                                'entered_by' => $by,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]));
                            $arrearsMsg = ' Arrears of Rs '.number_format($arrears, 2).' for '
                                .rtrim(rtrim(number_format($months, 2), '0'), '.')
                                .' back month(s) created as an approved "Salary Arrears" entry — it will fold into the '
                                .Carbon::parse($targetMonth.'-01')->format('M Y').' payroll.';
                        }
                    }
                }
            } catch (\Throwable $eArr) {
                $arrearsMsg = ' (Arrears could not be auto-computed: '.$eArr->getMessage().' — add manually via Bonus & Encashment if due.)';
            }

            return response()->json([
                'ok' => true,
                'message' => 'CTC updated to Rs '.number_format((float) $r->new_ctc, 2)
                    .(! empty($r->new_designation) ? ' · designation now '.$r->new_designation : '')
                    .' — payroll uses the new salary from the next generation.'.$arrearsMsg,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
