<?php

namespace App\Http\Middleware;

use App\Http\Controllers\DemoAccessController;
use Closure;
use Illuminate\Http\Request;

/**
 * rev 99 — PUBLIC DEMO protection (Ejaz: "some sensitive information should
 * not be displayed, and some important inter-dependent features should not
 * allow to delete or edit, in demo").
 *
 * For users of the SHARED demo workspace only: destructive or sensitive
 * write-endpoints are blocked SERVER-SIDE (a friendly 403), so a visitor can
 * never change the demo password, delete companies/employees, edit settings
 * or wreck the structure other visitors depend on. Trying features (apply
 * leave, add commission, generate payroll, run a hiring drive…) stays fully
 * allowed — the 3-hour reset cleans those up. Real tenants are unaffected.
 */
class DemoWriteGuard
{
    /** Blocked path patterns (regex on the relative path) for demo users. */
    private const BLOCKED = [
        // Logins, roles & passwords — a visitor could lock everyone out.
        '#^app/users#',
        '#^app/roles#',
        '#^app/change-password$#',
        // Platform/tenant config & credentials.
        '#^app/mail-settings#',
        '#^app/branding#',
        '#^app/master/(wa-settings|sms-settings|sms-templates)(/|$)#',
        '#^app/wa-templates#',
        // Company structure & core policy masters (inter-dependent skeleton).
        '#^app/master/(companies|late-policy|leave-types|designations|departments|branches|salary-setup|salary-schedules)/\d+/delete$#',
        '#^app/master/companies$#',
        // Deleting people (single, bulk, exits) breaks every other screen.
        '#^app/employees/bulk-delete$#',
        '#^app/master/.+/\d+/delete$#',
        '#^app/requests/exits#',
        // Money: the demo must never reach a payment gateway.
        '#^app/my-subscription/(quote|renew)#',
        '#^app/fin-year/set$#',
        // rev 107: licence + self-update machinery is never for demo visitors.
        '#^app/activate$#',
        '#^app/updates/#',
        '#^admin/(onprem|releases)#',
    ];

    public function handle(Request $request, Closure $next)
    {
        try {
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                // rev 105: TEAM demos (/teamdemo, /app1-3, PIN-entered) are
                // UNRESTRICTED — the Ametecs team demonstrates everything
                // personally. Only the public OTP /demo stays write-guarded.
                if ($request->session()->get('demo_team')) {
                    return $next($request);
                }
                $u = $request->user();
                if ($u && DemoAccessController::isDemoTenant($u->tenant_id)) {
                    $path = ltrim($request->path(), '/');
                    foreach (self::BLOCKED as $re) {
                        if (preg_match($re, $path)) {
                            return response()->json([
                                'ok' => false,
                                'error' => 'This action is disabled in the live demo — in your own SmartPRS workspace it works fully. Sign up at smartprs.com/signup.',
                            ], 403);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // fail open — never break real tenants over the demo check
        }

        return $next($request);
    }
}
