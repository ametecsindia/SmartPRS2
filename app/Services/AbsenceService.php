<?php

namespace App\Services;

use App\Services\ShiftResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F10 — Previous-day absence notification.
 *
 * Each working morning (default 11:00, decision #6) this evaluates the previous
 * calendar day and notifies every active employee who was ABSENT — i.e. had no
 * attendance punch AND was not on approved leave, a holiday, or a weekly-off —
 * with a link to regularise (F2 attendance correction). Email + in-app via the
 * P0 NotificationService; idempotent per employee/day.
 *
 * The 11:00 default (configurable) gives biometric imports time to land before
 * anyone is flagged (the "grace window" of decision #6). Ships OPT-IN
 * (enabled=false). Absence classification mirrors the attendance report:
 *   present    = any attendance_logs row for (emp_code, log_date)
 *   holiday    = holidays row (tenant_id, date)
 *   on leave   = approved leaves row spanning the date
 *   weekly-off = a roster entry for that date decides; otherwise the Weekly
 *                Offs setting (Attendance -> Working Shifts: weekly_off_day +
 *                sat_off_mode), via PayrollGenController::isWeekOff() — the
 *                SAME rule payroll uses, so attendance and pay never disagree.
 *
 * No WFH table exists in this codebase, so WFH is not separately excluded; a
 * WFH day is expected to be recorded as leave or regularised via the link.
 */
class AbsenceService
{
    public const CKEY = 'absence_notify';

    public static function defaults(): array
    {
        return [
            'enabled' => false,          // opt-in
            'send_hour' => 11,           // local hour the sweep should fire (grace for late imports)
            'notify_employee' => true,
            'notify_manager' => false,
            'regularise_screen' => 'att-correction',   // F2 deep link
        ];
    }

    public static function config(?int $tenantId): array
    {
        $cfg = self::defaults();
        try {
            if (Schema::hasTable('app_settings')) {
                $raw = DB::table('app_settings')->where('tenant_id', $tenantId ?? 0)
                    ->where('ckey', self::CKEY)->value('value');
                if ($raw) {
                    $saved = is_array($raw) ? $raw : json_decode($raw, true);
                    if (is_array($saved)) {
                        $cfg = array_replace_recursive($cfg, $saved);
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return $cfg;
    }

    public static function saveConfig(?int $tenantId, array $cfg): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['tenant_id' => $tenantId ?? 0, 'ckey' => self::CKEY],
            ['value' => json_encode($cfg), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /**
     * Evaluate one date for one tenant (or all) and notify absentees.
     *
     * @return array ['notified'=>int,'evaluated'=>int,'tenants'=>int]
     */
    public static function runForDate(?int $tenantId = null, $date = null, ?int $hourGate = null): array
    {
        $notified = 0;
        $evaluated = 0;
        $tenantsRun = 0;
        try {
            if (! Schema::hasTable('employees') || ! Schema::hasTable('attendance_logs')) {
                return ['notified' => 0, 'evaluated' => 0, 'tenants' => 0];
            }
            $day = self::toCarbon($date)->startOfDay();
            $ymd = $day->toDateString();
            $iso = (int) $day->isoWeekday();   // 1..7

            // NULL tenant = self-hosted install; it used to be filtered out, so
            // on-prem absence alerts never ran (2026-09-28).
            $tenantIds = $tenantId !== null ? [$tenantId]
                : DB::table('employees')->distinct()->pluck('tenant_id')->unique()->values()->all();

            foreach ($tenantIds as $tid) {
                $tid = $tid === null ? null : (int) $tid;
                $cfg = self::config($tid);
                if (empty($cfg['enabled'])) {
                    continue;
                }
                // At or AFTER send_hour (was: exactly that hour). Notices dedupe per
                // employee/day, so a later hourly run is safe — and a web-driven tick
                // that missed the send hour still delivers the alert that day.
                if ($hourGate !== null && (int) ($cfg['send_hour'] ?? 11) > $hourGate) {
                    continue;
                }
                $tenantsRun++;

                // 7 Oct 2026 — holidays per company / branch (Applies To), checked per employee below.
                $holSet = \App\Services\WorkCalendar::holidays($tid, $ymd, $ymd);

                // Bulk sets for this date (one query each, not per-employee).
                $present = self::presentEmpCodes($tid, $ymd);          // lower-cased emp_codes with a punch
                $onLeave = self::onLeaveEmployeeIds($tid, $ymd);       // employee ids with approved leave
                [$shiftDefs, $rosterMap] = self::shiftContext($tid, $ymd);
                $offRules = \App\Http\Controllers\SettingsController::rates($tid);   // Weekly Offs setting

                $emps = DB::table('employees')->where('tenant_id', $tid)
                    ->whereNull('deleted_at')->where('status', 'active')->get();

                foreach ($emps as $e) {
                    if (! empty($e->doj) && substr((string) $e->doj, 0, 10) > $ymd) {
                        continue;   // not yet joined
                    }
                    if (\App\Services\WorkCalendar::isHoliday($holSet, $ymd, \App\Services\WorkCalendar::companyName((int) ($e->company_id ?? 0)), $e->branch ?? null)) {
                        continue;   // holiday for this employee's company / branch
                    }
                    $evaluated++;
                    if (self::isAbsent($e, $ymd, $iso, $present, $onLeave, $shiftDefs, $rosterMap, $offRules)) {
                        if (self::notify($tid, $e, $ymd, $cfg)) {
                            $notified++;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AbsenceService::runForDate failed: '.$e->getMessage());
        }

        return ['notified' => $notified, 'evaluated' => $evaluated, 'tenants' => $tenantsRun];
    }

    /** Absence decision for one employee on one date. Pure given the prepared sets. */
    public static function isAbsent($emp, string $ymd, int $iso, array $present, array $onLeave, array $shiftDefs, array $rosterMap, array $offRules): bool
    {
        $a = (array) $emp;
        $code = strtolower((string) ($a['emp_code'] ?? ''));
        if ($code === '') {
            return false;
        }
        // Present?
        if (isset($present[$code])) {
            return false;
        }
        // On approved leave?
        if (isset($onLeave[(int) ($a['id'] ?? 0)])) {
            return false;
        }
        if (self::isWeeklyOff($emp, $ymd, $iso, $shiftDefs, $rosterMap, $offRules)) {
            return false;   // weekly-off — not expected to work
        }

        return true;   // no punch, not on leave, not holiday, not weekly-off → absent
    }

    /**
     * Weekly-off decision for one employee on one date. A ROSTER entry for the
     * date wins (Week-off = off; a scheduled shift = working, even on the usual
     * off day). Otherwise the Weekly Offs setting decides ($offRules =
     * SettingsController::rates(): weekly_off_day + sat_off_mode) through
     * PayrollGenController::isWeekOff() — the exact rule payroll uses for
     * working days / LOP. 2026-09-28: previously this read a separate
     * absence_notify.working_days list and treated any employee with a default
     * shift as working every day, so reports and payroll could disagree.
     */
    public static function isWeeklyOff($emp, string $ymd, int $iso, array $shiftDefs, array $rosterMap, array $offRules): bool
    {
        $a = (array) $emp;
        if ($shiftDefs) {
            try {
                $sh = ShiftResolver::resolve($shiftDefs, $rosterMap, (string) ($a['name'] ?? ''), $a['shift'] ?? null, $ymd);
                if (is_array($sh) && ($sh['source'] ?? '') === 'roster') {
                    return (bool) ($sh['off'] ?? false);
                }
            } catch (\Throwable $e) {
            }
        }

        // 7 Oct 2026 — the employee's company may override the weekly offs.
        return \App\Http\Controllers\PayrollGenController::isWeekOff(Carbon::parse($ymd), \App\Services\WorkCalendar::forCompany($offRules, $a['company_id'] ?? null));
    }

    /**
     * Holiday dates for a tenant within [from,to], as a 'Y-m-d' => true set.
     * ONE query for the whole range (unlike isHoliday(), which is per-day) —
     * for a caller enumerating many days at once, e.g. the attendance report.
     */
    public static function holidaysInRange(?int $tid, string $from, string $to): array
    {
        // 7 Oct 2026 — 'Y-m-d' => [] (everyone) | [company/branch scopes]; see WorkCalendar.
        return \App\Services\WorkCalendar::holidays($tid, $from, $to);
    }

    /**
     * Approved leaves overlapping [from,to] for a tenant, ONE query, keyed by
     * employee_id => list of ['from'=>Y-m-d,'to'=>Y-m-d,'days'=>float]. `days`
     * is read as a float (as the rest of the app already does, e.g.
     * LeaveController) because a single-day leave recorded with days < 1 is
     * how this schema represents a half-day — there is no separate half-day
     * flag/table.
     */
    public static function leavesInRange(?int $tid, string $from, string $to): array
    {
        $out = [];
        try {
            if (! Schema::hasTable('leaves')) {
                return $out;
            }
            $rows = DB::table('leaves')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'approved')
                ->whereDate('from_date', '<=', $to)
                ->whereDate('to_date', '>=', $from)
                ->get(['employee_id', 'from_date', 'to_date', 'days']);
            foreach ($rows as $r) {
                $out[(int) $r->employee_id][] = [
                    'from' => Carbon::parse($r->from_date)->toDateString(),
                    'to' => Carbon::parse($r->to_date)->toDateString(),
                    'days' => (float) ($r->days ?? 1),
                ];
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /**
     * Classify ONE employee's day when there is NO punch, using the bulk sets
     * above (holidaysInRange/leavesInRange — load once per report, not per
     * employee/day) plus the same weekly-off rule isAbsent() uses. Shared by
     * AttendanceReportController::buildReport() so its "Daily rows" list and
     * this service's own absence sweep can never disagree on a given day.
     * Never returns 'present' — this is only called for a day with no punch.
     *
     * @param  array  $holidaySet   from holidaysInRange()
     * @param  array  $leavesByEmp  from leavesInRange()
     * @return string 'holiday'|'on_leave'|'half_day'|'weekly_off'|'absent'
     */
    public static function classifyNoPunchDay($emp, string $ymd, array $holidaySet, array $leavesByEmp, array $shiftDefs, array $rosterMap, array $offRules): string
    {
        $a = (array) $emp;
        if (\App\Services\WorkCalendar::isHoliday($holidaySet, $ymd, \App\Services\WorkCalendar::companyName((int) ($a['company_id'] ?? 0)), $a['branch'] ?? null)) {
            return 'holiday';
        }
        $empId = (int) ($a['id'] ?? 0);
        foreach ($leavesByEmp[$empId] ?? [] as $lv) {
            if ($ymd >= $lv['from'] && $ymd <= $lv['to']) {
                // A single-day leave logged with a fractional day count is a half-day.
                return ($lv['from'] === $lv['to'] && $lv['days'] > 0 && $lv['days'] < 1) ? 'half_day' : 'on_leave';
            }
        }
        $iso = (int) Carbon::parse($ymd)->isoWeekday();
        if (self::isWeeklyOff($emp, $ymd, $iso, $shiftDefs, $rosterMap, $offRules)) {
            return 'weekly_off';
        }

        return 'absent';
    }

    /** Send the absence notice to the employee (and optionally the manager). */
    private static function notify(?int $tid, $emp, string $ymd, array $cfg): bool
    {
        $a = (array) $emp;
        $empId = (int) ($a['id'] ?? 0);
        $nice = Carbon::parse($ymd)->format('D, d-m-Y');
        $screen = $cfg['regularise_screen'] ?? 'att-correction';
        $any = false;

        if (! empty($cfg['notify_employee'])) {
            $uid = self::userIdFor($a);
            $id = NotificationService::sendOnce('absence:'.$empId.':'.$ymd, [
                'tenant_id' => $tid, 'company_id' => $a['company_id'] ?? null,
                'user_id' => $uid, 'employee_id' => $empId,
                'kind' => 'absence.prev_day',
                'title' => 'No attendance recorded for '.$nice,
                'body' => "We didn't find any attendance for you on {$nice}. If you were working, on duty, or this is an error, please regularise it or inform your manager.",
                'url' => $screen,
                'in_app' => true, 'email' => true,
                'email_to' => $a['email'] ?? null,
                'email_to_name' => $a['name'] ?? '',
                'email_subject' => 'Attendance missing for '.$nice,
                'email_cta_label' => 'Regularise attendance',
            ]);
            $any = $any || ($id !== null);
        }

        if (! empty($cfg['notify_manager']) && ! empty($a['reporting_manager_id'])) {
            $muid = self::managerUserId((int) $a['reporting_manager_id']);
            if ($muid) {
                $mid = NotificationService::sendOnce('absence:'.$empId.':'.$ymd.':mgr', [
                    'tenant_id' => $tid, 'user_id' => $muid, 'employee_id' => $empId,
                    'kind' => 'absence.prev_day.manager',
                    'title' => ($a['name'] ?? 'An employee').' — no attendance on '.$nice,
                    'body' => ($a['name'] ?? 'An employee')." had no attendance recorded on {$nice}. Please follow up or approve a correction.",
                    'url' => 'att-report',
                    'in_app' => true, 'email' => true,
                ]);
                $any = $any || ($mid !== null);
            }
        }

        return $any;
    }

    // ---- prepared-set helpers (one query each per tenant/day) ---------------

    private static function isHoliday(?int $tid, string $ymd): bool
    {
        try {
            if (! Schema::hasTable('holidays')) {
                return false;
            }

            return DB::table('holidays')->where('tenant_id', $tid)->whereDate('date', $ymd)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Lower-cased emp_codes that have at least one punch on the date. */
    private static function presentEmpCodes(?int $tid, string $ymd): array
    {
        $set = [];
        try {
            $rows = DB::table('attendance_logs')
                ->when(Schema::hasColumn('attendance_logs', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->whereDate('log_date', $ymd)
                ->distinct()->pluck('emp_code');
            foreach ($rows as $c) {
                $set[strtolower((string) $c)] = true;
            }
        } catch (\Throwable $e) {
        }

        return $set;
    }

    /** Employee ids with an APPROVED leave spanning the date. */
    private static function onLeaveEmployeeIds(?int $tid, string $ymd): array
    {
        $set = [];
        try {
            if (! Schema::hasTable('leaves')) {
                return $set;
            }
            $rows = DB::table('leaves')
                ->when(Schema::hasColumn('leaves', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('status', 'approved')
                ->whereDate('from_date', '<=', $ymd)
                ->whereDate('to_date', '>=', $ymd)
                ->pluck('employee_id');
            foreach ($rows as $id) {
                $set[(int) $id] = true;
            }
        } catch (\Throwable $e) {
        }

        return $set;
    }

    /** [shiftDefs, rosterMap] for the tenant/date, or [[], []] on any issue. */
    private static function shiftContext(?int $tid, string $ymd): array
    {
        try {
            $defs = ShiftResolver::shifts($tid);
            if (! $defs) {
                return [[], []];
            }
            $roster = ShiftResolver::rosterMap($tid, $ymd, $ymd);

            return [$defs, $roster];
        } catch (\Throwable $e) {
            return [[], []];
        }
    }

    private static function userIdFor(array $emp): ?int
    {
        try {
            if (! Schema::hasTable('users')) {
                return null;
            }
            $u = DB::table('users')->where('employee_id', $emp['id'] ?? 0)->value('id');
            if ($u) {
                return (int) $u;
            }
            if (! empty($emp['email'])) {
                $u = DB::table('users')->where('email', $emp['email'])->value('id');

                return $u ? (int) $u : null;
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    private static function managerUserId(int $mgrEmpId): ?int
    {
        try {
            $u = DB::table('users')->where('employee_id', $mgrEmpId)->value('id');

            return $u ? (int) $u : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function toCarbon($on): Carbon
    {
        try {
            return $on ? Carbon::parse($on) : Carbon::now();
        } catch (\Throwable $e) {
            return Carbon::now();
        }
    }
}
