<?php

/**
 * SBB (Smart Biometric Bridge) — the authenticated JSON ingest path.
 *
 * Each test here pins one promise the endpoint makes to an at-least-once
 * sender. They are written against behaviour, not implementation: what a punch
 * does, where it ends up, and what the caller is told about it.
 */

use App\Services\ApiKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* ---------------------------------------------------------------------------
 | helpers
 |-------------------------------------------------------------------------- */

/** Issue a key and return [plaintextSecret, apiKeyId]. */
function makeApiKey(?int $tenantId, ?int $companyId = null, array $scopes = ['ingest', 'read'], $expiresAt = null, bool $active = true): array
{
    $minted = ApiKeys::mint();
    $id = DB::table('api_keys')->insertGetId([
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        'name' => 'SBB test key',
        'prefix' => $minted['prefix'],
        'key_hash' => $minted['hash'],
        'scopes' => json_encode($scopes),
        'expires_at' => $expiresAt,
        'active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$minted['secret'], $id];
}

/** One well-formed punch, overridable field by field. */
function punch(array $overrides = []): array
{
    return array_merge([
        'external_id' => (string) Str::uuid(),
        'device_sn' => 'AXK7231900123',
        'device_user_id' => '1043',
        'punch_at' => '2026-08-16 09:41:00',
        'direction' => 'IN',
        'verify_mode' => 'FINGERPRINT',
    ], $overrides);
}

function postPunches(string $key, array $punches)
{
    return test()->withHeader('X-Api-Key', $key)
        ->postJson('/api/v1/attendance/punches', ['punches' => $punches]);
}

/* ---------------------------------------------------------------------------
 | ping
 |-------------------------------------------------------------------------- */

it('identifies the customer on ping so an installer can confirm the right client', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid, ['ingest']);

    $res = $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/ping');

    $res->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('app', 'SmartPRS')
        ->assertJsonPath('tenant_id', $tid)
        ->assertJsonPath('company_name', 'Test Co')
        ->assertJsonPath('scopes', ['ingest'])
        ->assertJsonPath('timezone', config('app.timezone'));

    expect($res->json('server_time'))->toBeString()->not->toBeEmpty();
});

/* ---------------------------------------------------------------------------
 | the happy path
 |-------------------------------------------------------------------------- */

it('ingests a punch for a mapped employee and stores it in attendance_logs', function () {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    $p = punch();
    $res = postPunches($key, [$p]);

    $res->assertOk()
        ->assertJsonPath('batch.received', 1)
        ->assertJsonPath('batch.accepted', 1)
        ->assertJsonPath('results.0.external_id', $p['external_id'])
        ->assertJsonPath('results.0.status', 'accepted');

    $row = DB::table('attendance_logs')->where('external_id', $p['external_id'])->first();

    expect($row)->not->toBeNull();
    expect($row->emp_code)->toBe('EMP1043');
    expect($row->tenant_id)->toEqual($tid);
    expect($row->source)->toBe('sbb');
    expect($row->direction)->toBe('in');
    expect($row->device_sn)->toBe('AXK7231900123');
    expect($row->device_user_id)->toBe('1043');
    expect($row->verify_mode)->toBe('FINGERPRINT');

    // The wall clock is stored EXACTLY as sent — no offset applied, none stripped.
    expect(substr((string) $row->punch_at, 0, 19))->toBe('2026-08-16 09:41:00');
});

/* ---------------------------------------------------------------------------
 | idempotency — the whole reason external_id exists
 |-------------------------------------------------------------------------- */

it('accepts an external_id once and calls the re-send a duplicate, leaving exactly one row', function () {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    $p = punch();

    postPunches($key, [$p])
        ->assertOk()
        ->assertJsonPath('batch.accepted', 1)
        ->assertJsonPath('results.0.status', 'accepted');

    postPunches($key, [$p])
        ->assertOk()
        ->assertJsonPath('batch.accepted', 0)
        ->assertJsonPath('batch.duplicates', 1)
        ->assertJsonPath('results.0.status', 'duplicate');

    expect(DB::table('attendance_logs')->where('external_id', $p['external_id'])->count())->toBe(1);
    expect(DB::table('attendance_logs')->count())->toBe(1);
});

