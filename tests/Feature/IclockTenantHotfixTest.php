<?php

/**
 * 18 Aug 2026 HOTFIX — the /iclock ingest path.
 *
 * FIX 1  cross-customer punch contamination on the shared cloud
 * FIX 2  every punch on day one destroyed, silently
 *
 * These go to four customer sites in the morning: three self-hosted
 * (single-tenant, tenant_id null throughout) and one on the shared Ametecs
 * cloud (multi-tenant). Test 3 is the regression that protects the three
 * self-hosted installs; tests 1 and 2 are the ones that protect the cloud.
 */

use App\Services\ETimeOfficeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/* ---------------------------------------------------------------------------
 | helpers
 |-------------------------------------------------------------------------- */

/** A punch in the shape PushController::importAttlog builds. */
function iclockPunch(string $code, string $sn = 'SN-A', string $at = '2026-08-18 09:05:00', string $dir = 'in'): array
{
    return [
        'emp_code' => $code,
        'name' => null,
        'punch_at' => Carbon::createFromFormat('Y-m-d H:i:s', $at),
        'direction' => $dir,
        'machine' => $sn,          // PushController puts the device serial here
    ];
}

/** The cfg array PushController::cfgForSn produces. */
function iclockCfg(?int $tenantId, string $prefix = '', string $source = 'push'): array
{
    return [
        'emp_prefix' => $prefix,
        'in_machine_id' => '',
        'out_machine_id' => '',
        'tenant_id' => $tenantId,
        'source' => $source,
    ];
}

