<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ClientUpdateController;
use App\Services\Edition;
use Closure;
use Illuminate\Http\Request;

/**
 * rev 107 — ACTIVATION GATE (SRS FR-7; Ejaz: "when entered allows to enter
 * else it will stay at admin login... wait for activation").
 *
 * On an on-prem install whose licence is missing OR EXPIRED: every protected
 * screen is blocked and the user is sent back to the login to enter a valid
 * License Code — so an expired LC truly stops the app until it is renewed.
 * rev151: the gate runs whenever licence_enforce is ON (independent of
 * APP_ENV) so expiry blocks even mid-session. Your own demo/edition installs
 * stay open because they set SMARTPRS_LICENCE_ENFORCE=false. Fail-soft: any
 * internal error lets the request through — a licence check must never take a
 * client's HR system down.
 */
class LicenseGate
{
    private const ALLOW = ['app/activate', 'logout', 'app/change-password'];

    public function handle(Request $request, Closure $next)
    {
        try {
            // rev156: EDITION DEMONSTRATIONS (/app1 /app2 /app3 /teamdemo) run on
            // the SaaS server and borrow an edition VIEW via the session
            // (edition_demo / demo_team). Edition::current() then reports l1/l2/l3,
            // which makes isOnPrem() true — but these are NOT real on-prem installs
            // and have no licence, so the gate would log the demo in and instantly
            // bounce it back to /login. Never licence-gate a demo session.
            if ($request->hasSession()
                && ($request->session()->has('edition_demo') || $request->session()->get('demo_team'))) {
                return $next($request);
            }

            // rev139: licenceValid() is expiry-aware — it is false both when
            // the install was never activated AND when a previously valid
            // licence has EXPIRED (subscription / block model). It always
            // returns true off the on-prem editions, so SaaS is untouched.
            if (! Edition::isOnPrem()
                || ! filter_var(config('smartprs.licence_enforce', true), FILTER_VALIDATE_BOOLEAN)
                || ClientUpdateController::licenceValid()) {
                return $next($request);
            }

            $path = trim($request->path(), '/');
            foreach (self::ALLOW as $a) {
                if ($path === $a || str_starts_with($path, $a.'/')) {
                    return $next($request);
                }
            }

            // Licence missing or expired → block and send the user back to the
            // LOGIN screen, where the License Code field (admins) or the hold
            // message (everyone else) is shown. The active session is ended so
            // re-entry of a valid LC happens during sign-in, per the spec.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => 'Your SmartPRS licence needs activation. Please sign in again and enter the License Code.'], 403);
            }
            try {
                \Illuminate\Support\Facades\Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } catch (\Throwable $e) {
            }

            return redirect('/login');
        } catch (\Throwable $e) {
            return $next($request);
        }
    }
}
