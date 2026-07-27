<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commission / Incentive calculation engine (bulk).
 *
 * Computes per-employee payouts from a chosen BASIS and FORMULA, supports bulk
 * CSV import of the per-employee figures, previews the result, and bulk-creates
 * commission entries that feed payroll (same store the single-entry screen uses).
 *
 * BASIS:    collected (amount recovered) | target (gate on % of target met) | manual (amount is the payout)
 * FORMULA:  flat (% of figure) | slab (whole-amount band rate) | portfolio (rate per portfolio/bank)
 *
 * Incentives for salaried staff use the same engine (type = incentive); the
 * created entries are tagged to the month and, once approved, fold into payroll.
 * Money math mirrors the Python-verified model. Admin/HR guarded, fail-soft.
 */
class IncentiveController extends Controller
{
    public function template()
    {
        $csv = "emp_code,employee,portfolio,collected,target,amount\n"
            ."EMP100,Sample Name,ICICI,150000,100000,0\n"
            ."EMP101,Another Name,HDFC,600000,800000,0\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="commission-import-template.csv"',
        ]);
    }

    /** Pure compute — returns the preview rows + total. No writes. */
    public function calculate(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $cfg = $this->cfg($request);
            $rows = $request->input('rows', []);
            if (! is_array($rows)) {
                $rows = [];
            }
            $out = [];
            $total = 0.0;
            foreach ($rows as $r) {
                [$payout, $ach] = $this->payoutFor((array) $r, $cfg);
                $total += $payout;
                $out[] = [
                    'emp_code' => $r['emp_code'] ?? '',
                    'employee' => $r['employee'] ?? '',
                    'portfolio' => $r['portfolio'] ?? '',
                    'collected' => (float) ($r['collected'] ?? 0),
                    'target' => (float) ($r['target'] ?? 0),
                    'achievement' => $ach,
                    'payout' => $payout,
                ];
            }

            return response()->json(['ok' => true, 'rows' => $out, 'total' => round($total, 2), 'count' => count($out)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Compute + create commission entries for the matched employees. */
    public function commit(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $cfg = $this->cfg($request);
            $tid = $request->user()->tenant_id;
            $month = trim((string) $request->input('month', ''));
            $status = $request->input('status') === 'approved' ? 'approved' : 'pending';
            $rows = $request->input('rows', []);
            // F2 — tag each created entry with a payslip pay-line label ("purpose")
            // so bulk incentive runs show as a SEPARATE "Incentive" line on the
            // payslip (vs the "Commission" line), instead of all folding into one.
            // Honour an explicit purpose, else derive from the engine type selector.
            $purpose = trim((string) $request->input('purpose', ''));
            if ($purpose === '') {
                $purpose = strtolower(trim((string) $request->input('type', ''))) === 'incentive' ? 'Incentive' : 'Commission';
            }
            if (! is_array($rows) || $month === '') {
                return response()->json(['ok' => false, 'error' => 'Month and at least one row are required.'], 422);
            }

            $created = 0;
            $skipped = [];
            FinYearController::stamp('commissions', $tid);     // ensure fin_year column
            $fy = FinYearController::fyOf($month);
            // rev176 — ensure the gross/TDS columns exist (mirrors the single-entry
            // path in RequestController) so the 194H/26Q registers — which read
            // gross_amount/tds_amount — see bulk-committed entries too, instead of
            // safeRow silently dropping them on an older schema.
            try {
                $ensure = [
                    'gross_amount' => fn ($t) => $t->decimal('gross_amount', 14, 2)->nullable(),
                    'tds_rate' => fn ($t) => $t->decimal('tds_rate', 6, 2)->nullable(),
                    'tds_amount' => fn ($t) => $t->decimal('tds_amount', 14, 2)->nullable(),
                ];
                foreach ($ensure as $cname => $add) {
                    if (! Schema::hasColumn('commissions', $cname)) {
                        try {
                            Schema::table('commissions', $add);
                        } catch (\Throwable $e) {
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            // rev176 (BUGFIX) — TDS 194H on bulk-committed entries too. The
            // single-entry and scheme-claim paths always deducted it, but bulk
            // commit wrote the full payout with NO TDS — inconsistent tax
            // treatment. gross − TDS = net; the NET is what folds into payroll.
            // Rate: request 'tds_rate' override → Statutory Settings
            // comm_tds_rate → 5% default. Clamped 0–100.
            $tdsRate = 5.0;
            $ratesCfg = [];
            try {
                $ratesCfg = SettingsController::rates($tid);
                $tdsRate = (float) ($ratesCfg['comm_tds_rate'] ?? 5);
            } catch (\Throwable $e) {
                // settings unavailable → statutory default 5%
            }
            // rev181 — eligibility gates, resolved ONCE for the whole batch:
            // DRA gate (off|warn|block) + monthly points threshold (0 = off).
            $draGate = in_array(($ratesCfg['dra_gate'] ?? 'warn'), ['off', 'warn', 'block'], true) ? $ratesCfg['dra_gate'] : 'warn';
            $ptsMin = max(0, (int) ($ratesCfg['points_gate_min'] ?? 0));
            $hasPoints = $ptsMin > 0 && Schema::hasTable('points_ledger');
            // rev181c (D3b + D4) — payout controls for the WHOLE batch: method
            // (with_salary default | separate) and an optional payout date that
            // decides which month's payslip pays. When no date is given and the
            // tenant's incentive_payout_lag is N > 0, the date auto-fills to the
            // 1st of (earned month + N) — the retention-guard lag, hands-free.
            $payMethod = $request->input('payout_method') === 'separate' ? 'separate' : 'with_salary';
            $payDate = null;
            if ($request->filled('payout_date')) {
                try {
                    $payDate = \Illuminate\Support\Carbon::parse((string) $request->input('payout_date'))->toDateString();
                } catch (\Throwable $e) {
                    $payDate = null;
                }
            }
            $lagNote = '';
            if ($payDate === null) {
                $lag = max(0, min(12, (int) ($ratesCfg['incentive_payout_lag'] ?? 0)));
                if ($lag > 0) {
                    try {
                        $payDate = \Illuminate\Support\Carbon::parse($month.'-01')->addMonthsNoOverflow($lag)->toDateString();
                        $lagNote = ' Payout date auto-set to '.$payDate.' by the tenant payout lag ('.$lag.' month'.($lag === 1 ? '' : 's').').';
                    } catch (\Throwable $e) {
                        $payDate = null;
                    }
                }
            }
            $draChecked = [];      // employee_id => ['ok'=>bool,'note'=>string]
            $draBlockedList = []; // block mode — skipped rows
            $draWarnList = [];    // warn mode — paid but flagged
            $ptsSkipList = [];    // below the points threshold — skipped rows
            if ($request->filled('tds_rate') && is_numeric($request->input('tds_rate'))) {
                $tdsRate = (float) $request->input('tds_rate');
            }
            $tdsRate = max(0.0, min(100.0, $tdsRate));
            $tdsTotal = 0.0;
            foreach ($rows as $r) {
                $r = (array) $r;
                [$payout] = $this->payoutFor($r, $cfg);
                if ($payout <= 0) {
                    continue;   // nothing to pay (e.g. target not met)
                }
                $emp = $this->resolveEmployee($tid, $r['emp_code'] ?? '', $r['employee'] ?? '');
                if (! $emp) {
                    $skipped[] = ($r['employee'] ?? $r['emp_code'] ?? '?');
                    continue;
                }
                $who = trim((string) ($r['employee'] ?? $r['emp_code'] ?? ('#'.$emp->id)));
                // rev181 — DRA gate: block = skip the row entirely; warn = pay but list it.
                if ($draGate !== 'off') {
                    if (! array_key_exists($emp->id, $draChecked)) {
                        $draChecked[$emp->id] = ComplianceController::draValid((int) $emp->id);
                    }
                    if (! $draChecked[$emp->id]['ok']) {
                        if ($draGate === 'block') {
                            $draBlockedList[] = $who.' ('.$draChecked[$emp->id]['note'].')';
                            continue;
                        }
                        $draWarnList[$emp->id] = $who.' ('.$draChecked[$emp->id]['note'].')';
                    }
                }
                // rev181 — points-eligibility gate: points earned IN THE INCENTIVE
                // MONTH must reach the configured minimum. Points never convert to
                // money — they only decide WHO qualifies for the incentive run.
                if ($hasPoints) {
                    try {
                        $mFrom = $month.'-01';
                        $mTo = \Illuminate\Support\Carbon::parse($mFrom)->addMonthNoOverflow()->format('Y-m-d');
                        $pts = (int) DB::table('points_ledger')->where('employee_id', $emp->id)
                            ->when($tid && Schema::hasColumn('points_ledger', 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                            ->where('date', '>=', $mFrom)->where('date', '<', $mTo)
                            ->sum('points');
                        if ($pts < $ptsMin) {
                            $ptsSkipList[] = $who.' ('.$pts.'/'.$ptsMin.' pts)';
                            continue;
                        }
                    } catch (\Throwable $e) {
                        // points data unreadable → never block the payout on it
                    }
                }
                $tds = round($payout * $tdsRate / 100, 2);
                $net = round($payout - $tds, 2);
                if ($net <= 0) {
                    continue;   // fully consumed by TDS — nothing to pay
                }
                $tdsTotal = round($tdsTotal + $tds, 2);
                $row = ApprovalService::safeRow('commissions', [
                    'tenant_id' => $tid,
                    'company_id' => $emp->company_id,
                    'employee_id' => $emp->id,
                    'purpose' => $purpose,   // F2 — payslip pay-line label (Commission | Incentive)
                    'portfolio' => $r['portfolio'] ?? null,
                    'gross_amount' => $payout,
                    'tds_rate' => $tdsRate,
                    'tds_amount' => $tds,
                    'amount' => $net,
                    'payout_method' => $payMethod,      // rev181c — batch payout method
                    'payout_date' => $payDate,          // rev181c — explicit or lag-derived
                    'cycle_month' => $month,
                    'month' => $month,
                    'fin_year' => $fy,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('commissions')->insert($row);
                $created++;
            }

            $msg = "Created {$created} commission entr".($created === 1 ? 'y' : 'ies')." for {$month} (status: {$status}).";
            if ($tdsRate > 0 && $created > 0) {
                $msg .= ' TDS 194H @ '.rtrim(rtrim(number_format($tdsRate, 2), '0'), '.').'% deducted (₹'.number_format($tdsTotal, 2).' in total) — the NET amounts will pay.';
            }
            if ($payMethod === 'separate') {
                $msg .= ' Payout method: SEPARATE — these entries never fold into a payslip; pay them via Record Payment (printable vouchers in the ledger).';
            } elseif ($payDate) {
                $msg .= ' Payout date '.$payDate.' — they fold into THAT month\'s payslip once approved.'.$lagNote;
            } elseif ($status === 'approved') {
                $msg .= ' They will fold into that month\'s payroll.';
            } else {
                $msg .= ' Approve them to reflect in payroll.';
            }
            if ($skipped) {
                $msg .= ' Skipped (no matching employee): '.implode(', ', array_slice($skipped, 0, 10)).(count($skipped) > 10 ? '…' : '').'.';
            }
            // rev181 — gate outcomes, spelled out so nobody wonders where a row went.
            if ($draBlockedList) {
                $msg .= ' DRA GATE — skipped '.count($draBlockedList).' row(s), certification expired/missing: '
                    .implode(', ', array_slice($draBlockedList, 0, 8)).(count($draBlockedList) > 8 ? '…' : '')
                    .'. Renew in Field Force -> DRA Certifications, or set the gate to Warn in Statutory Rates.';
            }
            if ($draWarnList) {
                $msg .= ' DRA WARNING — paid, but the certification is expired/missing for: '
                    .implode(', ', array_slice(array_values($draWarnList), 0, 8)).(count($draWarnList) > 8 ? '…' : '').'.';
            }
            if ($ptsSkipList) {
                $msg .= ' POINTS GATE — skipped '.count($ptsSkipList).' row(s) below the '.$ptsMin.'-point monthly minimum: '
                    .implode(', ', array_slice($ptsSkipList, 0, 8)).(count($ptsSkipList) > 8 ? '…' : '').'.';
            }

            return response()->json(['ok' => true, 'created' => $created, 'skipped' => $skipped, 'message' => $msg,
                'draBlocked' => count($draBlockedList), 'draWarned' => count($draWarnList), 'pointsSkipped' => count($ptsSkipList)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- internals ----------------------------------------------------------

    private function cfg(Request $request): array
    {
        return [
            'basis' => in_array($request->input('basis'), ['collected', 'target', 'manual'], true) ? $request->input('basis') : 'collected',
            'formula' => in_array($request->input('formula'), ['flat', 'slab', 'portfolio'], true) ? $request->input('formula') : 'flat',
            'flat_rate' => (float) $request->input('flat_rate', 0),
            'threshold' => (float) $request->input('threshold', 100),
            'slabs' => is_array($request->input('slabs')) ? $request->input('slabs') : [],
            'portfolio_rates' => is_array($request->input('portfolio_rates')) ? $request->input('portfolio_rates') : [],
        ];
    }

    /** Returns [payout, achievementPctOrNull]. Mirrors the Python-verified model. */
    private function payoutFor(array $row, array $cfg): array
    {
        $collected = (float) ($row['collected'] ?? 0);
        $target = (float) ($row['target'] ?? 0);
        $manual = (float) ($row['amount'] ?? 0);
        $basis = $cfg['basis'];
        $formula = $cfg['formula'];

        if ($basis === 'manual') {
            return [round($manual, 2), null];
        }

        $fig = $collected;
        $ach = $target > 0 ? round($collected / $target * 100, 1) : 0.0;
        if ($basis === 'target' && ($target <= 0 || $ach < $cfg['threshold'])) {
            return [0.0, $ach];
        }

        $p = 0.0;
        if ($formula === 'flat') {
            $p = $fig * $cfg['flat_rate'] / 100;
        } elseif ($formula === 'slab') {
            $bands = $cfg['slabs'];
            $rate = count($bands) ? (float) ($bands[count($bands) - 1]['rate'] ?? 0) : 0.0;
            foreach ($bands as $b) {
                $upto = (float) ($b['upto'] ?? 0);
                if ($upto > 0 && $fig <= $upto) {
                    $rate = (float) ($b['rate'] ?? 0);
                    break;
                }
            }
            $p = $fig * $rate / 100;
        } else {
            $pf = strtolower(trim((string) ($row['portfolio'] ?? '')));
            $rate = 0.0;
            foreach ($cfg['portfolio_rates'] as $pr) {
                if (strtolower(trim((string) ($pr['name'] ?? ''))) === $pf && $pf !== '') {
                    $rate = (float) ($pr['rate'] ?? 0);
                    break;
                }
            }
            $p = $fig * $rate / 100;
        }

        return [round($p, 2), $basis === 'target' ? $ach : null];
    }

    private function resolveEmployee(?int $tid, string $code, string $name)
    {
        $q = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))->whereNull('deleted_at');
        $code = trim($code);
        $name = trim($name);
        // "Name (CODE)" → pull the code out.
        if ($code === '' && preg_match('/\(([^)]+)\)\s*$/', $name, $m)) {
            $code = trim($m[1]);
        }
        if ($code !== '') {
            $e = (clone $q)->where('emp_code', $code)->first(['id', 'company_id']);
            if ($e) {
                return $e;
            }
        }
        if ($name !== '') {
            $bare = trim(preg_replace('/\([^)]*\)\s*$/', '', $name));
            $e = (clone $q)->whereRaw('LOWER(name) = ?', [strtolower($bare)])->first(['id', 'company_id']);
            if ($e) {
                return $e;
            }
        }

        return null;
    }
}
