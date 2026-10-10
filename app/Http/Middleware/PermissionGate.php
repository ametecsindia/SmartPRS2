<?php

namespace App\Http\Middleware;

use App\Services\Hierarchy;
use Closure;
use Illuminate\Http\Request;

/**
 * 7 Oct 2026 (Ejaz) — Settings → Roles & Permissions enforced on the SERVER,
 * not only in the menu.
 *
 * Every /app/... endpoint below is tagged with its matrix module and action
 * (GET = view; decide / approve / reject = approve; delete = del; other
 * writes = create/edit). For a login whose role has a Roles & Permissions row:
 *
 *  - RESTRICT: View, Approve and Delete are enforced here — an unticked box
 *    is a 403 even if someone calls the URL directly. (Create / Edit stay
 *    with the screens and the controllers, so an employee's own leave /
 *    request / punch never breaks on a matrix detail.)
 *  - GRANT: a role with Company / Group scope that has the box ticked passes
 *    the controllers' Admin/HR-only checks for that module
 *    (ApprovalService::denyUnlessRole reads the 'sp_grant' attribute).
 *
 * Admin / Super Admin and roles with no row are untouched. Unlisted paths
 * (bootstrap data, notifications, self-service) are never gated.
 */
class PermissionGate
{
    /** path prefix (after /app/) => matrix module. Longest prefix wins. */
    private const MODULES = [
        'leaves' => 'leave',
        'attendance-report' => 'attendance', 'attendance-bulk' => 'attendance', 'attendance-corrections' => 'attendance',
        'pending-punches' => 'attendance', 'biometric-config' => 'attendance', 'absence' => 'attendance', 'api-keys' => 'attendance',
        'payroll' => 'payroll', 'payslip' => 'payroll', 'salary-runs' => 'payroll', 'salary-lines' => 'payroll',
        'live-salary' => 'payroll', 'salary-simulate' => 'payroll',
        'requests/commissions' => 'commissions', 'requests/clawbacks' => 'commissions', 'requests/bonus' => 'commissions',
        'incentive' => 'commissions', 'bank-pack' => 'commissions',
        'requests/expenses' => 'expenses', 'requests/advances' => 'expenses',
        'requests/loans' => 'loans',
        'requests/transfers' => 'employees', 'requests/increments' => 'performance', 'requests/exits' => 'performance',
        'employees' => 'employees', 'employee-import' => 'employees', 'documents-mgr' => 'employees', 'probation' => 'employees',
        'onboarding' => 'employees', 'self-onboarding' => 'employees', 'recruitment' => 'employees', 'offroll-agent' => 'employees',
        'transfers' => 'employees', 'idcard' => 'employees',
        'statutory' => 'statutory', 'statutory-config' => 'statutory', 'statutory-report' => 'statutory',
        'compliance' => 'field', 'compliance-alerts' => 'field',
        'performance' => 'performance', 'points' => 'performance', 'increments' => 'performance',
        'kb' => 'kb', 'letters' => 'kb',
        'reports' => 'reports', 'computed' => 'reports',
        'assets' => 'assets', 'asset-stock' => 'assets', 'asset-issue' => 'assets',
        'users' => 'settings', 'settings' => 'settings', 'branding' => 'settings', 'mail-settings' => 'settings',
        'wa-templates' => 'settings', 'fin-year' => 'settings', 'greetings' => 'settings', 'mobile-devices' => 'settings',
        'saas' => 'saas', 'billing' => 'saas',
    ];

    /** Own-record actions that must work whatever the matrix says. */
    private const SELF_SERVICE = [
        '#^attendance/punch#', '#^attendance-corrections(/day)?$#', '#^attendance-corrections/\d+/cancel$#',
        '#^salary-lines/\d+/acknowledge$#', '#^leaves/balances/#', '#^payslip/#',
        // 8 Oct 2026 (Ejaz) - the lists behind Apply Leave / Expense Claims are already
        // own + reporting team on the server, so an employee always sees their own.
        '#^leaves$#', '#^requests/expenses$#',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $path = ltrim((string) $request->path(), '/');
        if (! $user || ! str_starts_with($path, 'app/') || $user->hasAnyRole(['super_admin', 'admin'])) {
            return $next($request);
        }
        $rest = substr($path, 4);
        [$module, $action] = self::classify($rest, $request->method());
        if (! $module) {
            return $next($request);
        }
        $row = \App\Http\Controllers\AppDataController::roleMatrixRow($user);
        if (! $row) {
            return $next($request);
        }
        $allowed = $action === 'write'
            ? (Hierarchy::matrixAllows($user, $module, 'create') || Hierarchy::matrixAllows($user, $module, 'edit'))
            : Hierarchy::matrixAllows($user, $module, $action);

        // GRANT — a Company/Group-scope role with the box ticked acts like HR for this module.
        if ($allowed && ! Hierarchy::teamScoped($user)) {
            $request->attributes->set('sp_grant', $module);
        }
        // RESTRICT — view / approve / delete only (see class note); self-service is exempt.
        if (! $allowed && $action !== 'write' && ! self::selfService($rest)) {
            $msg = 'Your role ('.\App\Services\AccessRoles::labelOf($user).') does not have '
                .($action === 'del' ? 'Delete' : ucfirst($action)).' permission for '.ucfirst($module)
                .'. Ask your Admin to tick it in Settings → Roles & Permissions.';

            return $request->expectsJson() || $request->ajax()
                ? response()->json(['ok' => false, 'error' => $msg], 403)
                : response($msg, 403);
        }

        return $next($request);
    }

    /** [module|null, view|approve|del|write] */
    public static function classify(string $rest, string $method): array
    {
        $module = null;
        $best = -1;
        foreach (self::MODULES as $prefix => $mk) {
            if (($rest === $prefix || str_starts_with($rest, $prefix.'/')) && strlen($prefix) > $best) {
                $module = $mk;
                $best = strlen($prefix);
            }
        }
        if (! $module) {
            return [null, null];
        }
        $m = strtoupper($method);
        if ($m === 'GET' || $m === 'HEAD') {
            return [$module, 'view'];
        }
        if ($m === 'DELETE' || preg_match('#/(delete|bulk-delete|delete-all|revoke)$#', $rest)) {
            return [$module, 'del'];
        }
        if (preg_match('#/(decide|approve|reject|accounts|confirm)$#', $rest)) {
            return [$module, 'approve'];
        }

        return [$module, 'write'];
    }

    private static function selfService(string $rest): bool
    {
        foreach (self::SELF_SERVICE as $rx) {
            if (preg_match($rx, $rest)) {
                return true;
            }
        }

        return false;
    }
}
