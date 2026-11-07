<?php

namespace Ametecs\Qronex\Services;

use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\Setting;
use Ametecs\Qronex\Qronex;

/**
 * The host app is the employee master. This mirrors its employees into qx_employees (code, name, contact, department, status),
 * keyed by the host employee id so a renamed code follows. Leavers are deactivated here and their phones revoked.
 * Module-only fields (office/gate binding, scan scope, phone exception) are never overwritten.
 */
class EmployeeSync
{
    /** @return array{added:int, updated:int, deactivated:int} */
    public static function run(int $tenantId): array
    {
        $n = ['added' => 0, 'updated' => 0, 'deactivated' => 0];
        $seen = [];
        $existing = Employee::withoutGlobalScopes()->where('tenant_id', $tenantId)->get()->keyBy('host_employee_id');

        foreach (Qronex::host()->employees($tenantId) as $h) {
            $seen[$h['id']] = true;
            $attrs = ['employee_code' => (string) $h['code'], 'name' => (string) $h['name'], 'email' => $h['email'] ?: null, 'mobile' => $h['mobile'] ? mb_substr($h['mobile'], 0, 20) : null,
                'department' => $h['department'] ?: null, 'designation' => $h['designation'] ?: null];
            $e = $existing->get($h['id']);
            if (! $e) {
                if ($h['active']) {
                    Employee::create($attrs + ['tenant_id' => $tenantId, 'host_employee_id' => $h['id'], 'status' => 'active']);
                    $n['added']++;
                }

                continue;
            }
            $e->fill($attrs);
            if ($h['active'] && $e->status !== 'active') {
                $e->status = 'active';
            }
            if ($e->isDirty()) {
                $e->save();
                $n['updated']++;
            }
            if (! $h['active'] && $e->status === 'active') {
                $e->deactivate(null, 'Employee inactive in '.Qronex::host()->name());
                $n['deactivated']++;
            }
        }
        foreach ($existing as $hostId => $e) {
            if (! isset($seen[$hostId]) && $e->status === 'active') {
                $e->deactivate(null, 'Employee removed in '.Qronex::host()->name());
                $n['deactivated']++;
            }
        }
        Setting::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenantId], ['employees_synced_at' => now()] + (Setting::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists() ? [] : Setting::defaults()));

        return $n;
    }
}
