<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 5 locked SmartPRS roles + a baseline permission set.
 * Roles: super_admin, admin, hr_manager, field_agent, employee.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Baseline permissions (extend per module as the build progresses).
        $permissions = [
            'company.manage',
            'tenant.switch',          // super admin only — cross-tenant
            'user.manage',
            'role.manage',
            'employee.view',
            'employee.create',
            'employee.update',
            'employee.delete',
            'attendance.view',
            'attendance.sync',        // pull from ZKTeco devices
            'payroll.run',
            'payroll.view',
            'reports.view',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $roles = [
            'super_admin' => $permissions, // everything, incl. cross-tenant
            'admin' => [
                'company.manage', 'user.manage', 'role.manage',
                'employee.view', 'employee.create', 'employee.update', 'employee.delete',
                'attendance.view', 'attendance.sync', 'payroll.run', 'payroll.view', 'reports.view',
            ],
            'hr_manager' => [
                'employee.view', 'employee.create', 'employee.update',
                'attendance.view', 'attendance.sync', 'payroll.run', 'payroll.view', 'reports.view',
            ],
            'field_agent' => [
                'attendance.view',
            ],
            'employee' => [
                'attendance.view',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
