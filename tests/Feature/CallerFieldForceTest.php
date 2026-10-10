<?php

/**
 * Caller (Ametecs Managed Mobile) field-force API — geofence sign-in check and
 * login/logout attendance. Behaviour-level, like SbbIngestTest.
 */

use App\Services\ApiKeys;
use Illuminate\Support\Facades\DB;

function ffKey(int $tenantId, int $companyId): string
{
    $m = ApiKeys::mint();
    DB::table('api_keys')->insert(['tenant_id' => $tenantId, 'company_id' => $companyId, 'name' => 'Caller', 'prefix' => $m['prefix'],
        'key_hash' => $m['hash'], 'scopes' => json_encode(['ingest']), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

    return $m['secret'];
}

/** Hyderabad office fence, 0.5 km. */
function ffFence(int $tid, int $empId, string $outside = 'strict'): void
{
    DB::table('geofence_rules')->insert(['tenant_id' => $tid, 'employee_id' => $empId, 'start' => 'office',
        'lat' => 17.4600, 'lng' => 78.3600, 'radius_km' => 0.5, 'outside' => $outside]);
}

it('allows sign-in inside the fence and blocks it outside', function () {
    [$tid, $cid] = makeTenantCompany();
    ffFence($tid, makeEmployee($tid, $cid, 'FF01'));
    $key = ffKey($tid, $cid);

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/geofence/check', ['emp_code' => 'ff01', 'lat' => 17.4610, 'lng' => 78.3605])
        ->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('status', 'within');

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/geofence/check', ['emp_code' => 'FF01', 'lat' => 17.3850, 'lng' => 78.4867])
        ->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('status', 'outside');

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/geofence/check', ['emp_code' => 'FF01'])
        ->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('status', 'no-gps');
});

it('lets employees without a fence, or unknown to PRS, sign in', function () {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'FF02');
    $key = ffKey($tid, $cid);

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/geofence/check', ['emp_code' => 'FF02', 'lat' => 1, 'lng' => 1])
        ->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('status', 'no-rule');
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/geofence/check', ['emp_code' => 'NOPE'])
        ->assertOk()->assertJsonPath('employee_found', false)->assertJsonPath('allowed', true);
});

it('stores login/logout as in/out attendance in local time, once per external_id', function () {
    [$tid, $cid] = makeTenantCompany();
    ffFence($tid, makeEmployee($tid, $cid, 'FF03'), '1km');
    $key = ffKey($tid, $cid);
    config(['app.timezone' => 'Asia/Kolkata']);

    $events = [
        ['external_id' => 'c-1', 'emp_code' => 'FF03', 'type' => 'login', 'at' => '2026-10-06T04:00:00Z', 'lat' => 17.4601, 'lng' => 78.3601],
        ['external_id' => 'c-2', 'emp_code' => 'FF03', 'type' => 'logout', 'at' => '2026-10-06T18:30:00+05:30', 'lat' => 17.3850, 'lng' => 78.4867],
        ['external_id' => 'c-3', 'emp_code' => 'FF03', 'type' => 'login', 'at' => '2026-10-06 09:30:00'],   // no offset → rejected
        ['external_id' => 'c-4', 'emp_code' => 'GHOST', 'type' => 'login', 'at' => '2026-10-06T09:30:00+05:30'],
    ];
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/attendance', ['events' => $events])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.0.geo_status', 'within')
        ->assertJsonPath('results.1.status', 'accepted')->assertJsonPath('results.1.geo_status', 'outside')
        ->assertJsonPath('results.2.status', 'rejected')
        ->assertJsonPath('results.3.status', 'pending');

    $in = DB::table('attendance_logs')->where('external_id', 'c-1')->first();
    expect($in->direction)->toBe('in');
    expect($in->source)->toBe('caller');
    expect(substr((string) $in->punch_at, 0, 19))->toBe('2026-10-06 09:30:00');   // 04:00Z = 09:30 IST
    expect(DB::table('attendance_logs')->where('external_id', 'c-2')->value('direction'))->toBe('out');

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/attendance', ['events' => [$events[0]]])
        ->assertOk()->assertJsonPath('results.0.status', 'duplicate');
    expect(DB::table('attendance_logs')->where('tenant_id', $tid)->count())->toBe(2);
});

it('holds an unknown code on Pending Punches and matches it once mapped', function () {
    [$tid, $cid] = makeTenantCompany();
    $empId = makeEmployee($tid, $cid, 'FF04');
    $key = ffKey($tid, $cid);
    config(['app.timezone' => 'Asia/Kolkata']);
    $ev = fn ($id, $type, $at) => ['events' => [['external_id' => $id, 'emp_code' => 'APP9', 'type' => $type, 'at' => $at]]];

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/attendance', $ev('h-1', 'login', '2026-10-06T09:30:00+05:30'))
        ->assertOk()->assertJsonPath('results.0.status', 'pending');
    $held = DB::table('attendance_pending')->where('external_id', 'h-1')->first();
    expect($held->device_sn)->toBe('CALLER');
    expect($held->device_user_id)->toBe('APP9');
    expect($held->direction)->toBe('in');

    // What the Map button does: link the code to the employee, then release the held punches.
    DB::table('employees')->where('id', $empId)->update(['device_user_id' => 'APP9']);
    $emp = DB::table('employees')->where('id', $empId)->first();
    expect(\App\Services\ETimeOfficeService::promotePending($tid, 'APP9', $emp, 'CALLER'))->toBe(1);
    expect(DB::table('attendance_logs')->where('emp_code', 'FF04')->count())->toBe(1);

    // Later punches with the mapped code go straight to the employee.
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/fieldforce/attendance', $ev('h-2', 'logout', '2026-10-06T18:30:00+05:30'))
        ->assertOk()->assertJsonPath('results.0.status', 'accepted');
    expect(DB::table('attendance_logs')->where('emp_code', 'FF04')->count())->toBe(2);
});

it('refuses calls without a key', function () {
    $this->postJson('/api/v1/fieldforce/geofence/check', ['emp_code' => 'X'])->assertStatus(401);
});
