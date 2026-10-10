<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\NotifyLateArrivals;
use App\Services\FieldForceGeofence;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Field-force API for the Caller (Ametecs Managed Mobile) server — v1.
 *
 *   POST /api/v1/fieldforce/geofence/check   may this employee sign in here?
 *   POST /api/v1/fieldforce/attendance       app login/logout → attendance_logs
 *
 * Authenticated by the existing API key middleware (scope "ingest"), so the
 * tenant comes from the key, never from the body — same rule as SBB.
 *
 * Login/logout land in attendance_logs exactly like a biometric or self punch
 * (direction in/out, lat/lng, geo_status) with source "caller", so the
 * attendance report, late-arrival mails and payroll pick them up unchanged.
 */
class FieldForceApiController extends Controller
{
    public const SOURCE = 'caller';

    public const MAX_BATCH = 500;

    public function geofenceCheck(Request $request)
    {
        $v = $request->validate([
            'emp_code' => 'required|string|max:64',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);
        $tenantId = $request->attributes->get('tenant_id');
        $emp = $this->employee($tenantId, $v['emp_code']);

        if (! $emp) {
            // Not an employee in this workspace: PRS has no rule to enforce.
            return response()->json(['ok' => true, 'employee_found' => false, 'allowed' => true, 'status' => 'unknown-employee']);
        }

        $verdict = FieldForceGeofence::evaluate($emp, $tenantId, $v['lat'] ?? null, $v['lng'] ?? null);

        return response()->json(['ok' => true, 'employee_found' => true,
            'employee' => ['emp_code' => $emp->emp_code, 'name' => $emp->name]] + $verdict);
    }

    /**
     * events[]: external_id (unique per event), emp_code, type login|logout,
     *           at (ISO-8601 WITH offset), lat?, lng?, accuracy_m?
     * Returns a verdict per event: accepted | duplicate | pending (held for mapping) | unknown_employee | rejected.
     */
    public function attendance(Request $request)
    {
        $request->validate(['events' => 'required|array|min:1|max:'.self::MAX_BATCH]);
        $tenantId = (int) $request->attributes->get('tenant_id');
        $hasGeo = Schema::hasColumn('attendance_logs', 'lat');
        $hasGeoStatus = Schema::hasColumn('attendance_logs', 'geo_status');

        $results = [];
        $touched = [];
        foreach ($request->input('events') as $raw) {
            $e = is_array($raw) ? $raw : [];
            $ext = substr(trim((string) ($e['external_id'] ?? '')), 0, 96);
            $type = strtolower((string) ($e['type'] ?? ''));
            $when = self::toLocal((string) ($e['at'] ?? ''));
            $lat = isset($e['lat']) && is_numeric($e['lat']) && abs((float) $e['lat']) <= 90 ? (float) $e['lat'] : null;
            $lng = isset($e['lng']) && is_numeric($e['lng']) && abs((float) $e['lng']) <= 180 ? (float) $e['lng'] : null;

            if ($ext === '' || ! in_array($type, ['login', 'logout'], true) || $when === null) {
                $results[] = ['external_id' => $ext ?: null, 'status' => 'rejected', 'reason' => 'VALIDATION'];

                continue;
            }
            $code = substr(trim((string) ($e['emp_code'] ?? '')), 0, 64);
            $emp = $this->employee($tenantId, $code);
            if (! $emp) {
                // 6 Oct 2026 — hold, never drop: the punch shows on Pending Punches (device "CALLER")
                // and moves into attendance when an admin maps the code there.
                $held = $code !== '' && $this->hold($tenantId, $code, $ext, $when, $type === 'login' ? 'in' : 'out');
                $results[] = ['external_id' => $ext, 'status' => $held ? 'pending' : 'unknown_employee'];

                continue;
            }

            // The verdict is recomputed here: PRS owns the rule, the sender is not trusted with it.
            $geo = FieldForceGeofence::evaluate($emp, $tenantId, $lat, $lng)['status'];
            $direction = $type === 'login' ? 'in' : 'out';
            $row = [
                'tenant_id' => $emp->tenant_id,
                'company_id' => $emp->company_id,
                'emp_code' => $emp->emp_code,
                'emp_name' => $emp->name,
                'log_date' => $when->toDateString(),
                'punch_at' => $when->format('Y-m-d H:i:s'),
                'direction' => $direction,
                'source' => self::SOURCE,
                'device_sn' => 'CALLER',
                'device_user_id' => $emp->emp_code,
                'external_id' => $ext,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasGeo) {
                $row['lat'] = $lat;
                $row['lng'] = $lng;
            }
            if ($hasGeoStatus && $geo !== 'no-rule') {
                $row['geo_status'] = $geo;
            }

            // insertOrIgnore + (tenant_id, external_id) unique index: a retried event is a no-op.
            $inserted = DB::table('attendance_logs')->insertOrIgnore($row);
            $results[] = ['external_id' => $ext, 'status' => $inserted > 0 ? 'accepted' : 'duplicate', 'geo_status' => $geo];
            if ($inserted > 0 && $direction === 'in') {
                $touched[$emp->emp_code][$when->toDateString()] = true;
            }
        }

        if ($touched) {
            try {
                NotifyLateArrivals::dispatch($tenantId, $touched);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        Log::info('caller.attendance.batch', ['key' => $request->attributes->get('api_key_prefix'), 'tenant' => $tenantId,
            'received' => count($results), 'accepted' => count(array_filter($results, fn ($r) => $r['status'] === 'accepted'))]);

        return response()->json(['ok' => true, 'results' => $results]);
    }

    private function employee(?int $tenantId, string $code): ?object
    {
        $code = trim($code);
        if ($code === '' || ! $tenantId) {
            return null;
        }

        $q = fn () => DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        // Emp. Code first; else a code mapped on Pending Punches (stored in employees.device_user_id).
        return $q()->whereRaw('UPPER(emp_code) = ?', [strtoupper($code)])->first()
            ?: $q()->whereRaw('UPPER(device_user_id) = ?', [strtoupper($code)])->first();
    }

    /** Quarantine a punch for an unknown code in attendance_pending (same shape as the SBB path). */
    private function hold(int $tenantId, string $code, string $ext, Carbon $when, string $direction): bool
    {
        try {
            if (! Schema::hasTable('attendance_pending')) {
                return false;
            }
            $row = ['tenant_id' => $tenantId, 'company_id' => null, 'device_sn' => 'CALLER', 'device_user_id' => $code,
                'punch_at' => $when->format('Y-m-d H:i:s'), 'direction' => $direction, 'verify_mode' => null,
                'external_id' => $ext, 'source' => self::SOURCE, 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()];
            if (Schema::hasColumn('attendance_pending', 'device_code')) {
                $row['device_code'] = $code;
            }
            DB::table('attendance_pending')->insertOrIgnore($row);   // retried event: unique external_id → no-op

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** ISO-8601 with an offset → naive app-timezone wall clock (what attendance_logs stores). */
    public static function toLocal(string $iso): ?Carbon
    {
        if (! preg_match('~(?:Z|[+\-]\d{2}:?\d{2})$~i', trim($iso))) {
            return null;   // without an offset the instant is ambiguous — refuse rather than guess
        }
        try {
            return Carbon::parse($iso)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
