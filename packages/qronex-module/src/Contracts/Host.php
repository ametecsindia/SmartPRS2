<?php

namespace Ametecs\Qronex\Contracts;

use Ametecs\Qronex\Models\AttendanceEvent;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What the module needs from the application it runs in (PRS2, EPT). One small adapter per host;
 * everything else (verification, devices, approvals, screens, phone API) is shared module code.
 */
interface Host
{
    /** Short product name shown in the module header, e.g. "SmartPRS". */
    public function name(): string;

    /** URL of the host app's home, for the "Back to …" link. */
    public function homeUrl(): string;

    /** Host tenant (PRS2 tenant / EPT company) the user belongs to; null for platform super admins. */
    public function tenantIdFor(Authenticatable $user): ?int;

    /** Tenants a platform super admin may open: [id => name]. */
    public function tenants(): array;

    /** Resolve the company code typed into the phone app → tenant id, or null. */
    public function tenantBySlug(string $slug): ?int;

    /** ['name' => …, 'slug' => …] for a tenant. */
    public function tenant(int $tenantId): array;

    /** view | approve | manage | configure — see Ametecs\Qronex\Qronex::ABILITIES. */
    public function allows(Authenticatable $user, string $ability): bool;

    /** Licence / plan gate (EPT: Central plan feature). false = module can't be enabled for this tenant. */
    public function licensed(int $tenantId): bool;

    /**
     * Host employees to mirror: iterable of ['id','code','name','email','mobile','department','designation','active' => bool].
     * The host stays the master; the module only keeps a synced copy.
     */
    public function employees(int $tenantId): iterable;

    /** Write a verified punch into the host's own attendance so its reports/payroll/late rules see it. Must be idempotent (key: $event->ref). */
    public function recordPunch(AttendanceEvent $event): void;

    /** Emails of users who approve phones / remote work / corrections for this tenant. */
    public function approverEmails(int $tenantId): array;
}
