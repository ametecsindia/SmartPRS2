<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real, DB-backed Leave management with a hierarchy-based approval workflow.
 *
 * - Leaves are stored in the normalized `leaves` table (not the prototype state).
 * - On apply, the approver is resolved from the employee's reporting manager
 *   (reporting_manager_id → that employee; else reporting_manager name; else a
 *   company admin/HR). This answers "by whom".
 * - Approve / Reject stamp who decided + when + remarks (audit) via self-creating
 *   columns so it works on the deployed schema.
 * - The Approvals Inbox lists every pending item awaiting the logged-in user.
 *
 * All endpoints fail soft: on error they return JSON with an `error` string
 * (never a raw 500), so the screen never hangs on "Loading…".
 */
class LeaveController extends Controller
{
    /** Legacy native route — superseded by the /app prototype UI. */
    public function index()
    {
        return redirect('/app');
    }

    /** Add approval-audit columns to `leaves` if the deployed table lacks them. */
    private function ensureCols(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }
        $strs = [];
        foreach (['type_name', 'approver_name', 'decided_by', 'remarks'] as $c) {
            if (! Schema::hasColumn('leaves', $c)) {
                $strs[] = $c;
            }
        }
        $needDecidedAt = ! Schema::hasColumn('leaves', 'decided_at');
        if (! $strs && ! $needDecidedAt) {
            return;
        }
        Schema::table('leaves', function (Blueprint $t) use ($strs, $needDecidedAt) {
            foreach ($strs as $c) {
                $t->string($c)->nullable();
            }
            if ($needDecidedAt) {
                $t->timestamp('decided_at')->nullable();
            }
        });
    }

    /**
     * The employee row matching the logged-in user. Prefers the real
     * users.employee_id link; falls back to email, then name, for legacy users.
     */
    private function currentEmployee(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (! empty($user->employee_id)) {
            $byId = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($byId) {
                return $byId;
            }
        }

        return DB::table('employees')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')
            ->where(function ($q) use ($user) {
                $q->where('email', $user->email)->orWhere('name', $user->name);
            })->first();
    }

    /** Managers/admins/HR can decide on anyone; others only on items assigned to them. */
    private function isManagerRole(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    /**
     * Resolve who should approve a given employee's request:
     *   1) reporting_manager_id (FK) → that employee;
     *   2) reporting_manager (name) → matched employee;
     *   3) fallback label so any manager/HR can act.
     * Returns [approver_employee_id|null, approver_name].
     */
    private function resolveApprover($emp, ?int $tenantId): array
    {
        if (! $emp) {
            return [null, ''];
        }
        $a = (array) $emp;
        if (! empty($a['reporting_manager_id'])) {
            $mgr = DB::table('employees')->where('id', $a['reporting_manager_id'])->first();
            if ($mgr) {
                return [(int) $mgr->id, $mgr->name];
            }
        }
        if (! empty($a['reporting_manager'])) {
            $mgr = DB::table('employees')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('name', $a['reporting_manager'])->first();

            return [$mgr ? (int) $mgr->id : null, $a['reporting_manager']];
        }

        return [null, 'Reporting Manager / HR'];
    }

    /** List leaves for the tenant, with approver + decision audit + canDecide flag. */
    public function listLeaves(Request $request)
    {
        try {
            $this->ensureCols();
            $tenantId = $request->user()->tenant_id;
            $me = $this->currentEmployee($request);
            $myId = $me->id ?? null;
            $manager = $this->isManagerRole($request);
            // rev184 (Ejaz) — an Employee login NEVER gets approve/reject, even if
            // some record names them as approver; they see only their own leaves.
            $plainEmp = $request->user()->hasRole('employee');

            $rows = DB::table('leaves as l')
                ->leftJoin('employees as e', 'e.id', '=', 'l.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'l.company_id')
                ->when($tenantId, fn ($q) => $q->where('l.tenant_id', $tenantId))
                ->when(! $manager, fn ($q) => $q->where('l.employee_id', (int) ($myId ?? -1)))
                ->orderByDesc('l.id')
                ->get(['l.*', 'e.name as emp_name', 'e.emp_code', 'c.name as company_name']);

            $out = $rows->map(function ($r) use ($myId, $manager, $plainEmp) {
                $pending = $r->status === 'pending';
                $canDecide = $pending && ! $plainEmp && ($manager || ($myId && (int) $r->approver_id === (int) $myId));

                return [
                    'id' => $r->id,
                    'employee' => $r->emp_name ?: ('#'.$r->employee_id),
                    'empCode' => $r->emp_code ?? '',
                    'company' => $r->company_name ?? '',
                    'type' => $r->type_name ?? '',
                    'from' => $r->from_date,
                    'to' => $r->to_date,
                    'days' => (float) $r->days,
                    'reason' => $r->reason ?? '',
                    'status' => ucfirst($r->status),
                    'approver' => $r->approver_name ?? '',
                    'decidedBy' => $r->decided_by ?? '',
                    'decidedAt' => $r->decided_at ? Carbon::parse($r->decided_at)->format('d M Y H:i') : '',
                    'remarks' => $r->remarks ?? '',
                    'canDecide' => $canDecide,
                ];
            })->values();

            // rev173f — the tenant's OWN leave types for the Apply form's dropdown
            // (falls back to the standard five when none are configured yet).
            $typeNames = [];
            try {
                if (Schema::hasTable('leave_types')) {
                    $typeNames = DB::table('leave_types')
                        ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                        ->orderBy('name')->pluck('name')->filter()->values()->all();
                }
            } catch (\Throwable $e) {
                $typeNames = [];
            }
            if (! $typeNames) {
                $typeNames = ['Casual Leave', 'Sick Leave', 'Earned Leave', 'Comp-Off', 'Loss of Pay'];
            }

            return response()->json(['rows' => $out, 'me' => $me->name ?? $request->user()->name, 'isManager' => $manager, 'types' => $typeNames]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Compute leave balances for one employee for the current calendar year.
     * For each leave type: entitlement (days_per_year) + carry-forward opening
     * − used (approved this year) − pending. Unpaid/Loss-of-Pay types are
     * unlimited (balance null). Returns a list keyed by type name.
     */
    private function computeBalances($emp, ?int $tenantId): array
    {
        $year = now()->year;
        $types = DB::table('leave_types')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get();
        // If no types are defined yet, fall back to sensible defaults so the
        // screen is still useful.
        if ($types->isEmpty()) {
            $types = collect([
                (object) ['name' => 'Casual Leave', 'days_per_year' => 12, 'carry_forward' => 0, 'paid' => 1],
                (object) ['name' => 'Sick Leave', 'days_per_year' => 12, 'carry_forward' => 0, 'paid' => 1],
                (object) ['name' => 'Earned Leave', 'days_per_year' => 15, 'carry_forward' => 1, 'paid' => 1],
                (object) ['name' => 'Comp-Off', 'days_per_year' => 0, 'carry_forward' => 1, 'paid' => 1],
                (object) ['name' => 'Loss of Pay', 'days_per_year' => 0, 'carry_forward' => 0, 'paid' => 0],
            ]);
        }

        // Approved + pending days this year grouped by type_name for this emp.
        $taken = [];
        if ($emp && Schema::hasTable('leaves')) {
            $rows = DB::table('leaves')
                ->where('employee_id', $emp->id)
                ->whereYear('from_date', $year)
                ->get(['type_name', 'days', 'status']);
            foreach ($rows as $r) {
                $t = $r->type_name ?: 'Leave';
                $taken[$t] = $taken[$t] ?? ['used' => 0.0, 'pending' => 0.0];
                if ($r->status === 'approved') {
                    $taken[$t]['used'] += (float) $r->days;
                } elseif ($r->status === 'pending') {
                    $taken[$t]['pending'] += (float) $r->days;
                }
            }
        }

        $out = [];
        foreach ($types as $t) {
            $paid = (int) ($t->paid ?? 1) === 1;
            $ent = (float) ($t->days_per_year ?? 0);
            $u = $taken[$t->name]['used'] ?? 0.0;
            $p = $taken[$t->name]['pending'] ?? 0.0;
            $out[] = [
                'type' => $t->name,
                'paid' => $paid,
                'entitlement' => $ent,
                'used' => $u,
                'pending' => $p,
                'balance' => $paid ? round($ent - $u, 1) : null,   // null = unlimited
            ];
        }

        return $out;
    }

    /** Balances for one employee (by name / emp_code / id) — for the apply form. */
    public function balances(Request $request, string $employee)
    {
        try {
            $tenantId = $request->user()->tenant_id;
            $emp = DB::table('employees')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('name', $employee)->orWhere('emp_code', $employee)->orWhere('id', is_numeric($employee) ? (int) $employee : 0))
                ->first();
            if (! $emp) {
                return response()->json(['rows' => [], 'error' => 'Employee not found']);
            }

            return response()->json(['rows' => $this->computeBalances($emp, $tenantId), 'employee' => $emp->name]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Apply for leave — resolves the approver from the employee's hierarchy. */
    public function apply(Request $request)
    {
        try {
            $this->ensureCols();
            $user = $request->user();
            $tenantId = $user->tenant_id ?? DB::table('tenants')->value('id');

            $v = $request->validate([
                'employee' => ['nullable', 'string'],   // rev184b — employee logins are forced to self below; a stale client posting blank must not die in validation
                'type' => ['nullable', 'string', 'max:60'],
                'from' => ['required', 'string'],
                'to' => ['required', 'string'],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            // rev173f — TOLERANT employee resolution. The old exact-match broke on
            // (a) names with double/trailing spaces (the HTML dropdown collapses
            // whitespace, so the posted name differed from the DB), and (b) installs
            // where users have no tenant_id but the fallback picked the first tenant
            // while employee rows carry NULL. Scope like the /app/data feed does
            // (only the USER's tenant, no fallback), try exact code/name, then a
            // whitespace/case-normalised name comparison.
            // rev184b (Ejaz) — an Employee login always applies for THEMSELVES,
            // whatever the client posted (the UI locks the field; this makes the
            // server safe too and fixes the blank-dropdown submit failure).
            if ($user->hasRole('employee')) {
                $selfRow = $this->currentEmployee($request);
                if ($selfRow) {
                    $v['employee'] = (string) (($selfRow->emp_code ?? '') !== '' ? $selfRow->emp_code : $selfRow->name);
                }
            }
            if (trim((string) ($v['employee'] ?? '')) === '') {
                return response()->json(['ok' => false, 'error' => 'Pick the employee this leave is for.'], 422);
            }
            $needle = trim((string) $v['employee']);
            $userTid = $user->tenant_id;
            $empBase = fn () => DB::table('employees')
                ->when($userTid, fn ($q) => $q->where('tenant_id', $userTid))
                ->whereNull('deleted_at');
            $emp = $empBase()
                ->where(function ($q) use ($needle) {
                    $q->where('emp_code', $needle)->orWhere('name', $needle);
                })->first();
            if (! $emp) {
                $norm = fn ($s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $s)));
                $want = $norm($needle);
                foreach ($empBase()->get() as $cand) {   // full rows — column list varies by install
                    if ($norm($cand->name) === $want || $norm($cand->emp_code) === $want) {
                        $emp = $cand;
                        break;
                    }
                }
            }
            if (! $emp) {
                return response()->json(['ok' => false, 'error' => 'Employee not found: '.$v['employee'].'. Refresh the page (Ctrl+F5) and pick from the list again — if it repeats, the employee record may be deleted or under another company login.'], 422);
            }

            try {
                $from = Carbon::parse($v['from']);
                $to = Carbon::parse($v['to']);
            } catch (\Exception $e) {
                return response()->json(['ok' => false, 'error' => 'Invalid dates'], 422);
            }
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }
            $days = $from->diffInDays($to) + 1;

            // Enforce balance for paid/limited leave types (skip unpaid/Loss of Pay).
            $typeName = $v['type'] ?? 'Leave';
            $bal = collect($this->computeBalances($emp, $tenantId))->firstWhere('type', $typeName);
            if ($bal && $bal['paid'] && $bal['balance'] !== null) {
                // rev173f — a PAID type whose yearly quota was never set (days/year
                // 0 or blank) blocked EVERY application with a cryptic "Available: 0".
                // Name the real problem so the admin can fix it in one step.
                if (($bal['entitlement'] ?? 0) <= 0) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'The leave type "'.$typeName.'" has no yearly quota configured (days/year is 0). An admin should set it under Leave → Leave Types — or apply as Loss of Pay.',
                    ], 422);
                }
                $remaining = $bal['balance'] - $bal['pending'];
                if ($days > $remaining) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'Insufficient '.$typeName.' balance. Available: '.max(0, $remaining).' day(s), requested: '.$days.'. Use Loss of Pay for the excess.',
                    ], 422);
                }
            }

            [$approverId, $approverName] = $this->resolveApprover($emp, $tenantId);

            $row = [
                'tenant_id' => $emp->tenant_id,
                'company_id' => $emp->company_id,
                'employee_id' => $emp->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'days' => $days,
                'status' => 'pending',
                'approver_id' => $approverId,
                'reason' => $v['reason'] ?? null,
                'type_name' => $v['type'] ?? 'Leave',
                'approver_name' => $approverName,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $row = array_intersect_key($row, array_flip(Schema::getColumnListing('leaves')));

            $leaveId = DB::table('leaves')->insertGetId($row);

            \App\Services\Audit::record($emp->tenant_id ? (int) $emp->tenant_id : null, optional($request->user())->id, 'submit', 'leave', (int) $leaveId, ['type' => $v['type'] ?? 'Leave', 'days' => $days], $request->ip());

            return response()->json(['ok' => true, 'approver' => $approverName]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Approve or reject a leave — records who decided, when, and remarks. */
    public function decide(Request $request, int $id)
    {
        try {
            $this->ensureCols();
            $v = $request->validate([
                'action' => ['required', 'in:approve,reject'],
                'remarks' => ['nullable', 'string', 'max:500'],
            ]);
            $tenantId = $request->user()->tenant_id;
            $leave = DB::table('leaves')->where('id', $id)
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->first();
            if (! $leave) {
                return response()->json(['ok' => false, 'error' => 'Leave not found'], 404);
            }

            // rev184 (Ejaz) — Employee logins can never decide leaves.
            if ($request->user()->hasRole('employee')) {
                return response()->json(['ok' => false, 'error' => 'Leave approvals are not available on an employee login.'], 403);
            }
            $me = $this->currentEmployee($request);
            $myId = $me->id ?? null;
            $allowed = $this->isManagerRole($request) || ($myId && (int) $leave->approver_id === (int) $myId);
            if (! $allowed) {
                return response()->json(['ok' => false, 'error' => 'You are not the approver for this request.'], 403);
            }

            $update = [
                'status' => $v['action'] === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $me->name ?? $request->user()->name,
                'decided_at' => now(),
                'remarks' => $v['remarks'] ?? null,
                'updated_at' => now(),
            ];
            $update = array_intersect_key($update, array_flip(Schema::getColumnListing('leaves')));

            DB::table('leaves')->where('id', $id)->update($update);

            \App\Services\Audit::record($tenantId ? (int) $tenantId : null, optional($request->user())->id, $update['status'], 'leave', (int) $id, ['remarks' => $v['remarks'] ?? null], $request->ip());

            // Notify the employee of the leave decision. Fail-soft.
            try {
                $emp = DB::table('employees')->where('id', $leave->employee_id)->first();
                if ($emp && ! empty($emp->email)) {
                    $decided = $update['status'];
                    $lines = [
                        'Type' => $leave->type_name ?? 'Leave',
                        'Dates' => ($leave->from_date ?? '').' to '.($leave->to_date ?? ''),
                        'Days' => (string) ((float) ($leave->days ?? 0)),
                        'Decision' => ucfirst($decided),
                        'Decided by' => $me->name ?? $request->user()->name,
                    ];
                    if (! empty($v['remarks'])) {
                        $lines['Remarks'] = $v['remarks'];
                    }
                    \App\Services\MailService::queue([
                        'tenant_id' => $emp->tenant_id,
                        'company_id' => $emp->company_id,
                        'to' => $emp->email,
                        'to_name' => $emp->name,
                        'subject' => 'Leave '.$decided,
                        'heading' => 'Your leave request was '.$decided,
                        'intro' => 'Your leave request has been '.$decided.'.',
                        'lines' => $lines,
                        'kind' => 'leave.'.$decided,
                    ]);
                }
            } catch (\Throwable $e) {
                // best-effort
            }

            return response()->json(['ok' => true, 'status' => $update['status']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Approvals Inbox — everything pending that awaits the logged-in user. */
    public function inbox(Request $request)
    {
        try {
            $this->ensureCols();
            $tenantId = $request->user()->tenant_id;
            $me = $this->currentEmployee($request);
            $myId = $me->id ?? null;
            $manager = $this->isManagerRole($request);

            $q = DB::table('leaves as l')
                ->leftJoin('employees as e', 'e.id', '=', 'l.employee_id')
                ->when($tenantId, fn ($qq) => $qq->where('l.tenant_id', $tenantId))
                ->where('l.status', 'pending');
            if (! $manager) {
                $q->where('l.approver_id', $myId ?? -1);
            }
            $rows = $q->orderByDesc('l.id')->get(['l.*', 'e.name as emp_name', 'e.emp_code']);

            $items = $rows->map(fn ($r) => [
                'kind' => 'Leave',
                'id' => $r->id,
                'employee' => $r->emp_name ?: ('#'.$r->employee_id),
                'detail' => ($r->type_name ?? 'Leave').' · '.$r->from_date.' → '.$r->to_date.' ('.((float) $r->days).' day/s)',
                'reason' => $r->reason ?? '',
                'approver' => $r->approver_name ?? '',
            ])->values();

            return response()->json(['items' => $items, 'count' => $items->count(), 'isManager' => $manager]);
        } catch (\Throwable $e) {
            return response()->json(['items' => [], 'count' => 0, 'error' => $e->getMessage()]);
        }
    }
}
