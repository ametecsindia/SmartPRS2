<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field-force geofence verdict for the Caller app (Field Force → Geofence Rule master).
 *
 * Same rule and same arithmetic as AttendanceReportController::checkGeofence (the
 * in-app self punch), returned as data instead of a JsonResponse so the stateless
 * API can hand the full verdict to the Caller server.
 *
 * ponytail: duplicated from AttendanceReportController::checkGeofence so that live
 * 49 KB controller is not touched; point the controller at this class when it is
 * next edited.
 */
class FieldForceGeofence
{
    /** Grace distance added to radius_km for each `outside` mode. */
    public const GRACE_KM = ['strict' => 0.0, '1km' => 1.0, '2km' => 2.0];

    /**
     * @return array{allowed:bool,status:string,distance_km:?float,limit_km:?float,fence:?array,message:?string}
     *   status: within | outside | no-gps | no-rule
     */
    public static function evaluate(object $emp, ?int $tenantId, $lat, $lng): array
    {
        $rule = Schema::hasTable('geofence_rules')
            ? DB::table('geofence_rules')
                ->where('employee_id', $emp->id)
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->first()
            : null;

        if (! $rule || $rule->lat === null || $rule->lng === null) {
            return ['allowed' => true, 'status' => 'no-rule', 'distance_km' => null, 'limit_km' => null, 'fence' => null, 'message' => null];
        }

        $outside = $rule->outside ?: 'strict';
        $limit = (float) $rule->radius_km + (self::GRACE_KM[$outside] ?? 0.0);
        $fence = [
            'start' => $rule->start ?: null,
            'lat' => (float) $rule->lat,
            'lng' => (float) $rule->lng,
            'radius_km' => (float) $rule->radius_km,
            'outside' => $outside,
            'limit_km' => round($limit, 3),
        ];

        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            $strict = $outside === 'strict';

            return ['allowed' => ! $strict, 'status' => 'no-gps', 'distance_km' => null, 'limit_km' => $limit, 'fence' => $fence,
                'message' => $strict ? 'Location required: turn on GPS/location to sign in — your account has a strict geofence.' : null];
        }

        $dist = self::haversineKm((float) $rule->lat, (float) $rule->lng, (float) $lat, (float) $lng);
        if ($dist > $limit) {
            return ['allowed' => false, 'status' => 'outside', 'distance_km' => round($dist, 3), 'limit_km' => $limit, 'fence' => $fence,
                'message' => 'Outside your allowed area: you are '.round($dist, 2).' km from your '.($rule->start ?: 'start').' point (limit '.round($limit, 2).' km).'];
        }

        return ['allowed' => true, 'status' => 'within', 'distance_km' => round($dist, 3), 'limit_km' => $limit, 'fence' => $fence, 'message' => null];
    }

    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
