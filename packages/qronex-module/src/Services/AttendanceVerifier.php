<?php

namespace Ametecs\Qronex\Services;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\AttendanceRejection;
use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\Gate;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Models\RemotePermission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The 11 server-side checks from the Stage 1 prototype. First failure stops evaluation (later checks = "skip").
 * Every attempt is stored: verified → attendance_events, failed → attendance_rejections.
 */
class AttendanceVerifier
{
    private array $checks = [];
    private ?array $fail = null;

    /** @param array{qr:string,lat:float,lng:float,accuracy:float,mocked:bool,type:?string} $in */
    public function scan(Device $device, array $in, ?string $ip = null): array
    {
        $this->checks = [];
        $this->fail = null;
        $employee = $device->employee;
        $settings = Qronex::settings($device->tenant_id);
        $type = $in['type'] ?: self::nextType($employee->id);
        $gate = null;
        $distance = null;

        $this->add('EMPLOYEE_INACTIVE', 'Employee account active', $employee->status === 'active', "{$employee->name} · {$employee->employee_code}".($employee->status === 'active' ? '' : " · {$employee->status}"));
        $this->add('DEVICE_NOT_APPROVED', 'Device registered and approved', $device->status === 'approved', "{$device->model} · {$device->status}");
        $this->add('AUTH', 'User authentication valid', true, 'Device token valid');

        $p = QrToken::parse($in['qr']);
        if ($p) {
            $gate = Gate::withoutGlobalScopes()->with('office')->where('tenant_id', $device->tenant_id)->find($p['gate_id']);
        }
        $this->add('QR_INVALID', 'QR cryptographic validity', $p && $gate && QrToken::validSignature($gate, $p), $gate ? 'Signature matches this company’s gate' : 'Not a QR code of this company');

        $age = $p ? QrToken::age($p) : null;
        $limit = ($p['interval'] ?? 0) + $settings->grace_s;
        $this->add('QR_EXPIRED', 'QR time window acceptable', $p && $age >= 0 && $age <= $limit, $p ? "Token age {$age} s · limit {$limit} s ({$p['interval']} s rotation + {$settings->grace_s} s grace)" : '');

        $office = $gate?->office;
        $this->add('GATE_INACTIVE', 'Office and gate active', $gate && $gate->is_active && $office?->is_active, $gate ? "{$office?->name} · {$gate->name}" : '');
        $blocked = $gate ? $employee->scanBlockedAt($gate) : 'Unknown gate';
        $this->add('OFFICE_NOT_ASSIGNED', 'Employee authorised for this office and gate', $office && $blocked === null,
            $blocked ?? (Employee::SCAN_SCOPES[$employee->scan_scope ?: 'office'] ?? '').' · '.($office?->name ?? ''));

        $acc = (int) round($in['accuracy']);
        $this->add('LOW_GPS_ACCURACY', 'Reported location accuracy', $acc <= $settings->gps_accuracy_m, "±{$acc} m reported · policy ±{$settings->gps_accuracy_m} m");

        if ($office) {
            $distance = (int) round(self::metres($in['lat'], $in['lng'], $office->lat, $office->lng));
        }
        $this->add('OUTSIDE_GEOFENCE', 'Geofence policy', $office && $distance <= $office->geofence_radius_m, $office ? "{$distance} m from office centre · radius {$office->geofence_radius_m} m" : '');

        // ponytail: only the OS mock-location flag today; Play Integrity / App Attest belong to the hardening stage.
        $this->add('MOCK_LOCATION', 'Device integrity signals', ! $in['mocked'], $in['mocked'] ? 'Mock / fake location app detected' : 'Mock-location flag clear (Play Integrity / App Attest: later stage)');

        $last = AttendanceEvent::withoutGlobalScopes()->where('employee_id', $employee->id)->latest('recorded_at')->first();
        $dupe = $last && $last->type === $type && $last->recorded_at->diffInMinutes(now()) < $settings->duplicate_window_min;
        $replay = $p && $gate && AttendanceEvent::withoutGlobalScopes()->where(['employee_id' => $employee->id, 'gate_id' => $gate->id, 'qr_window' => $p['window']])->exists();
        $this->add('DUPLICATE', 'Duplicate / replay check', ! $dupe && ! $replay, $dupe ? "Already punched {$type} {$last->recorded_at->diffForHumans()} (window {$settings->duplicate_window_min} min)" : ($replay ? 'This QR code was already used by you' : 'No duplicate in window'));

        $device->forceFill(['last_seen_at' => now()])->save();

        if (! $this->fail) {
            try {
                $event = AttendanceEvent::create([
                    'ref' => self::ref('QRX'), 'tenant_id' => $device->tenant_id, 'employee_id' => $employee->id, 'device_id' => $device->id,
                    'office_id' => $office->id, 'gate_id' => $gate->id, 'type' => $type, 'mode' => 'OFFICE_QR', 'qr_window' => $p['window'],
                    'lat' => $in['lat'], 'lng' => $in['lng'], 'accuracy_m' => $acc, 'distance_m' => $distance, 'recorded_at' => now(),
                ]);

                $event->setRelation('office', $office)->setRelation('gate', $gate)->setRelation('employee', $employee);
                HostSync::push($event);

                return ['ok' => true, 'event' => $event, 'checks' => $this->checks];
            } catch (UniqueConstraintViolationException) {
                $this->checks[array_key_last($this->checks)] = ['label' => 'Duplicate / replay check', 'status' => 'bad', 'detail' => 'This QR code was already used by you'];
                $this->fail = ['code' => 'DUPLICATE', 'reason' => 'This QR code was already used by you'];
            }
        }

        $rej = AttendanceRejection::create([
            'ref' => self::ref('RJ'), 'tenant_id' => $device->tenant_id, 'employee_id' => $employee->id, 'device_id' => $device->id, 'gate_id' => $gate?->id,
            'reason_code' => $this->fail['code'], 'reason' => mb_substr($this->fail['reason'], 0, 250), 'checks' => $this->checks,
            'lat' => $in['lat'], 'lng' => $in['lng'], 'accuracy_m' => min($acc, 65535), 'ip' => $ip,
        ]);

        return ['ok' => false, 'reason_code' => $this->fail['code'], 'reason' => $this->fail['reason'], 'ref' => $rej->ref, 'checks' => $this->checks];
    }

