<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev181 (Ejaz, collection-industry USP) — BANK / NBFC PAYOUT & BILLING PACK.
 *
 * A collection agency's month-end conversation with each bank/NBFC needs three
 * documents, all derived from data SmartPRS already holds:
 *   1. PAYOUT REGISTER — every commission entry earned against that bank's
 *      portfolio in the month, agent-wise: gross, TDS 194H, net, status.
 *   2. TDS 194H ANNEXURE — deductee-wise summary (PAN, gross, TDS, net) for
 *      the 26Q return and for issuing Form 16A to the agents.
 *   3. GST SERVICE INVOICE — the agency's bill to the bank for the month's
 *      collection services: line items suggested from the recorded collections,
 *      editable before saving; CGST+SGST vs IGST decided from the two GSTINs'
 *      state codes (mirrors the rev 90 SaaS billing rule); numbered per tenant
 *      per financial year and stored in a self-created bank_invoices table.
 *
 * The bank key is the free-text `portfolio` that commission entries already
 * carry (the Portfolio / Bank field on every claim). Off-roll agent earnings
 * carry no bank tag — a DELIBERATE v1 limit, stated on screen.
 *
 * Read guarded to admin / HR / accountant; invoice writes admin / accountant.
 * Everything fails soft (JSON {error}, never a 500).
 */
class BankPackController extends Controller
{
    /** Self-created invoice register (no migration required). */
    private static function ensureInvoices(): void
    {
        if (Schema::hasTable('bank_invoices')) {
            return;
        }
        Schema::create('bank_invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('invoice_no', 40);
            $t->string('bank', 150);
            $t->string('month', 7);
            $t->string('buyer_gstin', 20)->nullable();
            $t->string('buyer_address', 400)->nullable();
            $t->longText('lines')->nullable();          // [{desc, amount}]
            $t->decimal('taxable', 14, 2)->default(0);
            $t->decimal('cgst', 14, 2)->default(0);
            $t->decimal('sgst', 14, 2)->default(0);
            $t->decimal('igst', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->string('gst_mode', 10)->default('inter'); // intra | inter
            $t->string('fin_year', 10)->nullable();
            $t->string('created_by', 120)->nullable();
            $t->timestamps();
        });
    }

    private function guard(Request $request, bool $write = false)
    {
        $roles = $write ? ['admin', 'accountant'] : ['admin', 'hr_manager', 'accountant'];

        return ApprovalService::denyUnlessRole($request, $roles);
    }

