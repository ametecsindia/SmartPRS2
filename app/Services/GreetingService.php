<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F8 — Birthday & work-anniversary greetings.
 *
 * A pure back-end sweep: for a given day it finds every active employee whose
 * date-of-birth (dob) or date-of-joining (doj) falls on that calendar day and
 * sends them a greeting through the P0 {@see NotificationService} — in-app and
 * (optionally) email, EMAIL + IN-APP only (decision #5; WhatsApp parked).
 *
 * Idempotent by construction: each greeting uses a dedupe key of
 * `greeting:<kind>:<employeeId>:<year>`, so re-running the sweep on the same day
 * never double-sends. Config lives per-tenant in `app_settings` under the
 * `greetings` key (same store ConfigController uses).
 *
 * Nothing here touches payroll or any existing screen; delivery is recorded in
 * `notification_log` (the greetings "delivery log" the UI reads).
 */
class GreetingService
{
    /** Config key in app_settings. */
    public const CKEY = 'greetings';

    /** Sensible defaults so the feature works before anyone opens the config screen. */
    public static function defaults(): array
    {
        return [
            'enabled' => false,   // opt-in: HR turns it on when templates are reviewed
            'send_hour' => 9,     // 24h local hour the daily sweep should fire for this tenant
            'hide_birth_year' => true,
            'skip_inactive' => true,
            'birthday' => [
                'enabled' => true, 'email' => true, 'in_app' => true,
                'subject' => 'Happy Birthday, {{first_name}}! 🎉',
                'message' => "Dear {{name}},\n\nWishing you a very happy birthday! Thank you for being a valued part of {{company}}. We hope your day is wonderful.\n\nWarm wishes,\n{{company}} HR Team",
            ],
            'anniversary' => [
                'enabled' => true, 'email' => true, 'in_app' => true,
                'min_years' => 1,
                'subject' => 'Happy Work Anniversary, {{first_name}}!',
                'message' => "Dear {{name}},\n\nCongratulations on completing {{years}} year(s) with {{company}}! Thank you for your dedication and contribution.\n\nWarm wishes,\n{{company}} HR Team",
            ],
            // 2026-08-05 — Late-login email template. Used by LateArrivalService
            // when "Email employees on late arrival" is ON (the sending itself is
            // controlled by that toggle, NOT by the greetings master switch).
            // Extra placeholders: {{arrival_time}} {{shift_start}} {{grace}} {{late_by}}.
            'late' => [
                'enabled' => true, 'email' => true, 'in_app' => false,
                'subject' => 'Late arrival recorded — {{date}}',
                'message' => "Dear {{name}},\n\nThis is an automated notification that your attendance for {{date}} was recorded as a LATE ARRIVAL.\n\n  First punch (arrival) : {{arrival_time}}\n  Shift start + grace   : {{shift_start}} (+{{grace}} min grace)\n  Late by               : {{late_by}}\n\nPlease ensure timely arrival as per the company's attendance policy. If this is due to approved duty, travel or an exception, kindly inform your reporting manager / HR.\n\n{{company}}\n(This is a system-generated email from SmartPRS. Please do not reply.)",
            ],
        ];
    }

    /**
     * 2026-08-05 — the tenant's primary company name (first company), used when
     * an employee has no resolvable company_id and for previews/tests, so
     * templates say the real company instead of the "Your Company" placeholder.
     */
    public static function tenantCompany(?int $tenantId): string
    {
        try {
            if (Schema::hasTable('companies')) {
                return (string) (DB::table('companies')
                    ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->whereNull('deleted_at')->orderBy('id')->value('name') ?: '');
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    /** Read a tenant's greetings config merged over defaults. */
    public static function config(?int $tenantId): array
    {
        $cfg = self::defaults();
        try {
            if (Schema::hasTable('app_settings')) {
                $raw = DB::table('app_settings')
                    ->where('tenant_id', $tenantId ?? 0)
                    ->where('ckey', self::CKEY)
                    ->value('value');
                if ($raw) {
                    $saved = is_array($raw) ? $raw : json_decode($raw, true);
                    if (is_array($saved)) {
                        $cfg = array_replace_recursive($cfg, $saved);
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall back to defaults
        }

        return $cfg;
    }

    /** Persist a tenant's greetings config (whole object). */
    public static function saveConfig(?int $tenantId, array $cfg): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['tenant_id' => $tenantId ?? 0, 'ckey' => self::CKEY],
            ['value' => json_encode($cfg), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /**
     * Run the greeting sweep for one day.
     *
     * @param  int|null  $tenantId  limit to one tenant, or null = every tenant
     * @param  mixed     $onDate    the day to greet (defaults to today)
     * @param  int|null  $hourGate  if set, only run for tenants whose configured
     *                              send_hour equals this hour (used by the hourly
     *                              scheduler so each tenant fires at its own time)
     * @return array  ['sent'=>int,'skipped'=>int,'tenants'=>int]
     */
    public static function runForToday(?int $tenantId = null, $onDate = null, ?int $hourGate = null): array
    {
        $sent = 0;
        $skipped = 0;
        $tenantsRun = 0;
        try {
            if (! Schema::hasTable('employees')) {
                return ['sent' => 0, 'skipped' => 0, 'tenants' => 0];
            }
            $day = self::toCarbon($onDate);
            $md = $day->format('m-d');

            $tenantIds = $tenantId !== null
                ? [$tenantId]
                : DB::table('employees')->distinct()->pluck('tenant_id')->filter()->values()->all();

            foreach ($tenantIds as $tid) {
                $cfg = self::config($tid ? (int) $tid : null);
                if (empty($cfg['enabled'])) {
                    continue;
                }
                if ($hourGate !== null && (int) ($cfg['send_hour'] ?? 9) !== $hourGate) {
                    continue;
                }
                $tenantsRun++;

                $rows = self::candidates((int) $tid, $md, $day, $cfg);
                foreach ($rows as $r) {
                    $did = self::greet((int) $tid, $r, $day, $cfg);
                    $did ? $sent++ : $skipped++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GreetingService::runForToday failed: '.$e->getMessage());
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'tenants' => $tenantsRun];
    }

    /** Employees with a birthday or anniversary on $md for this tenant. */
    private static function candidates(int $tid, string $md, Carbon $day, array $cfg): array
    {
        $q = DB::table('employees')->where('tenant_id', $tid)->whereNull('deleted_at');
        if (! empty($cfg['skip_inactive'])) {
            $q->where('status', 'active');
        }
        // Match month-day on dob OR doj at the SQL level so we don't scan everyone.
        $q->where(function ($w) use ($md) {
            $w->whereRaw("DATE_FORMAT(dob, '%m-%d') = ?", [$md])
                ->orWhereRaw("DATE_FORMAT(doj, '%m-%d') = ?", [$md]);
        });

        return $q->get()->all();
    }

    /** Send birthday and/or anniversary greeting(s) to one employee. Returns true if any sent. */
    private static function greet(int $tid, $emp, Carbon $day, array $cfg): bool
    {
        $a = (array) $emp;
        $any = false;
        $md = $day->format('m-d');
        $year = $day->format('Y');
        // 2026-08-05 — when the employee's company_id doesn't resolve, fall back
        // to the tenant's primary company so greetings never say "our company".
        $company = self::companyName($tid, $a['company_id'] ?? null) ?: self::tenantCompany($tid);
        $userId = self::userIdFor($a);

        // Birthday
        if (! empty($cfg['birthday']['enabled']) && ! empty($a['dob'])
            && self::sameMonthDay($a['dob'], $md)) {
            $vars = self::vars($a, $company, null, $cfg);
            $id = NotificationService::sendOnce('greeting:birthday:'.$a['id'].':'.$year, [
                'tenant_id' => $tid, 'company_id' => $a['company_id'] ?? null,
                'user_id' => $userId, 'employee_id' => (int) $a['id'],
                'kind' => 'greeting.birthday',
                'title' => self::render($cfg['birthday']['subject'], $vars),
                'body' => self::render($cfg['birthday']['message'], $vars),
                'in_app' => ! empty($cfg['birthday']['in_app']),
                'email' => ! empty($cfg['birthday']['email']),
                'email_to' => $a['email'] ?? null,
                'email_to_name' => $a['name'] ?? '',
                'email_subject' => self::render($cfg['birthday']['subject'], $vars),
            ]);
            $any = $any || ($id !== null);
        }

        // Work anniversary (>= min_years)
        if (! empty($cfg['anniversary']['enabled']) && ! empty($a['doj'])
            && self::sameMonthDay($a['doj'], $md)) {
            $years = $day->year - Carbon::parse($a['doj'])->year;
            $min = (int) ($cfg['anniversary']['min_years'] ?? 1);
            if ($years >= $min) {
                $vars = self::vars($a, $company, $years, $cfg);
                $id = NotificationService::sendOnce('greeting:anniversary:'.$a['id'].':'.$year, [
                    'tenant_id' => $tid, 'company_id' => $a['company_id'] ?? null,
                    'user_id' => $userId, 'employee_id' => (int) $a['id'],
                    'kind' => 'greeting.anniversary',
                    'title' => self::render($cfg['anniversary']['subject'], $vars),
                    'body' => self::render($cfg['anniversary']['message'], $vars),
                    'in_app' => ! empty($cfg['anniversary']['in_app']),
                    'email' => ! empty($cfg['anniversary']['email']),
                    'email_to' => $a['email'] ?? null,
                    'email_to_name' => $a['name'] ?? '',
                    'email_subject' => self::render($cfg['anniversary']['subject'], $vars),
                ]);
                $any = $any || ($id !== null);
            }
        }

        return $any;
    }

    /** Build the template variable map. */
    public static function vars(array $emp, string $company, ?int $years, array $cfg): array
    {
        $name = $emp['name'] ?? 'there';
        $first = trim(explode(' ', $name)[0] ?? $name);

        return [
            'name' => $name,
            'first_name' => $first ?: $name,
            'company' => $company ?: 'our company',
            'years' => $years !== null ? (string) $years : '',
            'date' => now()->format('d M Y'),
        ];
    }

    /** Replace {{var}} tokens; unknown tokens are left blank. */
    public static function render(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '';
        }, $tpl);
    }

    private static function sameMonthDay($date, string $md): bool
    {
        try {
            return Carbon::parse($date)->format('m-d') === $md;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function companyName(int $tid, $companyId): string
    {
        try {
            if ($companyId && Schema::hasTable('companies')) {
                return (string) (DB::table('companies')->where('id', $companyId)->value('name') ?: '');
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    /** Resolve the app-user id for an employee so the greeting lands in their bell. */
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

    private static function toCarbon($on): Carbon
    {
        try {
            return $on ? Carbon::parse($on) : Carbon::now();
        } catch (\Throwable $e) {
            return Carbon::now();
        }
    }
}
