<?php

namespace Ametecs\Qronex\Http\Middleware;

use Ametecs\Qronex\Qronex;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin screens: host user must have the ability; sets the module tenant (platform super admins pick one, kept in the session).
 * When the module is disabled only the Settings page (to turn it on) is reachable.
 */
class AdminAccess
{
    public function handle(Request $request, Closure $next, string $ability = 'view'): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest(Qronex::host()->homeUrl()); // sign in to the host app first (its own login page)
        }
        abort_unless(Qronex::can($user, $ability), 403, 'You do not have access to this part of QR Attendance.');

        $tid = Qronex::host()->tenantIdFor($user);
        if ($tid === null) {
            $tenants = Qronex::host()->tenants();
            if ($request->filled('tenant') && isset($tenants[(int) $request->query('tenant')])) {
                $request->session()->put('qronex_tenant', (int) $request->query('tenant'));
            }
            $tid = $request->session()->get('qronex_tenant') ?? (count($tenants) === 1 ? array_key_first($tenants) : null);
            if ($tid === null) {
                return response()->view('qronex::tenant-picker', ['tenants' => $tenants]);
            }
        }
        Qronex::setTenant($tid);

        if (! Qronex::enabled($tid) && ! $request->routeIs('qronex.settings*')) {
            return redirect()->route('qronex.settings');
        }

        return $next($request);
    }
}
