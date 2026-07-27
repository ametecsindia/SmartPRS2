<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic, DB-backed request + approval controller, reused by every money/HR
 * request module (expenses, advances, loans, commissions, clawbacks,
 * increments, exits, bonus). Mirrors the proven Leave pattern but config-driven
 * via ApprovalService::modules(), so one controller serves them all.
 *
 *  - list  : GET  /app/requests/{module}
 *  - apply : POST /app/requests/{module}
 *  - decide: POST /app/requests/{module}/{id}/decide
 *  - inbox : GET  /app/approvals  (aggregates leaves + every module)
 *
 * Every endpoint fails soft (JSON {error}, never a 500). Inserts/updates are
 * filtered to real columns (ApprovalService::safeRow) so they're schema-safe.
 */
class RequestController extends Controller
{
    private function cfg(string $module): ?array
    {
        return ApprovalService::modules()[$module] ?? null;
    }

    private function currentEmployee(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;

        // Prefer the real users.employee_id link; fall back to email/name for
        // legacy accounts that were never linked to an employee record.
        if (! empty($user->employee_id)) {
            $byId = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($byId) {
                return $byId;
            }
        }

        return DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
            ->first();
    }

    private function isManager(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    /** rev 116: Accounts dept — confirms money received BEFORE manager approval. */
    private function isAccounts(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin', 'accountant']);
    }

    /**
     * rev 116 (Ejaz): COLLECTION EVIDENCE on every commission claim — customer,
     * account ref, what was collected, when, where, how (mode), proof upload,
     * and the Accounts-confirmation stage that gates manager approval.
     */
    private static function ensureCollectionCols(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('commissions')) {
                return;
            }
            $cols = [
                // base_amount/scheme_id normally come from SchemeController::ensure,
                // but a claim can arrive before the Schemes screen was ever opened.
                'base_amount' => fn ($t) => $t->decimal('base_amount', 14, 2)->nullable(),
                'scheme_id' => fn ($t) => $t->unsignedBigInteger('scheme_id')->nullable()->index(),
                'customer_name' => fn ($t) => $t->string('customer_name', 150)->nullable(),
                'account_ref' => fn ($t) => $t->string('account_ref', 100)->nullable(),
                'collection_type' => fn ($t) => $t->string('collection_type', 40)->nullable(),
                'collected_at' => fn ($t) => $t->dateTime('collected_at')->nullable(),
                'collection_location' => fn ($t) => $t->string('collection_location', 190)->nullable(),
                'collection_mode' => fn ($t) => $t->string('collection_mode', 30)->nullable(),
                'proof_path' => fn ($t) => $t->string('proof_path', 255)->nullable(),
                'claim_type' => fn ($t) => $t->string('claim_type', 12)->nullable(), // collection | simple (rev 116d)
                'accounts_status' => fn ($t) => $t->string('accounts_status', 12)->nullable(), // pending | confirmed | flagged
                'accounts_by' => fn ($t) => $t->string('accounts_by', 120)->nullable(),
                'accounts_at' => fn ($t) => $t->timestamp('accounts_at')->nullable(),
                'accounts_note' => fn ($t) => $t->string('accounts_note', 255)->nullable(),
            ];
            foreach ($cols as $name => $add) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('commissions', $name)) {
                    try {
                        \Illuminate\Support\Facades\Schema::table('commissions', $add);
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** Normalise the collection-mode display label → stable token. */
    private static function normMode(string $v): string
    {
        $v = strtolower($v);
        if (strpos($v, 'office') !== false) {
            return 'cash_office';
        }
        if (strpos($v, 'deposit') !== false) {
            return 'cash_deposited';
        }
        if (strpos($v, 'direct') !== false || strpos($v, 'client') !== false) {
            return 'client_direct';
        }
        if (strpos($v, 'online') !== false || strpos($v, 'upi') !== false) {
            return 'online_company';
        }

        return $v !== '' ? substr($v, 0, 30) : '';
    }

    /** List rows for one module with names + audit + canDecide. */
    public function list(Request $request, string $module)
    {
        try {
            $cfg = $this->cfg($module);
            if (! $cfg) {
                return response()->json(['rows' => [], 'error' => 'Unknown module']);
            }
            $table = $cfg['table'];
            ApprovalService::ensureAuditCols($table);
            $tid = $request->user()->tenant_id;
            $me = $this->currentEmployee($request);
            $myId = $me->id ?? null;
            $manager = $this->isManager($request);
            $amountCol = $cfg['amount'];

            // Some request tables have no company_id column — only join companies
            // when it actually exists, else skip that join and select.
            $hasCompany = Schema::hasColumn($table, 'company_id');
            $sel = ['r.*', 'e.name as emp_name', 'e.emp_code'];
            if ($hasCompany) {
                $sel[] = 'c.name as company_name';
            }
            $fy = (string) $request->query('fy', '');
            $hasFinYear = Schema::hasColumn($table, 'fin_year');
            $rows = DB::table($table.' as r')
                ->leftJoin('employees as e', 'e.id', '=', 'r.employee_id')
                ->when($hasCompany, fn ($q) => $q->leftJoin('companies as c', 'c.id', '=', 'r.company_id'))
                ->when($tid, fn ($q) => $q->where('r.tenant_id', $tid))
                // rev 84 (Ejaz): self-claims — a non-Admin/HR user sees their
                // OWN entries plus their reportees' (as approver / manager).
                ->when($module === 'commissions' && ! $manager, function ($q) use ($myId, $me) {
                    $q->where(function ($w) use ($myId, $me) {
                        $w->where('r.employee_id', (int) ($myId ?? 0))
                            ->orWhere('r.approver_id', (int) ($myId ?? 0))
                            ->orWhere('e.reporting_manager_id', (int) ($myId ?? 0));
                        if ($me && ! empty($me->name)) {
                            $w->orWhere('e.reporting_manager', $me->name);
                        }
                    });
                })
                ->when($fy !== '' && $fy !== 'all', function ($q) use ($fy, $hasFinYear) {
                    [$from, $to] = FinYearController::range($fy);
                    $to .= ' 23:59:59';
                    $q->where(function ($w) use ($fy, $from, $to, $hasFinYear) {
                        if ($hasFinYear) {
                            $w->where('r.fin_year', $fy)
                                ->orWhere(function ($w2) use ($from, $to) { $w2->whereNull('r.fin_year')->whereBetween('r.created_at', [$from, $to]); });
                        } else {
                            $w->whereBetween('r.created_at', [$from, $to]);
                        }
                    });
                })
                ->orderByDesc('r.id')
                ->get($sel);

            // rev 78: PROCESS TRACKER data for transfers — onboarding status per
            // employee so the timeline can show the formalities stage.
            $onb = [];
            if ($module === 'transfers' && Schema::hasTable('onboarding')) {
                try {
                    $empIds = $rows->pluck('employee_id')->filter()->unique()->values();
                    foreach (DB::table('onboarding')->whereIn('employee_id', $empIds)->orderBy('id')->get(['employee_id', 'status']) as $o) {
                        $onb[(int) $o->employee_id] = $o->status;   // latest row wins
                    }
                } catch (\Throwable $e) {
                }
            }

            // rev 85: ledger totals per commission row (paid so far → balance).
            $paidMap = [];
            if ($module === 'commissions' && Schema::hasTable('commission_payments')) {
                try {
                    $ids = $rows->pluck('id')->all();
                    foreach (DB::table('commission_payments')->whereIn('commission_id', $ids)
                        ->groupBy('commission_id')->selectRaw('commission_id, SUM(amount) s')->get() as $p) {
                        $paidMap[(int) $p->commission_id] = (float) $p->s;
                    }
                } catch (\Throwable $e) {
                }
            }

            $out = $rows->map(function ($r) use ($myId, $manager, $amountCol, $module, $onb, $paidMap) {
                $a = (array) $r;
                $pending = ($a['status'] ?? '') === 'pending';
                $canDecide = $pending && ($manager || ($myId && (int) ($a['approver_id'] ?? 0) === (int) $myId));

                $timeline = [];
                if ($module === 'transfers') {
                    $timeline = [
                        'type' => $a['type'] ?? '',
                        'raisedAt' => ! empty($a['created_at']) ? substr((string) $a['created_at'], 0, 16) : '',
                        'orderSentAt' => ! empty($a['order_sent_at']) ? substr((string) $a['order_sent_at'], 0, 16) : '',
                        'acceptedAt' => ! empty($a['accepted_at']) ? substr((string) $a['accepted_at'], 0, 16) : '',
                        'appliedAt' => ! empty($a['applied_at']) ? substr((string) $a['applied_at'], 0, 16) : '',
                        'effectiveDate' => $a['effective_date'] ?? '',
                        'onboarding' => $onb[(int) ($a['employee_id'] ?? 0)] ?? '',
                    ];
                }

                return [
                    'timeline' => $timeline,
                    // rev 83b: increments — manual-application + letter stamps.
                    'appliedAt' => ! empty($a['applied_at']) ? substr((string) $a['applied_at'], 0, 10) : '',
                    'appliedBy' => $a['applied_by'] ?? '',
                    'letterAt' => ! empty($a['letter_sent_at']) ? substr((string) $a['letter_sent_at'], 0, 10) : '',
                    // rev 84b: increments — raw fields for the View/Edit dialogs.
                    'cycle' => $a['cycle'] ?? '',
                    'oldCtc' => isset($a['old_ctc']) ? (float) $a['old_ctc'] : null,
                    'newCtc' => isset($a['new_ctc']) ? (float) $a['new_ctc'] : null,
                    'pct' => isset($a['pct']) ? (float) $a['pct'] : null,
                    'newDesignation' => $a['new_designation'] ?? '',
                    'effectiveDate' => ! empty($a['effective']) ? substr((string) $a['effective'], 0, 10) : '',
                    // rev 84: commissions — lifecycle lock + payout date + raw
                    // field values so the Edit dialog can prefill.
                    'locked' => ! empty($a['locked_at']),
                    'lockSource' => $a['lock_source'] ?? '',
                    'payoutDate' => ! empty($a['payout_date']) ? substr((string) $a['payout_date'], 0, 10) : '',
                    'purpose' => $a['purpose'] ?? '',
                    'portfolio' => $a['portfolio'] ?? '',
                    'cycleMonth' => $a['cycle_month'] ?? '',
                    'grossAmount' => isset($a['gross_amount']) ? (float) $a['gross_amount'] : null,
                    'tdsRate' => isset($a['tds_rate']) ? (float) $a['tds_rate'] : null,
                    'descriptionText' => $a['description'] ?? '',
                    // rev 116: collection evidence + accounts stage.
                    'claimType' => $a['claim_type'] ?? '',
                    'customerName' => $a['customer_name'] ?? '',
                    'accountRef' => $a['account_ref'] ?? '',
                    'collectionType' => $a['collection_type'] ?? '',
                    'collectedAt' => ! empty($a['collected_at']) ? substr((string) $a['collected_at'], 0, 16) : '',
                    'collectionLocation' => $a['collection_location'] ?? '',
                    'collectionMode' => $a['collection_mode'] ?? '',
                    'hasProof' => ! empty($a['proof_path']),
                    'accountsStatus' => $a['accounts_status'] ?? '',
                    'accountsBy' => $a['accounts_by'] ?? '',
                    'accountsNote' => $a['accounts_note'] ?? '',
                    // rev181 — bounce trail.
                    'bounced' => ! empty($a['bounced_at']),
                    'bounceReason' => $a['bounce_reason'] ?? '',
                    'bounceClawbackId' => $a['bounce_clawback_id'] ?? null,
                    // rev 85: disbursement ledger summary per entry.
                    'payoutMethod' => ($a['payout_method'] ?? '') ?: 'with_salary',
                    'paidTotal' => round((float) ($paidMap[(int) $a['id']] ?? 0), 2),
                    'balance' => $module === 'commissions' && isset($a['amount']) ? round((float) $a['amount'] - (float) ($paidMap[(int) $a['id']] ?? 0), 2) : null,
                    'id' => $a['id'],
                    'employee' => $a['emp_name'] ?: ('#'.($a['employee_id'] ?? '').' (employee record no longer exists)'),
                    'empCode' => $a['emp_code'] ?? '',
                    'company' => $a['company_name'] ?? '',
                    'amount' => $amountCol && isset($a[$amountCol]) ? (float) $a[$amountCol] : null,
                    'status' => ucfirst($a['status'] ?? 'pending'),
                    'approver' => $a['approver_name'] ?? '',
                    'decidedBy' => $a['decided_by'] ?? '',
                    'decidedAt' => ! empty($a['decided_at']) ? Carbon::parse($a['decided_at'])->format('d M Y H:i') : '',
                    'remarks' => $a['remarks'] ?? '',
                    'reason' => $a['reason'] ?? '',
                    'extra' => $this->extraSummary($a),
                    'canDecide' => $canDecide,
                ];
            })->values();

            return response()->json(['rows' => $out, 'isManager' => $manager, 'me' => $me->name ?? '', 'label' => $cfg['label'], 'fyActive' => FinYearController::active($tid), 'fyOptions' => FinYearController::options(),
                'canAccounts' => $module === 'commissions' ? $this->isAccounts($request) : false]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /** A short human summary of module-specific columns for the list/inbox. */
    private function extraSummary(array $a): string
    {
        $bits = [];
        foreach (['purpose', 'type', 'kind', 'portfolio', 'cycle', 'cycle_month'] as $k) {
            if (! empty($a[$k])) {
                $bits[] = $a[$k];
            }
        }
        // rev 116d: claim type tag.
        if (($a['claim_type'] ?? '') === 'simple') {
            $bits[] = 'Simple claim';
        }
        // rev 116: collection evidence — customer + account + when/where/how.
        if (! empty($a['customer_name'])) {
            $bits[] = $a['customer_name'].(! empty($a['account_ref']) ? ' ('.$a['account_ref'].')' : '');
        }
        if (! empty($a['collection_type'])) {
            $bits[] = $a['collection_type'].' collected';
        }
        if (! empty($a['collected_at'])) {
            $bits[] = 'on '.substr((string) $a['collected_at'], 0, 16).(! empty($a['collection_location']) ? ' at '.$a['collection_location'] : '');
        }
        if (! empty($a['collection_mode'])) {
            $modes = ['cash_office' => 'cash → office', 'cash_deposited' => 'cash deposited to bank', 'client_direct' => 'client paid directly', 'online_company' => 'online to company a/c'];
            $bits[] = $modes[$a['collection_mode']] ?? $a['collection_mode'];
        }
        // rev 83: commission register — gross − TDS = net, free-text note, audit.
        if (! empty($a['gross_amount'])) {
            $g = (float) $a['gross_amount'];
            $t = (float) ($a['tds_amount'] ?? 0);
            $bits[] = 'Gross ₹'.number_format($g, 2).' − TDS '.rtrim(rtrim(number_format((float) ($a['tds_rate'] ?? 0), 2), '0'), '.').'% (₹'.number_format($t, 2).')';
        }
        if (! empty($a['description'])) {
            $bits[] = $a['description'];
        }
        if (! empty($a['payout_date'])) {
            $bits[] = 'payout '.substr((string) $a['payout_date'], 0, 10);
        }
        if (! empty($a['entered_by'])) {
            $bits[] = 'entered by '.$a['entered_by'].(! empty($a['created_at']) ? ' on '.substr((string) $a['created_at'], 0, 10) : '');
        }
        if (! empty($a['emi'])) {
            $bits[] = 'EMI ₹'.number_format((float) $a['emi']);
        }
        if (! empty($a['old_ctc']) && ! empty($a['new_ctc'])) {
            $bits[] = '₹'.number_format((float) $a['old_ctc']).' → ₹'.number_format((float) $a['new_ctc'])
                .(! empty($a['pct']) ? ' ('.rtrim(rtrim(number_format((float) $a['pct'], 2), '0'), '.').'%)' : '');
        }
        // rev 83b: increments — promotion + effective date + application trail.
        if (! empty($a['new_designation'])) {
            $bits[] = 'promotion → '.$a['new_designation'];
        }
        if (! empty($a['effective'])) {
            $bits[] = 'w.e.f. '.substr((string) $a['effective'], 0, 10);
        }
        if (! empty($a['applied_at'])) {
            $bits[] = 'applied to record '.substr((string) $a['applied_at'], 0, 10).(! empty($a['applied_by']) ? ' by '.$a['applied_by'] : '');
        }
        if (! empty($a['last_working_day'])) {
            $bits[] = 'LWD '.$a['last_working_day'];
        }
        // rev 77: transfer trail (from → to, effective date).
        if (! empty($a['to_company']) || ! empty($a['to_branch']) || ! empty($a['to_department'])) {
            $from = trim(($a['from_company'] ?? '').(! empty($a['from_branch']) ? ' / '.$a['from_branch'] : ''));
            $to = trim(($a['to_company'] ?? '').(! empty($a['to_branch']) ? ' / '.$a['to_branch'] : '').(! empty($a['to_department']) ? ' / '.$a['to_department'].' dept.' : ''));
            $bits[] = ($from !== '' ? $from.' → ' : '→ ').$to;
        }
        if (! empty($a['effective_date'])) {
            $bits[] = 'w.e.f. '.$a['effective_date'];
        }
        if (! empty($a['accepted_at'])) {
            $bits[] = 'acknowledged '.substr((string) $a['accepted_at'], 0, 10);
        }

        return implode(' · ', $bits);
    }

    /** Create a request — approver resolved from hierarchy + amount threshold. */
    public function apply(Request $request, string $module)
    {
        try {
            $cfg = $this->cfg($module);
            if (! $cfg) {
                return response()->json(['ok' => false, 'error' => 'Unknown module'], 422);
            }
            $table = $cfg['table'];
            ApprovalService::ensureAuditCols($table);

            $user = $request->user();
            $tid = $user->tenant_id ?? DB::table('tenants')->value('id');

            $v = $request->validate([
                'employee' => ['required', 'string'],
                'amount' => ['nullable', 'numeric'],
                'fields' => ['array'],
            ]);

            $emp = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('name', $v['employee'])->orWhere('emp_code', $v['employee']))
                ->first();
            if (! $emp) {
                return response()->json(['ok' => false, 'error' => 'Employee not found: '.$v['employee']], 422);
            }

            $amount = isset($v['amount']) ? (float) $v['amount'] : null;

            // rev 83 (Ejaz): commission entries carry gross + TDS 194H; the NET
            // is what drives payroll, live salary and the approver threshold.
            // Server recomputes the arithmetic — never trusts the client's.
            $fields = (array) $request->input('fields', []);
            $fields['entered_by'] = trim((string) ($user->name ?? '')) ?: (string) ($user->email ?? '');
            if ($module === 'commissions') {
                // rev 84 (Ejaz): SELF-CLAIMS — an employee may claim for
                // themself; a reporting manager for their own reportees;
                // Admin / HR for anyone. Enforced server-side.
                if (! $this->isManager($request)) {
                    $meEmp = $this->currentEmployee($request);
                    $isSelf = $meEmp && (int) $meEmp->id === (int) $emp->id;
                    $isMyReportee = $meEmp && (
                        (int) ($emp->reporting_manager_id ?? 0) === (int) $meEmp->id
                        || (! empty($emp->reporting_manager) && trim((string) $emp->reporting_manager) === trim((string) $meEmp->name))
                    );
                    if (! $isSelf && ! $isMyReportee) {
                        return response()->json(['ok' => false, 'error' => 'You can claim for yourself, or enter for your own reportees only.'], 403);
                    }
                }
                // rev 115: claim raised AGAINST A SCHEME — the scheme decides the
                // money (percent of base / fixed / open), purpose, TDS and payout
                // method. Validated + computed server-side; friendly errors.
                if (! empty($fields['scheme_id']) && is_numeric($fields['scheme_id'])) {
                    try {
                        $sv = \App\Http\Controllers\SchemeController::validateClaim((int) $fields['scheme_id'], $emp, $fields);
                        $fields = array_merge($fields, $sv);
                    } catch (\RuntimeException $se) {
                        return response()->json(['ok' => false, 'error' => $se->getMessage()], 422);
                    }
                } else {
                    unset($fields['scheme_id'], $fields['base_amount']);
                }
                $gross = isset($fields['gross_amount']) && is_numeric($fields['gross_amount']) ? (float) $fields['gross_amount'] : null;
                if ($gross !== null && $gross > 0) {
                    $rate = isset($fields['tds_rate']) && is_numeric($fields['tds_rate']) ? max(0.0, min(100.0, (float) $fields['tds_rate'])) : 5.0;
                    $fields['gross_amount'] = round($gross, 2);
                    $fields['tds_rate'] = $rate;
                    $fields['tds_amount'] = round($gross * $rate / 100, 2);
                    $amount = round($gross - $fields['tds_amount'], 2);
                }
                if (! empty($fields['cycle_month'])) {
                    try {
                        $fields['cycle_month'] = Carbon::parse((string) $fields['cycle_month'])->format('M Y');
                    } catch (\Throwable $e) {
                        // keep what was typed; payroll's own parser is tolerant
                    }
                }
                if (! empty($fields['payout_date'])) {
                    try {
                        $fields['payout_date'] = Carbon::parse((string) $fields['payout_date'])->toDateString();
                    } catch (\Throwable $e) {
                        unset($fields['payout_date']);
                    }
                }
                // rev 85: payout method — 'separate' = paid through the ledger
                // on its own dates; anything else = with salary (payslip).
                $fields['payout_method'] = (stripos((string) ($fields['payout_method'] ?? ''), 'sep') !== false) ? 'separate' : 'with_salary';

                // rev 116d (Ejaz): TWO CLAIM TYPES — 'collection' (customer money
                // collected → full evidence + Accounts confirmation) vs 'simple'
                // (target achieved / special incentive / bonus → notes + normal
                // approval only, no customer, no accounts stage).
                self::ensureCollectionCols();
                $claimType = stripos((string) ($fields['claim_type'] ?? ''), 'simp') !== false ? 'simple' : 'collection';
                $fields['claim_type'] = $claimType;
                if ($claimType === 'simple') {
                    // No collection evidence on a simple claim — clear any strays.
                    foreach (['customer_name', 'account_ref', 'collection_type', 'collected_at', 'collection_location', 'collection_mode', 'base_amount', 'proof_path', 'accounts_status'] as $k0) {
                        unset($fields[$k0]);
                    }
                } else {
                    // rev 116: COLLECTION EVIDENCE — required on every collection claim.
                    foreach ([
                        'customer_name' => 'Enter the customer name.',
                        'account_ref' => 'Enter the account no / CC no / customer ID.',
                        'collection_type' => 'Pick what was collected (EMI / penalty / settlement…).',
                        'collected_at' => 'Enter the collection date & time.',
                        'collection_mode' => 'Pick the mode of collection.',
                    ] as $reqK => $reqMsg) {
                        if (trim((string) ($fields[$reqK] ?? '')) === '') {
                            return response()->json(['ok' => false, 'error' => $reqMsg], 422);
                        }
                    }
                    try {
                        $fields['collected_at'] = Carbon::parse((string) $fields['collected_at'])->format('Y-m-d H:i:s');
                    } catch (\Throwable $e) {
                        return response()->json(['ok' => false, 'error' => 'The collection date & time could not be read — please re-pick it.'], 422);
                    }
                    // rev 116c (Ejaz — "gross confusion"): base_amount = what the
                    // CUSTOMER paid (evidence); gross_amount = the COMMISSION claimed.
                    $baseAmt = isset($fields['base_amount']) && is_numeric($fields['base_amount']) ? (float) $fields['base_amount'] : 0.0;
                    if ($baseAmt <= 0) {
                        return response()->json(['ok' => false, 'error' => 'Enter the amount collected from the customer.'], 422);
                    }
                    $fields['base_amount'] = round($baseAmt, 2);
                    $fields['collection_mode'] = self::normMode((string) $fields['collection_mode']);
                    // rev 116e (Ejaz): proof is OPTIONAL — banks send the payments-
                    // received list, so Accounts verifies against that; a screenshot
                    // or deposit slip just speeds the confirmation up.
                    // Safety: only accept proof paths our own uploader produced.
                    if (! empty($fields['proof_path']) && strpos((string) $fields['proof_path'], 'commission-proofs/') !== 0) {
                        unset($fields['proof_path']);
                    }
                    $fields['accounts_status'] = 'pending';   // Accounts confirms before approval
                }
            }
            // rev 83b (Ejaz): increments — Old CTC defaults to the employee's
            // CURRENT record; New CTC ↔ % cross-computed server-side so the
            // three figures can never disagree.
            if ($module === 'increments') {
                $old = isset($fields['old_ctc']) && is_numeric($fields['old_ctc']) ? (float) $fields['old_ctc'] : 0.0;
                if ($old <= 0) {
                    $old = (float) ($emp->ctc ?? 0);
                }
                $new = isset($fields['new_ctc']) && is_numeric($fields['new_ctc']) ? (float) $fields['new_ctc'] : 0.0;
                $pct = isset($fields['pct']) && is_numeric($fields['pct']) ? (float) $fields['pct'] : 0.0;
                if ($new <= 0 && $old > 0 && $pct != 0.0) {
                    $new = round($old * (1 + $pct / 100), 2);
                }
                if ($new > 0 && $old > 0) {
                    $pct = round(($new - $old) / $old * 100, 2);
                }
                if ($new <= 0) {
                    return response()->json(['ok' => false, 'error' => 'Enter the New CTC (or a % increase).'], 422);
                }
                $fields['old_ctc'] = round($old, 2);
                $fields['new_ctc'] = $new;
                $fields['pct'] = $pct;
                $amount = $new; // threshold escalation works off the new CTC
            }
            [$approverId, $approverName] = ApprovalService::resolveApprover($emp, $tid, $amount, (float) $cfg['threshold']);

            // Base row + any extra module fields the form sent (schema-filtered).
            $row = array_merge($fields, [
                'tenant_id' => $emp->tenant_id,
                'company_id' => $emp->company_id,
                'employee_id' => $emp->id,
                'status' => 'pending',
                'approver_id' => $approverId,
                'approver_name' => $approverName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($cfg['amount'] && $amount !== null) {
                $row[$cfg['amount']] = $amount;
            }
            $fy = FinYearController::stamp($table, $request->user()->tenant_id);
            if ($fy) {
                $row['fin_year'] = $fy;
            }
            $row = ApprovalService::safeRow($table, $row);

            $newId = DB::table($table)->insertGetId($row);

            // J2 — audit the request submission.
            \App\Services\Audit::record($emp->tenant_id ? (int) $emp->tenant_id : null, optional($request->user())->id, 'submit', $module, (int) $newId, ['employee' => $emp->name ?? null], $request->ip());

            // rev 84: commissions get a permanent per-entry history trail.
            if ($module === 'commissions') {
                ApprovalService::logCommission($emp->tenant_id, $newId, 'created',
                    'Entry created'.(! empty($fields['purpose']) ? ' · '.$fields['purpose'] : '')
                    .(! empty($fields['gross_amount']) ? ' · gross ₹'.number_format((float) $fields['gross_amount'], 2).' − TDS ₹'.number_format((float) ($fields['tds_amount'] ?? 0), 2).' = net ₹'.number_format((float) $amount, 2) : '')
                    .(! empty($fields['cycle_month']) ? ' · earned '.$fields['cycle_month'] : '')
                    .(! empty($fields['payout_date']) ? ' · payout '.$fields['payout_date'] : '')
                    .' · approver '.$approverName,
                    $fields['entered_by'] ?? '');
            }

            // Notify the approver that a new request awaits them. Fail-soft.
            try {
                if ($approverId) {
                    $appr = DB::table('employees')->where('id', $approverId)->first();
                    if ($appr && ! empty($appr->email)) {
                        $lines = ['Type' => $cfg['label'], 'Requested by' => $emp->name];
                        if ($amount !== null) {
                            $lines['Amount'] = 'Rs '.number_format($amount, 2);
                        }
                        \App\Services\MailService::queue([
                            'tenant_id' => $emp->tenant_id,
                            'company_id' => $emp->company_id,
                            'to' => $appr->email,
                            'to_name' => $appr->name,
                            'subject' => 'Approval needed: '.$cfg['label'],
                            'heading' => 'A request needs your approval',
                            'intro' => $emp->name.' has submitted a '.strtolower($cfg['label']).' request awaiting your decision.',
                            'lines' => $lines,
                            'body' => 'Open the Approvals Inbox in SmartPRS to approve or reject.',
                            'kind' => 'request.submitted',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // best-effort
            }

            return response()->json(['ok' => true, 'approver' => $approverName]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 83 (Ejaz): commission bulk upload. The Excel/CSV is parsed in the
     * browser (SheetJS); we receive clean rows. Admin / HR only. Every row is
     * inserted as PENDING with the employee's own hierarchy approver — same
     * discipline as a manual entry (Ejaz's confirmed choice). Per-row errors
     * are reported back and do NOT block the good rows. No per-row emails
     * (a 200-row file must not fire 200 mails); approvers see their inbox.
     */
    public function bulkCommissions(Request $request)
    {
        try {
            $user = $request->user();
            // rev 116d (Ejaz): individual employees may bulk-upload their OWN
            // claims — every row is forced to themselves regardless of the sheet.
            $selfOnly = ! $user->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
            $meEmp = null;
            if ($selfOnly) {
                $meEmp = $this->currentEmployee($request);
                if (! $meEmp) {
                    return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record — ask HR to link it before uploading.'], 403);
                }
            }
            ApprovalService::ensureAuditCols('commissions');
            $v = $request->validate([
                'rows' => ['required', 'array', 'min:1', 'max:1000'],
                'rows.*.employee' => ['nullable', 'string'],   // rev 116d: self-uploads may omit it
                'rows.*.gross' => ['required', 'numeric', 'gt:0'],
            ]);
            $tid = $user->tenant_id ?? DB::table('tenants')->value('id');
            $enteredBy = trim((string) ($user->name ?? '')) ?: (string) ($user->email ?? '');

            // One employee lookup pass (by code OR name) instead of N queries.
            $emps = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')
                ->get(['id', 'tenant_id', 'company_id', 'name', 'emp_code', 'reporting_manager_id', 'reporting_manager']);
            $byCode = [];
            $byName = [];
            foreach ($emps as $e) {
                if (! empty($e->emp_code)) {
                    $byCode[strtolower(trim((string) $e->emp_code))] = $e;
                }
                $byName[strtolower(trim((string) $e->name))] = $e;
            }

            $created = 0;
            $errors = [];
            // Hoisted out of the loop: column list + FY stamp are per-table facts.
            self::ensureCollectionCols();   // rev 116c: evidence columns in bulk too
            $colFlip = array_flip(Schema::getColumnListing('commissions'));
            $fy = FinYearController::stamp('commissions', $tid);
            foreach ($v['rows'] as $i => $r) {
                $line = $i + 2; // +2 = header row + 1-based, matches what they see in Excel
                if ($selfOnly) {
                    $emp = $meEmp;   // rev 116d: agents upload for THEMSELVES only
                } else {
                    $key = strtolower(trim((string) ($r['employee'] ?? '')));
                    $emp = $key !== '' ? ($byCode[$key] ?? $byName[$key] ?? null) : null;
                }
                if (! $emp) {
                    $errors[] = 'Row '.$line.': employee not found — "'.($r['employee'] ?? '(blank)').'"';
                    continue;
                }
                $gross = round((float) $r['gross'], 2);
                $rate = isset($r['tds_rate']) && is_numeric($r['tds_rate']) ? max(0.0, min(100.0, (float) $r['tds_rate'])) : 5.0;
                $tds = round($gross * $rate / 100, 2);
                $month = trim((string) ($r['month'] ?? ''));
                if ($month !== '') {
                    try {
                        $month = Carbon::parse($month)->format('M Y');
                    } catch (\Throwable $e) {
                        $errors[] = 'Row '.$line.': month not understood — "'.$r['month'].'" (use e.g. May 2026)';
                        continue;
                    }
                }
                // rev 84: payout date decides which month's payslip pays it.
                $payout = trim((string) ($r['payout_date'] ?? ''));
                if ($payout !== '') {
                    try {
                        $payout = Carbon::parse($payout)->toDateString();
                    } catch (\Throwable $e) {
                        $errors[] = 'Row '.$line.': payout date not understood — "'.$r['payout_date'].'" (use e.g. 2026-07-10)';
                        continue;
                    }
                }
                [$approverId, $approverName] = ApprovalService::resolveApprover($emp, $tid, $gross - $tds, 0);
                // rev 116c: collection evidence from the sheet (optional per row —
                // Accounts can chase missing proof; the gate still applies).
                $collectedAt = null;
                if (trim((string) ($r['collected_at'] ?? '')) !== '') {
                    try {
                        $collectedAt = Carbon::parse((string) $r['collected_at'])->format('Y-m-d H:i:s');
                    } catch (\Throwable $e) {
                        $collectedAt = null;
                    }
                }
                // rev 116d: per-row claim type — 'simple' (target/bonus, manager
                // approval only) vs 'collection' (evidence + accounts stage).
                // Default: simple when the row carries NO customer details.
                $ctRaw = (string) ($r['claim_type'] ?? '');
                $rowType = stripos($ctRaw, 'simp') !== false ? 'simple'
                    : (stripos($ctRaw, 'coll') !== false ? 'collection'
                    : ((trim((string) ($r['customer_name'] ?? '')) !== '' || trim((string) ($r['account_ref'] ?? '')) !== '') ? 'collection' : 'simple'));
                $row = array_intersect_key([
                    'tenant_id' => $emp->tenant_id,
                    'company_id' => $emp->company_id,
                    'employee_id' => $emp->id,
                    'status' => 'pending',
                    'approver_id' => $approverId,
                    'approver_name' => $approverName,
                    'purpose' => trim((string) ($r['purpose'] ?? '')) ?: null,
                    'portfolio' => trim((string) ($r['portfolio'] ?? '')) ?: null,
                    'cycle_month' => $month ?: null,
                    'payout_date' => $payout ?: null,
                    'payout_method' => (stripos((string) ($r['payout_method'] ?? ''), 'sep') !== false) ? 'separate' : 'with_salary',
                    'description' => mb_substr(trim((string) ($r['description'] ?? '')), 0, 500) ?: null,
                    'claim_type' => $rowType,
                    'customer_name' => $rowType === 'collection' ? (trim((string) ($r['customer_name'] ?? '')) ?: null) : null,
                    'account_ref' => $rowType === 'collection' ? (trim((string) ($r['account_ref'] ?? '')) ?: null) : null,
                    'collection_type' => $rowType === 'collection' ? (trim((string) ($r['collection_type'] ?? '')) ?: null) : null,
                    'collected_at' => $rowType === 'collection' ? $collectedAt : null,
                    'collection_location' => $rowType === 'collection' ? (trim((string) ($r['collection_location'] ?? '')) ?: null) : null,
                    'collection_mode' => $rowType === 'collection' && trim((string) ($r['collection_mode'] ?? '')) !== '' ? self::normMode((string) $r['collection_mode']) : null,
                    'base_amount' => $rowType === 'collection' && isset($r['base_amount']) && is_numeric($r['base_amount']) && (float) $r['base_amount'] > 0 ? round((float) $r['base_amount'], 2) : null,
                    'accounts_status' => $rowType === 'collection' ? 'pending' : null,   // simple claims skip the accounts stage
                    'gross_amount' => $gross,
                    'tds_rate' => $rate,
                    'tds_amount' => $tds,
                    'amount' => round($gross - $tds, 2),
                    'entered_by' => $enteredBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $colFlip);
                if ($fy && isset($colFlip['fin_year'])) {
                    $row['fin_year'] = $fy;
                }
                $newId = DB::table('commissions')->insertGetId($row);
                ApprovalService::logCommission($emp->tenant_id, $newId, 'created',
                    'Bulk upload (row '.$line.') · gross ₹'.number_format($gross, 2).' − TDS ₹'.number_format($tds, 2).' = net ₹'.number_format($gross - $tds, 2)
                    .($month ? ' · earned '.$month : '').($payout ? ' · payout '.$payout : '').' · approver '.$approverName,
                    $enteredBy);
                $created++;
            }

            return response()->json([
                'ok' => true,
                'created' => $created,
                'failed' => count($errors),
                'errors' => array_slice($errors, 0, 30),
                'message' => $created.' entr'.($created === 1 ? 'y' : 'ies').' created as Pending (approvers see them in their inbox)'.(count($errors) ? ' · '.count($errors).' row(s) skipped' : ''),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 84 (Ejaz): EDIT a commission entry — allowed even after approval
     * (his rule), every field change diff-logged, blocked once LOCKED.
     * Admin/HR, or the employee's reporting manager.
     */
    public function updateCommission(Request $request, int $id)
    {
        try {
            ApprovalService::ensureAuditCols('commissions');
            $tid = $request->user()->tenant_id;
            $rec = DB::table('commissions')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response()->json(['ok' => false, 'error' => 'Entry not found'], 404);
            }
            if (! empty($rec->locked_at)) {
                return response()->json(['ok' => false, 'error' => 'This entry is LOCKED ('.($rec->lock_source ?: 'paid out').') and can no longer be edited.'], 422);
            }
            $me = $this->currentEmployee($request);
            $allowed = $this->isManager($request)
                || ($me && (int) ($rec->approver_id ?? 0) === (int) $me->id);
            if (! $allowed) {
                return response()->json(['ok' => false, 'error' => 'Only Admin / HR or the approver can edit an entry.'], 403);
            }

            $v = $request->validate([
                'purpose' => ['nullable', 'string', 'max:100'],
                'portfolio' => ['nullable', 'string', 'max:200'],
                'cycle_month' => ['nullable', 'string', 'max:20'],
                'payout_date' => ['nullable', 'string', 'max:20'],
                'payout_method' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string', 'max:500'],
                'gross_amount' => ['nullable', 'numeric', 'gt:0'],
                'tds_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ]);
            // rev 85: how much is already paid against this entry (ledger).
            $paid = 0.0;
            try {
                if (Schema::hasTable('commission_payments')) {
                    $paid = (float) DB::table('commission_payments')->where('commission_id', $id)->sum('amount');
                }
            } catch (\Throwable $e) {
            }
            $upd = [];
            $diffs = [];
            $fmtMoney = fn ($n) => '₹'.number_format((float) $n, 2);
            foreach (['purpose', 'portfolio', 'description'] as $k) {
                if (array_key_exists($k, $v) && (string) ($v[$k] ?? '') !== (string) ($rec->{$k} ?? '')) {
                    $upd[$k] = $v[$k] ?: null;
                    $diffs[] = $k.' "'.($rec->{$k} ?? '—').'" → "'.($v[$k] ?: '—').'"';
                }
            }
            if (! empty($v['cycle_month'])) {
                try {
                    $cm = Carbon::parse((string) $v['cycle_month'])->format('M Y');
                    if ($cm !== (string) ($rec->cycle_month ?? '')) {
                        $upd['cycle_month'] = $cm;
                        $diffs[] = 'earned month '.($rec->cycle_month ?? '—').' → '.$cm;
                    }
                } catch (\Throwable $e) {
                }
            }
            if (array_key_exists('payout_date', $v)) {
                $pd = null;
                if (! empty($v['payout_date'])) {
                    try {
                        $pd = Carbon::parse((string) $v['payout_date'])->toDateString();
                    } catch (\Throwable $e) {
                        $pd = null;
                    }
                }
                $old = ! empty($rec->payout_date) ? substr((string) $rec->payout_date, 0, 10) : null;
                if ($pd !== $old) {
                    $upd['payout_date'] = $pd;
                    $diffs[] = 'payout date '.($old ?: '—').' → '.($pd ?: '—');
                }
            }
            // rev 85: payout method change — blocked once money has moved.
            if (! empty($v['payout_method'])) {
                $pm = (stripos((string) $v['payout_method'], 'sep') !== false) ? 'separate' : 'with_salary';
                $oldPm = ($rec->payout_method ?? '') ?: 'with_salary';
                if ($pm !== $oldPm) {
                    if ($paid > 0) {
                        return response()->json(['ok' => false, 'error' => '₹'.number_format($paid, 2).' already paid against this entry — the payout method can no longer change.'], 422);
                    }
                    $upd['payout_method'] = $pm;
                    $diffs[] = 'payout method '.$oldPm.' → '.$pm;
                }
            }
            $gross = isset($v['gross_amount']) && is_numeric($v['gross_amount']) ? (float) $v['gross_amount'] : (float) ($rec->gross_amount ?? 0);
            $rate = isset($v['tds_rate']) && is_numeric($v['tds_rate']) ? (float) $v['tds_rate'] : (float) ($rec->tds_rate ?? 5);
            if ($gross > 0 && (round($gross, 2) !== round((float) ($rec->gross_amount ?? 0), 2) || round($rate, 2) !== round((float) ($rec->tds_rate ?? 0), 2))) {
                $tds = round($gross * $rate / 100, 2);
                $net = round($gross - $tds, 2);
                // rev 85 (Ejaz: editable until fully paid): the new net can
                // never drop below what the ledger says is ALREADY PAID.
                if ($paid > 0 && $net < $paid) {
                    return response()->json(['ok' => false, 'error' => '₹'.number_format($paid, 2).' is already paid against this entry — the new net (₹'.number_format($net, 2).') cannot be below that. Use a Clawback to recover excess.'], 422);
                }
                $upd['gross_amount'] = round($gross, 2);
                $upd['tds_rate'] = $rate;
                $upd['tds_amount'] = $tds;
                $upd['amount'] = $net;
                $diffs[] = 'amount '.$fmtMoney($rec->gross_amount ?? 0).' gross → '.$fmtMoney($gross).' gross (TDS '.$rate.'% = '.$fmtMoney($tds).', net '.$fmtMoney($net).')';
            }
            if (! $upd) {
                return response()->json(['ok' => true, 'message' => 'Nothing changed.']);
            }
            $upd['updated_at'] = now();
            DB::table('commissions')->where('id', $id)->update(ApprovalService::safeRow('commissions', $upd));

            $by = ($me->name ?? null) ?: ($request->user()->name ?? $request->user()->email);
            ApprovalService::logCommission($tid, $id, 'edited',
                implode(' · ', $diffs).(($rec->status ?? '') === 'approved' ? ' (edited AFTER approval)' : ''), (string) $by);

            return response()->json(['ok' => true, 'message' => 'Entry updated — change recorded in its history.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 85 (Ejaz): record a PAYMENT against a 'separate'-payout commission.
     * Partial payments allowed; fully paid → auto-LOCK. Admin / HR only.
     * 'With salary' entries are paid by payroll — never from here (double-pay
     * protection).
     */
    public function payCommission(Request $request, int $id)
    {
        try {
            if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
                return $deny;
            }
            ApprovalService::ensureAuditCols('commissions');
            $tid = $request->user()->tenant_id;
            $rec = DB::table('commissions')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response()->json(['ok' => false, 'error' => 'Entry not found'], 404);
            }
            if (($rec->status ?? '') !== 'approved') {
                return response()->json(['ok' => false, 'error' => 'Only an APPROVED entry can be paid.'], 422);
            }
            if (! empty($rec->locked_at)) {
                return response()->json(['ok' => false, 'error' => 'This entry is LOCKED ('.($rec->lock_source ?: 'fully paid').').'], 422);
            }
            if ((($rec->payout_method ?? '') ?: 'with_salary') !== 'separate') {
                return response()->json(['ok' => false, 'error' => 'This entry is paid WITH SALARY — payroll pays it in the payslip of its payout month. Direct payments are only for "Separate payout" entries.'], 422);
            }

            $v = $request->validate([
                'amount' => ['required', 'numeric', 'gt:0'],
                'paid_on' => ['nullable', 'string', 'max:20'],
                'mode' => ['nullable', 'string', 'max:30'],
                'reference' => ['nullable', 'string', 'max:200'],
                'note' => ['nullable', 'string', 'max:500'],
            ]);
            $net = round((float) $rec->amount, 2);
            $paid = (float) DB::table('commission_payments')->where('commission_id', $id)->sum('amount');
            $balance = round($net - $paid, 2);
            $amt = round((float) $v['amount'], 2);
            if ($amt > $balance + 0.005) {
                return response()->json(['ok' => false, 'error' => 'Amount ₹'.number_format($amt, 2).' exceeds the balance ₹'.number_format($balance, 2).'.'], 422);
            }
            $paidOn = now()->toDateString();
            if (! empty($v['paid_on'])) {
                try {
                    $paidOn = Carbon::parse((string) $v['paid_on'])->toDateString();
                } catch (\Throwable $e) {
                }
            }
            $by = trim((string) ($request->user()->name ?? '')) ?: (string) $request->user()->email;
            DB::table('commission_payments')->insert([
                'tenant_id' => $rec->tenant_id,
                'commission_id' => $id,
                'employee_id' => $rec->employee_id,
                'paid_on' => $paidOn,
                'amount' => $amt,
                'mode' => strtolower(trim((string) ($v['mode'] ?? ''))) ?: 'bank',
                'reference' => trim((string) ($v['reference'] ?? '')) ?: null,
                'note' => trim((string) ($v['note'] ?? '')) ?: null,
                'by' => $by,
                'created_at' => now(),
            ]);
            $newBalance = round($balance - $amt, 2);
            ApprovalService::logCommission($tid, $id, 'paid',
                'Paid ₹'.number_format($amt, 2).' on '.$paidOn.' ('.(($v['mode'] ?? '') ?: 'bank').(! empty($v['reference']) ? ' · ref '.$v['reference'] : '').')'
                .' · balance ₹'.number_format($newBalance, 2)
                .(! empty($v['note']) ? ' · '.$v['note'] : ''), $by);

            $lockedNow = false;
            if ($newBalance <= 0.005) {
                DB::table('commissions')->where('id', $id)->update(ApprovalService::safeRow('commissions', [
                    'locked_at' => now(), 'locked_by' => $by, 'lock_source' => 'fully paid', 'updated_at' => now(),
                ]));
                ApprovalService::logCommission($tid, $id, 'locked', 'Fully paid — entry is now frozen', $by);
                $lockedNow = true;
            }

            return response()->json([
                'ok' => true,
                'paid' => round($paid + $amt, 2),
                'balance' => max(0, $newBalance),
                'message' => '₹'.number_format($amt, 2).' recorded.'.($lockedNow ? ' Entry is now FULLY PAID and locked.' : ' Balance ₹'.number_format($newBalance, 2).'.'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 87 (Ejaz): SALARY disbursements — keyed by employee + month (NOT
     * payslip id, so regenerating a draft run never orphans payments).
     * Partial amounts allowed; the ledger shows per-month balances.
     */
    private static function ensureSalaryPayments(): void
    {
        try {
            if (! Schema::hasTable('salary_payments')) {
                Schema::create('salary_payments', function (\Illuminate\Database\Schema\Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->unsignedBigInteger('employee_id')->index();
                    $t->string('month', 10)->index();        // 'YYYY-MM' (payslip month)
                    $t->date('paid_on')->nullable();
                    $t->decimal('amount', 12, 2);
                    $t->string('mode', 30)->nullable();      // bank|upi|cash|cheque
                    $t->string('reference')->nullable();
                    $t->string('note', 500)->nullable();
                    $t->string('by')->nullable();
                    $t->timestamp('created_at')->useCurrent();
                });
            }
        } catch (\Throwable $e) {
        }
    }

    /** rev 87: record a (partial) salary payment for one employee + month. Admin/HR. */
    public function salaryPay(Request $request)
    {
        try {
            if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
                return $deny;
            }
            self::ensureSalaryPayments();
            $v = $request->validate([
                'employee_id' => ['required', 'integer'],
                'month' => ['required', 'string', 'max:10'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'paid_on' => ['nullable', 'string', 'max:20'],
                'mode' => ['nullable', 'string', 'max:30'],
                'reference' => ['nullable', 'string', 'max:200'],
                'note' => ['nullable', 'string', 'max:500'],
            ]);
            $tid = $request->user()->tenant_id;
            $emp = DB::table('employees')->where('id', (int) $v['employee_id'])
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $emp) {
                return response()->json(['ok' => false, 'error' => 'Employee not found'], 404);
            }
            $month = substr(trim($v['month']), 0, 7);
            $net = (float) DB::table('payslips')->where('employee_id', $emp->id)->where('month', $month)->orderByDesc('id')->value('net');
            if ($net <= 0) {
                return response()->json(['ok' => false, 'error' => 'No payslip found for '.$month.' — generate payroll first.'], 422);
            }
            $paid = (float) DB::table('salary_payments')->where('employee_id', $emp->id)->where('month', $month)->sum('amount');
            $balance = round($net - $paid, 2);
            $amt = round((float) $v['amount'], 2);
            if ($amt > $balance + 0.005) {
                return response()->json(['ok' => false, 'error' => 'Amount ₹'.number_format($amt, 2).' exceeds the unpaid balance ₹'.number_format($balance, 2).' for '.$month.'.'], 422);
            }
            $paidOn = now()->toDateString();
            if (! empty($v['paid_on'])) {
                try {
                    $paidOn = Carbon::parse((string) $v['paid_on'])->toDateString();
                } catch (\Throwable $e) {
                }
            }
            $by = trim((string) ($request->user()->name ?? '')) ?: (string) $request->user()->email;
            DB::table('salary_payments')->insert([
                'tenant_id' => $emp->tenant_id,
                'employee_id' => $emp->id,
                'month' => $month,
                'paid_on' => $paidOn,
                'amount' => $amt,
                'mode' => strtolower(trim((string) ($v['mode'] ?? ''))) ?: 'bank',
                'reference' => trim((string) ($v['reference'] ?? '')) ?: null,
                'note' => trim((string) ($v['note'] ?? '')) ?: null,
                'by' => $by,
                'created_at' => now(),
            ]);
            $newBal = round($balance - $amt, 2);

            return response()->json(['ok' => true,
                'message' => '₹'.number_format($amt, 2).' salary payment recorded for '.$month.'.'.($newBal <= 0.005 ? ' Month FULLY PAID.' : ' Balance ₹'.number_format($newBal, 2).'.')]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 85 (Ejaz): per-employee commission LEDGER — passbook of credits
     * (approved entries) and debits (payments incl. automatic payslip debits)
     * with running balance. Managers see anyone; an employee sees themself.
     * rev 87: extended with SALARY credits (payslips) + debits (salary_payments)
     * so the All tab is the employee's complete money account.
     */
    public function commissionLedger(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id;
            $me = $this->currentEmployee($request);
            $manager = $this->isManager($request);
            $who = trim((string) $request->query('employee', ''));

            $emp = null;
            if ($manager && $who !== '') {
                $emp = DB::table('employees')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('name', $who)->orWhere('emp_code', $who))
                    ->first();
            }
            if (! $emp) {
                $emp = $manager && $who !== '' ? null : $me;
            }
            if (! $emp) {
                return response()->json(['ok' => false, 'error' => $who !== '' ? 'Employee not found: '.$who : 'No employee record linked to your login.'], 422);
            }
            if (! $manager && $me && (int) $emp->id !== (int) $me->id) {
                return response()->json(['ok' => false, 'error' => 'You can view your own ledger only.'], 403);
            }

            $lines = [];
            $earned = 0.0;
            $pendingNet = 0.0;
            if (Schema::hasTable('commissions')) {
                $cSel = ['id', 'amount', 'status', 'created_at'];
                foreach (['purpose', 'portfolio', 'cycle_month', 'payout_date', 'payout_method', 'decided_at'] as $c) {
                    if (Schema::hasColumn('commissions', $c)) {
                        $cSel[] = $c;
                    }
                }
                foreach (DB::table('commissions')->where('employee_id', $emp->id)->whereIn('status', ['approved', 'pending'])->orderBy('id')->limit(500)->get($cSel) as $c) {
                    if ($c->status === 'pending') {
                        $pendingNet += (float) $c->amount;

                        continue;
                    }
                    $earned += (float) $c->amount;
                    $date = ! empty($c->decided_at) ? substr((string) $c->decided_at, 0, 10) : substr((string) $c->created_at, 0, 10);
                    $lines[] = [
                        'date' => $date,
                        'sort' => $date.'-1-'.str_pad((string) $c->id, 9, '0', STR_PAD_LEFT),
                        'particulars' => trim((string) (($c->purpose ?? '') ?: 'Commission'))
                            .(! empty($c->portfolio) ? ' ('.$c->portfolio.')' : '')
                            .(! empty($c->cycle_month) ? ' · earned '.$c->cycle_month : '')
                            .((($c->payout_method ?? '') ?: 'with_salary') === 'separate' ? ' · separate payout' : ' · with salary')
                            .(! empty($c->payout_date) ? ' · payout due '.substr((string) $c->payout_date, 0, 10) : ''),
                        'credit' => round((float) $c->amount, 2),
                        'debit' => 0,
                        'entryId' => (int) $c->id,
                    ];
                }
            }
            $paidTotal = 0.0;
            if (Schema::hasTable('commission_payments')) {
                foreach (DB::table('commission_payments')->where('employee_id', $emp->id)->orderBy('id')->limit(1000)->get() as $p) {
                    $paidTotal += (float) $p->amount;
                    $date = ! empty($p->paid_on) ? substr((string) $p->paid_on, 0, 10) : substr((string) $p->created_at, 0, 10);
                    $lines[] = [
                        'date' => $date,
                        'sort' => $date.'-2-'.str_pad((string) $p->id, 9, '0', STR_PAD_LEFT),
                        'particulars' => 'Payment — '.strtoupper((string) ($p->mode ?: 'bank'))
                            .(! empty($p->reference) ? ' · '.$p->reference : '')
                            .' (entry #'.$p->commission_id.')'
                            .(! empty($p->note) ? ' · '.$p->note : '')
                            .(! empty($p->by) ? ' · by '.$p->by : ''),
                        'credit' => 0,
                        'debit' => round((float) $p->amount, 2),
                        'entryId' => (int) $p->commission_id,
                        'paymentId' => (int) $p->id,                       // rev181c — voucher link
                        'pmode' => (string) ($p->mode ?: 'bank'),          // rev181c — payslip debits get no voucher
                    ];
                }
            }
            foreach ($lines as &$l0) {
                $l0['kind'] = 'commission';
            }
            unset($l0);

            // ---- rev 87: SALARY section — payslip credits + disbursement debits.
            self::ensureSalaryPayments();
            $salEarned = 0.0;
            $salPaid = 0.0;
            $salPaidByMonth = [];
            if (Schema::hasTable('salary_payments')) {
                foreach (DB::table('salary_payments')->where('employee_id', $emp->id)->orderBy('id')->limit(1000)->get() as $sp) {
                    $salPaid += (float) $sp->amount;
                    $salPaidByMonth[$sp->month] = ($salPaidByMonth[$sp->month] ?? 0) + (float) $sp->amount;
                    $date = ! empty($sp->paid_on) ? substr((string) $sp->paid_on, 0, 10) : substr((string) $sp->created_at, 0, 10);
                    $lines[] = [
                        'kind' => 'salary',
                        'date' => $date,
                        'sort' => $date.'-2-'.str_pad((string) $sp->id, 9, '0', STR_PAD_LEFT),
                        'particulars' => 'Salary payment — '.strtoupper((string) ($sp->mode ?: 'bank'))
                            .(! empty($sp->reference) ? ' · '.$sp->reference : '')
                            .' ('.$sp->month.')'
                            .(! empty($sp->note) ? ' · '.$sp->note : '')
                            .(! empty($sp->by) ? ' · by '.$sp->by : ''),
                        'credit' => 0,
                        'debit' => round((float) $sp->amount, 2),
                    ];
                }
            }
            if (Schema::hasTable('payslips')) {
                // Latest slip per month wins (regenerated drafts replace older ones).
                $seenMonth = [];
                foreach (DB::table('payslips')->where('employee_id', $emp->id)->orderByDesc('id')->limit(120)->get(['id', 'month', 'net', 'created_at']) as $ps) {
                    $m = substr((string) $ps->month, 0, 7);
                    if ($m === '' || isset($seenMonth[$m])) {
                        continue;
                    }
                    $seenMonth[$m] = 1;
                    $net = round((float) $ps->net, 2);
                    $salEarned += $net;
                    $mPaid = round((float) ($salPaidByMonth[$m] ?? 0), 2);
                    $date = substr((string) $ps->created_at, 0, 10);
                    $lines[] = [
                        'kind' => 'salary',
                        'date' => $date,
                        'sort' => $date.'-1-'.str_pad((string) $ps->id, 9, '0', STR_PAD_LEFT),
                        'particulars' => 'Salary — '.$m.' payslip (net payable)',
                        'credit' => $net,
                        'debit' => 0,
                        'month' => $m,
                        'slipNet' => $net,
                        'slipPaid' => $mPaid,
                        'slipBalance' => round($net - $mPaid, 2),
                    ];
                }
            }

            usort($lines, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
            $bal = 0.0;
            foreach ($lines as &$l) {
                $bal += $l['credit'] - $l['debit'];
                $l['balance'] = round($bal, 2);
                unset($l['sort']);
            }
            unset($l);

            return response()->json([
                'ok' => true,
                'employee' => ['id' => $emp->id, 'name' => $emp->name, 'code' => $emp->emp_code],
                'lines' => $lines,
                'totals' => [
                    'earned' => round($earned, 2),
                    'paid' => round($paidTotal, 2),
                    'outstanding' => round($earned - $paidTotal, 2),
                    'pending' => round($pendingNet, 2),
                    'salaryEarned' => round($salEarned, 2),
                    'salaryPaid' => round($salPaid, 2),
                    'salaryOutstanding' => round($salEarned - $salPaid, 2),
                    'allEarned' => round($earned + $salEarned, 2),
                    'allPaid' => round($paidTotal + $salPaid, 2),
                    'allOutstanding' => round(($earned - $paidTotal) + ($salEarned - $salPaid), 2),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 85c (Ejaz): clean ORPHANED entries — rows whose employee record no
     * longer exists (left behind by old deploy-time demo reseeds that
     * renumbered employees). Admin only; removes the entries plus their
     * ledger payments and history rows. Living entries are never touched.
     */
    public function cleanOrphanCommissions(Request $request)
    {
        try {
            if ($deny = ApprovalService::denyUnlessRole($request, ['admin'])) {
                return $deny;
            }
            ApprovalService::ensureAuditCols('commissions');
            $tid = $request->user()->tenant_id;
            $orphanIds = DB::table('commissions as r')
                ->leftJoin('employees as e', 'e.id', '=', 'r.employee_id')
                ->whereNull('e.id')
                ->when($tid, fn ($q) => $q->where(fn ($w) => $w->where('r.tenant_id', $tid)->orWhereNull('r.tenant_id')))
                ->pluck('r.id');
            if ($orphanIds->isEmpty()) {
                return response()->json(['ok' => true, 'removed' => 0, 'message' => 'No orphaned entries found.']);
            }
            foreach (['commission_payments', 'commission_logs'] as $tbl) {
                if (Schema::hasTable($tbl)) {
                    DB::table($tbl)->whereIn('commission_id', $orphanIds)->delete();
                }
            }
            DB::table('commissions')->whereIn('id', $orphanIds)->delete();

            return response()->json(['ok' => true, 'removed' => $orphanIds->count(),
                'message' => $orphanIds->count().' orphaned entr'.($orphanIds->count() === 1 ? 'y' : 'ies').' removed (their employees no longer exist).']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** rev 84: manual LOCK (Admin only) — freezes the entry forever. */
    public function lockCommission(Request $request, int $id)
    {
        try {
            if ($deny = ApprovalService::denyUnlessRole($request, ['admin'])) {
                return $deny;
            }
            ApprovalService::ensureAuditCols('commissions');
            $tid = $request->user()->tenant_id;
            $rec = DB::table('commissions')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response()->json(['ok' => false, 'error' => 'Entry not found'], 404);
            }
            if (! empty($rec->locked_at)) {
                return response()->json(['ok' => false, 'error' => 'Already locked.'], 422);
            }
            $by = trim((string) ($request->user()->name ?? '')) ?: (string) $request->user()->email;
            DB::table('commissions')->where('id', $id)->update(ApprovalService::safeRow('commissions', [
                'locked_at' => now(), 'locked_by' => $by, 'lock_source' => 'manual', 'updated_at' => now(),
            ]));
            ApprovalService::logCommission($tid, $id, 'locked', 'Locked manually — entry is now frozen', $by);

            return response()->json(['ok' => true, 'message' => 'Entry locked — it can no longer be edited or re-decided.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** rev 84: the full per-entry history trail (managers, approver, or the employee). */
    public function commissionHistory(Request $request, int $id)
    {
        try {
            $tid = $request->user()->tenant_id;
            $rec = DB::table('commissions')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response()->json(['rows' => [], 'error' => 'Entry not found']);
            }
            $me = $this->currentEmployee($request);
            $allowed = $this->isManager($request)
                || ($me && (int) ($rec->approver_id ?? 0) === (int) $me->id)
                || ($me && (int) ($rec->employee_id ?? 0) === (int) $me->id);
            if (! $allowed) {
                return response()->json(['rows' => [], 'error' => 'Not your entry.']);
            }
            $rows = Schema::hasTable('commission_logs')
                ? DB::table('commission_logs')->where('commission_id', $id)->orderBy('id')->get(['action', 'details', 'by', 'at'])
                : collect();

            return response()->json(['rows' => $rows->map(fn ($l) => [
                'action' => $l->action,
                'details' => $l->details,
                'by' => $l->by,
                'at' => substr((string) $l->at, 0, 16),
            ])->values()]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Approve / reject — records who + when + remarks, with authorisation. */
    public function decide(Request $request, string $module, int $id)
    {
        try {
            $cfg = $this->cfg($module);
            if (! $cfg) {
                return response()->json(['ok' => false, 'error' => 'Unknown module'], 422);
            }
            $table = $cfg['table'];
            ApprovalService::ensureAuditCols($table);
            // rev 84: 'reopen' (approved → pending) is allowed for COMMISSIONS
            // only — Ejaz's reversible-but-never-deleted lifecycle.
            $v = $request->validate([
                'action' => ['required', $module === 'commissions' ? 'in:approve,reject,reopen' : 'in:approve,reject'],
                'remarks' => ['nullable', 'string', 'max:500'],
            ]);
            $tid = $request->user()->tenant_id;
            $rec = DB::table($table)->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response()->json(['ok' => false, 'error' => 'Request not found'], 404);
            }

            // rev 84: a LOCKED commission is frozen forever — no decision changes.
            if ($module === 'commissions' && ! empty($rec->locked_at)) {
                return response()->json(['ok' => false, 'error' => 'This entry is LOCKED ('.($rec->lock_source ?: 'paid out').') and can no longer be changed.'], 422);
            }
            // rev 116 (Ejaz): APPROVAL GATE — the manager can approve only AFTER
            // Accounts confirms the money was actually received. Claims with an
            // accounts stage carry accounts_status (pending|confirmed|flagged).
            if ($module === 'commissions' && $v['action'] === 'approve'
                && ! empty($rec->accounts_status ?? null) && $rec->accounts_status !== 'confirmed') {
                return response()->json(['ok' => false, 'error' => $rec->accounts_status === 'flagged'
                    ? 'Accounts FLAGGED this collection ('.($rec->accounts_note ?: 'see note').') — resolve it before approval.'
                    : 'Accounts must confirm this collection first — the money trail comes before the commission.'], 422);
            }

            // rev181 — DRA-EXPIRY GATE at the money point: approving a commission
            // for an agent whose DRA certification is expired/missing is refused
            // (block mode) or allowed-with-warning (warn mode, the default).
            // Controlled by Settings -> Statutory Rates -> DRA gate. Fail-soft.
            $draWarning = null;
            if ($module === 'commissions' && $v['action'] === 'approve' && ! empty($rec->employee_id)) {
                try {
                    $gate = (string) (\App\Http\Controllers\SettingsController::rates($tid)['dra_gate'] ?? 'warn');
                    if ($gate !== 'off') {
                        $dra = \App\Http\Controllers\ComplianceController::draValid((int) $rec->employee_id);
                        if (! $dra['ok']) {
                            if ($gate === 'block') {
                                return response()->json(['ok' => false, 'error' => 'DRA gate: '.$dra['note'].' — this commission cannot be approved until the agent\'s DRA certification is valid (Field Force -> DRA Certifications). Switch the gate to Warn in Statutory Rates to override.'], 422);
                            }
                            $draWarning = 'DRA warning: '.$dra['note'].' — approved anyway (gate is in Warn mode); recorded in the audit log.';
                            if (Schema::hasTable('activity_logs')) {
                                DB::table('activity_logs')->insert([
                                    'tenant_id' => $tid, 'user_id' => optional($request->user())->id,
                                    'action' => 'incentive_dra_invalid', 'entity' => 'commissions', 'entity_id' => $id,
                                    'detail' => json_encode(['note' => 'Commission approved with invalid/expired DRA', 'dra' => $dra['note']]),
                                    'ip' => $request->ip(), 'created_at' => now(),
                                ]);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // the DRA gate must never break approvals
                }
            }

            $me = $this->currentEmployee($request);
            $myId = $me->id ?? null;
            $allowed = $this->isManager($request) || ($myId && (int) (($rec->approver_id ?? 0)) === (int) $myId);
            if (! $allowed) {
                return response()->json(['ok' => false, 'error' => 'You are not the approver for this request.'], 403);
            }

            $newStatus = $v['action'] === 'approve' ? 'approved' : ($v['action'] === 'reject' ? 'rejected' : 'pending');
            $update = ApprovalService::safeRow($table, [
                'status' => $newStatus,
                'decided_by' => $v['action'] === 'reopen' ? null : ($me->name ?? $request->user()->name),
                'decided_at' => $v['action'] === 'reopen' ? null : now(),
                'remarks' => $v['remarks'] ?? null,
                'updated_at' => now(),
            ]);
            DB::table($table)->where('id', $id)->update($update);

            // J2 — audit every approval decision.
            \App\Services\Audit::record($tid ? (int) $tid : null, optional($request->user())->id, $newStatus, $module, (int) $id, ['remarks' => $v['remarks'] ?? null], $request->ip());

            // F1 — compliance-gated incentive (warn + allow + audit). Approving a
            // commission for a low-compliance agent is allowed but recorded.
            if ($module === 'commissions' && $v['action'] === 'approve'
                && Schema::hasColumn($table, 'employee_id') && ! empty($rec->employee_id)) {
                try {
                    $minScore = (int) (\App\Http\Controllers\SettingsController::rates($tid)['incentive_min_compliance'] ?? 60);
                    $score = \App\Http\Controllers\ComplianceController::scoreFor((int) $rec->employee_id, $tid);
                    if ($score < $minScore && Schema::hasTable('activity_logs')) {
                        DB::table('activity_logs')->insert([
                            'tenant_id' => $tid, 'user_id' => optional($request->user())->id,
                            'action' => 'incentive_low_compliance', 'entity' => 'commissions', 'entity_id' => $id,
                            'detail' => json_encode(['note' => 'Incentive approved despite a low compliance score', 'score' => $score, 'min' => $minScore]),
                            'ip' => $request->ip(), 'created_at' => now(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // never block an approval on the compliance check
                }
            }

            // H2 — grievance-lock awareness: paying an incentive while a grievance /
            // complaint is OPEN is allowed but recorded (warn + audit).
            if ($module === 'commissions' && $v['action'] === 'approve'
                && Schema::hasColumn($table, 'employee_id') && ! empty($rec->employee_id) && Schema::hasTable('complaints')) {
                try {
                    $open = DB::table('complaints')->where('employee_id', $rec->employee_id)
                        ->whereIn('status', ['open', 'pending', 'in_progress'])->count();
                    if ($open > 0 && Schema::hasTable('activity_logs')) {
                        DB::table('activity_logs')->insert([
                            'tenant_id' => $tid, 'user_id' => optional($request->user())->id,
                            'action' => 'grievance_pending_incentive', 'entity' => 'commissions', 'entity_id' => $id,
                            'detail' => json_encode(['note' => 'Incentive approved while a grievance/complaint is open', 'open_complaints' => $open]),
                            'ip' => $request->ip(), 'created_at' => now(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // grievance check is best-effort; never block approval on it
                }
            }

            if ($module === 'commissions') {
                $by = $me->name ?? ($request->user()->name ?? $request->user()->email);
                ApprovalService::logCommission($tid, $id,
                    $v['action'] === 'reopen' ? 'reopened' : ($newStatus === 'approved' ? 'approved' : 'rejected'),
                    ($v['action'] === 'reopen' ? 'Sent back to Pending (was '.($rec->status ?? '?').')' : ucfirst($newStatus))
                    .(! empty($v['remarks']) ? ' · remarks: '.$v['remarks'] : ''),
                    (string) $by);
            }

            // rev 77: an APPROVED transfer is applied immediately when its
            // effective date has arrived (future-dated ones auto-apply via the
            // daily transfers:apply job), and the formal TRANSFER ORDER PDF is
            // emailed to the employee with the acknowledgement link (rev 77b).
            // Fail-soft — the approval stands either way.
            if ($module === 'transfers' && $update['status'] === 'approved') {
                try {
                    \App\Services\TransferService::applyIfDue($id);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Transfer apply failed (#'.$id.'): '.$e->getMessage());
                }
                try {
                    TransferController::sendOrder($id);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Transfer order email failed (#'.$id.'): '.$e->getMessage());
                }
            }

            // rev 83b (Ejaz): an APPROVED increment auto-emails the formal
            // Increment Letter PDF. Applying to the employee record stays a
            // manual one-click HR action (his explicit choice). Fail-soft.
            if ($module === 'increments' && $update['status'] === 'approved') {
                try {
                    IncrementController::sendLetter($id);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Increment letter email failed (#'.$id.'): '.$e->getMessage());
                }
            }

            // Notify the requesting employee of the decision. Fail-soft.
            try {
                if (! empty($rec->employee_id)) {
                    $emp = DB::table('employees')->where('id', $rec->employee_id)->first();
                    if ($emp && ! empty($emp->email)) {
                        $decided = $update['status'];
                        $lines = ['Type' => $cfg['label'], 'Decision' => ucfirst($decided), 'Decided by' => $me->name ?? $request->user()->name];
                        if (! empty($v['remarks'])) {
                            $lines['Remarks'] = $v['remarks'];
                        }
                        \App\Services\MailService::queue([
                            'tenant_id' => $emp->tenant_id,
                            'company_id' => $emp->company_id,
                            'to' => $emp->email,
                            'to_name' => $emp->name,
                            'subject' => $cfg['label'].' '.$decided,
                            'heading' => 'Your '.strtolower($cfg['label']).' was '.$decided,
                            'intro' => 'Your '.strtolower($cfg['label']).' request has been '.$decided.'.',
                            'lines' => $lines,
                            'kind' => 'request.'.$decided,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // best-effort
            }

            return response()->json(['ok' => true, 'status' => $update['status'], 'warning' => $draWarning ?? null]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Unified Approvals Inbox: pending items across leaves + every request
     * module that await the logged-in user. Each item carries its `module` so
     * the frontend knows which decide endpoint to call.
     */
    public function inbox(Request $request)
    {
        try {
            $tid = $request->user()->tenant_id;
            $me = $this->currentEmployee($request);
            $myId = $me->id ?? null;
            $manager = $this->isManager($request);
            $items = [];

            // 1) Leaves (own table/shape).
            if (Schema::hasTable('leaves')) {
                $q = DB::table('leaves as l')->leftJoin('employees as e', 'e.id', '=', 'l.employee_id')
                    ->when($tid, fn ($qq) => $qq->where('l.tenant_id', $tid))->where('l.status', 'pending');
                if (! $manager) {
                    $q->where('l.approver_id', $myId ?? -1);
                }
                foreach ($q->orderByDesc('l.id')->get(['l.*', 'e.name as emp_name']) as $r) {
                    $a = (array) $r;
                    $items[] = [
                        'module' => 'leave',
                        'kind' => 'Leave',
                        'id' => $a['id'],
                        'employee' => $a['emp_name'] ?: ('#'.$a['employee_id']),
                        'detail' => ($a['type_name'] ?? 'Leave').' · '.$a['from_date'].' → '.$a['to_date'].' ('.((float) $a['days']).'d)',
                        'approver' => $a['approver_name'] ?? '',
                    ];
                }
            }

            // 2) Every generic money/HR module.
            foreach (ApprovalService::modules() as $key => $cfg) {
                $table = $cfg['table'];
                if (! Schema::hasTable($table)) {
                    continue;
                }
                ApprovalService::ensureAuditCols($table);
                $q = DB::table($table.' as r')->leftJoin('employees as e', 'e.id', '=', 'r.employee_id')
                    ->when($tid, fn ($qq) => $qq->where('r.tenant_id', $tid))->where('r.status', 'pending');
                if (! $manager) {
                    $q->where('r.approver_id', $myId ?? -1);
                }
                foreach ($q->orderByDesc('r.id')->get(['r.*', 'e.name as emp_name']) as $r) {
                    $a = (array) $r;
                    $amt = ($cfg['amount'] && isset($a[$cfg['amount']])) ? ' · ₹'.number_format((float) $a[$cfg['amount']]) : '';
                    $items[] = [
                        'module' => $key,
                        'kind' => $cfg['label'],
                        'id' => $a['id'],
                        'employee' => $a['emp_name'] ?: ('#'.($a['employee_id'] ?? '').' (employee record no longer exists)'),
                        'detail' => $this->extraSummary($a).$amt,
                        'approver' => $a['approver_name'] ?? '',
                    ];
                }
            }

            return response()->json(['items' => $items, 'count' => count($items), 'isManager' => $manager]);
        } catch (\Throwable $e) {
            return response()->json(['items' => [], 'count' => 0, 'error' => $e->getMessage()]);
        }
    }

    // ---- rev 116: COLLECTION PROOF + ACCOUNTS CONFIRMATION -------------------

    /** POST /app/requests/commissions/proof-upload — image/PDF, returns the stored path. */
    public function proofUpload(Request $request)
    {
        try {
            $v = $request->validate([
                'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            ], [
                'file.mimes' => 'Upload an image (JPG/PNG/WebP) or a PDF.',
                'file.max' => 'The proof must be under 5 MB.',
            ]);
            $path = $request->file('file')->store('commission-proofs', 'public');

            return response()->json(['ok' => true, 'path' => $path]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** GET /app/requests/commissions/{id}/proof — view the uploaded proof (owner / manager / accounts). */
    public function proofServe(Request $request, int $id)
    {
        self::ensureCollectionCols();
        $tid = $request->user()->tenant_id;
        $rec = DB::table('commissions')->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        abort_unless($rec && ! empty($rec->proof_path), 404);
        $me = $this->currentEmployee($request);
        $own = $me && (int) $me->id === (int) ($rec->employee_id ?? 0);
        abort_unless($this->isManager($request) || $this->isAccounts($request) || $own, 403);
        $full = storage_path('app/public/'.$rec->proof_path);
        abort_unless(is_file($full), 404);

        return response()->file($full);
    }

    /**
     * POST /app/requests/commissions/{id}/accounts {action: confirm|flag, note}
     * The Accounts stage — money confirmed in BEFORE the manager can approve.
     */
    public function accountsDecide(Request $request, int $id)
    {
        try {
            self::ensureCollectionCols();
            if (! $this->isAccounts($request)) {
                return response()->json(['ok' => false, 'error' => 'Only Accounts (or an Admin) can confirm collections.'], 403);
            }
            $v = $request->validate([
                'action' => ['required', 'in:confirm,flag'],
                'note' => ['nullable', 'string', 'max:255'],
            ]);
            $tid = $request->user()->tenant_id;
            $rec = DB::table('commissions')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response()->json(['ok' => false, 'error' => 'Entry not found.'], 404);
            }
            if (! empty($rec->locked_at)) {
                return response()->json(['ok' => false, 'error' => 'This entry is locked.'], 422);
            }
            $by = (string) ($request->user()->name ?? $request->user()->email);
            DB::table('commissions')->where('id', $id)->update(ApprovalService::safeRow('commissions', [
                'accounts_status' => $v['action'] === 'confirm' ? 'confirmed' : 'flagged',
                'accounts_by' => $by, 'accounts_at' => now(),
                'accounts_note' => $v['note'] ?? null, 'updated_at' => now(),
            ]));
            try {
                ApprovalService::logCommission($tid, $id, 'accounts',
                    ($v['action'] === 'confirm' ? 'Accounts CONFIRMED the collection' : 'Accounts FLAGGED the collection')
                    .(! empty($v['note']) ? ' · '.$v['note'] : ''), $by);
            } catch (\Throwable $e) {
            }

            return response()->json(['ok' => true, 'message' => $v['action'] === 'confirm'
                ? 'Collection confirmed — the entry can now be approved by the manager.'
                : 'Collection flagged — approval stays blocked until this is resolved and confirmed.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** rev181 — bounce columns on commissions (self-healing, idempotent). */
    private static function ensureBounceCols(): void
    {
        try {
            if (! Schema::hasTable('commissions')) {
                return;
            }
            foreach ([
                'bounced_at' => fn ($t) => $t->timestamp('bounced_at')->nullable(),
                'bounced_by' => fn ($t) => $t->string('bounced_by', 120)->nullable(),
                'bounce_reason' => fn ($t) => $t->string('bounce_reason', 300)->nullable(),
                'bounce_clawback_id' => fn ($t) => $t->unsignedBigInteger('bounce_clawback_id')->nullable(),
            ] as $name => $add) {
                if (! Schema::hasColumn('commissions', $name)) {
                    try {
                        Schema::table('commissions', $add);
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * rev181 (Ejaz, collection-industry USP) — MARK A COLLECTION BOUNCED.
     * A cheque returns / a settlement cancels after the commission entry exists.
     * One action, and the money story corrects itself:
     *   - entry NOT yet paid (pending, or approved-but-unlocked with no ledger
     *     payments) -> it is REJECTED with the bounce reason: it will never pay.
     *   - entry already PAID (locked into a payslip, or a separate-payout entry
     *     with recorded payments) -> an APPROVED Clawback is auto-created for
     *     the paid amount; the payroll engine recovers it from the next payslip
     *     (see PayrollGenController::clawbacksDue) with the reason on record.
     * Accounts or managers only. Idempotent: a bounced entry stays bounced.
     */
    public function bounce(Request $request, int $id)
    {
        if (! $this->isManager($request) && ! $this->isAccounts($request)) {
            return response()->json(['ok' => false, 'error' => 'Only managers or Accounts can mark a bounce.'], 403);
        }
        try {
            $v = $request->validate(['reason' => ['required', 'string', 'max:300']]);
            $tid = $request->user()->tenant_id;
            self::ensureBounceCols();
            ApprovalService::ensureAuditCols('clawbacks');
            $rec = DB::table('commissions')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response()->json(['ok' => false, 'error' => 'Commission entry not found'], 404);
            }
            if (! empty($rec->bounced_at)) {
                return response()->json(['ok' => false, 'error' => 'This entry is already marked bounced ('.substr((string) $rec->bounced_at, 0, 10).').'], 422);
            }
            if (($rec->status ?? '') === 'rejected') {
                return response()->json(['ok' => false, 'error' => 'This entry is already rejected — nothing was paid, so there is nothing to bounce.'], 422);
            }
            $by = (string) ($request->user()->name ?? $request->user()->email);

            // How much has actually been PAID on this entry?
            $paid = 0.0;
            $locked = ! empty($rec->locked_at);
            try {
                if (Schema::hasTable('commission_payments')) {
                    $paid = (float) DB::table('commission_payments')->where('commission_id', $id)->sum('amount');
                }
            } catch (\Throwable $e) {
            }
            if ($locked && $paid <= 0) {
                $paid = (float) ($rec->amount ?? 0);   // locked into a payslip = the net paid
            }
            $paid = round($paid, 2);

            $stamp = ['bounced_at' => now(), 'bounced_by' => $by, 'bounce_reason' => $v['reason'], 'updated_at' => now()];
            $clawbackId = null;
            $msg = '';

            // An UNLOCKED bounced entry must never pay anything further — reject
            // it (locked entries stay frozen as-is; the clawback below carries
            // the correction, and a regenerate of their run stays consistent).
            if (! $locked) {
                $stamp['status'] = 'rejected';
                $stamp['decided_by'] = $by;
                $stamp['decided_at'] = now();
                $stamp['remarks'] = 'Bounced: '.$v['reason'];
            }

            if ($paid > 0) {
                // Money is out — auto-create an APPROVED clawback for the paid amount.
                // cycle_month: recover in the CURRENT month if its run is still open
                // (none, or draft) — otherwise the NEXT month (mirrors the rev180
                // arrears targeting). The payroll engine deducts what fits in the
                // slip; any shortfall stays visible on the clawback row.
                $target = now()->format('Y-m');
                try {
                    if (Schema::hasTable('payroll_runs')) {
                        $gone = DB::table('payroll_runs')
                            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                            ->where('cycle_label', $target)->where('status', '!=', 'draft')->exists();
                        if ($gone) {
                            $target = now()->addMonthNoOverflow()->format('Y-m');
                        }
                    }
                } catch (\Throwable $e) {
                }
                $fy = FinYearController::fyOf($target);
                FinYearController::stamp('clawbacks', $tid);
                $cbRow = ApprovalService::safeRow('clawbacks', [
                    'tenant_id' => $tid,
                    'company_id' => $rec->company_id ?? null,
                    'employee_id' => $rec->employee_id ?? null,
                    'amount' => $paid,
                    'portfolio' => $rec->portfolio ?? null,
                    'cycle_month' => $target,
                    'reason' => substr('Bounce: '.$v['reason'].' (commission #'.$id
                        .(! empty($rec->customer_name) ? ' · '.$rec->customer_name : '')
                        .(! empty($rec->account_ref) ? ' · '.$rec->account_ref : '').')', 0, 240),
                    'status' => 'approved',
                    'decided_by' => $by.' (bounce)',
                    'decided_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $clawbackId = DB::table('clawbacks')->insertGetId($cbRow);
                $stamp['bounce_clawback_id'] = $clawbackId;
                $msg = 'Bounce recorded. ₹'.number_format($paid, 2).' was already paid — clawback #'.$clawbackId
                    .' created (approved) and will be recovered from the '.$target.' payslip; any shortfall stays open on the clawback.';
            } else {
                // Nothing paid yet — the reject above already stops it forever.
                $msg = 'Bounce recorded. Nothing was paid on this entry yet — it is now REJECTED and will never fold into a payslip.';
            }
            if ($paid > 0 && ! $locked) {
                $msg .= ' The entry is also REJECTED so its unpaid balance can never pay.';
            }
            DB::table('commissions')->where('id', $id)->update(ApprovalService::safeRow('commissions', $stamp));

            try {
                ApprovalService::logCommission($tid, $id, 'bounced',
                    'Marked BOUNCED — '.$v['reason'].($clawbackId ? ' · clawback #'.$clawbackId.' auto-created (approved)' : ' · entry rejected (nothing paid)'), $by);
            } catch (\Throwable $e) {
            }
            try {
                if (Schema::hasTable('activity_logs')) {
                    DB::table('activity_logs')->insert([
                        'tenant_id' => $tid, 'user_id' => optional($request->user())->id,
                        'action' => 'commission_bounced', 'entity' => 'commissions', 'entity_id' => $id,
                        'detail' => json_encode(['reason' => $v['reason'], 'paid' => $paid, 'clawback_id' => $clawbackId]),
                        'ip' => $request->ip(), 'created_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
            }

            return response()->json(['ok' => true, 'message' => $msg, 'clawbackId' => $clawbackId]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev181c (D3a, Ejaz) — COMMISSION PAYMENT VOUCHER: a printable, branded
     * voucher for every SEPARATE payout recorded via Record Payment. Salary
     * has a signed voucher; now commission payments do too. Rendered as a
     * self-printing HTML page (letterhead style, signature blocks) so it works
     * without any PDF library — print-to-PDF from the browser gives the file.
     * Managers / HR / Accounts only. Payslip-mode ledger debits have no
     * voucher (the payslip itself is the document).
     */
    public function commissionPaymentVoucher(Request $request, int $pid)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager', 'accountant'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            if (! Schema::hasTable('commission_payments')) {
                return response('No payments recorded yet.', 404);
            }
            $p = DB::table('commission_payments')->where('id', $pid)->first();
            if (! $p) {
                return response('Payment not found.', 404);
            }
            $rec = DB::table('commissions')->where('id', (int) $p->commission_id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $rec) {
                return response('Payment not found.', 404);   // tenant mismatch reads as not-found
            }
            if (strtolower((string) ($p->mode ?? '')) === 'payslip') {
                return response('Payslip-paid amounts have no separate voucher — the payslip is the document.', 422);
            }
            $emp = ! empty($rec->employee_id) ? DB::table('employees')->where('id', $rec->employee_id)->first() : null;
            $co = ($emp && ! empty($emp->company_id)) ? DB::table('companies')->where('id', $emp->company_id)->first() : null;
            $paidAll = (float) DB::table('commission_payments')->where('commission_id', (int) $p->commission_id)->sum('amount');
            $net = round((float) ($rec->amount ?? 0), 2);
            $balance = max(0, round($net - $paidAll, 2));
            $h = fn ($x) => htmlspecialchars((string) ($x ?? ''), ENT_QUOTES, 'UTF-8');
            $inr = fn ($n) => '₹'.number_format((float) $n, 2);
            $no = 'CPV-'.str_pad((string) $pid, 6, '0', STR_PAD_LEFT);
            $logo = ($co && ! empty($co->id)) ? url('/app/branding/logo/'.$co->id) : '';

            $entryBits = array_filter([
                'Entry #'.$rec->id,
                $rec->purpose ?? null,
                ! empty($rec->portfolio) ? 'Portfolio: '.$rec->portfolio : null,
                ! empty($rec->customer_name) ? 'Customer: '.$rec->customer_name.(! empty($rec->account_ref) ? ' ('.$rec->account_ref.')' : '') : null,
                ! empty($rec->cycle_month) ? 'Earned: '.$rec->cycle_month : null,
            ]);
            $gross = (float) ($rec->gross_amount ?? 0);
            $tds = (float) ($rec->tds_amount ?? 0);

            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.$h($no).' — Commission Payment Voucher</title>'
                .'<style>body{font-family:Arial,Helvetica,sans-serif;color:#0f172a;max-width:760px;margin:24px auto;padding:0 20px}'
                .'.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0f172a;padding-bottom:12px;margin-bottom:16px}'
                .'h1{font-size:19px;margin:0}.co{font-size:13px;color:#334155}.muted{color:#64748b;font-size:11.5px}'
                .'table{width:100%;border-collapse:collapse;margin:10px 0}th,td{border:1px solid #cbd5e1;padding:7px 10px;font-size:12.5px;text-align:left}'
                .'th{background:#f1f5f9;font-size:10px;text-transform:uppercase;color:#475569}.r{text-align:right}'
                .'.amt{font-size:17px;font-weight:800}.sig{display:flex;justify-content:space-between;margin-top:60px}'
                .'.sig div{width:220px;border-top:1.5px solid #0f172a;padding-top:6px;font-size:11.5px;text-align:center;color:#334155}'
                .'.bar{background:#0f172a;color:#fff;display:inline-block;padding:3px 14px;border-radius:99px;font-size:11px;font-weight:800;letter-spacing:.6px}'
                .'@media print{.noprint{display:none}}</style></head><body>'
                .'<div class="head"><div>'
                .($logo !== '' ? '<img src="'.$h($logo).'" alt="" style="max-height:46px;max-width:200px;margin-bottom:6px" onerror="this.style.display=&#39;none&#39;">' : '')
                .'<h1>'.$h($co->name ?? 'Company').'</h1>'
                .'<div class="co">'.$h($co->address ?? '').($co && ! empty($co->pan) ? ' · PAN '.$h($co->pan) : '').'</div></div>'
                .'<div style="text-align:right"><span class="bar">COMMISSION PAYMENT VOUCHER</span>'
                .'<div style="font-size:14px;font-weight:800;margin-top:8px">'.$h($no).'</div>'
                .'<div class="muted">Paid on '.$h(substr((string) ($p->paid_on ?: $p->created_at), 0, 10)).'</div></div></div>'
                .'<table><tr><th style="width:32%">Paid to</th><td><b>'.$h($emp->name ?? ('#'.($rec->employee_id ?? ''))).'</b>'
                .($emp && ! empty($emp->emp_code) ? ' ('.$h($emp->emp_code).')' : '')
                .($emp && ! empty($emp->pan) ? ' · PAN '.$h($emp->pan) : '').'</td></tr>'
                .'<tr><th>Against</th><td>'.$h(implode(' · ', $entryBits)).'</td></tr>'
                .($gross > 0 ? '<tr><th>Entry value</th><td>Gross '.$inr($gross).' − TDS 194H '.$inr($tds).' = Net payable '.$inr($net).'</td></tr>' : '<tr><th>Entry net payable</th><td>'.$inr($net).'</td></tr>')
                .'<tr><th>This payment</th><td class="amt">'.$inr($p->amount).'</td></tr>'
                .'<tr><th>Mode / reference</th><td>'.$h(strtoupper((string) ($p->mode ?: 'bank'))).(! empty($p->reference) ? ' · '.$h($p->reference) : '').'</td></tr>'
                .(! empty($p->note) ? '<tr><th>Note</th><td>'.$h($p->note).'</td></tr>' : '')
                .'<tr><th>Recorded by</th><td>'.$h($p->by ?? '').'</td></tr>'
                .'<tr><th>Cumulative on this entry</th><td>Paid '.$inr($paidAll).' · Balance '.$inr($balance).($balance <= 0.005 ? ' — FULLY PAID' : '').'</td></tr></table>'
                .'<div class="muted">TDS u/s 194H was deducted when the commission entry was recorded; this voucher pays the NET amount. System-generated from SmartPRS — entry #'.$h($rec->id).' carries the full history and audit trail.</div>'
                .'<div class="sig"><div>Received by (signature / date)</div><div>Authorised signatory</div></div>'
                .'<div class="noprint" style="margin-top:26px"><button onclick="window.print()" style="padding:9px 20px;font-size:14px;border-radius:8px;border:1px solid #0f172a;background:#0f172a;color:#fff;cursor:pointer">Print voucher</button></div>'
                .'<script>setTimeout(function () { try { window.print(); } catch (e) {} }, 400);</script>'
                .'</body></html>';

            return response($html);
        } catch (\Throwable $e) {
            return response('Voucher error: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }
    }
}
