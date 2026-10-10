<?php

namespace App\Http\Controllers;

use App\Services\AttendanceCorrectionService as Svc;
use App\Services\AuditTrail;
use App\Services\FeaturePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * F2 — Attendance correction endpoints.
 *
 * Employees raise and cancel their own corrections; managers / HR review and
 * approve (optionally modifying the times). Plain employees are scoped to their
 * own rows, matching the self-service rule already applied across leave,
 * payslips and attendance.
 */
class AttendanceCorrectionController extends Controller
{
    /** GET /app/attendance-corrections — list (own rows for plain employees). */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id ? (int) $user->tenant_id : null;
            $emp = $this->currentEmployee($request);
            // 7 Oct 2026 (Ejaz) — hierarchy: own + whole reporting line below
            // (Admin / HR see all); any manager above the employee may decide.
            $own = $this->canReview($request) ? null : (\App\Services\Hierarchy::visibleIds($user) ?? null);
            $myId = isset($emp->id) ? (int) $emp->id : 0;
            $items = array_map(function ($it) use ($user, $myId) {
                $it['own'] = (int) $it['employee_id'] === $myId;
                $it['can_decide'] = $it['status'] === 'pending'
                    && \App\Services\Hierarchy::canApprove($user, (int) $it['employee_id'], null, 'attendance');

                return $it;
            }, Svc::listFor($tid, $own, [
                'status' => $request->input('status'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ]));
            $leads = $myId && \App\Services\Hierarchy::downline($myId, $tid);

            return response()->json([
                'ok' => true,
                'can_review' => $this->canReview($request) || $leads,
                'sub_types' => Svc::SUB_TYPES,
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * GET /app/attendance-corrections/day?date=Y-m-d — what the system currently
     * has for the logged-in employee on that date, so the form can prefill.
     */
    public function day(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id ? (int) $user->tenant_id : null;
            $emp = $this->currentEmployee($request);
            if (! $emp) {
                return response()->json(['ok' => true, 'linked' => false]);
            }
            $date = Svc::ymd($request->input('date')) ?: now()->subDay()->toDateString();
            [$in, $out] = Svc::existingPunches($tid, (string) $emp->emp_code, $date);

            return response()->json([
                'ok' => true, 'linked' => true, 'date' => $date,
                'orig_in' => $in, 'orig_out' => $out,
                'has_attendance' => (bool) ($in || $out),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/attendance-corrections — raise a request (own attendance). */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            $emp = $this->currentEmployee($request);
            if (! $emp) {
                return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record.'], 422);
            }
            $res = Svc::create([
                'tenant_id' => $user->tenant_id ? (int) $user->tenant_id : null,
                'employee' => $emp,
                'log_date' => $request->input('date'),
                'sub_type' => $request->input('sub_type'),
                'req_in' => $request->input('req_in'),
                'req_out' => $request->input('req_out'),
                'reason' => $request->input('reason'),
            ]);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => $res['error']], 422);
            }
            // 30 Sep 2026 (Ejaz) — bell notification up the hierarchy + Admins / HR.
            \App\Services\NotificationService::notifyUpline($emp, 'request.att_correction', 'Attendance correction · '.$emp->name,
                trim($request->input('date').' · '.(Svc::SUB_TYPES[$request->input('sub_type')] ?? $request->input('sub_type'))
                    .($request->input('req_in') ? ' · In '.$request->input('req_in') : '').($request->input('req_out') ? ' · Out '.$request->input('req_out') : '')
                    .($request->input('reason') ? ' · '.$request->input('reason') : '')),
                'att-correction', (int) $res['id'], null, $user->id ?? null);

            AuditTrail::log($request, 'attendance.correction.requested', 'attendance_corrections', $res['id'], [
                'date' => $request->input('date'), 'sub_type' => $request->input('sub_type'),
            ]);

            return response()->json(['ok' => true, 'id' => $res['id']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/attendance-corrections/{id}/cancel — employee cancels (pending only). */
    public function cancel(Request $request, $id)
    {
        try {
            $user = $request->user();
            $emp = $this->currentEmployee($request);
            $res = Svc::cancel((int) $id, (int) ($emp->id ?? 0), $user->tenant_id ? (int) $user->tenant_id : null);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => $res['error']], 422);
            }
            AuditTrail::log($request, 'attendance.correction.cancelled', 'attendance_corrections', (int) $id);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /app/attendance-corrections/{id}/decide
     * { action: approve|reject, app_in?, app_out?, remarks? }
     */
    public function decide(Request $request, $id)
    {
        try {
            $user = $request->user();
            // 7 Oct 2026 (Ejaz) — HR / Admin as before, or any manager above the
            // employee in the reporting line (never your own request).
            $rowEmp = (int) (DB::table('attendance_corrections')->where('id', (int) $id)
                ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))->value('employee_id') ?? 0);
            $hrOk = FeaturePermissions::guard($request, 'attendance.correction.review') === null;
            if (! ($hrOk && $rowEmp !== (int) (\App\Services\Hierarchy::meId($user) ?? -1))
                && ! \App\Services\Hierarchy::canApprove($user, $rowEmp, null, 'attendance')) {
                return response()->json(['ok' => false, 'error' => 'Only a manager above this employee in the reporting line (or HR / Admin) can decide this correction.'], 403);
            }
            $res = Svc::decide((int) $id, (string) $request->input('action'), [
                'tenant_id' => $user->tenant_id ? (int) $user->tenant_id : null,
                'by' => $user->name ?? $user->email ?? 'HR',
                'app_in' => $request->input('app_in'),
                'app_out' => $request->input('app_out'),
                'remarks' => $request->input('remarks'),
            ]);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => $res['error']], 422);
            }
            AuditTrail::log($request, 'attendance.correction.'.$res['status'], 'attendance_corrections', (int) $id, [
                'punches_written' => $res['punches_written'] ?? 0,
                'payroll_locked' => $res['payroll_locked'] ?? false,
            ]);
            // 8 Oct 2026 (Ejaz) — bell to the employee: their correction was decided.
            \App\Services\NotificationService::notifyEmployee($rowEmp, 'decision.att_correction.'.($res['status'] ?? 'decided'),
                'Attendance correction '.($res['status'] ?? 'decided').' by '.($user->name ?? 'your manager'),
                $request->input('remarks') ? (string) $request->input('remarks') : null, 'att-correction', (int) $id, $user->id ?? null);

            return response()->json($res);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Managers, HR and admins may review; plain employees may not. */
    private function canReview(Request $request): bool
    {
        try {
            $u = $request->user();

            return $u && $u->hasAnyRole(['super_admin', 'admin', 'hr_manager', 'hr', 'manager']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Same resolution as EssController. */
    private function currentEmployee(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id ?? null;
        if (! empty($user->employee_id)) {
            $e = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($e) {
                return $e;
            }
        }

        return DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
            ->first();
    }
}