    /**
     * Remote check-in (no QR): needs an approved permission covering today. Location must be inside the permission's
     * radius unless the permission waives it (audited exception). Same accuracy / mock / duplicate rules as office scans.
     */
    public function remoteScan(Device $device, array $in, ?string $ip = null): array
    {
        $this->checks = [];
        $this->fail = null;
        $employee = $device->employee;
        $settings = Qronex::settings($device->tenant_id);
        $type = $in['type'] ?: self::nextType($employee->id);
        $perm = RemotePermission::activeFor($employee->id);
        $distance = null;

        $this->add('EMPLOYEE_INACTIVE', 'Employee identity', $employee->status === 'active', "{$employee->name} · {$employee->employee_code}");
        $this->add('DEVICE_NOT_APPROVED', 'Registered device', $device->status === 'approved', "{$device->model} · {$device->status}");
        $this->add('NO_REMOTE_PERMISSION', 'Approved remote permission', (bool) $perm, $perm ? "{$perm->ref} · {$perm->typeLabel()}" : 'No approved permission covers today');
        $this->add('OUTSIDE_PERMISSION_DATES', 'Date/time eligibility', (bool) $perm, $perm ? "{$perm->from_date->toDateString()} → {$perm->to_date->toDateString()}" : '');

        $acc = (int) round($in['accuracy']);
        if ($perm?->location_waived) {
            $this->add('LOCATION', 'Location requirement', true, 'Waived — exception recorded on the permission');
        } else {
            $this->add('LOW_GPS_ACCURACY', 'Reported location accuracy', $acc <= $settings->gps_accuracy_m, "±{$acc} m reported · policy ±{$settings->gps_accuracy_m} m");
            if ($perm && $perm->lat !== null) {
                $distance = (int) round(self::metres($in['lat'], $in['lng'], $perm->lat, $perm->lng));
            }
            $this->add('OUTSIDE_APPROVED_LOCATION', 'Location requirement', $perm && $distance !== null && $distance <= (int) $perm->radius_m,
                $perm && $distance !== null ? "{$distance} m from {$perm->location_label} · radius {$perm->radius_m} m" : 'Permission has no approved location');
        }
        $this->add('MOCK_LOCATION', 'Device integrity signals', ! $in['mocked'], $in['mocked'] ? 'Mock / fake location app detected' : 'Mock-location flag clear');

        $last = AttendanceEvent::withoutGlobalScopes()->where('employee_id', $employee->id)->latest('recorded_at')->first();
        $dupe = $last && $last->type === $type && $last->recorded_at->diffInMinutes(now()) < $settings->duplicate_window_min;
        $this->add('DUPLICATE', 'Duplicate check', ! $dupe, $dupe ? "Already punched {$type} {$last->recorded_at->diffForHumans()}" : 'No duplicate in window');

        $device->forceFill(['last_seen_at' => now()])->save();

        if (! $this->fail) {
            $event = AttendanceEvent::create([
                'ref' => self::ref('QRX'), 'tenant_id' => $device->tenant_id, 'employee_id' => $employee->id, 'device_id' => $device->id,
                'office_id' => null, 'gate_id' => null, 'type' => $type, 'mode' => RemotePermission::MODES[$perm->type],
                'remote_permission_id' => $perm->id, 'lat' => $in['lat'], 'lng' => $in['lng'], 'accuracy_m' => min($acc, 65535), 'distance_m' => $distance, 'recorded_at' => now(),
            ]);
            $event->setRelation('employee', $employee)->setRelation('remotePermission', $perm);
            HostSync::push($event);

            return ['ok' => true, 'event' => $event, 'checks' => $this->checks];
        }

        $rej = AttendanceRejection::create([
            'ref' => self::ref('RJ'), 'tenant_id' => $device->tenant_id, 'employee_id' => $employee->id, 'device_id' => $device->id, 'gate_id' => null,
            'reason_code' => $this->fail['code'], 'reason' => mb_substr($this->fail['reason'], 0, 250), 'checks' => $this->checks,
            'lat' => $in['lat'], 'lng' => $in['lng'], 'accuracy_m' => min($acc, 65535), 'ip' => $ip,
        ]);

        return ['ok' => false, 'reason_code' => $this->fail['code'], 'reason' => $this->fail['reason'], 'ref' => $rej->ref, 'checks' => $this->checks];
    }

    /** IN if the employee's last punch today (IST) was OUT or there is none. */
    public static function nextType(int $employeeId): string
    {
        $last = AttendanceEvent::withoutGlobalScopes()->where('employee_id', $employeeId)
            ->where('recorded_at', '>=', now('Asia/Kolkata')->startOfDay()->setTimezone(config('app.timezone')))->latest('recorded_at')->first();

        return $last?->type === 'IN' ? 'OUT' : 'IN';
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    public static function ref(string $prefix): string
    {
        return $prefix.'-'.strtoupper(Str::random(10));
    }

    private function add(string $code, string $label, bool $ok, string $detail): void
    {
        if ($this->fail) {
            $this->checks[] = ['label' => $label, 'status' => 'skip', 'detail' => 'Not evaluated'];

            return;
        }
        $this->checks[] = ['label' => $label, 'status' => $ok ? 'ok' : 'bad', 'detail' => $detail];
        if (! $ok) {
            $this->fail = ['code' => $code, 'reason' => "$label: $detail"];
        }
    }
}
