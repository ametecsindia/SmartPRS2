<?php

namespace App\Services;

use App\Support\SchemaHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F7 (configurable probation period) + F6 (completion reminders).
 *
 * Builds on the EXISTING employment-stage flag: an employee is "on probation"
 * when `employees.employment_stage = 'probation'` (rev174/176 — probation &
 * internship skip PF/PT/TDS). This service adds:
 *   - a configurable probation DURATION (company default months + per-employee
 *     override), from which the probation END date is derived (doj + months);
 *   - CONFIRM (make permanent — clears the stage so statutory resumes) and
 *     EXTEND (push the end date, keep on probation) actions with history;
 *   - scheduled REMINDERS to HR + reporting manager at configurable milestones
 *     (default 30/15/7/0 days before end, plus an overdue nudge), via the P0
 *     NotificationService (email + in-app), idempotent per milestone.
 *
 * Additive columns are self-healed on employees; history lands in a small
 * `probation_events` table. Nothing here recalculates finalised payroll.
 */
class ProbationService
{
    public const CKEY = 'probation';

    public static function defaults(): array
    {
        return [
            'default_months' => 6,
            'reminder_days' => [30, 15, 7, 0],   // days before end to nudge
            'overdue_nudge' => true,             // one nudge once the end date passes unconfirmed
            'notify_hr' => true,
            'notify_manager' => true,
            'notify_employee_on_confirm' => true,
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

    /** Self-heal the additive employee columns + the history table. */
    public static function ensureSchema(): void
    {
        SchemaHelper::ensureColumns('employees', [
            'probation_months' => fn ($t) => $t->unsignedSmallInteger('probation_months')->nullable(),
            'probation_end' => fn ($t) => $t->date('probation_end')->nullable(),
            'probation_confirmed_on' => fn ($t) => $t->date('probation_confirmed_on')->nullable(),
            'probation_confirmed_by' => fn ($t) => $t->string('probation_confirmed_by')->nullable(),
        ]);
        SchemaHelper::ensureTable('probation_events', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('action', 30);            // confirmed|extended|reminded|config
            $t->string('details', 1000)->nullable();
            $t->date('effective')->nullable();   // new end date on extend, confirm date on confirm
            $t->string('by')->nullable();
            $t->timestamp('at')->useCurrent();
        });
    }

    /** Is this employee currently on probation (by the existing stage flag)? */
    public static function isOnProbation($emp): bool
    {
        $stage = strtolower(trim((string) (((array) $emp)['employment_stage'] ?? '')));

        return $stage === 'probation';
    }

    /** The probation end date: explicit column, else doj + months(override|default). */
    public static function endDate($emp, array $cfg): ?Carbon
    {
        $a = (array) $emp;
        if (! empty($a['probation_end'])) {
            try {
                return Carbon::parse($a['probation_end'])->startOfDay();
            } catch (\Throwable $e) {
            }
        }
        if (empty($a['doj'])) {
            return null;
        }
        $months = (int) ($a['probation_months'] ?? 0) ?: (int) ($cfg['default_months'] ?? 6);
        try {
            return Carbon::parse($a['doj'])->startOfDay()->addMonths($months);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** True once confirmed (permanent). */
    public static function isConfirmed($emp): bool
    {
        return ! empty(((array) $emp)['probation_confirmed_on']);
    }

    /**
     * Employees whose probation is upcoming or overdue for a tenant, with the
     * computed end date + days-left — powers the HR "Probation" screen and the
     * reminder sweep.
     */
    public static function board(int $tenantId, $onDate = null, ?array $cfg = null): array
    {
        $cfg ??= self::config($tenantId);
        $today = self::toCarbon($onDate)->startOfDay();
        $out = [];
        try {
            if (! Schema::hasColumn('employees', 'employment_stage')) {
                return [];
            }
            $rows = DB::table('employees')->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('employment_stage', 'probation')
                ->get();
            foreach ($rows as $e) {
                if (self::isConfirmed($e)) {
                    continue;
                }
                $end = self::endDate($e, $cfg);
                if (! $end) {
                    continue;
                }
                $out[] = [
                    'employee_id' => (int) $e->id,
                    'name' => $e->name ?? '',
                    'doj' => $e->doj ?? null,
                    'probation_end' => $end->toDateString(),
                    'days_left' => (int) $today->diffInDays($end, false),
                    'overdue' => $end->lt($today),
                ];
            }
            usort($out, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);
        } catch (\Throwable $e) {
            Log::warning('ProbationService::board failed: '.$e->getMessage());
        }

        return $out;
    }

    /**
     * Send due reminders for a tenant (or all). Milestones from config; each
     * (employee, milestone, end-date) fires once (dedupe). Returns count sent.
     */
    public static function runReminders(?int $tenantId = null, $onDate = null): int
    {
        self::ensureSchema();
        $sent = 0;
        $today = self::toCarbon($onDate)->startOfDay();
        $tenantIds = $tenantId !== null ? [$tenantId]
            : DB::table('employees')->distinct()->pluck('tenant_id')->filter()->values()->all();

        foreach ($tenantIds as $tid) {
            $tid = (int) $tid;
            $cfg = self::config($tid);
            $milestones = array_map('intval', (array) ($cfg['reminder_days'] ?? [30, 15, 7, 0]));
            foreach (self::board($tid, $today, $cfg) as $row) {
                $daysLeft = $row['days_left'];
                $milestone = null;
                if (in_array($daysLeft, $milestones, true)) {
                    $milestone = (string) $daysLeft;
                } elseif ($daysLeft < 0 && ! empty($cfg['overdue_nudge'])) {
                    $milestone = 'overdue';
                }
                if ($milestone === null) {
                    continue;
                }
                $sent += self::notify($tid, $row, $milestone, $cfg);
            }
        }

        return $sent;
    }

    /** Notify HR + manager about one employee's probation milestone (idempotent). */
    private static function notify(int $tid, array $row, string $milestone, array $cfg): int
    {
        $empId = $row['employee_id'];
        $end = $row['probation_end'];
        $when = $milestone === 'overdue'
            ? "is overdue (ended {$end})"
            : ($milestone === '0' ? "completes today ({$end})" : "completes in {$milestone} days ({$end})");
        $title = "Probation review: {$row['name']} {$when}";
        $body = "{$row['name']}'s probation {$when}. Please confirm, extend, or update their status in the Probation screen.";
        $dedupeBase = "probation:{$empId}:{$end}:{$milestone}";

        $recipients = self::recipientUserIds($tid, $empId, $cfg);
        $count = 0;
        foreach ($recipients as $uid) {
            $id = NotificationService::sendOnce($dedupeBase.':u'.$uid, [
                'tenant_id' => $tid, 'user_id' => $uid, 'employee_id' => $empId,
                'kind' => $milestone === 'overdue' ? 'probation.overdue' : 'probation.due',
                'title' => $title, 'body' => $body, 'url' => 'probation',
                'in_app' => true, 'email' => true,
                'email_subject' => $title,
            ]);
            if ($id) {
                $count++;
            }
        }
        if ($count) {
            self::event($tid, $empId, 'reminded', "milestone={$milestone}, recipients={$count}", null, 'system');
        }

        return $count;
    }

    /** HR/admin users of the tenant + the employee's reporting manager's user. */
    private static function recipientUserIds(int $tid, int $empId, array $cfg): array
    {
        $ids = [];
        try {
            if (! empty($cfg['notify_hr']) && Schema::hasTable('users')) {
                // Users in this tenant that carry an admin/HR role, via spatie pivot.
                $hr = DB::table('users')
                    ->when(Schema::hasColumn('users', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                    ->join('model_has_roles', function ($j) {
                        $j->on('model_has_roles.model_id', '=', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User');
                    })
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereIn('roles.name', ['admin', 'hr_manager', 'hr', 'super_admin'])
                    ->pluck('users.id')->all();
                $ids = array_merge($ids, $hr);
            }
        } catch (\Throwable $e) {
            // pivot shape differences — skip HR fan-out rather than fail
        }
        try {
            if (! empty($cfg['notify_manager']) && Schema::hasTable('employees')) {
                $emp = DB::table('employees')->where('id', $empId)->first();
                $mgrEmpId = $emp->reporting_manager_id ?? null;
                if ($mgrEmpId && Schema::hasTable('users')) {
                    $mu = DB::table('users')->where('employee_id', $mgrEmpId)->value('id');
                    if ($mu) {
                        $ids[] = (int) $mu;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /**
     * Confirm probation → make permanent. Clears the employment_stage flag so
     * PF/PT/TDS resume next run; stamps confirmed_on/by; logs history + audit.
     * Guarded by the caller (FeaturePermissions 'probation.manage').
     */
    public static function confirm(int $tid, int $empId, string $by, ?string $remarks = null, $onDate = null): bool
    {
        self::ensureSchema();
        try {
            $emp = DB::table('employees')->where('tenant_id', $tid)->where('id', $empId)->first();
            if (! $emp) {
                return false;
            }
            $date = self::toCarbon($onDate)->toDateString();
            $upd = ['probation_confirmed_on' => $date, 'probation_confirmed_by' => mb_substr($by, 0, 200), 'updated_at' => now()];
            if (Schema::hasColumn('employees', 'employment_stage')) {
                $upd['employment_stage'] = '';   // permanent
            }
            DB::table('employees')->where('id', $empId)->update($upd);
            self::event($tid, $empId, 'confirmed', $remarks ?: 'Probation confirmed — employee made permanent.', $date, $by);
            AuditTrail::system($tid, 'probation.confirmed', 'employee', $empId, ['by' => $by, 'on' => $date, 'remarks' => $remarks]);

            // optional courtesy note to the employee
            $cfg = self::config($tid);
            if (! empty($cfg['notify_employee_on_confirm'])) {
                $uid = self::employeeUserId($empId);
                NotificationService::send([
                    'tenant_id' => $tid, 'user_id' => $uid, 'employee_id' => $empId,
                    'kind' => 'probation.confirmed',
                    'title' => 'Your probation is confirmed 🎉',
                    'body' => 'Congratulations — your probation period is complete and your employment is now confirmed as permanent.',
                    'in_app' => true, 'email' => true,
                    'email_to' => $emp->email ?? null,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('ProbationService::confirm failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Extend probation to a new end date (mandatory remarks per the roadmap).
     * Keeps employment_stage='probation'; logs history + audit.
     */
    public static function extend(int $tid, int $empId, string $newEnd, string $by, string $remarks): bool
    {
        self::ensureSchema();
        try {
            $end = Carbon::parse($newEnd)->toDateString();
            DB::table('employees')->where('tenant_id', $tid)->where('id', $empId)
                ->update(['probation_end' => $end, 'updated_at' => now()]);
            self::event($tid, $empId, 'extended', $remarks, $end, $by);
            AuditTrail::system($tid, 'probation.extended', 'employee', $empId, ['to' => $end, 'by' => $by, 'remarks' => $remarks]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ProbationService::extend failed: '.$e->getMessage());

            return false;
        }
    }

    /** History rows for one employee (newest first). */
    public static function history(int $tid, int $empId): array
    {
        try {
            if (! Schema::hasTable('probation_events')) {
                return [];
            }

            return DB::table('probation_events')->where('tenant_id', $tid)->where('employee_id', $empId)
                ->orderByDesc('id')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function event(int $tid, int $empId, string $action, ?string $details, ?string $effective, string $by): void
    {
        try {
            DB::table('probation_events')->insert([
                'tenant_id' => $tid, 'employee_id' => $empId, 'action' => $action,
                'details' => $details ? mb_substr($details, 0, 1000) : null,
                'effective' => $effective, 'by' => mb_substr($by, 0, 200), 'at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function employeeUserId(int $empId): ?int
    {
        try {
            $u = DB::table('users')->where('employee_id', $empId)->value('id');

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
