<?php

namespace Ametecs\Qronex\Http\Middleware;

use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\DeviceSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Phone API auth: Bearer token issued at activation (SHA-256 stored) + device signature when the phone has a key. Revoked/rejected → 401; module disabled → 403. */
class DeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $device = $token ? Device::withoutGlobalScopes()->with('employee.office')->where('token_hash', hash('sha256', $token))->first() : null;
        if (! $device || in_array($device->status, ['revoked', 'rejected'], true)) {
            return response()->json(['message' => 'This phone is not signed in.'], 401);
        }
        if (! Qronex::enabled($device->tenant_id)) {
            return response()->json(['message' => 'QR attendance is turned off for your company.', 'reason_code' => 'module_disabled'], 403);
        }
        if ($device->public_key && ($err = DeviceSignature::check($request, $device->public_key))) {
            $msg = ['signature_stale' => "Your phone's date/time is wrong. Turn on automatic date & time and try again.",
                'signature_replayed' => 'That request was already received. Please try again.'][$err] ?? 'This request could not be verified as coming from your approved phone.';

            return response()->json(['message' => $msg, 'reason_code' => $err], $err === 'signature_stale' || $err === 'signature_replayed' ? 409 : 401);
        }
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