    /** The tenant's billing company (master company first, else the first one). */
    private function sellerCompany($tid): ?object
    {
        try {
            if (! Schema::hasTable('companies')) {
                return null;
            }
            $q = DB::table('companies')->when($tid, fn ($x) => $x->where('tenant_id', $tid));
            $c = Schema::hasColumn('companies', 'is_master') ? (clone $q)->where('is_master', 1)->first() : null;

            return $c ?: $q->orderBy('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Distinct bank / portfolio names seen anywhere in the tenant's data. */
    private function bankNames($tid): array
    {
        $names = [];
        $collect = function (string $table, string $col) use (&$names, $tid) {
            try {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                    return;
                }
                $rows = DB::table($table)
                    ->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNotNull($col)->where($col, '!=', '')
                    ->distinct()->limit(200)->pluck($col);
                foreach ($rows as $n) {
                    $n = trim((string) $n);
                    if ($n !== '') {
                        $names[strtolower($n)] = $n;   // case-insensitive dedup, keep first casing
                    }
                }
            } catch (\Throwable $e) {
            }
        };
        $collect('commissions', 'portfolio');
        $collect('agent_authorizations', 'bank');
        $collect('payout_recon', 'bank');
        ksort($names);

        return array_values($names);
    }

    /** GST split by the two GSTINs' state codes (mirrors rev 90 billing rule). */
    private function gstMode(?string $sellerGstin, ?string $buyerGstin): string
    {
        $code = function (?string $g): ?string {
            $g = strtoupper(trim((string) $g));

            return preg_match('/^(\d{2})/', $g, $m) ? $m[1] : null;
        };
        $sc = $code($sellerGstin);
        $bc = $code($buyerGstin);

        return ($sc !== null && $bc !== null && $sc === $bc) ? 'intra' : 'inter';
    }

    /**
     * GET /app/bank-pack/data?month=YYYY-MM&bank=NAME
     * Bank list + (when a bank is chosen) the full pack data for the month.
     */
    public function data(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $month = trim((string) $request->query('month', ''));
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = now()->format('Y-m');
            }
            $bank = trim((string) $request->query('bank', ''));
            $seller = $this->sellerCompany($tid);
            $out = [
                'ok' => true,
                'month' => $month,
                'bank' => $bank,
                'banks' => $this->bankNames($tid),
                'seller' => $seller ? [
                    'name' => $seller->name ?? '',
                    'pan' => $seller->pan ?? '',
                    'gstin' => $seller->gstin ?? '',
                    'address' => $seller->address ?? '',
                ] : null,
                'canInvoice' => $request->user()->hasAnyRole(['super_admin', 'admin', 'accountant']),
            ];
            if ($bank === '') {
                return response()->json($out + ['rows' => [], 'annexure' => [], 'invoices' => []]);
            }

            // ---- payout register: this bank's commission entries this month ----
            $rows = [];
            $agg = [];       // employee_id => annexure aggregate
            $tGross = 0.0;
            $tTds = 0.0;
            $tNet = 0.0;
            $tNetApproved = 0.0;
            $tCollected = 0.0;
            if (Schema::hasTable('commissions') && Schema::hasColumn('commissions', 'portfolio')) {
                $cols = ['id', 'employee_id', 'amount', 'status', 'portfolio'];
                foreach (['gross_amount', 'tds_rate', 'tds_amount', 'purpose', 'customer_name', 'account_ref',
                    'base_amount', 'cycle_month', 'month', 'payout_method', 'locked_at', 'bounced_at'] as $c) {
                    if (Schema::hasColumn('commissions', $c)) {
                        $cols[] = $c;
                    }
                }
                $hasCycle = Schema::hasColumn('commissions', 'cycle_month');
                $hasMonth = Schema::hasColumn('commissions', 'month');
                $recs = DB::table('commissions')
                    ->when($tid && Schema::hasColumn('commissions', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                    ->whereRaw('LOWER(TRIM(portfolio)) = ?', [strtolower($bank)])
                    ->where(function ($q) use ($month, $hasCycle, $hasMonth) {
                        if ($hasCycle) {
                            $q->where('cycle_month', 'like', $month.'%');
                        }
                        if ($hasMonth) {
                            $hasCycle ? $q->orWhere('month', 'like', $month.'%') : $q->where('month', 'like', $month.'%');
                        }
                        if (! $hasCycle && ! $hasMonth) {
                            $q->whereRaw('1 = 1');
                        }
                    })
                    ->orderBy('employee_id')->orderBy('id')
                    ->limit(2000)->get($cols);
                $empIds = $recs->pluck('employee_id')->filter()->unique()->values()->all();
                $emps = $empIds ? DB::table('employees')->whereIn('id', $empIds)
                    ->get(array_values(array_filter(['id', 'name', 'emp_code',
                        Schema::hasColumn('employees', 'pan') ? 'pan' : null])))->keyBy('id') : collect();
                foreach ($recs as $r) {
                    $e = $emps[$r->employee_id] ?? null;
                    $net = round((float) ($r->amount ?? 0), 2);
                    $gross = round((float) (($r->gross_amount ?? null) ?: $net), 2);
                    $tds = round((float) (($r->tds_amount ?? null) ?: max(0, $gross - $net)), 2);
                    $st = (string) ($r->status ?? 'pending');
                    $bounced = ! empty($r->bounced_at ?? null);
                    $rows[] = [
                        'id' => (int) $r->id,
                        'employee' => $e->name ?? ('#'.$r->employee_id),
                        'code' => $e->emp_code ?? '',
                        'pan' => $e->pan ?? '',
                        'purpose' => $r->purpose ?? '',
                        'customer' => trim((string) ($r->customer_name ?? '').(! empty($r->account_ref) ? ' ('.$r->account_ref.')' : '')),
                        'collected' => round((float) ($r->base_amount ?? 0), 2),
                        'gross' => $gross,
                        'tdsRate' => (float) ($r->tds_rate ?? 0),
                        'tds' => $tds,
                        'net' => $net,
                        'status' => $st,
                        'locked' => ! empty($r->locked_at ?? null),
                        'bounced' => $bounced,
                    ];
                    if ($st === 'rejected') {
                        continue;   // never entered the payables — kept on the register for the story only
                    }
                    $tGross += $gross;
                    $tTds += $tds;
                    $tNet += $net;
                    $tCollected += (float) ($r->base_amount ?? 0);
                    if ($st === 'approved') {
                        $tNetApproved += $net;
                    }
                    $eid = (int) ($r->employee_id ?? 0);
                    if (! isset($agg[$eid])) {
                        $agg[$eid] = ['employee' => $e->name ?? ('#'.$eid), 'code' => $e->emp_code ?? '',
                            'pan' => $e->pan ?? '', 'entries' => 0, 'gross' => 0.0, 'tds' => 0.0, 'net' => 0.0];
                    }
                    $agg[$eid]['entries']++;
                    $agg[$eid]['gross'] = round($agg[$eid]['gross'] + $gross, 2);
                    $agg[$eid]['tds'] = round($agg[$eid]['tds'] + $tds, 2);
                    $agg[$eid]['net'] = round($agg[$eid]['net'] + $net, 2);
                }
            }

            // ---- saved invoices for this bank + month, and buyer prefill --------
            self::ensureInvoices();
            $invoices = DB::table('bank_invoices')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereRaw('LOWER(TRIM(bank)) = ?', [strtolower($bank)])
                ->where('month', $month)->orderByDesc('id')->limit(20)->get()
                ->map(fn ($i) => [
                    'id' => (int) $i->id, 'no' => $i->invoice_no, 'month' => $i->month,
                    'buyerGstin' => $i->buyer_gstin, 'buyerAddress' => $i->buyer_address,
                    'lines' => json_decode((string) $i->lines, true) ?: [],
                    'taxable' => (float) $i->taxable, 'cgst' => (float) $i->cgst, 'sgst' => (float) $i->sgst,
                    'igst' => (float) $i->igst, 'total' => (float) $i->total, 'mode' => $i->gst_mode,
                    'by' => $i->created_by, 'at' => substr((string) $i->created_at, 0, 10),
                ])->values();
            $lastBuyer = DB::table('bank_invoices')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereRaw('LOWER(TRIM(bank)) = ?', [strtolower($bank)])
                ->orderByDesc('id')->first(['buyer_gstin', 'buyer_address']);

            return response()->json($out + [
                'rows' => $rows,
                'annexure' => array_values($agg),
                'totals' => [
                    'entries' => count($rows),
                    'collected' => round($tCollected, 2),
                    'gross' => round($tGross, 2),
                    'tds' => round($tTds, 2),
                    'net' => round($tNet, 2),
                    'netApproved' => round($tNetApproved, 2),
                ],
                'invoices' => $invoices,
                'buyerGstin' => $lastBuyer->buyer_gstin ?? '',
                'buyerAddress' => $lastBuyer->buyer_address ?? '',
                'note' => 'Register = commission entries whose Portfolio / Bank matches "'.$bank.'" for '.$month
                    .'. Rejected entries are listed but excluded from totals. Off-roll agent earnings carry no bank tag and are not included (v1 limit).',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * POST /app/bank-pack/invoice — save a GST service invoice to the bank.
     * Body: bank, month, buyer_gstin?, buyer_address?, lines: [{desc, amount}].
     */
    public function invoiceSave(Request $request)
    {
        if ($deny = $this->guard($request, true)) {
            return $deny;
        }
        try {
            $v = $request->validate([
                'bank' => ['required', 'string', 'max:150'],
                'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
                'buyer_gstin' => ['nullable', 'string', 'max:20'],
                'buyer_address' => ['nullable', 'string', 'max:400'],
                'lines' => ['required', 'array', 'min:1', 'max:20'],
                'lines.*.desc' => ['required', 'string', 'max:200'],
                'lines.*.amount' => ['required', 'numeric', 'min:0'],
            ]);
            $tid = $request->user()->tenant_id;
            self::ensureInvoices();
            $seller = $this->sellerCompany($tid);

            $lines = array_values(array_filter(array_map(fn ($l) => [
                'desc' => trim((string) $l['desc']),
                'amount' => round((float) $l['amount'], 2),
            ], $v['lines']), fn ($l) => $l['desc'] !== '' && $l['amount'] > 0));
            if (! $lines) {
                return response()->json(['ok' => false, 'error' => 'At least one line with a description and a positive amount is required.'], 422);
            }
            $taxable = round(array_sum(array_column($lines, 'amount')), 2);
            $mode = $this->gstMode($seller->gstin ?? null, $v['buyer_gstin'] ?? null);
            $cgst = $mode === 'intra' ? round($taxable * 0.09, 2) : 0.0;
            $sgst = $cgst;
            $igst = $mode === 'inter' ? round($taxable * 0.18, 2) : 0.0;
            $total = round($taxable + $cgst + $sgst + $igst, 2);

            $fy = FinYearController::fyOf($v['month']);
            $seq = (int) DB::table('bank_invoices')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('fin_year', $fy)->count() + 1;
            $no = 'BPK/'.$fy.'/'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

            $id = DB::table('bank_invoices')->insertGetId([
                'tenant_id' => $tid,
                'company_id' => $seller->id ?? null,
                'invoice_no' => $no,
                'bank' => trim($v['bank']),
                'month' => $v['month'],
                'buyer_gstin' => strtoupper(trim((string) ($v['buyer_gstin'] ?? ''))) ?: null,
                'buyer_address' => trim((string) ($v['buyer_address'] ?? '')) ?: null,
                'lines' => json_encode($lines),
                'taxable' => $taxable,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'total' => $total,
                'gst_mode' => $mode,
                'fin_year' => $fy,
                'created_by' => (string) ($request->user()->name ?? $request->user()->email),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            try {
                if (Schema::hasTable('activity_logs')) {
                    DB::table('activity_logs')->insert([
                        'tenant_id' => $tid, 'user_id' => optional($request->user())->id,
                        'action' => 'bank_invoice_created', 'entity' => 'bank_invoices', 'entity_id' => $id,
                        'detail' => json_encode(['no' => $no, 'bank' => $v['bank'], 'month' => $v['month'], 'total' => $total]),
                        'ip' => $request->ip(), 'created_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
            }

            return response()->json(['ok' => true, 'id' => $id, 'no' => $no,
                'taxable' => $taxable, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst,
                'total' => $total, 'mode' => $mode,
                'message' => 'Invoice '.$no.' saved — taxable ₹'.number_format($taxable, 2).' + GST = ₹'.number_format($total, 2)
                    .' ('.($mode === 'intra' ? 'CGST 9% + SGST 9% — same-state GSTINs' : 'IGST 18% — inter-state / GSTIN missing').').',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
