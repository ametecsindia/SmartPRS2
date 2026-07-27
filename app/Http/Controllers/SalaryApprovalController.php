<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salary run approval — TWO-STEP: HR approval → Finance approval.
 *
 * payroll_runs.status flow:
 *   draft  → hr_approved  → approved (final)   [or]  rejected
 *
 * - HR step: an hr_manager/admin/super_admin moves draft → hr_approved.
 * - Finance step: an admin/super_admin (Finance authority) moves
 *   hr_approved → approved (locks the run).
 * - Reject (either step) → rejected.
 * - Individual AND bulk (multi-select) approve are supported; bulk applies the
 *   next legal step to each selected run the user is authorised for.
 *
 * Audit columns (hr_by/hr_at/fin_by/fin_at/remarks) are self-creating so this
 * works on the deployed schema. Every endpoint fails soft (JSON {error}).
 */
class SalaryApprovalController extends Controller
{
    private function ensureCols(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            return;
        }
        $strs = [];
        foreach (['hr_by', 'fin_by', 'remarks'] as $c) {
            if (! Schema::hasColumn('payroll_runs', $c)) {
                $strs[] = $c;
            }
        }
        $ts = [];
        foreach (['hr_at', 'fin_at'] as $c) {
            if (! Schema::hasColumn('payroll_runs', $c)) {
                $ts[] = $c;
            }
        }
        if (! $strs && ! $ts) {
            return;
        }
        Schema::table('payroll_runs', function (Blueprint $t) use ($strs, $ts) {
            foreach ($strs as $c) {
                $t->string($c)->nullable();
            }
            foreach ($ts as $c) {
                $t->timestamp($c)->nullable();
            }
        });
    }

    private function isHR(Request $request): bool
    {
        return $request->user()->hasAnyRole(['hr_manager', 'admin', 'super_admin']);
    }

    /** Finance authority = admin / super_admin (HR-only cannot do the finance step). */
    private function isFinance(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Per-EMPLOYEE salary-line lifecycle columns on `payslips` (self-creating).
     * Each line moves: pending → (on_hold / in_review) → approved → disbursed →
     * acknowledged   [or]   rejected.
     */
    private function ensureLineCols(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }
        $strs = ['line_status', 'line_remarks', 'held_by', 'reviewed_by', 'approved_by',
            'disbursed_by', 'ack_name', 'ack_by', 'ack_ip'];
        $tss = ['held_at', 'reviewed_at', 'approved_at', 'disbursed_at', 'ack_at', 'bank_filed_at'];
        $missStr = array_values(array_filter($strs, fn ($c) => ! Schema::hasColumn('payslips', $c)));
        $missTs = array_values(array_filter($tss, fn ($c) => ! Schema::hasColumn('payslips', $c)));
        if (! $missStr && ! $missTs) {
            return;
        }
        Schema::table('payslips', function (Blueprint $t) use ($missStr, $missTs) {
            foreach ($missStr as $c) {
                $t->string($c)->nullable();
            }
            foreach ($missTs as $c) {
                $t->timestamp($c)->nullable();
            }
        });
    }

    /** Human label for a per-line status. */
    private function lineLabel(?string $s): string
    {
        return [
            'pending' => 'Prepared — awaiting HR',
            'on_hold' => 'On hold',
            'in_review' => 'In review',
            'approved' => 'Approved — awaiting disbursement',
            'disbursed' => 'Disbursed — awaiting acknowledgement',
            'acknowledged' => 'Acknowledged (signed)',
            'rejected' => 'Rejected',
        ][$s ?: 'pending'] ?? ucfirst((string) $s);
    }

    /** Which line actions the given user may take on a line in $status. */
    private function lineActionsFor(?string $status, bool $hr, bool $fin, bool $mine): array
    {
        $s = $status ?: 'pending';
        $a = [];
        if ($hr && in_array($s, ['pending', 'on_hold', 'in_review'], true)) {
            $a[] = 'approve';
            $a[] = 'reject';
            if ($s !== 'on_hold') {
                $a[] = 'hold';
            }
            if ($s !== 'in_review') {
                $a[] = 'review';
            }
        }
        if ($fin && $s === 'approved') {
            $a[] = 'disburse';
        }
        if ($mine && $s === 'disbursed') {
            $a[] = 'acknowledge';
        }

        return $a;
    }

    /**
     * Resolve the Employee id for the current user. Prefers the real
     * users.employee_id link; falls back to email, then name, for legacy users
     * whose account was never linked to an employee record.
     */
    private function currentEmployeeId(Request $request): ?int
    {
        $u = $request->user();
        if (! empty($u->employee_id)) {
            return (int) $u->employee_id;
        }
        $tid = $u->tenant_id;
        $q = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid));
        $emp = (clone $q)->where('email', $u->email)->first();
        if (! $emp && $u->name) {
            $emp = (clone $q)->where('name', $u->name)->first();
        }

        return $emp->id ?? null;
    }

    /** What's the next legal step for a run, given its status? */
    private function nextStep(string $status): ?string
    {
        if ($status === 'draft') {
            return 'hr';       // HR approval
        }
        if ($status === 'hr_approved') {
            return 'finance';  // Finance approval (final)
        }

        return null;           // approved / rejected / paid → nothing pending
    }

    private function statusLabel(string $s): string
    {
        return [
            'draft' => 'Draft — awaiting HR',
            'hr_approved' => 'HR approved — awaiting Finance',
            'approved' => 'Approved (final)',
            'paid' => 'Paid',
            'rejected' => 'Rejected',
        ][$s] ?? ucfirst($s);
    }

    /** List salary runs with two-step state + what the current user may do. */
    public function listRuns(Request $request)
    {
        try {
            $this->ensureCols();
            $tid = $request->user()->tenant_id;
            $hr = $this->isHR($request);
            $fin = $this->isFinance($request);

            $rows = DB::table('payroll_runs as r')
                ->leftJoin('companies as c', 'c.id', '=', 'r.company_id')
                ->when($tid, fn ($q) => $q->where('r.tenant_id', $tid))
                ->orderByDesc('r.id')
                ->get(['r.*', 'c.name as company_name']);

            $out = $rows->map(function ($r) use ($hr, $fin) {
                $a = (array) $r;
                $status = $a['status'] ?? 'draft';
                $next = $this->nextStep($status);
                $canHR = $next === 'hr' && $hr;
                $canFin = $next === 'finance' && $fin;

                return [
                    'id' => $a['id'],
                    'company' => $a['company_name'] ?? '',
                    'cycle' => $a['cycle_label'] ?? '',
                    'payDate' => ! empty($a['pay_date']) ? Carbon::parse($a['pay_date'])->format('d M Y') : '',
                    'employees' => (int) ($a['employees_count'] ?? 0),
                    'net' => (float) ($a['net_total'] ?? 0),
                    'status' => $status,
                    'statusLabel' => $this->statusLabel($status),
                    'hrBy' => $a['hr_by'] ?? '',
                    'hrAt' => ! empty($a['hr_at']) ? Carbon::parse($a['hr_at'])->format('d M Y H:i') : '',
                    'finBy' => $a['fin_by'] ?? '',
                    'finAt' => ! empty($a['fin_at']) ? Carbon::parse($a['fin_at'])->format('d M Y H:i') : '',
                    'remarks' => $a['remarks'] ?? '',
                    'next' => $next,            // 'hr' | 'finance' | null
                    'canApprove' => $canHR || $canFin,
                    'canReject' => ($next !== null) && ($canHR || $canFin),
                ];
            })->values();

            return response()->json(['rows' => $out, 'isHR' => $hr, 'isFinance' => $fin]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Per-employee salary sheet for ONE payroll run — the prepared salaries that
     * make up the run, so an approver sees every employee's gross/deductions/net
     * before approving. Reuses the normalized `payslips` rows for that run.
     */
    public function sheet(Request $request, int $id)
    {
        try {
            $this->ensureLineCols();
            $tid = $request->user()->tenant_id;
            $hr = $this->isHR($request);
            $fin = $this->isFinance($request);
            $meId = $this->currentEmployeeId($request);
            $run = DB::table('payroll_runs')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $run) {
                return response()->json(['rows' => [], 'error' => 'Run not found']);
            }

            $cols = ['p.id', 'p.employee_id', 'p.gross', 'p.total_ded', 'p.net', 'p.earnings', 'p.deductions',
                'p.line_status', 'p.line_remarks', 'p.approved_by', 'p.approved_at',
                'p.disbursed_by', 'p.disbursed_at', 'p.ack_name', 'p.ack_at',
                'e.emp_code', 'e.name'];

            $q = DB::table('payslips as p')
                ->join('employees as e', 'e.id', '=', 'p.employee_id')
                ->where('p.run_id', $id)
                // rev165 SECURITY: scope to the run's tenant + company so a user
                // with a null tenant_id can never read another tenant's payslip
                // lines (defence-in-depth; mirrors the fallback query below).
                ->where('p.tenant_id', $run->tenant_id)
                ->where('p.company_id', $run->company_id)
                ->orderBy('e.emp_code');
            $rows = $q->get($cols);

            // Fallback: older runs may have payslips keyed by month, not run_id.
            if ($rows->isEmpty()) {
                $rows = DB::table('payslips as p')
                    ->join('employees as e', 'e.id', '=', 'p.employee_id')
                    ->where('p.tenant_id', $run->tenant_id)
                    ->where('p.company_id', $run->company_id)
                    ->where('p.month', $run->cycle_label)
                    ->orderBy('e.emp_code')
                    ->get($cols);
            }

            $out = $rows->map(function ($p) use ($hr, $fin, $meId) {
                $earn = json_decode($p->earnings ?? '{}', true) ?: [];
                $ded = json_decode($p->deductions ?? '{}', true) ?: [];
                $st = $p->line_status ?: 'pending';
                $mine = $meId && (int) $p->employee_id === (int) $meId;
                $stamp = '';
                if ($st === 'acknowledged' && $p->ack_at) {
                    $stamp = ($p->ack_name ?: 'Employee').' · '.Carbon::parse($p->ack_at)->format('d M Y H:i');
                } elseif ($st === 'disbursed' && $p->disbursed_by) {
                    $stamp = $p->disbursed_by.($p->disbursed_at ? ' · '.Carbon::parse($p->disbursed_at)->format('d M Y H:i') : '');
                } elseif ($st === 'approved' && $p->approved_by) {
                    $stamp = $p->approved_by.($p->approved_at ? ' · '.Carbon::parse($p->approved_at)->format('d M Y H:i') : '');
                }

                return [
                    'id' => (int) $p->id,
                    'code' => $p->emp_code,
                    'name' => $p->name,
                    'basic' => (float) ($earn['Basic'] ?? 0),
                    'hra' => (float) ($earn['HRA'] ?? 0),
                    'pf' => (float) ($ded['PF'] ?? 0),
                    'esi' => (float) ($ded['ESI'] ?? 0),
                    'pt' => (float) ($ded['Professional Tax'] ?? 0),
                    'tds' => (float) ($ded['TDS'] ?? 0),
                    'gross' => (float) $p->gross,
                    'ded' => (float) $p->total_ded,
                    'net' => (float) $p->net,
                    'lineStatus' => $st,
                    'lineLabel' => $this->lineLabel($st),
                    'lineRemarks' => $p->line_remarks ?? '',
                    'stamp' => $stamp,
                    'mine' => $mine,
                    'actions' => $this->lineActionsFor($st, $hr, $fin, $mine),
                ];
            })->values();

            return response()->json([
                'rows' => $out,
                'cycle' => $run->cycle_label,
                'status' => $this->statusLabel($run->status ?? 'draft'),
                'isHR' => $hr,
                'isFinance' => $fin,
                'totals' => [
                    'gross' => round($out->sum('gross'), 2),
                    'ded' => round($out->sum('ded'), 2),
                    'net' => round($out->sum('net'), 2),
                    'count' => $out->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Bank disbursement (NEFT) file for ONE payroll run.
     *
     * Includes per-employee salary lines that Finance has actioned
     * (line_status approved or disbursed) and that are NOT yet in a bank file
     * (bank_filed_at NULL) — unless ?all=1 (re-download everything actioned).
     * Lines with no bank account / IFSC are SKIPPED and reported separately so
     * bad rows never reach the bank.
     *
     * preview()  → JSON {included[], skipped[], totals} for the confirm dialog.
     * download() → streams a generic NEFT CSV and stamps bank_filed_at.
     */
    private function bankFileQuery(int $runId, $run, bool $all)
    {
        $q = DB::table('payslips as p')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->where('p.run_id', $runId)
            ->whereIn('p.line_status', ['approved', 'disbursed']);
        if (! $all && Schema::hasColumn('payslips', 'bank_filed_at')) {
            $q->whereNull('p.bank_filed_at');
        }

        // Fallback for older runs keyed by month, not run_id.
        if ((clone $q)->count() === 0) {
            $q = DB::table('payslips as p')
                ->join('employees as e', 'e.id', '=', 'p.employee_id')
                ->where('p.tenant_id', $run->tenant_id)
                ->where('p.company_id', $run->company_id)
                ->where('p.month', $run->cycle_label)
                ->whereIn('p.line_status', ['approved', 'disbursed']);
            if (! $all && Schema::hasColumn('payslips', 'bank_filed_at')) {
                $q->whereNull('p.bank_filed_at');
            }
        }

        return $q->orderBy('e.emp_code')
            ->get(['p.id', 'p.net', 'p.line_status', 'e.emp_code', 'e.name', 'e.bank_name', 'e.bank_acc', 'e.ifsc']);
    }

    /**
     * Bank-specific NEFT column layouts on top of the generic one. Returns
     * ['header'=>[...], 'row'=>fn($r,$narration)=>[...]]. These match the common
     * corporate-upload column orders for each bank; tweak to your exact current
     * portal spec if it rejects a column.
     */
    private function bankFormat(string $bank): array
    {
        $amt = fn ($r) => number_format((float) $r->net, 2, '.', '');
        $ifsc = fn ($r) => strtoupper(trim((string) $r->ifsc));
        $bank = strtolower(trim($bank));
        if ($bank === 'icici') {
            return ['header' => ['Beneficiary Name', 'Beneficiary Account No', 'IFSC', 'Amount', 'Payment Mode', 'Employee Code', 'Remarks'],
                'row' => fn ($r, $n) => [$r->name, $r->bank_acc, $ifsc($r), $amt($r), 'NEFT', $r->emp_code, $n]];
        }
        if ($bank === 'hdfc') {
            return ['header' => ['Transaction Type', 'Beneficiary Code', 'Beneficiary Account No', 'Amount', 'Beneficiary Name', 'IFSC', 'Narration'],
                'row' => fn ($r, $n) => ['NEFT', $r->emp_code, $r->bank_acc, $amt($r), $r->name, $ifsc($r), $n]];
        }
        if ($bank === 'sbi') {
            return ['header' => ['Beneficiary Name', 'Beneficiary Account No', 'IFSC', 'Amount', 'Pay Mode', 'Employee Code', 'Remark'],
                'row' => fn ($r, $n) => [$r->name, $r->bank_acc, $ifsc($r), $amt($r), 'NEFT', $r->emp_code, $n]];
        }

        return ['header' => ['Beneficiary Name', 'Account Number', 'IFSC', 'Amount', 'Transaction Type', 'Employee Code', 'Narration'],
            'row' => fn ($r, $n) => [$r->name, $r->bank_acc, $ifsc($r), $amt($r), 'NEFT', $r->emp_code, $n]];
    }

    /** Split rows into bank-payable vs skipped (missing account / IFSC). */
    private function bankFileSplit($rows): array
    {
        $included = [];
        $skipped = [];
        foreach ($rows as $r) {
            $acc = trim((string) $r->bank_acc);
            $ifsc = trim((string) $r->ifsc);
            if ($acc === '' || $ifsc === '') {
                $skipped[] = ['code' => $r->emp_code, 'name' => $r->name, 'reason' => $acc === '' ? 'No account number' : 'No IFSC'];
            } else {
                $included[] = $r;
            }
        }

        return [$included, $skipped];
    }

    /** Preview what a bank file would contain (for the confirm dialog). */
    public function bankFilePreview(Request $request, int $id)
    {
        try {
            $this->ensureLineCols();
            $tid = $request->user()->tenant_id;
            $all = (bool) $request->query('all', 0);
            $run = DB::table('payroll_runs')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $run) {
                return response()->json(['error' => 'Run not found']);
            }
            [$included, $skipped] = $this->bankFileSplit($this->bankFileQuery($id, $run, $all));
            $total = array_reduce($included, fn ($s, $r) => $s + (float) $r->net, 0.0);

            return response()->json([
                'cycle' => $run->cycle_label,
                'includedCount' => count($included),
                'skippedCount' => count($skipped),
                'skipped' => $skipped,
                'total' => round($total, 2),
                'canDownload' => count($included) > 0,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    /** Stream the generic NEFT CSV and stamp the included lines as filed. */
    public function bankFile(Request $request, int $id)
    {
        $this->ensureLineCols();
        $tid = $request->user()->tenant_id;
        $all = (bool) $request->query('all', 0);
        $run = DB::table('payroll_runs')->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
        if (! $run) {
            return response('Run not found', 404)->header('Content-Type', 'text/plain');
        }
        if (! $this->isFinance($request)) {
            return response('Only Finance/Admin can generate the bank file.', 403)->header('Content-Type', 'text/plain');
        }

        [$included, $skipped] = $this->bankFileSplit($this->bankFileQuery($id, $run, $all));
        if (empty($included)) {
            return response('No payable salary lines for this run (all are either unapproved, already in a bank file, or missing bank details).', 422)
                ->header('Content-Type', 'text/plain');
        }

        $company = DB::table('companies')->where('id', $run->company_id)->value('name');
        $cycle = $run->cycle_label;
        $narrationBase = 'SALARY '.$cycle.($company ? ' '.strtoupper(substr(preg_replace('/[^A-Za-z0-9 ]/', '', $company), 0, 18)) : '');

        // Bank-specific (or generic) NEFT CSV layout.
        $bank = (string) $request->query('bank', 'generic');
        $fmt = $this->bankFormat($bank);
        $esc = function ($v) {
            $v = (string) $v;

            return (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n"))
                ? '"'.str_replace('"', '""', $v).'"'
                : $v;
        };
        $lines = [implode(',', array_map($esc, $fmt['header']))];
        $ids = [];
        $disburseIds = [];
        $total = 0.0;
        foreach ($included as $r) {
            $total += (float) $r->net;
            $ids[] = $r->id;
            if (($r->line_status ?? '') === 'approved') {
                $disburseIds[] = $r->id;   // bank file = disbursement → flip to "paid"
            }
            $lines[] = implode(',', array_map($esc, $fmt['row']($r, $narrationBase)));
        }
        // Trailer summary row (commented with #, ignored by most parsers; remove if your bank rejects it).
        $lines[] = '# TOTAL,'.count($included).' beneficiaries,,'.number_format($total, 2, '.', '').',,,';

        // Stamp the included lines as filed, and auto-flip approved → disbursed
        // (the bank file IS the disbursement), unless this is a re-download (?all=1).
        if (! $all) {
            if (Schema::hasColumn('payslips', 'bank_filed_at')) {
                DB::table('payslips')->whereIn('id', $ids)->update(['bank_filed_at' => now(), 'updated_at' => now()]);
            }
            if ($disburseIds) {
                DB::table('payslips')->whereIn('id', $disburseIds)->update(ApprovalService::safeRow('payslips', [
                    'line_status' => 'disbursed', 'disbursed_by' => $request->user()->name, 'disbursed_at' => now(), 'updated_at' => now(),
                ]));
            }
        }

        $bankTag = preg_replace('/[^A-Za-z0-9]/', '', strtolower($bank));
        $fname = 'NEFT-'.($bankTag && $bankTag !== 'generic' ? strtoupper($bankTag).'-' : '').preg_replace('/[^A-Za-z0-9]/', '', (string) $cycle).'-run'.$id.'.csv';
        $csv = implode("\r\n", $lines)."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$fname.'"',
        ]);
    }

    /**
     * Signed salary voucher PDF for one payslip line — with the e-sign
     * acknowledgement block (name + timestamp + IP) when the employee has
     * acknowledged. Available once a line is disbursed/acknowledged.
     */
    public function voucher(Request $request, int $id)
    {
        $this->ensureLineCols();
        $tid = $request->user()->tenant_id;
        $p = DB::table('payslips as p')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->where('p.id', $id)
            ->when($tid, fn ($q) => $q->where('p.tenant_id', $tid))
            ->first(['p.*', 'e.emp_code', 'e.name as emp_name', 'e.bank_name', 'e.bank_acc', 'e.ifsc', 'e.pan', 'e.uan']);
        if (! $p) {
            return response('Voucher not found.', 404)->header('Content-Type', 'text/plain');
        }
        $st = $p->line_status ?? 'pending';
        if (! in_array($st, ['disbursed', 'acknowledged'], true)) {
            return response('Voucher is available only after the salary line is disbursed.', 422)->header('Content-Type', 'text/plain');
        }
        $company = DB::table('companies')->find($p->company_id);
        $brand = [];
        try {
            $brand = ConfigController::brandFor($p->tenant_id, $p->company_id);
        } catch (\Throwable $e) {
            $brand = [];
        }
        $earn = json_decode($p->earnings ?? '{}', true) ?: [];
        $ded = json_decode($p->deductions ?? '{}', true) ?: [];
        $monthLabel = Carbon::parse(($p->month ?: now()->format('Y-m')).'-01')->format('F Y');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('voucher-pdf', compact('p', 'company', 'brand', 'earn', 'ded', 'monthLabel'));

        return $pdf->download('voucher-'.$p->emp_code.'-'.($p->month ?: 'salary').'.pdf');
    }

    /** Apply one per-employee line action (used by individual + bulk). */
    private function applyLineAction(Request $request, $slip, string $action): array
    {
        $st = $slip->line_status ?: 'pending';
        $hr = $this->isHR($request);
        $fin = $this->isFinance($request);
        $name = $request->user()->name;
        $remarks = (string) $request->input('remarks', '');
        $now = now();

        $map = [
            'approve' => ['need' => $hr, 'from' => ['pending', 'on_hold', 'in_review'], 'to' => 'approved', 'by' => 'approved_by', 'at' => 'approved_at', 'err' => 'HR/Admin only'],
            'reject' => ['need' => $hr, 'from' => ['pending', 'on_hold', 'in_review'], 'to' => 'rejected', 'by' => null, 'at' => null, 'err' => 'HR/Admin only'],
            'hold' => ['need' => $hr, 'from' => ['pending', 'in_review'], 'to' => 'on_hold', 'by' => 'held_by', 'at' => 'held_at', 'err' => 'HR/Admin only'],
            'review' => ['need' => $hr, 'from' => ['pending', 'on_hold'], 'to' => 'in_review', 'by' => 'reviewed_by', 'at' => 'reviewed_at', 'err' => 'HR/Admin only'],
            'disburse' => ['need' => $fin, 'from' => ['approved'], 'to' => 'disbursed', 'by' => 'disbursed_by', 'at' => 'disbursed_at', 'err' => 'Finance/Admin only'],
        ];
        if (! isset($map[$action])) {
            return ['ok' => false, 'error' => 'Unknown action'];
        }
        $m = $map[$action];
        if (! $m['need']) {
            return ['ok' => false, 'error' => $m['err'].' — line #'.$slip->id];
        }
        if (! in_array($st, $m['from'], true)) {
            return ['ok' => false, 'error' => 'Cannot '.$action.' a line that is "'.$this->lineLabel($st).'" — #'.$slip->id];
        }
        // rev172 (H4) — a payslip line may be DISBURSED only after its parent run
        // has cleared HR approval. Prevents Finance side-stepping the four-eyes
        // control by disbursing individual lines on a still-draft run.
        if ($action === 'disburse' && ! empty($slip->run_id)) {
            $run = DB::table('payroll_runs')->where('id', $slip->run_id)->first();
            if ($run && ! in_array($run->status, ['hr_approved', 'approved', 'locked', 'paid'], true)) {
                return ['ok' => false, 'error' => 'This run must be HR-approved before any line can be disbursed — #'.$slip->id];
            }
        }
        $row = ['line_status' => $m['to'], 'updated_at' => $now];
        if ($m['by']) {
            $row[$m['by']] = $name;
        }
        if ($m['at']) {
            $row[$m['at']] = $now;
        }
        if ($remarks !== '') {
            $row['line_remarks'] = $remarks;
        }
        DB::table('payslips')->where('id', $slip->id)->update(ApprovalService::safeRow('payslips', $row));

        // Notify the employee when their salary is approved, disbursed, held or
        // rejected. Fail-soft — a mail problem must never fail the action.
        $this->notifyLine($slip, $m['to'], $name, $remarks);

        return ['ok' => true, 'status' => $m['to']];
    }

    /** Queue an email to the affected employee about a line-status change. */
    private function notifyLine($slip, string $status, string $actorName, string $remarks = ''): void
    {
        try {
            $emp = DB::table('employees')->where('id', $slip->employee_id)->first();
            if (! $emp || empty($emp->email)) {
                return;
            }
            $company = DB::table('companies')->where('id', $slip->company_id ?? $emp->company_id)->first();
            $month = $slip->month ?? '';
            $monthLabel = $month ? \Illuminate\Support\Carbon::parse($month.'-01')->format('F Y') : '';
            $net = number_format((float) ($slip->net ?? 0), 2);

            $copy = [
                'approved' => ['Salary approved for '.$monthLabel, 'Your salary has been approved and is pending disbursement.'],
                'disbursed' => ['Salary disbursed for '.$monthLabel, 'Your salary has been disbursed. Please log in to acknowledge receipt and e-sign your voucher.'],
                'on_hold' => ['Salary on hold for '.$monthLabel, 'Your salary for this cycle has been placed on hold. HR will be in touch.'],
                'rejected' => ['Salary update for '.$monthLabel, 'There is an update on your salary for this cycle. Please contact HR.'],
            ];
            if (! isset($copy[$status])) {
                return;   // approved/disbursed/on_hold/rejected are the notify-worthy states
            }
            [$subject, $intro] = $copy[$status];

            $lines = ['Employee' => $emp->name, 'Pay period' => $monthLabel ?: '—', 'Net pay' => '₹'.$net];
            if ($remarks !== '') {
                $lines['Remarks'] = $remarks;
            }

            $payload = [
                'tenant_id' => $emp->tenant_id,
                'company_id' => $slip->company_id ?? $emp->company_id,
                'to' => $emp->email,
                'to_name' => $emp->name,
                'subject' => $subject,
                'heading' => $subject,
                'intro' => $intro,
                'lines' => $lines,
                'body' => $status === 'disbursed' ? 'Your payslip is attached. Acknowledging confirms you have received this salary; the timestamp is recorded as your e-signature.' : '',
                'kind' => 'salary.'.$status,
            ];

            // rev168 (Ejaz): attach the actual payslip PDF when salary is disbursed.
            // Best-effort — a PDF/render problem must never block the notification.
            if ($status === 'disbursed' && $month !== '') {
                try {
                    $pdf = app(\App\Http\Controllers\AppDataController::class)
                        ->payslipPdfString((int) $slip->employee_id, (string) $month);
                    if ($pdf) {
                        $payload['attach_b64'] = base64_encode($pdf);
                        $payload['attach_name'] = 'payslip-'.($emp->emp_code ?? $slip->employee_id).'-'.$month.'.pdf';
                        $payload['attach_mime'] = 'application/pdf';
                    }
                } catch (\Throwable $ex) {
                    // attachment is optional; the email still sends without it
                }
            }

            \App\Services\MailService::queue($payload);
        } catch (\Throwable $e) {
            // notifications are best-effort
        }
    }

    /** Individual per-employee line action. */
    public function lineDecide(Request $request, int $id)
    {
        try {
            $this->ensureLineCols();
            $v = $request->validate([
                'action' => ['required', 'in:approve,reject,hold,review,disburse'],
                'remarks' => ['nullable', 'string', 'max:500'],
            ]);
            $tid = $request->user()->tenant_id;
            $slip = DB::table('payslips')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $slip) {
                return response()->json(['ok' => false, 'error' => 'Salary line not found'], 404);
            }
            $res = $this->applyLineAction($request, $slip, $v['action']);

            return response()->json($res, $res['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Bulk: apply the same line action to many selected lines (skips invalid). */
    public function lineBulk(Request $request)
    {
        try {
            $this->ensureLineCols();
            $v = $request->validate([
                'action' => ['required', 'in:approve,reject,hold,review,disburse'],
                'ids' => ['required', 'array'],
                'ids.*' => ['integer'],
                'remarks' => ['nullable', 'string', 'max:500'],
            ]);
            $tid = $request->user()->tenant_id;
            $slips = DB::table('payslips')->whereIn('id', $v['ids'])
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->get();
            $done = 0;
            $skipped = 0;
            foreach ($slips as $slip) {
                $res = $this->applyLineAction($request, $slip, $v['action']);
                $res['ok'] ? $done++ : $skipped++;
            }

            return response()->json([
                'ok' => true,
                'done' => $done,
                'skipped' => $skipped,
                'message' => $done.' line(s) '.$v['action'].'d'.($skipped ? ', '.$skipped.' skipped (not allowed at their stage)' : ''),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Employee acknowledgement (in-app e-sign) of their own disbursed salary line.
     * Stores the typed name, timestamp and IP as the signature of receipt.
     */
    public function acknowledge(Request $request, int $id)
    {
        try {
            $this->ensureLineCols();
            $v = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'confirm' => ['accepted'],
            ]);
            $tid = $request->user()->tenant_id;
            $slip = DB::table('payslips')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $slip) {
                return response()->json(['ok' => false, 'error' => 'Salary line not found'], 404);
            }
            $meId = $this->currentEmployeeId($request);
            if (! $meId || (int) $slip->employee_id !== (int) $meId) {
                return response()->json(['ok' => false, 'error' => 'You can only acknowledge your own salary.'], 403);
            }
            if (($slip->line_status ?: '') !== 'disbursed') {
                return response()->json(['ok' => false, 'error' => 'This salary is not yet disbursed, or is already acknowledged.'], 422);
            }
            $now = now();
            DB::table('payslips')->where('id', $id)->update(ApprovalService::safeRow('payslips', [
                'line_status' => 'acknowledged',
                'ack_name' => $v['name'],
                'ack_by' => $request->user()->name,
                'ack_at' => $now,
                'ack_ip' => $request->ip(),
                'updated_at' => $now,
            ]));

            // Email the employee a receipt confirmation of their e-signature.
            try {
                $emp = DB::table('employees')->where('id', $slip->employee_id)->first();
                if ($emp && ! empty($emp->email)) {
                    $monthLabel = ! empty($slip->month) ? Carbon::parse($slip->month.'-01')->format('F Y') : '';
                    \App\Services\MailService::queue([
                        'tenant_id' => $emp->tenant_id,
                        'company_id' => $slip->company_id ?? $emp->company_id,
                        'to' => $emp->email,
                        'to_name' => $emp->name,
                        'subject' => 'Salary acknowledgement received'.($monthLabel ? ' — '.$monthLabel : ''),
                        'heading' => 'Acknowledgement recorded',
                        'intro' => 'Thank you. We have recorded your acknowledgement of salary receipt.',
                        'lines' => [
                            'Signed by' => $v['name'],
                            'Pay period' => $monthLabel ?: '-',
                            'Date & time' => $now->format('d M Y H:i'),
                        ],
                        'body' => 'This serves as your e-signed confirmation of receipt. No further action is needed.',
                        'kind' => 'salary.acknowledged',
                    ]);
                }
            } catch (\Throwable $e) {
                // best-effort
            }

            return response()->json(['ok' => true, 'status' => 'acknowledged']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Apply one decision to one run (used by both individual and bulk paths). */
    private function applyDecision(Request $request, $run, string $action, string $remarks): array
    {
        $status = $run->status ?? 'draft';
        $next = $this->nextStep($status);
        $name = $request->user()->name;

        if ($action === 'reject') {
            if ($next === null) {
                return ['ok' => false, 'error' => 'Run #'.$run->id.' is already finalised.'];
            }
            $allowed = ($next === 'hr' && $this->isHR($request)) || ($next === 'finance' && $this->isFinance($request));
            if (! $allowed) {
                return ['ok' => false, 'error' => 'Not authorised to reject run #'.$run->id];
            }
            $upd = ApprovalService::safeRow('payroll_runs', ['status' => 'rejected', 'remarks' => $remarks, 'updated_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->id)->update($upd);

            return ['ok' => true, 'status' => 'rejected'];
        }

        // approve = perform the next legal step
        if ($next === 'hr') {
            if (! $this->isHR($request)) {
                return ['ok' => false, 'error' => 'HR approval needs HR/Admin — run #'.$run->id];
            }
            $upd = ApprovalService::safeRow('payroll_runs', ['status' => 'hr_approved', 'hr_by' => $name, 'hr_at' => now(), 'remarks' => $remarks ?: ($run->remarks ?? null), 'updated_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->id)->update($upd);

            return ['ok' => true, 'status' => 'hr_approved'];
        }
        if ($next === 'finance') {
            if (! $this->isFinance($request)) {
                return ['ok' => false, 'error' => 'Finance approval needs Admin — run #'.$run->id];
            }
            $upd = ApprovalService::safeRow('payroll_runs', ['status' => 'approved', 'fin_by' => $name, 'fin_at' => now(), 'locked_at' => now(), 'remarks' => $remarks ?: ($run->remarks ?? null), 'updated_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->id)->update($upd);

            return ['ok' => true, 'status' => 'approved'];
        }

        return ['ok' => false, 'error' => 'Run #'.$run->id.' has no pending step.'];
    }

    /** Individual approve / reject of one run. */
    public function decide(Request $request, int $id)
    {
        try {
            $this->ensureCols();
            $v = $request->validate([
                'action' => ['required', 'in:approve,reject'],
                'remarks' => ['nullable', 'string', 'max:500'],
            ]);
            $tid = $request->user()->tenant_id;
            $run = DB::table('payroll_runs')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $run) {
                return response()->json(['ok' => false, 'error' => 'Run not found'], 404);
            }
            $res = $this->applyDecision($request, $run, $v['action'], $v['remarks'] ?? '');

            return response()->json($res, $res['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Bulk: apply the next step to each selected run the user is authorised for. */
    public function bulk(Request $request)
    {
        try {
            $this->ensureCols();
            $v = $request->validate([
                'action' => ['required', 'in:approve,reject'],
                'ids' => ['required', 'array'],
                'ids.*' => ['integer'],
                'remarks' => ['nullable', 'string', 'max:500'],
            ]);
            $tid = $request->user()->tenant_id;
            $runs = DB::table('payroll_runs')->whereIn('id', $v['ids'])
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->get();

            $done = 0;
            $skipped = 0;
            $errors = [];
            foreach ($runs as $run) {
                $res = $this->applyDecision($request, $run, $v['action'], $v['remarks'] ?? '');
                if (! empty($res['ok'])) {
                    $done++;
                } else {
                    $skipped++;
                    $errors[] = $res['error'] ?? '';
                }
            }

            return response()->json([
                'ok' => true,
                'done' => $done,
                'skipped' => $skipped,
                'message' => $done.' run(s) updated'.($skipped ? ', '.$skipped.' skipped (not authorised or no pending step)' : ''),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
