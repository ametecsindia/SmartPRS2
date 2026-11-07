<?php

namespace Ametecs\Qronex;

use Ametecs\Qronex\Contracts\Host;
use Ametecs\Qronex\Models\Setting;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Module runtime. Timestamps are stored in the host's app timezone (Eloquent's convention): PRS2/EPT run on IST, QRONEX on UTC —
 * so never call ->utc() before saving or querying; use ->setTimezone(config('app.timezone')).
 *
 * Module runtime: the host adapter, the tenant of the current admin request, and the per-tenant enable switch. */
class Qronex
{
    public const ABILITIES = [
        'view' => 'See attendance, devices and requests',
        'approve' => 'Approve phones, remote work and punch corrections',
        'manage' => 'Offices, gates, employee binding and activation codes',
        'configure' => 'Enable/disable the module, policy and extra-phone exceptions',
    ];

    private static ?int $tenantId = null;

    public static function host(): Host
    {
        return app(Host::class);
    }

    /** Set by the admin middleware (and tests); models scope to it. */
    public static function setTenant(?int $id): void
    {
        self::$tenantId = $id;
    }

    public static function tenantId(): ?int
    {
        return self::$tenantId;
    }

    public static function settings(int $tenantId): Setting
    {
        return Setting::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenantId], Setting::defaults());
    }

    /** Enabled by this tenant's admin AND allowed by the host licence. Disabled = phone API, displays and host writes all stop. */
    public static function enabled(int $tenantId): bool
    {
        return (bool) Setting::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('enabled') && self::host()->licensed($tenantId);
    }

    public static function can(?Authenticatable $user, string $ability): bool
    {
        return $user !== null && self::host()->allows($user, $ability);
    }
}
