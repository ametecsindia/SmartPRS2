<?php

namespace App\Services;

use App\Http\Controllers\ApprovalService;

/**
 * P0 Foundation — the capability registry for the 10-feature enhancement set.
 *
 * SmartPRS gates the menu CLIENT-SIDE with AppController's PERM_NAV map (module
 * key -> nav ids) against each role's saved perms {view,create,edit,approve,del},
 * and gates writes SERVER-SIDE with ApprovalService::denyUnlessRole(). This
 * registry is the one place that records, for every NEW screen the enhancement
 * features introduce:
 *   - which existing PERM_NAV MODULE it belongs under (so the role matrix keeps
 *     working with no new module column),
 *   - the SPA nav id(s) it adds,
 *   - a human label,
 *   - the default roles allowed to WRITE it.
 *
 * Nothing here HIDES an existing screen (working rule #1). The corresponding
 * PERM_NAV / SCREENS / nav edits are applied per-phase from the P0 menu-patch
 * plan; this class is the server-side contract those edits must match, and the
 * guard the new endpoints call.
 *
 * Observed roles in this codebase: super_admin, admin, hr_manager, manager, hr,
 * employee. super_admin is implicitly allowed everywhere (denyUnlessRole).
 */
class FeaturePermissions
{
    /**
     * capability key => [
     *   'feature'  => F-number,
     *   'module'   => existing PERM_NAV module key the nav ids attach to,
     *   'nav'      => [new nav ids] (empty = enhances an existing screen only),
     *   'label'    => menu / audit label,
     *   'write'    => [roles allowed to create/edit] (super_admin always allowed),
     * ]
     */
    public const MAP = [
        'statutory.config' => [
            'feature' => 'F1', 'module' => 'statutory', 'nav' => ['statutory-config'],
            'label' => 'Statutory Configuration', 'write' => ['admin', 'hr_manager'],
        ],
        'attendance.correction' => [
            'feature' => 'F2', 'module' => 'attendance', 'nav' => ['att-correction'],
            'label' => 'Attendance Correction', 'write' => ['admin', 'hr_manager', 'manager', 'employee'],
        ],
        'attendance.correction.review' => [
            'feature' => 'F2', 'module' => 'attendance', 'nav' => [],
            'label' => 'Attendance Correction — HR review', 'write' => ['admin', 'hr_manager', 'manager'],
        ],
        'dashboard.today' => [
            'feature' => 'F3', 'module' => 'dashboard', 'nav' => [],
            'label' => 'Employee Today panel', 'write' => ['admin', 'hr_manager', 'manager', 'hr', 'employee'],
        ],
        'employee.upload' => [
            'feature' => 'F4', 'module' => 'employees', 'nav' => [],
            'label' => 'Employee Upload (DPA/PCC columns)', 'write' => ['admin', 'hr_manager'],
        ],
        'pay.cycle' => [
            'feature' => 'F5', 'module' => 'payroll', 'nav' => [],   // 'pay-cycle' nav already exists
            'label' => 'Pay Cycle', 'write' => ['admin', 'hr_manager'],
        ],
        'probation.manage' => [
            'feature' => 'F6', 'module' => 'employees', 'nav' => ['probation'],
            'label' => 'Probation Tracking', 'write' => ['admin', 'hr_manager', 'manager'],
        ],
        'probation.config' => [
            'feature' => 'F7', 'module' => 'settings', 'nav' => ['probation-config'],
            'label' => 'Probation Settings', 'write' => ['admin', 'hr_manager'],
        ],
        'greetings' => [
            'feature' => 'F8', 'module' => 'settings', 'nav' => ['greetings', 'greetings-log'],
            'label' => 'Greetings (Birthday / Anniversary)', 'write' => ['admin', 'hr_manager'],
        ],
        'employee.import' => [
            'feature' => 'F9', 'module' => 'employees', 'nav' => ['emp-import'],
            'label' => 'Employee Import Wizard', 'write' => ['admin', 'hr_manager'],
        ],
        'absence.notify' => [
            'feature' => 'F10', 'module' => 'attendance', 'nav' => ['absence-config'],
            'label' => 'Previous-Day Absence Notification', 'write' => ['admin', 'hr_manager'],
        ],
    ];

    /** Look up a capability definition, or null. */
    public static function get(string $capability): ?array
    {
        return self::MAP[$capability] ?? null;
    }

    /**
     * Server-side write guard for a capability. Returns null when allowed, or a
     * fail-soft 403 JSON response. Super Admin is always allowed
     * (delegated to ApprovalService::denyUnlessRole). Unknown capability =>
     * restrict to admin/hr_manager as a safe default.
     */
    public static function guard($request, string $capability)
    {
        $def = self::get($capability);
        $roles = $def['write'] ?? ['admin', 'hr_manager'];

        return ApprovalService::denyUnlessRole($request, $roles);
    }

    /**
     * The PERM_NAV additions the SPA must make, grouped by module — consumed by
     * the menu-patch step of each phase and by the P0 build note so the client
     * gate and this server registry never drift apart.
     *
     * Returns ['statutory' => ['statutory-config'], 'attendance' => [...], ...].
     */
    public static function navAdditionsByModule(): array
    {
        $out = [];
        foreach (self::MAP as $def) {
            if (empty($def['nav'])) {
                continue;
            }
            $out[$def['module']] = array_values(array_unique(
                array_merge($out[$def['module']] ?? [], $def['nav'])
            ));
        }

        return $out;
    }
}