/** Insert an employee directly, so tenant_id can be forced to null. */
function rawEmployee(?int $tenantId, ?int $companyId, string $empCode, string $name = 'Test Person'): int
{
    return DB::table('employees')->insertGetId([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        'emp_code' => $empCode,
        'name' => $name,
        'type' => 'office',
        'status' => 'active',
        'ctc' => 0,
        'salary_type' => 'only_salary',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/* ---------------------------------------------------------------------------
 | FIX 1 — tenant isolation
 |-------------------------------------------------------------------------- */

it('1. matches a tenant device against ITS OWN employee, never another tenant', function () {
    [$tidA, $cidA] = makeTenantCompany();
    [$tidB, $cidB] = makeTenantCompany();

    // Both customers use EMP001 — the collision that caused the contamination.
    rawEmployee($tidA, $cidA, 'EMP001', 'Alice of A');
    rawEmployee($tidB, $cidB, 'EMP001', 'Bob of B');

    $r = ETimeOfficeService::import([iclockPunch('EMP001')], iclockCfg($tidA));

    expect($r['matched'])->toBe(1);

    $rows = DB::table('attendance_logs')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->tenant_id)->toEqual($tidA);
    expect($rows[0]->emp_name)->toBe('Alice of A');

    // Tenant B untouched.
    expect(DB::table('attendance_logs')->where('tenant_id', $tidB)->count())->toBe(0);
});

it('2. a null-tenant device matches NOBODY when the code exists only under a tenant', function () {
    [$tidB, $cidB] = makeTenantCompany();
    rawEmployee($tidB, $cidB, 'EMP001', 'Bob of B');

    // An auto-registered device: PushController::rowForSn writes tenant_id null.
    $r = ETimeOfficeService::import([iclockPunch('EMP001')], iclockCfg(null));

    // Fail-closed: no match, nothing filed against Bob.
    expect($r['matched'])->toBe(0);
    expect(DB::table('attendance_logs')->count())->toBe(0);

    // ...and the punch is HELD, not destroyed.
    $held = DB::table('attendance_pending')->get();
    expect($held)->toHaveCount(1);
    expect($held[0]->device_code)->toBe('EMP001');
    expect($held[0]->tenant_id)->toBeNull();
    expect($held[0]->resolved_at)->toBeNull();
});

it('3. REGRESSION — a single-tenant install behaves exactly as before', function () {
    // The three self-hosted customers: every employee tenant_id null, and the
    // device config tenant_id null. This must keep working unchanged.
    rawEmployee(null, null, 'EMP001', 'Solo Customer Person');

    $r = ETimeOfficeService::import([iclockPunch('EMP001')], iclockCfg(null));

    expect($r['matched'])->toBe(1);
    expect($r['imported'])->toBe(1);

    $rows = DB::table('attendance_logs')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->emp_code)->toBe('EMP001');
    expect($rows[0]->emp_name)->toBe('Solo Customer Person');

    // Nothing quarantined — it matched.
    expect(DB::table('attendance_pending')->count())->toBe(0);
});

it('3b. the emp_prefix path still resolves on a single-tenant install', function () {
    rawEmployee(null, null, 'AME1043', 'Prefixed Person');

    // Device sends the bare PIN; the config supplies the prefix.
    $r = ETimeOfficeService::import([iclockPunch('1043')], iclockCfg(null, 'AME'));

    expect($r['matched'])->toBe(1);
    expect(DB::table('attendance_logs')->value('emp_code'))->toBe('AME1043');
});

/* ---------------------------------------------------------------------------
 | FIX 2 — quarantine and replay
 |-------------------------------------------------------------------------- */

it('4. an unmapped code is held in attendance_pending and is not lost', function () {
    [$tid, $cid] = makeTenantCompany();

    $r = ETimeOfficeService::import([
        iclockPunch('999', 'SN-A', '2026-08-18 09:05:00', 'in'),
        iclockPunch('999', 'SN-A', '2026-08-18 18:10:00', 'out'),
    ], iclockCfg($tid));

    expect($r['matched'])->toBe(0);
    expect($r['unmatched'])->toHaveKey('999');
    expect($r['unmatched']['999'])->toBe(2);       // counter still feeds the mapping card

    $held = DB::table('attendance_pending')->whereNull('resolved_at')->get();
    expect($held)->toHaveCount(2);
    expect($held[0]->device_sn)->toBe('SN-A');
    expect($held[0]->source)->toBe('push');
    expect($held->pluck('direction')->all())->toBe(['in', 'out']);
});

it('5. mapping the code promotes the held punches, and not another device PIN 5', function () {
    [$tid, $cid] = makeTenantCompany();

    // Same PIN on two different devices = two different people.
    ETimeOfficeService::import([iclockPunch('5', 'SN-A', '2026-08-18 09:00:00')], iclockCfg($tid));
    ETimeOfficeService::import([iclockPunch('5', 'SN-B', '2026-08-18 09:30:00')], iclockCfg($tid));

    expect(DB::table('attendance_pending')->whereNull('resolved_at')->count())->toBe(2);

    // The admin maps PIN 5 on device SN-A only.
    rawEmployee($tid, $cid, 'EMP-A', 'Person On Device A');
    $emp = DB::table('employees')->where('emp_code', 'EMP-A')->first();

    $promoted = ETimeOfficeService::promotePending($tid, '5', $emp, 'SN-A');

    expect($promoted)->toBe(1);

    $logs = DB::table('attendance_logs')->get();
    expect($logs)->toHaveCount(1);
    expect($logs[0]->emp_code)->toBe('EMP-A');
    expect(substr((string) $logs[0]->punch_at, 0, 19))->toBe('2026-08-18 09:00:00');

    // SN-B's PIN 5 is still held — it belongs to somebody else.
    $stillHeld = DB::table('attendance_pending')->whereNull('resolved_at')->get();
    expect($stillHeld)->toHaveCount(1);
    expect($stillHeld[0]->device_sn)->toBe('SN-B');
});

it('6. re-running an import does not duplicate promoted rows', function () {
    [$tid, $cid] = makeTenantCompany();

    $punch = iclockPunch('777', 'SN-A', '2026-08-18 09:15:00');

    // Day one: nobody mapped, punch is held.
    ETimeOfficeService::import([$punch], iclockCfg($tid));
    expect(DB::table('attendance_pending')->count())->toBe(1);

    // Admin maps it; the held punch is promoted.
    rawEmployee($tid, $cid, 'EMP777', 'Late Mapped');
    $emp = DB::table('employees')->where('emp_code', 'EMP777')->first();
    DB::table('employees')->where('id', $emp->id)->update(['device_user_id' => '777']);

    expect(ETimeOfficeService::promotePending($tid, '777', $emp, 'SN-A'))->toBe(1);
    expect(DB::table('attendance_logs')->count())->toBe(1);

    // The device re-sends the same punch (ZKTeco re-uploads freely).
    ETimeOfficeService::import([$punch], iclockCfg($tid));

    // Still ONE row — updateOrInsert matched it, no unique index required.
    expect(DB::table('attendance_logs')->count())->toBe(1);

    // And promoting again is a no-op: resolved_at already stamped.
    expect(ETimeOfficeService::promotePending($tid, '777', $emp, 'SN-A'))->toBe(0);
    expect(DB::table('attendance_logs')->count())->toBe(1);
});

it('6b. quarantining the same punch twice does not pile up duplicates', function () {
    [$tid, $cid] = makeTenantCompany();
    $punch = iclockPunch('888', 'SN-A', '2026-08-18 10:00:00');

    ETimeOfficeService::import([$punch], iclockCfg($tid));
    ETimeOfficeService::import([$punch], iclockCfg($tid));

    expect(DB::table('attendance_pending')->count())->toBe(1);
});

it('6c. the same holds on a single-tenant install, where the unique index cannot help', function () {
    // tenant_id null: NULL never collides in a unique index, so this proves the
    // updateOrInsert — not the index — is what makes the quarantine idempotent.
    rawEmployee(null, null, 'SOMEONE-ELSE', 'Not This Punch');
    $punch = iclockPunch('321', 'SN-A', '2026-08-18 11:00:00');

    ETimeOfficeService::import([$punch], iclockCfg(null));
    ETimeOfficeService::import([$punch], iclockCfg(null));

    expect(DB::table('attendance_pending')->count())->toBe(1);
});

/* ---------------------------------------------------------------------------
 | FIX 1b — an unassigned device must be correctable
 |-------------------------------------------------------------------------- */

it('1b. an auto-registered device is listed as unassigned and can be claimed', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);

    // Exactly what PushController::rowForSn writes on first contact.
    $id = DB::table('biometric_configs')->insertGetId([
        'provider' => 'push',
        'serial_number' => 'AXK-NEW-001',
        'label' => 'Push device AXK-NEW-001',
        'enabled' => true,
        'empcode' => 'ALL',
        'tenant_id' => null,
        'last_status' => 'auto-registered',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // It must be visible to a tenanted admin, or it can never be fixed.
    $list = $this->actingAs($admin)->getJson('/app/biometric-config/list')->assertOk()->json();
    expect(collect($list['unassigned'] ?? [])->pluck('serial_number'))->toContain('AXK-NEW-001');

    // Claiming it attaches it to the admin's workspace.
    $this->actingAs($admin)
        ->postJson('/app/biometric-config/'.$id.'/assign', ['company_id' => $cid])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(DB::table('biometric_configs')->where('id', $id)->value('tenant_id'))->toEqual($tid);

    // From now on its punches reach that tenant's employees.
    rawEmployee($tid, $cid, 'EMP-CLAIMED', 'Now Reachable');
    $r = ETimeOfficeService::import([iclockPunch('EMP-CLAIMED', 'AXK-NEW-001')], iclockCfg($tid));
    expect($r['matched'])->toBe(1);
});

it('1b. one tenant cannot claim a device that already belongs to another', function () {
    [$tidA] = makeTenantCompany();
    [$tidB, $cidB] = makeTenantCompany();
    $adminB = makeUser('admin', $tidB);

    $id = DB::table('biometric_configs')->insertGetId([
        'provider' => 'push',
        'serial_number' => 'AXK-OWNED',
        'enabled' => true,
        'empcode' => 'ALL',
        'tenant_id' => $tidA,          // already owned by A
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($adminB)
        ->postJson('/app/biometric-config/'.$id.'/assign', [])
        ->assertStatus(404);

    expect(DB::table('biometric_configs')->where('id', $id)->value('tenant_id'))->toEqual($tidA);
});
