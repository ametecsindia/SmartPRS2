<?php

namespace Ametecs\Qronex\Services;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Qronex;
use Throwable;

/**
 * Every verified punch is written into the host's own attendance (PRS2 attendance_logs / EPT biometric_logs),
 * so payroll, late marks and reports work exactly as for biometric punches. A failed write is kept and retried
 * by `php artisan qronex:sync-punches` (scheduled), never lost.
 */
class HostSync
{
    public static function push(AttendanceEvent $e): bool
    {
        try {
            Qronex::host()->recordPunch($e->loadMissing('employee', 'office', 'gate'));
            $e->forceFill(['host_synced_at' => now(), 'host_sync_error' => null])->save();

            return true;
        } catch (Throwable $ex) {
            report($ex);
            $e->forceFill(['host_sync_error' => mb_substr($ex->getMessage(), 0, 250)])->save();

            return false;
        }
    }

    /** Retry punches the host has not accepted yet. Returns [synced, failed]. */
    public static function retry(?int $tenantId = null): array
    {
        $ok = $bad = 0;
        AttendanceEvent::withoutGlobalScopes()->whereNull('host_synced_at')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')->chunkById(200, function ($rows) use (&$ok, &$bad) {
                foreach ($rows as $e) {
                    if (Qronex::enabled($e->tenant_id)) {
                        self::push($e) ? $ok++ : $bad++;
                    }
                }
            });

        return [$ok, $bad];
    }
}