it('treats the same employee, moment and source as one punch even under a fresh external_id', function () {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    postPunches($key, [punch()])->assertJsonPath('results.0.status', 'accepted');
    postPunches($key, [punch()])->assertJsonPath('results.0.status', 'duplicate');

    expect(DB::table('attendance_logs')->count())->toBe(1);
});

/* ---------------------------------------------------------------------------
 | quarantine + replay — the go-live window
 |-------------------------------------------------------------------------- */

it('holds a punch for an unmapped PIN instead of discarding it, and says so', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid);

    $p = punch(['device_user_id' => '9999']);

    postPunches($key, [$p])
        ->assertOk()
        ->assertJsonPath('batch.pending', 1)
        ->assertJsonPath('batch.accepted', 0)
        ->assertJsonPath('results.0.external_id', $p['external_id'])
        ->assertJsonPath('results.0.status', 'pending')
        ->assertJsonPath('results.0.reason', 'EMPLOYEE_NOT_MAPPED');

    // Held, not lost.
    $held = DB::table('attendance_pending')->where('external_id', $p['external_id'])->first();
    expect($held)->not->toBeNull();
    expect($held->device_user_id)->toBe('9999');
    expect($held->resolved_at)->toBeNull();

    // And nothing was written to attendance under a bogus identity.
    expect(DB::table('attendance_logs')->count())->toBe(0);
});

it('releases held punches into attendance when the device ID is finally mapped', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid);

    $p = punch(['device_user_id' => '9999']);
    postPunches($key, [$p])->assertJsonPath('results.0.status', 'pending');

    // The employee exists but was never linked to the device — go-live, day one.
    makeEmployee($tid, $cid, 'EMP9999');
    $admin = makeUser('admin', $tid);

    $this->actingAs($admin)
        ->post('/app/biometric-config/map', ['device_id' => '9999', 'emp_code' => 'EMP9999'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('promoted', 1);

    $row = DB::table('attendance_logs')->where('external_id', $p['external_id'])->first();
    expect($row)->not->toBeNull();
    expect($row->emp_code)->toBe('EMP9999');
    expect($row->tenant_id)->toEqual($tid);
    expect(substr((string) $row->punch_at, 0, 19))->toBe('2026-08-16 09:41:00');

    // The quarantine row is closed out, so a second mapping cannot replay it twice.
    expect(DB::table('attendance_pending')->where('external_id', $p['external_id'])->value('resolved_at'))->not->toBeNull();
    expect(DB::table('attendance_pending')->whereNull('resolved_at')->count())->toBe(0);
});

/* ---------------------------------------------------------------------------
 | time — the subtle one
 |-------------------------------------------------------------------------- */

it('rejects a punch_at carrying a timezone offset rather than guessing what it meant', function () {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    $p = punch(['punch_at' => '2026-08-16 09:41:00+05:30']);

    postPunches($key, [$p])
        ->assertOk()
        ->assertJsonPath('batch.rejected', 1)
        ->assertJsonPath('batch.accepted', 0)
        ->assertJsonPath('results.0.external_id', $p['external_id'])
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.reason', 'TIME_FORMAT');

    expect(DB::table('attendance_logs')->count())->toBe(0);
});

it('rejects other zone-bearing and malformed timestamps', function (string $when) {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    postPunches($key, [punch(['punch_at' => $when])])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.reason', 'TIME_FORMAT');

    expect(DB::table('attendance_logs')->count())->toBe(0);
})->with([
    '2026-08-16T09:41:00Z',
    '2026-08-16T09:41:00+05:30',
    '2026-08-16 09:41:00 UTC',
    '16/08/2026 09:41:00',
    '2026-02-30 09:41:00',
    '2026-08-16 09:41',
]);

