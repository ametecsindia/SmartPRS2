<?php

namespace App\Services;

use App\Http\Controllers\ApprovalService;
use App\Support\SchemaHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F2 — Attendance correction (regularisation) requests.
 *
 * An employee whose attendance is wrong or missing raises a correction; their
 * reporting manager / HR approves, and on approval the corrected punches are
 * written back into `attendance_logs` with source='correction' so attendance
 * reports and payroll pick them up.
 *
 * Deliberately its OWN table + service rather than a new module inside the
 * 108 KB RequestController: this workflow needs richer state than the generic
 * pending/approved/rejected engine — original vs requested vs approved times,
 * five statuses, cancel-while-pending, and HR "modify and approve". It reuses
 * the established building blocks though: ApprovalService::resolveApprover for
 * the approver chain, AuditTrail for the audit ledger, NotificationService
 * (P0) for email + in-app, PayrollLock for the finalised-payroll guard, and
 * SchemaHelper for idempotent schema.
 *
 * This also completes the deep link raised by F10's absence notice
 * ({@see AbsenceService}), which points employees at 'att-correction'.
 */
class AttendanceCorrectionService
{
    /** The seven correction sub-types HR asked for. */
    public const SUB_TYPES = [
        'missed_in' => 'Missed check-in',
        'missed_out' => 'Missed check-out',
        'missed_both' => 'No punch recorded (full day)',
        'wrong_time' => 'Wrong punch time',
        'on_duty' => 'On duty / official outdoor work',
        'wfh' => 'Worked from home',
        'other' => 'Other (explain in reason)',
    ];

