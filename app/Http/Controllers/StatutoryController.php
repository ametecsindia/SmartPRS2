<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computed statutory reports (rev 40): Gratuity provision + Professional Tax.
 * These have no table of their own — they are derived live from active
 * employees + the configured statutory rates (reusing AppDataController::
 * computeSlip so basic/gross match payroll). Read-only, tenant-scoped,
 * admin/HR guarded, fail-soft JSON. Returns {columns, rows, note} the SPA
 * renders as a simple table.
 */
class StatutoryController extends Controller
{
    public function report(Request $request, string $type)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $rates = SettingsController::rates($tid);
            $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

            $emps = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('emp_code')
                ->get(array_values(array_filter(['id', 'emp_code', 'name', 'ctc', 'doj', 'company_id',
                    Schema::hasColumn('employees', 'pt_state') ? 'pt_state' : null,
                    Schema::hasColumn('employees', 'gender') ? 'gender' : null]))); // rev180 — schema-safe on fresh DBs; gender for MH female PT exemption

            if ($type === 'gratuity') {
                $cols = ['Code', 'Name', 'DOJ', 'Years', 'Monthly Basic', 'Eligible', 'Gratuity'];
                $rows = [];
                $total = 0.0;
                foreach ($emps as $e) {
                    // F1 — the rates that actually apply to THIS employee
                    // (company / state scope). Identical to $rates when the
                    // install has no override rows.
                    $er = \App\Services\StatutoryConfig::applyScope($rates, [
                        'tenant_id' => $tid,
                        'company_id' => $companyId ?: ($e->company_id ?? null),
                        'pt_state' => (string) ($e->pt_state ?? ''),
                        'month' => $month ?? now()->format('Y-m'),
                    ]);
                    $s = AppDataController::computeSlip((float) $e->ctc, $er);
                    $years = $e->doj ? Carbon::parse($e->doj)->diffInDays(now()) / 365.25 : 0;
                    $elig = $years >= 5;
                    $grat = $elig ? round((15 / 26) * $s['basic'] * floor($years)) : 0;
                    $total += $grat;
                    $rows[] = ['Code' => $e->emp_code, 'Name' => $e->name, 'DOJ' => $e->doj ?: '—',
                        'Years' => round($years, 1), 'Monthly Basic' => $s['basic'],
                        'Eligible' => $elig ? 'Yes' : 'No (<5y)', 'Gratuity' => $grat];
                }

                return response()->json([
                    'ok' => true,
                    'label' => 'Gratuity provision (payable on exit, 5+ years of service)',
                    'columns' => $cols,
                    'rows' => $rows,
                    'note' => 'Formula: (15 ÷ 26) × last monthly Basic × completed years; eligible at 5+ years. Total provisioned: ₹'.number_format($total),
                ]);
            }

            if ($type === 'pt') {
                // rev180 (Gap 1) — the PT report now uses the SAME state-aware
                // slab engine as the payslip (AppDataController::ptForGross), so
                // the report and the payslips can never disagree. The old flat
                // pt_amount is gone from this report.
                $cols = ['Code', 'Name', 'State', 'Monthly Gross', 'Monthly PT', 'Annual PT'];
                $rows = [];
                $total = 0.0;
                $month = now()->format('Y-m');
                foreach ($emps as $e) {
                    // F1 — the rates that actually apply to THIS employee
                    // (company / state scope). Identical to $rates when the
                    // install has no override rows.
                    $er = \App\Services\StatutoryConfig::applyScope($rates, [
                        'tenant_id' => $tid,
                        'company_id' => $companyId ?: ($e->company_id ?? null),
                        'pt_state' => (string) ($e->pt_state ?? ''),
                        'month' => $month ?? now()->format('Y-m'),
                    ]);
                    $s = AppDataController::computeSlip((float) $e->ctc, $er);
                    $mpt = AppDataController::ptForGross((float) $s['gross'], $er, (string) ($e->pt_state ?? ''), $month, (string) ($e->gender ?? ''));
                    // Annual: Maharashtra's February ₹300 makes the year ₹2,500 for the top slab.
                    $annual = $mpt * 12;
                    if (stripos((string) ($e->pt_state ?? ''), 'maharashtra') !== false && $mpt >= 200) {
                        $annual = 200 * 11 + 300;
                    }
                    $total += $annual;
                    $rows[] = ['Code' => $e->emp_code, 'Name' => $e->name, 'State' => $e->pt_state ?: '— (default slab)',
                        'Monthly Gross' => $s['gross'], 'Monthly PT' => $mpt, 'Annual PT' => $annual];
                }

                return response()->json([
                    'ok' => true,
                    'label' => 'Professional Tax — state-wise slab on monthly gross (same engine as the payslip)',
                    'columns' => $cols,
                    'rows' => $rows,
                    'note' => 'PT follows each employee\'s PT State (built-in slabs for major states; PT-free states show ₹0; employees without a state use the configured default slab). Slab values are indicative — verify against current state law before filing. Total annual PT across active staff: ₹'.number_format($total),
                ]);
            }