it('stores the wall clock literally, never shifting it into UTC', function () {
    config()->set('app.timezone', 'Asia/Kolkata');

    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    postPunches($key, [punch(['punch_at' => '2026-08-16 09:41:00'])])->assertJsonPath('results.0.status', 'accepted');

    // 04:11 would be the UTC rendering — the bug this endpoint exists to avoid.
    expect(substr((string) DB::table('attendance_logs')->value('punch_at'), 0, 19))->toBe('2026-08-16 09:41:00');
});

/* ---------------------------------------------------------------------------
 | tenant isolation
 |-------------------------------------------------------------------------- */

it('cannot write a punch attributed to another tenant', function () {
    [$tidA, $cidA] = makeTenantCompany();
    [$tidB, $cidB] = makeTenantCompany();

    // Both customers use the same obvious employee code — the exact collision
    // that makes the /iclock path leak across customers today.
    makeEmployee($tidA, $cidA, 'EMP001');
    makeEmployee($tidB, $cidB, 'EMP001');
    DB::table('employees')->where('tenant_id', $tidB)->update(['device_user_id' => '1043']);

    [$keyA] = makeApiKey($tidA, $cidA);

    $p = punch(['employee_code' => 'EMP001']);
    postPunches($keyA, [$p])->assertOk()->assertJsonPath('results.0.status', 'accepted');

    $row = DB::table('attendance_logs')->where('external_id', $p['external_id'])->first();

    // Tenant A's key wrote to tenant A. Tenant B has nothing.
    expect($row->tenant_id)->toEqual($tidA);
    expect(DB::table('attendance_logs')->where('tenant_id', $tidB)->count())->toBe(0);
});

it('ignores a tenant_id supplied in the request body', function () {
    [$tidA, $cidA] = makeTenantCompany();
    [$tidB] = makeTenantCompany();

    makeEmployee($tidA, $cidA, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$keyA] = makeApiKey($tidA, $cidA);

    $this->withHeader('X-Api-Key', $keyA)->postJson('/api/v1/attendance/punches', [
        'tenant_id' => $tidB,
        'company_id' => 9999,
        'punches' => [punch()],
    ])->assertOk()->assertJsonPath('results.0.status', 'accepted');

    expect(DB::table('attendance_logs')->value('tenant_id'))->toEqual($tidA);
    expect(DB::table('attendance_logs')->where('tenant_id', $tidB)->count())->toBe(0);
});

it('holds a punch for an employee who belongs to a different tenant', function () {
    [$tidA, $cidA] = makeTenantCompany();
    [$tidB, $cidB] = makeTenantCompany();

    // Only tenant B has this person; tenant A's key must not find them.
    makeEmployee($tidB, $cidB, 'EMP1043');
    DB::table('employees')->where('tenant_id', $tidB)->update(['device_user_id' => '1043']);

    [$keyA] = makeApiKey($tidA, $cidA);

    postPunches($keyA, [punch()])->assertOk()->assertJsonPath('results.0.status', 'pending');

    expect(DB::table('attendance_logs')->count())->toBe(0);
    expect(DB::table('attendance_pending')->where('tenant_id', $tidA)->count())->toBe(1);
});

/* ---------------------------------------------------------------------------
 | authentication
 |-------------------------------------------------------------------------- */

it('rejects a missing key with 401 and the documented body', function () {
    $res = $this->postJson('/api/v1/attendance/punches', ['punches' => [punch()]]);

    $res->assertStatus(401)->assertJsonPath('error.code', 'API_KEY_401');
    expect($res->json('error.message'))->toBeString()->not->toBeEmpty();
});

it('rejects an unknown key with 401', function () {
    makeTenantCompany();

    $this->withHeader('X-Api-Key', 'sk_prs_zzzz_'.Str::random(40))
        ->postJson('/api/v1/attendance/punches', ['punches' => [punch()]])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'API_KEY_401');
});

it('rejects an expired key with 401', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid, ['ingest'], now()->subDay());

    postPunches($key, [punch()])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'API_KEY_401');
});

it('rejects a revoked key with 401', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid, ['ingest'], null, false);

    postPunches($key, [punch()])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'API_KEY_401');
});

