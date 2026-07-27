<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * P0 Foundation — one uniform audit entry point for every new feature.
 *
 * The tamper-evident ledger already exists ({@see Audit} — hash-chained
 * `activity_logs`, J2 governance). What was missing was a SINGLE call shape so
 * that F1–F10 all record the same way instead of each re-resolving tenant/user/
 * IP by hand. AuditTrail is a thin, additive convenience layer on top of Audit;
 * it changes NOTHING about how the chain is written or verified.
 *
 * Usage inside a controller:
 *   AuditTrail::log($request, 'statutory.config.updated', 'statutory_config', $id, [
 *       'field' => 'esic_employee_pct', 'from' => 0.75, 'to' => 0.75,
 *       'effective_from' => '2026-08-01',
 *   ]);
 *
 * Or, when there is no request in scope (queued job, console command):
 *   AuditTrail::system($tenantId, 'greeting.sent', 'employee', $empId, [...]);
 *
 * Both delegate to Audit::record() and are best-effort — auditing never throws
 * and never blocks the business action.
 */
class AuditTrail
{
    /**
     * Record an action performed by the currently authenticated user.
     * Resolves tenant_id, user_id and client IP from the request automatically.
     */
    public static function log(Request $request, string $action, string $entity, $entityId = 0, $detail = null): void
    {
        $user = null;
        try {
            $user = $request->user();
        } catch (\Throwable $e) {
            $user = null;
        }

        $tenantId = $user->tenant_id ?? null;
        $userId = $user->id ?? null;

        $ip = null;
        try {
            $ip = $request->ip();
        } catch (\Throwable $e) {
            $ip = null;
        }

        Audit::record(
            $tenantId !== null ? (int) $tenantId : null,
            $userId !== null ? (int) $userId : null,
            $action,
            $entity,
            $entityId,
            $detail,
            $ip
        );
    }

    /**
     * Record a system / background action (no HTTP request in scope), e.g. a
     * scheduled greeting or an absence-notification sweep. user_id is null.
     */
    public static function system(?int $tenantId, string $action, string $entity, $entityId = 0, $detail = null): void
    {
        Audit::record($tenantId, null, $action, $entity, $entityId, $detail, null);
    }

    /**
     * Convenience for the common "old → new" change record, so config screens
     * (F1 statutory, F7 probation config, F10 absence config…) log field edits
     * consistently. Only fields whose value actually changed are recorded.
     */
    public static function change(Request $request, string $action, string $entity, $entityId, array $before, array $after): void
    {
        $diff = [];
        foreach ($after as $k => $v) {
            $old = $before[$k] ?? null;
            if ($old != $v) {
                $diff[$k] = ['from' => $old, 'to' => $v];
            }
        }
        if (! $diff) {
            return;   // nothing changed — no audit noise
        }
        self::log($request, $action, $entity, $entityId, ['changes' => $diff]);
    }
}
