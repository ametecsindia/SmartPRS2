<?php

namespace App\Qronex;

use Ametecs\Qronex\Contracts\Host;
use Ametecs\Qronex\Models\AttendanceEvent;
use App\Services\PunchIngestService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * SmartPRS adapter for the shared QRONEX attendance module (packages/qronex-module).
 * Tenant = users.tenant_id. Employees = `employees` (emp_code). Verified QR punches go through PRS2's own
 * PunchIngestService into attendance_logs (source 'qronex'), so payroll, late arrival mails and reports treat them
 * exactly like biometric punches. Nothing else in PRS2 is changed.
 */
class Prs2Host implements Host
{
    /**
     * PRS2 role → module abilities. The module works company-wide (no team/branch scoping yet), so only company-level
     * roles get in. ponytail: give `manager` approve rights once approvals are scoped to the manager's team.
     */
    private const ROLES = [
        'super_admin' => ['view', 'approve', 'manage', 'configure'],
        'admin' => ['view', 'approve', 'manage', 'configure'],
        'hr_manager' => ['view', 'approve', 'manage'],
        'hr' => ['view', 'approve', 'manage'],
        'accountant' => ['view'],
    ];

    public function name(): string
    {
        return 'SmartPRS';
    }

    public function homeUrl(): string
    {
        return url('/app');
    }

    public function tenantIdFor(Authenticatable $user): ?int
    {
        return $user->tenant_id ? (int) $user->tenant_id : null;
    }

    public function tenants(): array
    {
        return DB::table('tenants')->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Company code in the phone app = the tenant's subdomain; a single-tenant (on-prem) install accepts any code. */
    public function tenantBySlug(string $slug): ?int
    {
        $id = DB::table('tenants')->whereNull('deleted_at')->where('subdomain', $slug)->value('id');
        if (! $id && DB::table('tenants')->whereNull('deleted_at')->count() === 1) {
            $id = DB::table('tenants')->whereNull('deleted_at')->value('id');
        }

        return $id ? (int) $id : null;
    }

    public function tenant(int $tenantId): array
    {
        $t = DB::table('tenants')->where('id', $tenantId)->first(['name', 'subdomain']);

        return ['name' => $t->name ?? 'SmartPRS', 'slug' => $t->subdomain ?: (string) $tenantId];
    }

    public function allows(Authenticatable $user, string $ability): bool
    {
        if (($user->status ?? 'active') !== 'active') {
            return false;
        }
        if (! $user->tenant_id && ! $user->hasRole('super_admin')) {
            return false; // no tenant = platform super admin only; anyone else without a tenant gets nothing
        }
        foreach ($user->getRoleNames() as $role) {
            if (in_array($ability, self::ROLES[$role] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function licensed(int $tenantId): bool
    {
        return true; // ponytail: PRS2 plans gate by module group; add a 'qr_attendance' group here if it becomes a paid add-on.
    }

    public function employees(int $tenantId): iterable
    {
        $hasDept = Schema::hasColumn('employees', 'department');
        $hasDesig = Schema::hasColumn('employees', 'designation');
        $cols = ['id', 'emp_code', 'name', 'email', 'mobile', 'status', 'deleted_at'];
        foreach (DB::table('employees')->where('tenant_id', $tenantId)->orderBy('id')->get(array_merge($cols, $hasDept ? ['department'] : [], $hasDesig ? ['designation'] : [])) as $e) {
            if (blank($e->emp_code)) {
                continue;
            }
            yield ['id' => $e->id, 'code' => $e->emp_code, 'name' => $e->name, 'email' => $e->email, 'mobile' => $e->mobile,
                'department' => $e->department ?? null, 'designation' => $e->designation ?? null,
                'active' => $e->deleted_at === null && in_array(strtolower((string) $e->status), ['active', 'probation', 'notice'], true)];
        }
    }

    public function recordPunch(AttendanceEvent $event): void
    {
        $emp = DB::table('employees')->where('tenant_id', $event->tenant_id)->where('id', $event->employee->host_employee_id)->first(['emp_code', 'company_id']);
        if (! $emp) {
            throw new RuntimeException('Employee no longer exists in SmartPRS.');
        }
        $externalId = 'qx:'.$event->ref;
        $r = PunchIngestService::ingest([[
            'external_id' => $externalId,
            'device_sn' => 'QRONEX',
            'device_user_id' => $emp->emp_code,
            'employee_code' => $emp->emp_code,
            'punch_at' => $event->recorded_at->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'direction' => $event->type,
            'verify_mode' => 'QR',
        ]], (int) $event->tenant_id, $emp->company_id ? (int) $emp->company_id : null, 'qronex', 'qronex');

        $status = $r['results'][0]['status'] ?? 'rejected';
        if (! in_array($status, ['accepted', 'duplicate'], true)) {
            throw new RuntimeException('SmartPRS did not accept the punch: '.($r['results'][0]['reason'] ?? $status));
        }
        if ($event->lat !== null && Schema::hasColumn('attendance_logs', 'lat')) {
            DB::table('attendance_logs')->where('tenant_id', $event->tenant_id)->where('external_id', $externalId)
                ->update(['lat' => $event->lat, 'lng' => $event->lng, 'geo_status' => $event->mode === 'OFFICE_QR' ? 'inside' : strtolower($event->mode)]);
        }
    }

    public function approverEmails(int $tenantId): array
    {
        return \App\Models\User::where('tenant_id', $tenantId)->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'hr_manager', 'hr']))->pluck('email')->filter()->unique()->values()->all();
    }
}
