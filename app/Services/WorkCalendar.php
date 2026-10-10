<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7 Oct 2026 (Ejaz) — ONE answer to "is this a working day for this person?"
 * shared by attendance, the absence sweep, leave day-counts and payroll.
 *
 *  Weekly offs (Attendance → Working Shifts): tenant rule, optionally
 *  overridden per company (rates.weekly_offs_company[<company id>]):
 *    weekly_off_day   sunday … saturday
 *    sat_off_mode     none | all | 2_4 | 1_3 | custom
 *    sat_off_weeks    [1..5] — which Saturdays are off when mode = custom
 *
 *  Holidays (Leave → Holidays): a holiday whose "Applies To" is blank / All
 *  is for everyone; otherwise it applies only to the companies or branches
 *  named there (comma-separated, case-insensitive).
 */
class WorkCalendar
{
    /** Tenant rates with the company's weekly-off override merged in; carries the company name for holiday scoping. */
    public static function forCompany(array $rates, $companyId): array
    {
        $over = (array) (($rates['weekly_offs_company'] ?? [])[(string) $companyId] ?? []);
        foreach (['weekly_off_day', 'sat_off_mode', 'sat_off_weeks'] as $k) {
            if (array_key_exists($k, $over) && $over[$k] !== null && $over[$k] !== '') {
                $rates[$k] = $over[$k];
            }
        }
        $rates['__company'] = $companyId ? (self::companyName((int) $companyId) ?? '') : '';

        return $rates;
    }

    public static function companyName(?int $id): ?string
    {
        static $names = null;
        if (! $id) {
            return null;
        }
        if ($names === null) {
            try {
                $names = DB::table('companies')->pluck('name', 'id')->all();
            } catch (\Throwable $e) {
                $names = [];
            }
        }

        return $names[$id] ?? null;
    }

    /** 'Y-m-d' => [] (everyone) | [scope, …] (lower-cased company / branch names). */
    public static function holidays(?int $tid, string $from, string $to): array
    {
        $set = [];
        try {
            if (! Schema::hasTable('holidays')) {
                return $set;
            }
            $hasApplies = Schema::hasColumn('holidays', 'applies_to');
            $rows = DB::table('holidays')
                ->when($tid, fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', $tid)->orWhereNull('tenant_id')))
                ->when(Schema::hasColumn('holidays', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
                ->get($hasApplies ? ['date', 'applies_to'] : ['date']);
            foreach ($rows as $r) {
                $d = substr((string) $r->date, 0, 10);
                $scopes = self::scopes($hasApplies ? (string) ($r->applies_to ?? '') : '');
                if (! $scopes) {
                    $set[$d] = [];   // everyone wins over any scoped duplicate
                } elseif (! isset($set[$d]) || $set[$d]) {
                    $set[$d] = array_values(array_unique(array_merge($set[$d] ?? [], $scopes)));
                }
            }
        } catch (\Throwable $e) {
        }

        return $set;
    }

    private static function scopes(string $s): array
    {
        $out = [];
        foreach (preg_split('/[,;\/|]+/', strtolower($s)) as $p) {
            $p = trim($p);
            if ($p !== '' && ! in_array($p, ['all', 'all companies', 'everyone', 'all branches', 'company', '-', '—'], true)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** Is $ymd a holiday for someone in this company / branch? */
    public static function isHoliday(array $set, string $ymd, ?string $companyName = null, ?string $branch = null): bool
    {
        if (! array_key_exists($ymd, $set)) {
            return false;
        }
        if (! $set[$ymd]) {
            return true;
        }
        foreach ([$companyName, $branch] as $x) {
            $x = strtolower(trim((string) $x));
            if ($x !== '' && in_array($x, $set[$ymd], true)) {
                return true;
            }
        }

        return false;
    }

    /** The same holidays as a flat 'Y-m-d' => true set for one company (payroll's shape). */
    public static function holidaySetFor(array $set, ?string $companyName, ?string $branch = null): array
    {
        $out = [];
        foreach (array_keys($set) as $d) {
            if (self::isHoliday($set, $d, $companyName, $branch)) {
                $out[$d] = true;
            }
        }

        return $out;
    }

    /** Working days between two dates (inclusive) for one employee — leave day-counts. */
    public static function workingDays(string $from, string $to, ?int $tid, $emp): float
    {
        return (float) count(self::workingDates($from, $to, $tid, $emp));
    }

    /** 10 Oct 2026 — the working dates themselves ('Y-m-d', in order): weekly offs and holidays skipped. */
    public static function workingDates(string $from, string $to, ?int $tid, $emp): array
    {
        $a = (array) $emp;
        $rates = self::forCompany(\App\Http\Controllers\SettingsController::rates($tid), $a['company_id'] ?? null);
        $hol = self::holidays($tid, $from, $to);
        $out = [];
        for ($d = \Illuminate\Support\Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            if (\App\Http\Controllers\PayrollGenController::isWeekOff($d, $rates)
                || self::isHoliday($hol, $d->toDateString(), $rates['__company'], $a['branch'] ?? null)) {
                continue;
            }
            $out[] = $d->toDateString();
        }

        return $out;
    }

    public static function selfTest(): void
    {
        $set = ['2026-10-02' => [], '2026-10-20' => ['apex collections pvt. ltd.', 'hyderabad']];
        assert(self::isHoliday($set, '2026-10-02', 'Anything'));
        assert(self::isHoliday($set, '2026-10-20', 'Apex Collections Pvt. Ltd.'));
        assert(self::isHoliday($set, '2026-10-20', 'Other Co', 'Hyderabad'));
        assert(! self::isHoliday($set, '2026-10-20', 'Other Co', 'Pune'));
        assert(! self::isHoliday($set, '2026-10-03', 'Apex'));
        $r = new \ReflectionMethod(self::class, 'scopes');
        assert($r->invoke(null, 'All') === [] && $r->invoke(null, 'Apex, Meridian') === ['apex', 'meridian']);
    }
}
