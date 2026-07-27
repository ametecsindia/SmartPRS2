<?php

namespace App\Http\Middleware;

use App\Services\Edition;
use Closure;
use Illuminate\Http\Request;

/**
 * rev 103 — ON-PREMISE EDITION protection (SmartPRS-L1 / L2 / L3).
 *
 * Twin of DemoWriteGuard, but for licensing: on an on-prem edition the
 * modules outside the licence are blocked for EVERY method (GET too —
 * unlicensed data must stay invisible, not just read-only). The friendly
 * 403 doubles as an upsell. SaaS edition: this middleware does nothing.
 *
 * Rule 3 of the licensing doc: module off = invisible + locked, NEVER
 * deleted — upgrading is just a new SMARTPRS_EDITION value in .env.
 */
class EditionGuard
{
    public function handle(Request $request, Closure $next)
    {
        try {
            if (Edition::isOnPrem()) {
                $path = ltrim($request->path(), '/');

                // The SaaS marketing landing makes no sense on a client's own
                // server — send the root straight to the application.
                if ($path === '' || $path === '/') {
                    return redirect('/app');
                }

                // rev147: platform / Super-Admin surfaces are HIDDEN, not upsold
                // — a client must see no trace of them. Return a plain 404.
                foreach (Edition::platformPatterns() as $re) {
                    if (preg_match($re, $path)) {
                        abort(404);
                    }
                }

                foreach (Edition::blockedPatterns() as $re) {
                    if (preg_match($re, $path)) {
                        $msg = 'This module is not included in your ' . Edition::label()
                            . ' licence. Contact Ametecs (ejaz@ametecsindia.com · WhatsApp 9000098877) to upgrade — your data is safe and the module activates instantly.';

                        if ($request->expectsJson() || $request->ajax() || $request->isMethod('POST')) {
                            return response()->json(['ok' => false, 'error' => $msg], 403);
                        }

                        return response($msg, 403);
                    }
                }
            }
        } catch (\Throwable $e) {
            // fail open — a licence check must never take the product down
        }

        return $next($request);
    }
}
