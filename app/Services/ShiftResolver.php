<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev173 — Working Shifts resolution.
 *
 * One shared answer to "what shift is this employee on, on this date?" used by
 * BOTH the Attendance Report and Payroll generation, so the two never disagree.
 *
 * Precedence (most specific wins):
 *   1. Roster entry for that employee+date (Employees → Roster)
 *   2. The employee's default shift (employees.shift, set on the employee form)
 *   3. null → caller falls back to Late Policy shift_start/shift_end,
 *      then the legacy hardcoded 09:30–18:30.
 *
 * A shift whose end time is earlier than (or equal to) its start time crosses
 * midnight = NIGHT shift; night_allowance (Rs) is paid per night actually
 * worked by the payroll engine.
 *
 * Everything is guarded — missing tables/columns simply resolve to null, so
 * pre-shift deployments behave exactly as before.
 */
class ShiftResolver
{
    /**
     * All active shifts for a tenant, keyed by lowercase name.
     * Each: name, start ('HH:MM'), end, night (bool), grace (int|null),
     * full_hours (float|null), half_hours (float|null), break_budget (int|null),
     * allowance (float).
     */
    public static function shifts($tid): array
    {
        if (! Schema::hasTable('shifts')) {
            return [];
        }
        try {
            $rows = DB::table('shifts')
                ->when($tid && Schema::hasColumn('shifts', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->get();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            if (($a['status'] ?? '') === 'inactive') {
                continue;
            }
            $start = self::hm($a['start_time'] ?? '');
            $end = self::hm($a['end_time'] ?? '');
            if (! $start || ! $end || ! ($a['name'] ?? '')) {
                continue;   // unusable row — name + both times are required
            }
            $out[mb_strtolower(trim($a['name']))] = [
                'name' => trim($a['name']),
                'start' => $start,
                'end' => $end,
                'night' => self::toMin($end) <= self::toMin($start),
                'grace' => isset($a['grace_min']) && $a['grace_min'] !== null && $a['grace_min'] !== '' ? (int) $a['grace_min'] : null,
                'full_hours' => ! empty($a['full_day_hours']) ? (float) $a['full_day_hours'] : null,
                'half_hours' => ! empty($a['half_day_hours']) ? (float) $a['half_day_hours'] : null,
                'break_budget' => isset($a['break_budget']) && $a['break_budget'] !== null && $a['break_budget'] !== '' ? (int) $a['break_budget'] : null,
                'allowance' => ! empty($a['night_allowance']) ? (float) $a['night_allowance'] : 0.0,
            ];
        }

        return $out;
    }

    /**
     * Roster overrides for a date range, keyed by lowercase-employee-NAME.'|'.date:
     * ['shift' => name|'', 'off' => bool]. (The roster master stores the employee
     * display name, same as every other master screen.)
     */
    public static function rosterMap($tid, string $from, string $to): array
    {
        if (! Schema::hasTable('roster')) {
            return [];
        }
        try {
            $rows = DB::table('roster')
                ->when($tid && Schema::hasColumn('roster', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->whereBetween('date', [$from, $to])
                ->get(['employee', 'date', 'shift', 'status']);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $emp = mb_strtolower(trim((string) $r->employee));
            if ($emp === '' || ! $r->date) {
                continue;
            }
            $date = substr((string) $r->date, 0, 10);
            $out[$emp.'|'.$date] = [
                'shift' => trim((string) ($r->shift ?? '')),
                'off' => strtolower(trim((string) ($r->status ?? ''))) === 'off',
            ];
        }

        return $out;
    }

    /**
     * Resolve the shift for one employee on one date.
     * Returns the shift array (see shifts()) plus 'source' (roster|employee),
     * and 'off' => true when the roster marks the day a week-off —
     * or null when nothing resolves (caller falls back to Late Policy).
     */
    public static function resolve(array $shifts, array $rosterMap, string $empName, ?string $empDefault, string $date): ?array
    {
        $ro = $rosterMap[mb_strtolower(trim($empName)).'|'.$date] ?? null;
        if ($ro) {
            if ($ro['off']) {
                // Week-off from the roster wins even without a named shift.
                $s = ($ro['shift'] !== '' ? ($shifts[mb_strtolower($ro['shift'])] ?? null) : null)
                    ?: ($empDefault ? ($shifts[mb_strtolower(trim($empDefault))] ?? null) : null);

                return ($s ?: ['name' => '', 'start' => null, 'end' => null, 'night' => false, 'grace' => null, 'full_hours' => null, 'half_hours' => null, 'break_budget' => null, 'allowance' => 0.0])
                    + ['off' => true, 'source' => 'roster'];
            }
            if ($ro['shift'] !== '' && isset($shifts[mb_strtolower($ro['shift'])])) {
                return $shifts[mb_strtolower($ro['shift'])] + ['off' => false, 'source' => 'roster'];
            }
        }
        if ($empDefault && isset($shifts[mb_strtolower(trim($empDefault))])) {
            return $shifts[mb_strtolower(trim($empDefault))] + ['off' => false, 'source' => 'employee'];
        }

        return null;
    }

    /** Normalise a time string to 'HH:MM' (accepts '9:30', '09:30:00'…), or null. */
    public static function hm($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '' || ! preg_match('/^(\d{1,2}):(\d{2})/', $v, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $i);
    }

    /** 'HH:MM' → minutes since midnight. */
    public static function toMin(string $hm): int
    {
        $p = array_pad(explode(':', $hm), 2, '0');

        return ((int) $p[0]) * 60 + (int) $p[1];
    }
}