it('rejects a key without the ingest scope with 403', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid, ['read']);

    $res = postPunches($key, [punch()]);

    $res->assertStatus(403)->assertJsonPath('error.code', 'API_KEY_403');
    expect($res->json('error.message'))->toContain('ingest');

    // ...but the same key is fine on a route that only needs authentication.
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/ping')->assertOk();
});

it('accepts the key as an Authorization Bearer header too', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid);

    $this->withHeader('Authorization', 'Bearer '.$key)
        ->getJson('/api/v1/ping')
        ->assertOk()
        ->assertJsonPath('tenant_id', $tid);
});

it('records last_used_at when a key is used', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key, $id] = makeApiKey($tid, $cid);

    expect(DB::table('api_keys')->where('id', $id)->value('last_used_at'))->toBeNull();

    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/ping')->assertOk();

    expect(DB::table('api_keys')->where('id', $id)->value('last_used_at'))->not->toBeNull();
});

/* ---------------------------------------------------------------------------
 | batch behaviour
 |-------------------------------------------------------------------------- */

it('reports a verdict for every punch in a mixed batch and never silently drops one', function () {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    $accepted = punch(['punch_at' => '2026-08-16 09:41:00']);
    $duplicate = punch(['punch_at' => '2026-08-16 09:41:00']);   // same moment, new id
    $pending = punch(['device_user_id' => '9999', 'punch_at' => '2026-08-16 09:42:00']);
    $rejected = punch(['punch_at' => '2026-08-16 09:43:00+05:30']);

    $res = postPunches($key, [$accepted, $duplicate, $pending, $rejected]);

    $res->assertOk()
        ->assertJsonPath('batch.received', 4)
        ->assertJsonPath('batch.accepted', 1)
        ->assertJsonPath('batch.duplicates', 1)
        ->assertJsonPath('batch.pending', 1)
        ->assertJsonPath('batch.rejected', 1);

    expect($res->json('results'))->toHaveCount(4);

    $byId = collect($res->json('results'))->keyBy('external_id');
    expect($byId[$accepted['external_id']]['status'])->toBe('accepted');
    expect($byId[$duplicate['external_id']]['status'])->toBe('duplicate');
    expect($byId[$pending['external_id']]['status'])->toBe('pending');
    expect($byId[$rejected['external_id']]['status'])->toBe('rejected');
});

it('rejects a malformed punch individually instead of failing the whole batch', function () {
    [$tid, $cid] = makeTenantCompany();
    makeEmployee($tid, $cid, 'EMP1043');
    DB::table('employees')->where('emp_code', 'EMP1043')->update(['device_user_id' => '1043']);
    [$key] = makeApiKey($tid, $cid);

    $good = punch();
    $bad = punch(['direction' => 'SIDEWAYS', 'punch_at' => '2026-08-16 10:00:00']);

    $res = postPunches($key, [$good, $bad]);

    $res->assertOk()
        ->assertJsonPath('batch.accepted', 1)
        ->assertJsonPath('batch.rejected', 1);

    // The good punch still landed — a bad neighbour does not cost it.
    expect(DB::table('attendance_logs')->where('external_id', $good['external_id'])->count())->toBe(1);
});

it('rejects an empty or oversized batch with 422', function () {
    [$tid, $cid] = makeTenantCompany();
    [$key] = makeApiKey($tid, $cid);

    $this->withHeader('X-Api-Key', $key)
        ->postJson('/api/v1/attendance/punches', ['punches' => []])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_422');

    $this->withHeader('X-Api-Key', $key)
        ->postJson('/api/v1/attendance/punches', ['punches' => array_fill(0, 1001, punch())])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_422');
});

/* ---------------------------------------------------------------------------
 | the /iclock path must be untouched
 |-------------------------------------------------------------------------- */

it('leaves the unauthenticated ZKTeco push endpoint working', function () {
    $this->get('/iclock/cdata?SN=TESTSN001&options=all')
        ->assertOk()
        ->assertSee('GET OPTION FROM: TESTSN001');

    $this->call(
        'POST',
        '/iclock/cdata?SN=TESTSN001&table=ATTLOG',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "1043\t2026-08-16 09:41:00\t0\t1\n"
    )->assertOk();
});
