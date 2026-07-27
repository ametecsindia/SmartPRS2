<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0 Foundation — the single source of truth for "is this payroll period
 * finalised, and who may reopen it".
 *
 * SmartPRS stores generated payroll in `payroll_runs`
 * (tenant_id, company_id, cycle_label, pay_date, status enum
 * draft|locked|approved|paid, locked_at, approved_by …). PayrollGenController
 * already treats a run as immutable-from-regeneration once its status is one of
 * hr_approved|approved|locked|paid.
 *
 * F1 (statutory config) and F5 (pay cycle) both change numbers that feed
 * payroll, so before any such change lands on a past period we must know
 * whether that period is closed. Decision #2 (Ejaz, 26 Jul 2026): reopening a
 * finalised run is SUPER ADMIN ONLY — Company Admin / HR must request it and
 * everything else flows to the next-month adjustment.
 *
 * This class does NOT itself recalculate anything; it only answers the two
 * questions the guards need. Best-effort and fail-OPEN on read errors, matching
 * the rest of the codebase (availability over strictness).
 */
class PayrollLock
{
    /** Run statuses that count as "finalised" (immutable without a reopen). */
    public const FINALISED_STATUSES = ['locked', 'approved', 'hr_approved', 'paid'];

    /**
     * Is there a finalised payroll run whose period contains $on for the given
     * tenant/company? Matches on pay_date's month, then falls back to
     * cycle_label containing the YYYY-MM, so it works whether a run is keyed by
     * pay date or by a textual cycle label.
     */
    public static function isPeriodFinalised($on, ?int $tenantId = null, ?int $companyId = null): bool
    {
        try {
            if (! Schema::hasTable('payroll_runs')) {
                return false;
            }
            $ym = substr(EffectiveDated::toDate($on), 0, 7);   // YYYY-MM

            $hasPayDate = Schema::hasColumn('payroll_runs', 'pay_date');
            $hasCycle = Schema::hasColumn('payroll_runs', 'cycle_label');
            if (! $hasPayDate && ! $hasCycle) {
                // No period column to test against — cannot prove finalisation,
                // so treat the period as open (fail open).
                return false;
            }

            $q = DB::table('payroll_runs')->whereIn('status', self::FINALISED_STATUSES);
            if ($tenantId !== null && Schema::hasColumn('payroll_runs', 'tenant_id')) {
                $q->where('tenant_id', $tenantId);
            }
            if ($companyId !== null && Schema::hasColumn('payroll_runs', 'company_id')) {
                $q->where('company_id', $companyId);
            }
            $q->where(function ($w) use ($ym, $hasPayDate, $hasCycle) {
                if ($hasPayDate) {
                    $w->orWhereRaw("DATE_FORMAT(pay_date, '%Y-%m') = ?", [$ym]);
                }
                if ($hasCycle) {
                    $w->orWhere('cycle_label', 'like', '%'.$ym.'%');
                }
            });

            return $q->exists();
        } catch (\Throwable $e) {
            return false;   // fail open — never block on a read error
        }
    }

    /**
     * Return null if the current user is a Super Admin, else a fail-soft 403
     * JSON response carrying $message. Used to gate any "reopen finalised
     * payroll" or "edit a closed period" action.
     */
    public static function denyUnlessSuperAdmin($request, string $message)
    {
        try {
            $u = $request->user();
            if ($u && $u->hasRole('super_admin')) {
                return null;
            }

            return response()->json(['ok' => false, 'error' => $message, 'needs' => 'super_admin'], 403);
        } catch (\Throwable $e) {
            // If the role pivot can't be read, deny by default here: unlike menu
            // gating, reopening a closed payroll period is a destructive action
            // and should not fail OPEN.
            return response()->json(['ok' => false, 'error' => $message, 'needs' => 'super_admin'], 403);
        }
    }
}
