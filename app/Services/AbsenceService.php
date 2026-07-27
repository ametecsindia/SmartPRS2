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
 *   weekly-off = ShiftResolver 'off' for that date, else the configured
 *                working_days fallback when no shift is defined.
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
            // Fallback weekly pattern when no shift/roster defines the day.
            // ISO weekday: 1=Mon … 7=Sun. Default Mon–Sat working, Sun off.
            'working_days' => [1, 2, 3, 4, 5, 6],
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

            $tenantIds = $tenantId !== null ? [$tenantId]
                : DB::table('employees')->distinct()->pluck('tenant_id')->filter()->values()->all();

            foreach ($tenantIds as $tid) {
                $tid = (int) $tid;
                $cfg = self::config($tid);
                if (empty($cfg['enabled'])) {
                    continue;
                }
                if ($hourGate !== null && (int) ($cfg['send_hour'] ?? 11) !== $hourGate) {
                    continue;
                }
                $tenantsRun++;

                // Tenant-wide holiday? then nobody is absent.
                if (self::isHoliday($tid, $ymd)) {
                    continue;
                }

                // Bulk sets for this date (one query each, not per-employee).
                $present = self::presentEmpCodes($tid, $ymd);          // lower-cased emp_codes with a punch
                $onLeave = self::onLeaveEmployeeIds($tid, $ymd);       // employee ids with approved leave
                [$shiftDefs, $rosterMap] = self::shiftContext($tid, $ymd);
                $workingDays = array_map('intval', (array) ($cfg['working_days'] ?? [1, 2, 3, 4, 5, 6]));

                $emps = DB::table('employees')->where('tenant_id', $tid)
                    ->whereNull('deleted_at')->where('status', 'active')->get();

                foreach ($emps as $e) {
                    $evaluated++;
                    if (self::isAbsent($e, $ymd, $iso, $present, $onLeave, $shiftDefs, $rosterMap, $workingDays)) {
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
    public static function isAbsent($emp, string $ymd, int $iso, array $present, array $onLeave, array $shiftDefs, array $rosterMap, array $workingDays): bool
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
        // Weekly-off? Prefer the shift/roster; fall back to working_days pattern.
        $off = null;
        if ($shiftDefs) {
            try {
                $sh = ShiftResolver::resolve($shiftDefs, $rosterMap, (string) ($a['name'] ?? ''), $a['shift'] ?? null, $ymd);
                if (is_array($sh) && array_key_exists('off', $sh)) {
                    $off = (bool) $sh['off'];
                }
            } catch (\Throwable $e) {
                $off = null;
            }
        }
        if ($off === null) {
            $off = ! in_array($iso, $workingDays, true);   // no shift info → configured pattern
        }
        if ($off) {
            return false;   // weekly-off — not expected to work
        }

        return true;   // no punch, not on leave, not holiday, not weekly-off → absent
    }

    /** Send the absence notice to the employee (and optionally the manager). */
    private static function notify(int $tid, $emp, string $ymd, array $cfg): bool
    {
        $a = (array) $emp;
        $empId = (int) ($a['id'] ?? 0);
        $nice = Carbon::parse($ymd)->format('D, d M Y');
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

    private static function isHoliday(int $tid, string $ymd): bool
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
    private static function presentEmpCodes(int $tid, string $ymd): array
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
    private static function onLeaveEmployeeIds(int $tid, string $ymd): array
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
    private static function shiftContext(int $tid, string $ymd): array
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
