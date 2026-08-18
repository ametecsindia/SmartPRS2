<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PunchIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * SBB (Smart Biometric Bridge) public API — v1.
 *
 * Authenticated by App\Http\Middleware\ApiKeyAuth. Every identity value used
 * here (tenant_id, company_id) comes from $request->attributes, i.e. from the
 * API key. Nothing identity-bearing is ever read out of the request body.
 */
class PublicApiController extends Controller
{
    /**
     * GET /api/v1/ping
     *
     * An installer standing in a plant with a laptop must be able to confirm two
     * things from one call: that the key works, and that it points at the RIGHT
     * customer. Hence company_name. timezone + server_time let SBB detect a
     * clock or timezone mismatch before it starts shipping punches that would
     * land in the wrong hour.
     */
    public function ping(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $companyId = $request->attributes->get('company_id');

        return response()->json([
            'ok' => true,
            'app' => 'SmartPRS',
            'version' => (string) config('smartprs.version', ''),
            'tenant_id' => $tenantId,
            'company_name' => self::customerName($tenantId, $companyId),
            'scopes' => $request->attributes->get('scopes', []),
            'timezone' => (string) config('app.timezone'),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/attendance/punches
     *
     * Returns a verdict PER PUNCH. This is the most important part of the
     * contract: SBB is at-least-once, so it re-sends anything it did not see
     * acknowledged. A bare count, or a 200 that quietly dropped something, gives
     * it no way to know what actually landed — which is precisely the behaviour
     * this endpoint replaces.
     */
    public function ingestPunches(Request $request)
    {
        try {
            $validated = $request->validate([
                'punches' => 'required|array|min:1|max:'.PunchIngestService::MAX_BATCH,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_422',
                    'message' => $e->validator->errors()->first('punches') ?: 'Invalid request body.',
                ],
            ], 422);
        }

        $tenantId = $request->attributes->get('tenant_id');
        if ($tenantId === null) {
            // ApiKeyAuth already refuses a tenant-less ingest key; belt and braces.
            return response()->json([
                'error' => ['code' => 'API_KEY_401', 'message' => 'API key is not bound to a tenant.'],
            ], 401);
        }

        $out = PunchIngestService::ingest(
            $validated['punches'],
            (int) $tenantId,
            $request->attributes->get('company_id'),
            (string) $request->attributes->get('api_key_prefix', '')
        );

        return response()->json([
            'ok' => true,
            'batch' => $out['batch'],
            'results' => $out['results'],
        ]);
    }

    /**
     * The customer's name as an installer would recognise it: the company the
     * key is scoped to, else the tenant.
     */
    private static function customerName(?int $tenantId, ?int $companyId): ?string
    {
        try {
            if ($companyId && Schema::hasTable('companies')) {
                $name = DB::table('companies')->where('id', $companyId)->value('name');
                if ($name) {
                    return (string) $name;
                }
            }
            if ($tenantId && Schema::hasTable('tenants')) {
                $name = DB::table('tenants')->where('id', $tenantId)->value('name');
                if ($name) {
                    return (string) $name;
                }
            }
        } catch (\Throwable $e) {
            // A naming lookup must never fail a health check.
        }

        return null;
    }
}
