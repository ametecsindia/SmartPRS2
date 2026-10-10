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
        foreach (['type_name', 'approver_name', 'decided_by', 'remarks', 'edited_by'] as $c) {
            if (! Schema::hasColumn('leaves', $c)) {
                $strs[] = $c;
            }
        }
        $needDecidedAt = ! Schema::hasColumn('leaves', 'decided_at');
        $needEditedAt = ! Schema::hasColumn('leaves', 'edited_at');   // 30 Sep 2026 — approver date edits
        if (! $strs && ! $needDecidedAt && ! $needEditedAt) {
            return;
        }
        Schema::table('leaves', function (Blueprint $t) use ($strs, $needDecidedAt, $needEditedAt) {
            foreach ($strs as $c) {
                $t->string($c)->nullable();
            }
            if ($needDecidedAt) {
                $t->timestamp('decided_at')->nullable();
            }
            if ($needEditedAt) {
                $t->timestamp('edited_at')->nullable();
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
            $u = $request->user();
            $vis = \App\Services\Hierarchy::visibleIds($u);   // 7 Oct 2026 — own + reporting line (null = all)

            $rows = DB::table('leaves as l')
                ->leftJoin('employees as e', 'e.id', '=', 'l.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'l.company_id')
                ->when($tenantId, fn ($q) => $q->where('l.tenant_id', $tenantId))
                // 30 Sep 2026 (Ejaz) — own leaves + everyone below them in the reporting
                // chain + anything naming them approver, so a bell notification opens to
                // a row they can actually see. Approve/reject rights are unchanged.
                ->when(! $manager && $vis !== null, fn ($q) => $q->where(fn ($w) => $w
                    ->whereIn('l.employee_id', $vis)
                    ->orWhere('l.approver_id', (int) ($myId ?? -1))))
                ->orderByDesc('l.id')
                ->get(['l.*', 'e.name as emp_name', 'e.emp_code', 'c.name as company_name']);

            $out = $rows->map(function ($r) use ($u, $manager) {
                $pending = $r->status === 'pending';
                // 7 Oct 2026 — Admin/HR, the named approver, or ANY manager above the
                // employee in the reporting chain (first decision closes it).
                $canDecide = $pending && \App\Services\Hierarchy::canApprove($u, (int) $r->employee_id, $r->approver_id ? (int) $r->approver_id : null, 'leave');

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
                    'decidedAt' => $r->decided_at ? Carbon::parse($r->decided_at)->format('d-m-Y H:i') : '',
                    'remarks' => $r->remarks ?? '',
                    'canDecide' => $canDecide,
                    // 30 Sep 2026 (Ejaz) — full detail for the View dialog + date edit.
                    'appliedAt' => ! empty($r->created_at) ? Carbon::parse($r->created_at)->format('d-m-Y H:i') : '',
                    'editedBy' => $r->edited_by ?? '',
                    'editedAt' => ! empty($r->edited_at) ? Carbon::parse($r->edited_at)->format('d-m-Y H:i') : '',
                    'canEdit' => $manager && $pending,
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

            // 8 Oct 2026 (Ejaz) — the login's own code + leave balance for the Leave screen card.
            return response()->json(['rows' => $out, 'me' => $me->name ?? $request->user()->name, 'meCode' => $me->emp_code ?? '',
                'myBalances' => $me ? $this->computeBalances($me, $tenantId) : [], 'isManager' => $manager, 'types' => $typeNames,
                'period' => self::leaveYearLabel(self::leaveYearMode($tenantId), self::leaveYearStart(self::leaveYearMode($tenantId), now()))]);   // 10 Oct 2026 — leave-year label
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /*
     | 10 Oct 2026 (Ejaz) — LEAVE YEAR + OPENING BALANCE WITH CARRY FORWARD
     | The leave year is the admin's choice (Leave Balance Report screen):
     | 'calendar' = Jan–Dec (default, the old behaviour) or 'fy' = Apr–Mar.
     | Opening = the type's yearly quota + (carry-forward types only) last
     | year's unused balance. The carry chain starts at the later of the
     | employee's joining year and the tenant's FIRST recorded leave, so years
     | before SmartPRS tracked leave never invent a carried balance.
     */

    /** 'calendar' | 'fy' — stored in settings (key leave_year), default calendar. */
    public static function leaveYearMode(?int $tid): string
    {
        try {
            $v = DB::table('settings')->where('tenant_id', (int) ($tid ?? 0))->where('key', 'leave_year')->value('value');
            $v = trim((string) $v, "\" \t");

            return $v === 'fy' ? 'fy' : 'calendar';
        } catch (\Throwable $e) {
            return 'calendar';
        }
    }

    /** Start date of the leave year that contains $date. */
    public static function leaveYearStart(string $mode, $date): Carbon
    {
        $c = Carbon::parse($date ?: now())->startOfDay();
        if ($mode === 'fy') {
            return Carbon::create($c->month >= 4 ? $c->year : $c->year - 1, 4, 1)->startOfDay();
        }

        return Carbon::create($c->year, 1, 1)->startOfDay();
    }

    /** "2026" or "FY 2026-27" for a leave-year start. */
    public static function leaveYearLabel(string $mode, Carbon $start): string
    {
        return $mode === 'fy' ? 'FY '.$start->year.'-'.substr((string) ($start->year + 1), 2) : (string) $start->year;
    }

    /** The tenant's leave types (or the standard five when none are set up). */
    private function leaveTypes(?int $tenantId)
    {
        $types = Schema::hasTable('leave_types') ? DB::table('leave_types')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get() : collect();
        if ($types->isEmpty()) {
            $types = collect([
                (object) ['name' => 'Casual Leave', 'days_per_year' => 12, 'carry_forward' => 0, 'paid' => 1],
                (object) ['name' => 'Sick Leave', 'days_per_year' => 12, 'carry_forward' => 0, 'paid' => 1],
                (object) ['name' => 'Earned Leave', 'days_per_year' => 15, 'carry_forward' => 1, 'paid' => 1],
                (object) ['name' => 'Comp-Off', 'days_per_year' => 0, 'carry_forward' => 1, 'paid' => 1],
                (object) ['name' => 'Loss of Pay', 'days_per_year' => 0, 'carry_forward' => 0, 'paid' => 0],
            ]);
        }

        return $types;
    }

    /** First recorded leave date for the tenant (the carry chain never starts earlier). */
    private function firstLeaveDate(?int $tenantId): ?string
    {
        static $memo = [];
        $k = (int) $tenantId;
        if (! array_key_exists($k, $memo)) {
            try {
                $memo[$k] = DB::table('leaves')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->min('from_date');
            } catch (\Throwable $e) {
                $memo[$k] = null;
            }
        }

        return $memo[$k];
    }

    /**
     * Pure ledger: balances for the leave year starting $target, given the
     * employee's leave rows (from_date, type_name, days, status). Carry runs
     * from $chainFrom (a leave-year start ≤ $target), at most 10 years.
     */
    public static function ledger($types, array $rows, string $mode, Carbon $target, ?Carbon $chainFrom): array
    {
        $byYear = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            if (empty($r['from_date'])) {
                continue;
            }
            $y = self::leaveYearStart($mode, $r['from_date'])->toDateString();
            $t = $r['type_name'] ?: 'Leave';
            $byYear[$y][$t] = $byYear[$y][$t] ?? ['used' => 0.0, 'pending' => 0.0];
            if (($r['status'] ?? '') === 'approved') {
                $byYear[$y][$t]['used'] += (float) $r['days'];
            } elseif (($r['status'] ?? '') === 'pending') {
                $byYear[$y][$t]['pending'] += (float) $r['days'];
            }
        }
        $start = $chainFrom && $chainFrom->lt($target) ? $chainFrom->copy() : $target->copy();
        $floor = $target->copy()->subYears(9);
        if ($start->lt($floor)) {
            $start = $floor;
        }
        $carry = [];
        $out = [];
        for ($y = $start->copy(); $y->lte($target); $y->addYear()) {
            $ys = $y->toDateString();
            $out = [];
            foreach ($types as $t) {
                $paid = (int) ($t->paid ?? 1) === 1;
                $quota = (float) ($t->days_per_year ?? 0);
                $cf = $paid && (int) ($t->carry_forward ?? 0) === 1 ? max(0.0, (float) ($carry[$t->name] ?? 0)) : 0.0;
                $u = (float) ($byYear[$ys][$t->name]['used'] ?? 0);
                $p = (float) ($byYear[$ys][$t->name]['pending'] ?? 0);
                $opening = round($quota + $cf, 1);
                $out[] = [
                    'type' => $t->name,
                    'paid' => $paid,
                    'entitlement' => $quota,
                    'carried' => round($cf, 1),
                    'opening' => $paid ? $opening : null,
                    'used' => $u,
                    'pending' => $p,
                    'balance' => $paid ? round($opening - $u, 1) : null,   // null = unlimited
                ];
                $carry[$t->name] = $opening - $u;
            }
        }

        return $out;
    }

    /**
     * Leave balances for one employee for the leave year containing $asOf
     * (today by default). Each type: quota (entitlement) + carried = opening,
     * − used (approved) = balance; pending shown separately. Unpaid / Loss of
     * Pay types are unlimited (balance null).
     */
    private function computeBalances($emp, ?int $tenantId, $asOf = null): array
    {
        $mode = self::leaveYearMode($tenantId);
        $target = self::leaveYearStart($mode, $asOf ?: now());
        $rows = [];
        if ($emp && Schema::hasTable('leaves')) {
            $rows = DB::table('leaves')->where('employee_id', $emp->id)
                ->where('from_date', '<=', $target->copy()->addYear()->toDateString())
                ->get(['from_date', 'type_name', 'days', 'status'])->all();
        }

        return self::ledger($this->leaveTypes($tenantId), $rows, $mode, $target, $this->chainFrom($emp, $tenantId, $mode));
    }

    /** Where the carry-forward chain starts for this employee (see the note above). */
    private function chainFrom($emp, ?int $tenantId, string $mode): ?Carbon
    {
        $first = $this->firstLeaveDate($tenantId);
        if (! $first) {
            return null;
        }
        $from = self::leaveYearStart($mode, $first);
        $doj = $emp->doj ?? null;
        if ($doj) {
            try {
                $j = self::leaveYearStart($mode, $doj);
                if ($j->gt($from)) {
                    $from = $j;
                }
            } catch (\Throwable $e) {
            }
        }

        return $from;
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
            // 7 Oct 2026 — own balance or a reportee's only (hierarchy scope).
            $vis = \App\Services\Hierarchy::visibleIds($request->user());
            if ($vis !== null && ! in_array((int) $emp->id, $vis, true)) {
                return response()->json(['rows' => [], 'error' => 'Not in your reporting line.'], 403);
            }

            // 8 Oct 2026 (Ejaz) — ?from=&to= also returns the leave-day count the apply
            // form shows (same rule as apply(): weekly offs + holidays are not leave days).
            $days = null;
            if ($request->filled('from') && $request->filled('to')) {
                try {
                    $f = Carbon::parse((string) $request->query('from'));
                    $t = Carbon::parse((string) $request->query('to'));
                    if ($t->lt($f)) {
                        [$f, $t] = [$t, $f];
                    }
                    $days = \App\Services\WorkCalendar::workingDays($f->toDateString(), $t->toDateString(), $emp->tenant_id ? (int) $emp->tenant_id : null, $emp);
                } catch (\Throwable $e) {
                    $days = null;
                }
            }

            $asOf = $request->filled('from') ? (string) $request->query('from') : null;   // 10 Oct 2026 — the leave year of the From date
            $mode = self::leaveYearMode($tenantId);
            try {
                $period = self::leaveYearLabel($mode, self::leaveYearStart($mode, $asOf ?: now()));
            } catch (\Throwable $e) {
                $asOf = null;
                $period = self::leaveYearLabel($mode, self::leaveYearStart($mode, now()));
            }

            return response()->json(['rows' => $this->computeBalances($emp, $tenantId, $asOf), 'employee' => $emp->name, 'days' => $days, 'period' => $period]);
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
            // 7 Aug 2026 test report (item 9) — SECURITY: force self for ANY
            // non-manager login, not just the 'employee' role. A Field Agent was
            // able to select another employee and submit leave on their behalf.
            // Only super_admin / admin / hr_manager may apply for someone else.
            if (! $this->isManagerRole($request)) {
                $selfRow = $this->currentEmployee($request);
                if (! $selfRow) {
                    return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record, so you can only apply leave for yourself once linked. Contact your admin.'], 422);
                }
                $v['employee'] = (string) (($selfRow->emp_code ?? '') !== '' ? $selfRow->emp_code : $selfRow->name);
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
            // 7 Oct 2026 (Ejaz) — weekly offs and holidays inside the range are NOT
            // leave days (a Fri–Mon leave is 2 days, not 4).
            $days = \App\Services\WorkCalendar::workingDays($from->toDateString(), $to->toDateString(), $emp->tenant_id ? (int) $emp->tenant_id : null, $emp);
            if ($days <= 0) {
                return response()->json(['ok' => false, 'error' => 'Those dates are all weekly offs / holidays — no leave is needed.'], 422);
            }

            // 10 Oct 2026 (Ejaz) — balance short? Don't refuse: tell the employee
            // (available x, applied y, n unpaid) and, once they press OK
            // (confirm_lop), save the paid part on the first x working days and the
            // rest as Loss of Pay on the later days — two requests, one approver.
            $typeName = $v['type'] ?? 'Leave';
            $bal = collect($this->computeBalances($emp, $tenantId, $from->toDateString()))->firstWhere('type', $typeName);
            $parts = [[$typeName, $from->toDateString(), $to->toDateString(), $days]];
            if ($bal && $bal['paid'] && $bal['balance'] !== null) {
                $avail = max(0, (int) floor($bal['balance'] - $bal['pending']));
                if ($days > $avail) {
                    $unpaid = $days - $avail;
                    if (! $request->boolean('confirm_lop')) {
                        return response()->json(['ok' => false, 'needConfirm' => true, 'type' => $typeName,
                            'available' => $avail, 'applied' => $days, 'unpaid' => $unpaid], 409);
                    }
                    $lopType = $this->leaveTypes($tenantId)->first(fn ($t) => (int) ($t->paid ?? 1) === 0)->name ?? 'Loss of Pay';
                    $dates = \App\Services\WorkCalendar::workingDates($from->toDateString(), $to->toDateString(), $emp->tenant_id ? (int) $emp->tenant_id : null, $emp);
                    $parts = [];
                    if ($avail > 0) {
                        $parts[] = [$typeName, $from->toDateString(), $dates[$avail - 1], (float) $avail];
                    }
                    $parts[] = [$lopType, $avail > 0 ? Carbon::parse($dates[$avail - 1])->addDay()->toDateString() : $from->toDateString(), $to->toDateString(), (float) $unpaid];
                }
            }

            [$approverId, $approverName] = $this->resolveApprover($emp, $tenantId);

            $leaveId = 0;
            foreach ($parts as $i => [$pType, $pFrom, $pTo, $pDays]) {
                $row = [
                    'tenant_id' => $emp->tenant_id,
                    'company_id' => $emp->company_id,
                    'employee_id' => $emp->id,
                    'from_date' => $pFrom,
                    'to_date' => $pTo,
                    'days' => $pDays,
                    'status' => 'pending',
                    'approver_id' => $approverId,
                    'reason' => count($parts) > 1 && $pType !== $typeName
                        ? trim(($v['reason'] ?? '').' ['.$typeName.' balance exceeded - '.$pDays.' day(s) as '.$pType.']')
                        : ($v['reason'] ?? null),
                    'type_name' => $pType,
                    'approver_name' => $approverName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $row = array_intersect_key($row, array_flip(Schema::getColumnListing('leaves')));
                $id = DB::table('leaves')->insertGetId($row);
                $leaveId = $leaveId ?: $id;
            }
            $split = count($parts) > 1 || $parts[0][0] !== $typeName
                ? ' · '.implode(' + ', array_map(fn ($p) => $p[3].' '.$p[0], $parts)) : '';

            // 30 Sep 2026 (Ejaz) — bell notification up the hierarchy + Admins / HR.
            \App\Services\NotificationService::notifyUpline($emp, 'request.leave', $typeName.' request · '.$emp->name,
                $from->toDateString().' → '.$to->toDateString().' ('.$days.' day'.($days == 1 ? '' : 's').')'.$split.(! empty($v['reason']) ? ' · '.$v['reason'] : '').' · awaiting '.$approverName,
                'leave-apply', (int) $leaveId, $approverId ? (int) $approverId : null, optional($request->user())->id);

            \App\Services\Audit::record($emp->tenant_id ? (int) $emp->tenant_id : null, optional($request->user())->id, 'submit', 'leave', (int) $leaveId, ['type' => $typeName, 'days' => $days, 'split' => $split], $request->ip());

            return response()->json(['ok' => true, 'approver' => $approverName, 'split' => ltrim($split, ' ·')]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /app/leaves/{id}/edit  {from, to}
     * 30 Sep 2026 (Ejaz) — Admin / HR correct the DATES of a pending leave before
     * deciding it; days are recomputed. The employee's type and reason are left
     * exactly as they wrote them.
     */
    public function edit(Request $request, int $id)
    {
        try {
            if (! $this->isManagerRole($request)) {
                return response()->json(['ok' => false, 'error' => 'Only Admin / HR can edit a leave.'], 403);
            }
            $this->ensureCols();
            $tid = $request->user()->tenant_id;
            $row = DB::table('leaves')->where('id', $id)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $row) {
                return response()->json(['ok' => false, 'error' => 'Leave not found.'], 404);
            }
            if ($row->status !== 'pending') {
                return response()->json(['ok' => false, 'error' => 'Only a pending leave can be edited — it is already '.$row->status.'.'], 422);
            }
            try {
                $from = Carbon::parse((string) $request->input('from'));
                $to = Carbon::parse((string) $request->input('to'));
            } catch (\Exception $e) {
                return response()->json(['ok' => false, 'error' => 'Enter valid From and To dates.'], 422);
            }
            if ($to->lt($from)) {
                return response()->json(['ok' => false, 'error' => 'To date cannot be before From date.'], 422);
            }
            // ponytail: same whole-day count as apply(); the leave-balance check is not re-run for an Admin/HR edit.
            $empRow = DB::table('employees')->where('id', $row->employee_id)->first();
            $days = $empRow ? \App\Services\WorkCalendar::workingDays($from->toDateString(), $to->toDateString(), $empRow->tenant_id ? (int) $empRow->tenant_id : null, $empRow) : ($from->diffInDays($to) + 1);   // 7 Oct 2026
            $old = $row->from_date.' → '.$row->to_date.' ('.((float) $row->days).'d)';
            $new = $from->toDateString().' → '.$to->toDateString().' ('.$days.'d)';
            if ($old === $new) {
                return response()->json(['ok' => true, 'message' => 'Nothing changed.']);
            }
            $by = trim((string) ($request->user()->name ?? '')) ?: (string) ($request->user()->email ?? 'Admin');
            DB::table('leaves')->where('id', $id)->update(array_intersect_key([
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'days' => $days,
                'edited_by' => $by, 'edited_at' => now(), 'updated_at' => now(),
            ], array_flip(Schema::getColumnListing('leaves'))));
            \App\Services\Audit::record($tid ? (int) $tid : null, optional($request->user())->id, 'edit', 'leave', $id, ['dates' => $old.' ⇒ '.$new], $request->ip());

            return response()->json(['ok' => true, 'message' => 'Dates updated: '.$new]);
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

            // 7 Oct 2026 (Ejaz) — hierarchy approvals: Admin/HR, the named approver,
            // or any manager above the employee (any level). Never your own leave.
            // (Replaces rev184's blanket "Employee logins never decide".)
            $me = $this->currentEmployee($request);
            if (! \App\Services\Hierarchy::canApprove($request->user(), (int) $leave->employee_id, $leave->approver_id ? (int) $leave->approver_id : null, 'leave')) {
                return response()->json(['ok' => false, 'error' => 'Only a manager above this employee in the reporting line (or HR / Admin) can decide this leave.'], 403);
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

            // 8 Oct 2026 (Ejaz) — bell to the employee: their leave was decided.
            \App\Services\NotificationService::notifyEmployee($leave->employee_id, 'decision.leave.'.$update['status'],
                ($leave->type_name ?? 'Leave').' '.$update['status'].' by '.($me->name ?? $request->user()->name),
                ($leave->from_date ?? '').' → '.($leave->to_date ?? '').(! empty($v['remarks']) ? ' · '.$v['remarks'] : ''),
                'leave-apply', (int) $id, optional($request->user())->id);

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

    /**
     * GET /app/leaves/report — 10 Oct 2026 (Ejaz) LEAVE BALANCE REPORT (Admin / HR).
     * Filters: year (leave-year start Y-m-d), company_id, branch, department,
     * designation, employee (emp_code). One row per employee with, per leave type,
     * Opening (quota + carried), Availed (approved) and Balance.
     */
    public function report(Request $request)
    {
        if (! $this->isManagerRole($request)) {
            return response()->json(['ok' => false, 'error' => 'Only Admin / HR can see the leave balance report.'], 403);
        }
        try {
            $tid = $request->user()->tenant_id;
            $mode = self::leaveYearMode($tid);
            $cur = self::leaveYearStart($mode, now());
            $target = $request->filled('year') ? self::leaveYearStart($mode, (string) $request->query('year')) : $cur;

            // Year options: from the first recorded leave (max 10 back) to next year.
            $first = $this->firstLeaveDate($tid);
            $y0 = $first ? self::leaveYearStart($mode, $first) : $cur->copy();
            if ($y0->lt($cur->copy()->subYears(9))) {
                $y0 = $cur->copy()->subYears(9);
            }
            $years = [];
            for ($y = $cur->copy()->addYear(); $y->gte($y0); $y->subYear()) {
                $years[] = ['value' => $y->toDateString(), 'label' => self::leaveYearLabel($mode, $y)];
            }

            $has = fn ($c) => Schema::hasColumn('employees', $c);
            $emps = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->where('status', 'active')
                ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', (int) $request->query('company_id')))
                ->when($request->filled('employee'), fn ($q) => $q->where('emp_code', (string) $request->query('employee')))
                ->orderBy('emp_code')->get();

            $names = [];
            $nameOf = function ($e, string $col, string $fk, string $table) use (&$names, $has) {
                $v = $has($col) ? trim((string) ($e->$col ?? '')) : '';
                if ($v !== '' || ! $has($fk) || empty($e->$fk)) {
                    return $v;
                }
                $k = $table.':'.$e->$fk;
                if (! array_key_exists($k, $names)) {
                    try {
                        $names[$k] = (string) (DB::table($table)->where('id', $e->$fk)->value('name') ?? '');
                    } catch (\Throwable $x) {
                        $names[$k] = '';
                    }
                }

                return $names[$k];
            };
            $coNames = DB::table('companies')->pluck('name', 'id')->all();
            $want = fn ($k) => mb_strtolower(trim((string) $request->query($k, '')));

            $types = $this->leaveTypes($tid);
            $rowsByEmp = [];
            if ($emps->isNotEmpty() && Schema::hasTable('leaves')) {
                foreach (DB::table('leaves')->whereIn('employee_id', $emps->pluck('id')->all())
                    ->where('from_date', '<', $target->copy()->addYear()->toDateString())
                    ->get(['employee_id', 'from_date', 'type_name', 'days', 'status']) as $l) {
                    $rowsByEmp[$l->employee_id][] = $l;
                }
            }

            $out = [];
            foreach ($emps as $e) {
                $br = $nameOf($e, 'branch', 'branch_id', 'branches');
                $dep = $nameOf($e, 'department', 'department_id', 'departments');
                $des = $nameOf($e, 'designation', 'designation_id', 'designations');
                if (($w = $want('branch')) !== '' && mb_strtolower($br) !== $w) {
                    continue;
                }
                if (($w = $want('department')) !== '' && mb_strtolower($dep) !== $w) {
                    continue;
                }
                if (($w = $want('designation')) !== '' && mb_strtolower($des) !== $w) {
                    continue;
                }
                $bal = self::ledger($types, $rowsByEmp[$e->id] ?? [], $mode, $target, $this->chainFrom($e, $tid, $mode));
                // Paid types always; unpaid (LOP) types only when some were taken.
                $bal = array_values(array_filter($bal, fn ($b) => $b['paid'] || $b['used'] > 0));
                $out[] = [
                    'code' => $e->emp_code, 'name' => $e->name,
                    'company' => (string) ($coNames[$e->company_id] ?? ''),
                    'branch' => $br, 'department' => $dep, 'designation' => $des,
                    'types' => array_map(fn ($b) => ['type' => $b['type'], 'paid' => $b['paid'], 'opening' => $b['opening'], 'carried' => $b['carried'],
                        'availed' => $b['used'], 'pending' => $b['pending'], 'balance' => $b['balance']], $bal),
                ];
            }

            return response()->json(['ok' => true, 'mode' => $mode, 'year' => $target->toDateString(),
                'label' => self::leaveYearLabel($mode, $target), 'years' => $years, 'rows' => $out]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/leaves/year {mode: calendar|fy} — the leave year (Admin / HR). */
    public function setLeaveYear(Request $request)
    {
        if (! $this->isManagerRole($request)) {
            return response()->json(['ok' => false, 'error' => 'Only Admin / HR can change the leave year.'], 403);
        }
        $v = $request->validate(['mode' => ['required', 'in:calendar,fy']]);
        $tid = (int) ($request->user()->tenant_id ?? 0);
        DB::table('settings')->updateOrInsert(['tenant_id' => $tid, 'key' => 'leave_year'], ['value' => json_encode($v['mode']), 'updated_at' => now(), 'created_at' => now()]);   // same shape as FinYearController

        return response()->json(['ok' => true, 'mode' => $v['mode']]);
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
                // 7 Oct 2026 — everything pending from my whole reporting line.
                $down = \App\Services\Hierarchy::downline($myId ? (int) $myId : null, $tenantId ? (int) $tenantId : null);
                $q->where(fn ($w) => $w->whereIn('l.employee_id', $down ?: [-1])->orWhere('l.approver_id', $myId ?? -1))
                    ->where('l.employee_id', '!=', $myId ?? -1);
            }
            $rows = $q->orderByDesc('l.id')->get(['l.*', 'e.name as emp_name', 'e.emp_code']);
            $u = $request->user();
            $rows = $rows->filter(fn ($r) => \App\Services\Hierarchy::canApprove($u, (int) $r->employee_id, $r->approver_id ? (int) $r->approver_id : null, 'leave'))->values();

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
