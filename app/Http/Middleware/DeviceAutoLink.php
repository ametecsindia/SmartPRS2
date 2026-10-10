<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 7 Oct 2026 (Ejaz) — "the employee who sent the request should be linked by
 * default". The phone app opens the site as /login?app=1&device=<id>; the
 * device id is remembered in the session, and the first employee who signs
 * in on that phone becomes its owner on Mobile Devices (only when no
 * employee is linked yet — an Employee ID the phone sent at registration, or
 * one the admin picked, is never overwritten).
 */
class DeviceAutoLink
{
    public function handle(Request $request, Closure $next)
    {
        try {
            if ($request->hasSession()) {
                if ($request->query('app') && ($dev = (string) $request->query('device', '')) !== '') {
                    $request->session()->put('app_device', substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $dev), 0, 80));
                }
                $u = $request->user();
                $dev = $request->session()->get('app_device');
                if ($u && $dev) {
                    $empId = \App\Services\Hierarchy::meId($u);
                    if ($empId) {
                        \App\Http\Controllers\MobileDeviceController::ensure();
                        $code = DB::table('employees')->where('id', $empId)->value('emp_code');
                        $q = DB::table('mobile_devices')->where('device_id', $dev)
                            ->when($u->tenant_id, fn ($x) => $x->where('tenant_id', $u->tenant_id));
                        (clone $q)->whereNull('employee_id')->update(['employee_id' => $empId, 'emp_code' => $code, 'user_id' => $u->id, 'updated_at' => now()]);
                        (clone $q)->where('employee_id', $empId)->whereNull('user_id')->update(['user_id' => $u->id]);
                    }
                    $request->session()->forget('app_device');
                }
            }
        } catch (\Throwable $e) {
            // never block a page over device linking
        }

        return $next($request);
    }
}
