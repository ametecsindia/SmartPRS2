<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0 Foundation — effective-dated configuration resolver.
 *
 * Several enhancement features keep a HISTORY of settings that change over time
 * and must be read "as of" a date, never just "latest":
 *   F1 Statutory Config (ESIC/PF/TDS rates effective_from a date),
 *   F5 Pay Cycle (cycle definition effective_from a date),
 *   F7 Probation Period (company default that may change).
 *
 * The existing codebase already uses an `effective_from` / `effective_date`
 * column in several places (ApprovalService, MasterController, TransferService)
 * but each resolves it by hand. This helper centralises the rule:
 *
 *   "Of all rows matching the scope whose effective_from <= $on, take the one
 *    with the LATEST effective_from. Ties break on the highest id (last saved)."
 *
 * That guarantees a finalised payroll run always re-reads the SAME config that
 * was in force for its period — the invariant behind decision #3/#4 and the
 * "never recalculate finalised payroll" rule (see {@see PayrollLock}).
 */
class EffectiveDated
{
    /**
     * The single row in force on $on for the given scope, or null.
     *
     * @param  string  $table   e.g. 'statutory_configs'
     * @param  array   $scope   equality filters, e.g. ['tenant_id'=>5,'company_id'=>2,'kind'=>'esic']
     * @param  mixed   $on       date/Carbon/string; defaults to today
     * @param  string  $col      the effective-from column name
     */
    public static function resolve(string $table, array $scope, $on = null, string $col = 'effective_from'): ?object
    {
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                return null;
            }
            $onDate = self::toDate($on);

            $q = DB::table($table);
            foreach ($scope as $k => $v) {
                if (! Schema::hasColumn($table, $k)) {
                    continue;   // ignore scope keys the table doesn't have (self-healing tolerance)
                }
                $v === null ? $q->whereNull($k) : $q->where($k, $v);
            }
            // Only rows already in force. Rows with a NULL effective_from are
            // treated as "always in force" (effective since the beginning).
            $q->where(function ($w) use ($col, $onDate) {
                $w->whereNull($col)->orWhere($col, '<=', $onDate);
            });

            return $q->orderByRaw("COALESCE($col, '1900-01-01') DESC")
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Full version history for a scope (newest first) — for the "effective from"
     * timeline UI every config screen shows.
     */
    public static function history(string $table, array $scope, string $col = 'effective_from'): array
    {
        try {
            if (! Schema::hasTable($table)) {
                return [];
            }
            $q = DB::table($table);
            foreach ($scope as $k => $v) {
                if (! Schema::hasColumn($table, $k)) {
                    continue;
                }
                $v === null ? $q->whereNull($k) : $q->where($k, $v);
            }
            $order = Schema::hasColumn($table, $col) ? $col : 'id';

            return $q->orderByDesc($order)->orderByDesc('id')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Guard used when SAVING a new effective-dated row: an effective date that
     * falls inside an ALREADY-FINALISED payroll period is only allowed for a
     * Super Admin (decision #2 — everything else flows to next-month
     * adjustment). Returns null when allowed, or a fail-soft 403 JSON response.
     *
     * @param  mixed  $request        the current request (for the user/role)
     * @param  mixed  $effectiveDate  the effective_from being saved
     */
    public static function guardFinalisedPeriod($request, $effectiveDate, ?int $tenantId = null, ?int $companyId = null)
    {
        try {
            $on = self::toDate($effectiveDate);
            if (! PayrollLock::isPeriodFinalised($on, $tenantId, $companyId)) {
                return null;   // period still open — anyone with the screen may save
            }

            return PayrollLock::denyUnlessSuperAdmin(
                $request,
                'That date falls inside a payroll month that is already finalised. '
                .'Only a Super Admin can reopen it; otherwise apply the change from next month.'
            );
        } catch (\Throwable $e) {
            return null;   // fail open — never wedge a save on the guard
        }
    }

    /** Normalise any date-ish input to a Y-m-d string. */
    public static function toDate($on = null): string
    {
        try {
            if ($on === null || $on === '') {
                return now()->toDateString();
            }
            if ($on instanceof Carbon) {
                return $on->toDateString();
            }

            return Carbon::parse($on)->toDateString();
        } catch (\Throwable $e) {
            return now()->toDateString();
        }
    }
}
