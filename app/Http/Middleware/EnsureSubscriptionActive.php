<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Subscription lock-out (rev 75). Policy (Ejaz, 4 Jun 2026):
 *
 *   active          → full access
 *   grace (7 days)  → full access + warning banner (AppController cfg)
 *   locked          → tenant ADMIN may sign in but reach ONLY
 *                     My Subscription (to renew & pay) + logout;
 *                     everyone else is signed out with a clear message.
 *
 * Platform users (no tenant_id, e.g. super admin) and tenants with no
 * subscription on record are never gated. Fail-soft by design: any internal
 * error in state resolution counts as ACTIVE.
 */
class EnsureSubscriptionActive
{
    /** Paths a locked tenant ADMIN may still use (renew + sign out + the app shell). */
    private const LOCKED_ALLOW = [
        'app/my-subscription',   // index + quote + renew/order + renew/complete + invoice pdf
        'logout',
        'app/change-password',
    ];

    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u) {
            return $next($request);
        }

        // rev 81 (team test #4): a user disabled mid-session (e.g. the super
        // admin SUSPENDED the tenant, which cascades users to 'disabled') is
        // signed out IMMEDIATELY on their next request — not at next login.
        // (Subscription-expiry suspensions do NOT disable users, so the
        // admin-renew flow below is unaffected.) Fail-soft on any DB error.
        try {
            $fresh = \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->value('status');
            if ($fresh === 'disabled') {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'error' => 'Your account has been disabled. Please contact your administrator.'], 401);
                }
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors([
                    'email' => 'Your account has been disabled. Please contact your administrator.',
                ]);
            }
        } catch (\Throwable $e) {
        }

        if (! $u->tenant_id) {
            return $next($request);   // platform / super admin — never gated
        }

        // rev 103: on-premise editions are PERPETUAL licences — there is no
        // subscription to expire. Never lock an L1/L2/L3 installation.
        if (\App\Services\Edition::isOnPrem()) {
            return $next($request);
        }

        $state = SubscriptionService::state((int) $u->tenant_id)['state'];
        if ($state !== 'locked') {
            return $next($request);   // active / grace / none
        }

        $isAdmin = false;
        try {
            $isAdmin = $u->hasRole('admin');
        } catch (\Throwable $e) {
        }

        $path = trim($request->path(), '/');

        if ($isAdmin) {
            // The SPA shell (+ read-only boot data) stays reachable so the admin
            // lands on the renew screen (boot JS forces my-subscription when
            // cfg.subState is locked). GET only — every write is blocked below.
            if ($request->isMethod('GET') && ($path === 'app' || preg_match('#^app/[a-z0-9-]*$#', $path) === 1)) {
                return $next($request);
            }
            foreach (self::LOCKED_ALLOW as $allow) {
                if ($path === $allow || str_starts_with($path, $allow.'/')) {
                    return $next($request);
                }
            }
            // Boot data endpoints fail-soft in the SPA — block them quietly.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false, 'locked' => true,
                    'error' => 'Your subscription has expired. Please renew in Administration → My Subscription.',
                ], 402);
            }

            return redirect('/app');
        }

        // Employees / other roles: sign out with a clear message.
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false, 'locked' => true,
                'error' => 'This workspace is suspended (subscription expired). Please contact your administrator.',
            ], 402);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => 'This workspace is currently suspended (subscription expired). Please contact your administrator to renew.',
        ]);
    }
}