            if ($type === 'bonus') {
                // rev180 (Gap 5) — STATUTORY BONUS REGISTER (Payment of Bonus Act,
                // 1965): eligible when Basic+DA wage ≤ ₹21,000/month; bonus is
                // computed on a bonus wage capped at ₹7,000/month (or the state
                // minimum wage if higher — cap kept at ₹7,000 here); rate between
                // 8.33% (statutory minimum) and 20%, configurable via bonus_pct.
                $pct = (float) ($rates['bonus_pct'] ?? 8.33);
                $pct = max(8.33, min(20.0, $pct > 0 ? $pct : 8.33));
                $cols = ['Code', 'Name', 'DOJ', 'Monthly Basic', 'Eligible', 'Bonus Wage/mo', 'Months (FY)', 'Annual Bonus @'.rtrim(rtrim(number_format($pct, 2), '0'), '.').'%'];
                $rows = [];
                $total = 0.0;
                // Current financial year start (April 1).
                $fyStart = now()->month >= 4 ? now()->copy()->startOfYear()->month(4)->day(1) : now()->copy()->subYear()->startOfYear()->month(4)->day(1);
                foreach ($emps as $e) {
                    // F1 — the rates that actually apply to THIS employee
                    // (company / state scope). Identical to $rates when the
                    // install has no override rows.
                    $er = \App\Services\StatutoryConfig::applyScope($rates, [
                        'tenant_id' => $tid,
                        'company_id' => $companyId ?: ($e->company_id ?? null),
                        'pt_state' => (string) ($e->pt_state ?? ''),
                        'month' => $month ?? now()->format('Y-m'),
                    ]);
                    $s = AppDataController::computeSlip((float) $e->ctc, $er);
                    $basic = (float) $s['basic'];
                    $elig = $basic > 0 && $basic <= 21000;
                    $wage = $elig ? min($basic, 7000.0) : 0.0;
                    // Months worked this FY (joiners prorated; 30-day months).
                    $months = 12;
                    if ($e->doj) {
                        try {
                            $doj = Carbon::parse($e->doj);
                            if ($doj->gt($fyStart)) {
                                $months = max(0, min(12, (int) ceil($doj->diffInDays($fyStart->copy()->addYear()) / 30.44)));
                            }
                        } catch (\Throwable $ex) {
                            // unparseable DOJ → full year
                        }
                    }
                    $bonus = $elig ? round($wage * $months * $pct / 100) : 0.0;
                    $total += $bonus;
                    $rows[] = ['Code' => $e->emp_code, 'Name' => $e->name, 'DOJ' => $e->doj ?: '—',
                        'Monthly Basic' => $basic, 'Eligible' => $elig ? 'Yes' : 'No (Basic > ₹21,000)',
                        'Bonus Wage/mo' => $wage, 'Months (FY)' => $months, 'Annual Bonus @'.rtrim(rtrim(number_format($pct, 2), '0'), '.').'%' => $bonus];
                }

                return response()->json([
                    'ok' => true,
                    'label' => 'Statutory Bonus Register — Payment of Bonus Act (min 8.33%, max 20%)',
                    'columns' => $cols,
                    'rows' => $rows,
                    'note' => 'Eligibility: Basic+DA ≤ ₹21,000/month. Bonus wage capped at ₹7,000/month (use the state minimum wage if higher). Rate configurable in Settings → Statutory Rates (bonus %, default 8.33). Pay the amounts through Bonus & Encashment. Total provision: ₹'.number_format($total),
                ]);
            }

            return response()->json(['ok' => false, 'error' => 'Unknown statutory report'], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}