    /** The five statuses. */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'applied'];

    /** Idempotent schema (mirrors the migration). */
    public static function ensureSchema(): void
    {
        SchemaHelper::ensureTable('attendance_corrections', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('emp_code')->nullable()->index();
            $t->string('emp_name')->nullable();
            $t->date('log_date')->index();
            $t->string('sub_type', 30);                 // see SUB_TYPES
            $t->time('orig_in')->nullable();            // what the system had
            $t->time('orig_out')->nullable();
            $t->time('req_in')->nullable();             // what the employee asks for
            $t->time('req_out')->nullable();
            $t->time('app_in')->nullable();             // what HR finally approved
            $t->time('app_out')->nullable();
            $t->string('reason', 1000)->nullable();
            $t->string('status', 20)->default('pending')->index();
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->string('approver_name')->nullable();
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('remarks', 1000)->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->boolean('payroll_locked')->default(false);   // period already finalised at decision time
            $t->timestamps();
            $t->index(['tenant_id', 'employee_id', 'log_date']);
        });
    }

    /**
     * Raise a correction request.
     *
     * @return array ['ok'=>bool,'id'=>int|null,'error'=>string|null]
     */
    public static function create(array $in): array
    {
        self::ensureSchema();
        try {
            $tid = $in['tenant_id'] ?? null;
            $emp = $in['employee'] ?? null;
            if (! $emp) {
                return ['ok' => false, 'id' => null, 'error' => 'Employee not linked to this login.'];
            }
            $a = (array) $emp;
            $date = self::ymd($in['log_date'] ?? null);
            if (! $date) {
                return ['ok' => false, 'id' => null, 'error' => 'A valid date is required.'];
            }
            if (Carbon::parse($date)->startOfDay()->gt(Carbon::now()->startOfDay())) {
                return ['ok' => false, 'id' => null, 'error' => 'You cannot raise a correction for a future date.'];
            }
            $sub = (string) ($in['sub_type'] ?? '');
            if (! array_key_exists($sub, self::SUB_TYPES)) {
                return ['ok' => false, 'id' => null, 'error' => 'Please choose a valid correction type.'];
            }
            $reason = trim((string) ($in['reason'] ?? ''));
            if ($reason === '') {
                return ['ok' => false, 'id' => null, 'error' => 'A reason is required.'];
            }
            $reqIn = self::hm($in['req_in'] ?? null);
            $reqOut = self::hm($in['req_out'] ?? null);
            if (! $reqIn && ! $reqOut) {
                return ['ok' => false, 'id' => null, 'error' => 'Enter the corrected check-in and/or check-out time.'];
            }
            if ($reqIn && $reqOut && strcmp($reqOut, $reqIn) < 0) {
                return ['ok' => false, 'id' => null, 'error' => 'Check-out cannot be earlier than check-in.'];
            }

            // One open request per employee-day.
            $dupe = DB::table('attendance_corrections')
                ->where('employee_id', $a['id'])->whereDate('log_date', $date)
                ->whereIn('status', ['pending', 'approved'])->exists();
            if ($dupe) {
                return ['ok' => false, 'id' => null, 'error' => 'A correction for this date is already pending or approved.'];
            }

            [$origIn, $origOut] = self::existingPunches($tid, (string) ($a['emp_code'] ?? ''), $date);
            [$apprId, $apprName] = ApprovalService::resolveApprover($emp, $tid ? (int) $tid : null);

            $id = DB::table('attendance_corrections')->insertGetId([
                'tenant_id' => $tid, 'company_id' => $a['company_id'] ?? null,
                'employee_id' => (int) $a['id'], 'emp_code' => $a['emp_code'] ?? null, 'emp_name' => $a['name'] ?? null,
                'log_date' => $date, 'sub_type' => $sub,
                'orig_in' => $origIn, 'orig_out' => $origOut,
                'req_in' => $reqIn, 'req_out' => $reqOut,
                'reason' => mb_substr($reason, 0, 1000),
                'status' => 'pending',
                'approver_id' => $apprId, 'approver_name' => $apprName,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            self::notifyApprover($tid, $id, $a, $date, $apprId);

            return ['ok' => true, 'id' => $id, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('AttendanceCorrectionService::create failed: '.$e->getMessage());

            return ['ok' => false, 'id' => null, 'error' => 'Could not save the request.'];
        }
    }

    /** Employee cancels their own request — only while still pending. */
    public static function cancel(int $id, int $employeeId, ?int $tid): array
    {
        self::ensureSchema();
        try {
            $row = DB::table('attendance_corrections')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $row) {
                return ['ok' => false, 'error' => 'Request not found.'];
            }
            if ((int) $row->employee_id !== $employeeId) {
                return ['ok' => false, 'error' => 'You can only cancel your own request.'];
            }
            if ($row->status !== 'pending') {
                return ['ok' => false, 'error' => 'Only a pending request can be cancelled.'];
            }
            DB::table('attendance_corrections')->where('id', $id)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Approve or reject. HR may MODIFY the times while approving (app_in/app_out
     * default to what the employee requested). On approval the punches are
     * written back to attendance_logs unless the payroll period is already
     * finalised — in which case the row is flagged payroll_locked and left for
     * a Super Admin reopen / next-month adjustment (decision #2).
     */
    public static function decide(int $id, string $action, array $opts = []): array
    {
        self::ensureSchema();
        try {
            $tid = $opts['tenant_id'] ?? null;
            $row = DB::table('attendance_corrections')->where('id', $id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $row) {
                return ['ok' => false, 'error' => 'Request not found.'];
            }
            if ($row->status !== 'pending') {
                return ['ok' => false, 'error' => 'This request has already been decided.'];
            }
            $by = (string) ($opts['by'] ?? 'HR');
            $remarks = trim((string) ($opts['remarks'] ?? ''));

            if ($action === 'reject') {
                if ($remarks === '') {
                    return ['ok' => false, 'error' => 'Remarks are required when rejecting.'];
                }
                DB::table('attendance_corrections')->where('id', $id)->update([
                    'status' => 'rejected', 'decided_by' => $by, 'decided_at' => now(),
                    'remarks' => mb_substr($remarks, 0, 1000), 'updated_at' => now(),
                ]);
                self::notifyEmployee($row, 'rejected', $remarks);

                return ['ok' => true, 'status' => 'rejected', 'error' => null];
            }

            if ($action !== 'approve') {
                return ['ok' => false, 'error' => 'Unknown action.'];
            }

            // HR modify-and-approve: fall back to the requested times.
            $appIn = self::hm($opts['app_in'] ?? null) ?: $row->req_in;
            $appOut = self::hm($opts['app_out'] ?? null) ?: $row->req_out;
            if ($appIn && $appOut && strcmp((string) $appOut, (string) $appIn) < 0) {
                return ['ok' => false, 'error' => 'Check-out cannot be earlier than check-in.'];
            }

            $locked = PayrollLock::isPeriodFinalised(
                $row->log_date,
                $row->tenant_id ? (int) $row->tenant_id : null,
                $row->company_id ? (int) $row->company_id : null
            );

            $upd = [
                'status' => $locked ? 'approved' : 'applied',
                'app_in' => $appIn, 'app_out' => $appOut,
                'decided_by' => $by, 'decided_at' => now(),
                'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 1000) : null,
                'payroll_locked' => $locked,
                'updated_at' => now(),
            ];
            if (! $locked) {
                $upd['applied_at'] = now();
            }
            DB::table('attendance_corrections')->where('id', $id)->update($upd);

            $written = 0;
            if (! $locked) {
                $written = self::writeBack($row, $appIn, $appOut);
            }

            self::notifyEmployee($row, $locked ? 'approved_locked' : 'applied', $remarks);

            return [
                'ok' => true,
                'status' => $upd['status'],
                'punches_written' => $written,
                'payroll_locked' => $locked,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('AttendanceCorrectionService::decide failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'Could not record the decision.'];
        }
    }

    /**
     * Write approved times into attendance_logs as source='correction'.
     * Existing correction punches for that employee-day are replaced so a second
     * approval never doubles up. Returns the number of punches written.
     */
    public static function writeBack($row, ?string $appIn, ?string $appOut): int
    {
        try {
            if (! Schema::hasTable('attendance_logs')) {
                return 0;
            }
            $code = (string) ($row->emp_code ?? '');
            if ($code === '') {
                return 0;
            }
            $date = self::ymd($row->log_date);

            DB::table('attendance_logs')
                ->when($row->tenant_id && Schema::hasColumn('attendance_logs', 'tenant_id'),
                    fn ($q) => $q->where('tenant_id', $row->tenant_id))
                ->whereRaw('LOWER(emp_code) = ?', [strtolower($code)])
                ->whereDate('log_date', $date)
                ->where('source', 'correction')
                ->delete();

            $rows = [];
            foreach ([['in', $appIn], ['out', $appOut]] as [$dir, $hm]) {
                if (! $hm) {
                    continue;
                }
                $rows[] = [
                    'tenant_id' => $row->tenant_id, 'company_id' => $row->company_id ?? null,
                    'emp_code' => $code, 'emp_name' => $row->emp_name ?? null,
                    'log_date' => $date, 'punch_at' => $date.' '.substr($hm, 0, 5).':00',
                    'direction' => $dir, 'source' => 'correction',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            if (! $rows) {
                return 0;
            }
            DB::table('attendance_logs')->insert($rows);

            return count($rows);
        } catch (\Throwable $e) {
            Log::warning('AttendanceCorrectionService::writeBack failed: '.$e->getMessage());

            return 0;
        }
    }

    /** List requests. Plain employees are scoped to their own rows. */
    public static function listFor(?int $tid, ?int $ownEmployeeId, array $filters = []): array
    {
        self::ensureSchema();
        try {
            $q = DB::table('attendance_corrections')
                ->when($tid, fn ($x) => $x->where('tenant_id', $tid));
            if ($ownEmployeeId !== null) {
                $q->where('employee_id', $ownEmployeeId);
            }
            if (! empty($filters['status'])) {
                $q->where('status', $filters['status']);
            }
            if (! empty($filters['from'])) {
                $q->whereDate('log_date', '>=', self::ymd($filters['from']));
            }
            if (! empty($filters['to'])) {
                $q->whereDate('log_date', '<=', self::ymd($filters['to']));
            }

            return $q->orderByDesc('id')->limit(300)->get()->map(fn ($r) => [
                'id' => (int) $r->id,
                'employee_id' => (int) $r->employee_id,
                'emp_code' => $r->emp_code,
                'emp_name' => $r->emp_name,
                'date' => self::ymd($r->log_date),
                'sub_type' => $r->sub_type,
                'sub_type_label' => self::SUB_TYPES[$r->sub_type] ?? $r->sub_type,
                'orig_in' => self::hm($r->orig_in), 'orig_out' => self::hm($r->orig_out),
                'req_in' => self::hm($r->req_in), 'req_out' => self::hm($r->req_out),
                'app_in' => self::hm($r->app_in), 'app_out' => self::hm($r->app_out),
                'reason' => $r->reason,
                'status' => $r->status,
                'approver' => $r->approver_name,
                'decided_by' => $r->decided_by,
                'remarks' => $r->remarks,
                'payroll_locked' => (bool) $r->payroll_locked,
                'at' => (string) $r->created_at,
            ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---- helpers -----------------------------------------------------------

    /** Earliest in / latest out already on record for that employee-day. */
    public static function existingPunches(?int $tid, string $empCode, string $date): array
    {
        try {
            if ($empCode === '' || ! Schema::hasTable('attendance_logs')) {
                return [null, null];
            }
            $rows = DB::table('attendance_logs')
                ->when($tid && Schema::hasColumn('attendance_logs', 'tenant_id'),
                    fn ($q) => $q->where('tenant_id', $tid))
                ->whereRaw('LOWER(emp_code) = ?', [strtolower($empCode)])
                ->whereDate('log_date', $date)
                ->orderBy('punch_at')->pluck('punch_at')->all();
            if (! $rows) {
                return [null, null];
            }
            $first = Carbon::parse($rows[0])->format('H:i');
            $last = count($rows) > 1 ? Carbon::parse($rows[count($rows) - 1])->format('H:i') : null;

            return [$first, $last];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    private static function notifyApprover(?int $tid, int $id, array $emp, string $date, ?int $approverEmpId): void
    {
        try {
            $uid = null;
            if ($approverEmpId && Schema::hasTable('users')) {
                $uid = DB::table('users')->where('employee_id', $approverEmpId)->value('id');
            }
            if (! $uid) {
                return;   // no linked approver login — it still shows in the HR list
            }
            $nice = Carbon::parse($date)->format('D, d M Y');
            NotificationService::send([
                'tenant_id' => $tid, 'user_id' => (int) $uid, 'employee_id' => (int) $emp['id'],
                'kind' => 'attendance.correction.requested',
                'title' => 'Attendance correction: '.($emp['name'] ?? 'An employee').' — '.$nice,
                'body' => ($emp['name'] ?? 'An employee').' has requested an attendance correction for '.$nice.'. Please review and approve or reject.',
                'url' => 'att-correction',
                'in_app' => true, 'email' => true,
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function notifyEmployee($row, string $outcome, string $remarks): void
    {
        try {
            $uid = null;
            if (Schema::hasTable('users')) {
                $uid = DB::table('users')->where('employee_id', $row->employee_id)->value('id');
            }
            $nice = Carbon::parse(self::ymd($row->log_date))->format('D, d M Y');
            $map = [
                'applied' => ['Attendance correction approved — '.$nice, 'Your attendance for '.$nice.' has been corrected and updated.'],
                'approved_locked' => ['Attendance correction approved — '.$nice, 'Your correction for '.$nice.' was approved. Payroll for that month is already finalised, so the change will be carried into the next adjustment.'],
                'rejected' => ['Attendance correction rejected — '.$nice, 'Your correction request for '.$nice.' was not approved.'],
            ];
            [$title, $body] = $map[$outcome] ?? ['Attendance correction updated', ''];
            if ($remarks !== '') {
                $body .= ' Remarks: '.$remarks;
            }
            NotificationService::send([
                'tenant_id' => $row->tenant_id, 'company_id' => $row->company_id ?? null,
                'user_id' => $uid ? (int) $uid : null, 'employee_id' => (int) $row->employee_id,
                'kind' => 'attendance.correction.'.$outcome,
                'title' => $title, 'body' => $body, 'url' => 'att-correction',
                'in_app' => true, 'email' => true,
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** Normalise a time-ish value to H:i, or null. */
    public static function hm($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            if ($h < 0 || $h > 23 || $i < 0 || $i > 59) {
                return null;
            }

            return sprintf('%02d:%02d', $h, $i);
        }

        return null;
    }

    /** Normalise a date-ish value to Y-m-d, or null. */
    public static function ymd($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
