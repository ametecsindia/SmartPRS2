<?php

/**
 * SmartEPT webhook receiver.
 *
 * Each test pins one promise the receiver makes to SmartEPT's OutboundPusher,
 * which is fire-and-forget: it signs a body, POSTs once, and records only the
 * status line. Everything that could go wrong quietly — a wrong timezone, a
 * re-push stored twice, a body that chooses its own tenant — is pinned here.
 */

use App\Services\SmarteptWebhook;
use Illuminate\Support\Facades\DB;

/* ---------------------------------------------------------------------------
 | helpers
 |-------------------------------------------------------------------------- */

/** Create a receiver; returns [url, secret, endpointId]. */
function makeReceiver(?int $tenantId, ?int $companyId = null, array $events = ['attendance.punch', 'attendance.daily'], bool $active = true): array
{
    $minted = SmarteptWebhook::mint();
    $id = DB::table('smartept_webhook_endpoints')->insertGetId([
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        'name' => 'SmartEPT test receiver',
        'slug' => $minted['slug'],
        'secret' => SmarteptWebhook::encryptSecret($minted['secret']),
        'events' => json_encode($events),
        'active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['/api/v1/webhooks/smartept/'.$minted['slug'], $minted['secret'], $id];
}

/**
 * POST a payload exactly the way OutboundPusher::pushTo does.
 *
 * Headers go in as SERVER VARS, not via withHeaders(). call() builds its request
 * straight from $server and never applies $this->defaultHeaders — only get(),
 * post() and json() do that. Passing them to withHeaders() here silently drops
 * the signature and every request comes back 401.
 *
 * postJson() is not usable either: it re-encodes the array with its own flags,
 * so the bytes signed here would not be the bytes the receiver hashes. The raw
 * body IS the contract; this sends exactly what it signed.
 */
function pushToSmartprs(string $url, string $secret, array $payload, ?string $forceSignature = null, ?string $forceEvent = null)
{
    // JSON_UNESCAPED_SLASHES, matching the sender.
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $server = array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SMARTEPT_SIGNATURE' => $forceSignature ?? hash_hmac('sha256', $body, $secret),
        'HTTP_X_SMARTEPT_EVENT' => $forceEvent ?? ($payload['event'] ?? null),
    ], fn ($v) => $v !== null && $v !== '');

    return test()->call('POST', $url, [], [], [], $server, $body);
}

/** One attendance.punch payload as SmartEPT builds it, overridable. */
function eptPunch(array $overrides = []): array
{
    return array_merge([
        'event' => 'attendance.punch',
        'company_id' => 7,
        'device_id' => 'SMARTEPT-a1b2c3d4e5f6',
        'employee_code' => 'EMP1043',
        'biometric_employee_id' => 'EMP1043',
        'punch_type' => 'IN',
        'punched_at' => '2026-08-26T09:41:00+05:30',
        'verification_mode' => 'SYSTEM',
        'source' => 'AGENT',
        'sent_at' => '2026-08-26T09:41:02+05:30',
    ], $overrides);
}

function mappedEmployee(int $tid, int $cid, string $code = 'EMP1043'): void
{
    makeEmployee($tid, $cid, $code);
}

/* ---------------------------------------------------------------------------
 | authentication — the signature is the credential
 |-------------------------------------------------------------------------- */

it('rejects a push whose signature does not match the shared secret', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch(), forceSignature: str_repeat('0', 64))
        ->assertStatus(401);

    expect(DB::table('attendance_logs')->count())->toBe(0);
});

it('rejects a push carrying no signature at all', function () {
    [$tid, $cid] = makeTenantCompany();
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch(), forceSignature: '')
        ->assertStatus(401);
});

it('answers an unknown receiver and a bad signature identically, so endpoints cannot be enumerated', function () {
    [$tid, $cid] = makeTenantCompany();
    [$url, $secret] = makeReceiver($tid, $cid);

    $unknown = pushToSmartprs('/api/v1/webhooks/smartept/'.str_repeat('z', 16), $secret, eptPunch());
    $badSig = pushToSmartprs($url, $secret, eptPunch(), forceSignature: str_repeat('0', 64));

    expect($unknown->status())->toBe(401);
    expect($badSig->status())->toBe(401);
    expect($unknown->json('error.message'))->toBe($badSig->json('error.message'));
});

it('refuses a revoked receiver', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid, active: false);

    pushToSmartprs($url, $secret, eptPunch())->assertStatus(401);

    expect(DB::table('attendance_logs')->count())->toBe(0);
});

it('refuses a receiver with no tenant, because a null tenant cannot de-duplicate', function () {
    [$url, $secret] = makeReceiver(null);

    pushToSmartprs($url, $secret, eptPunch())->assertStatus(401);
});

/* ---------------------------------------------------------------------------
 | tenancy — the body never chooses the customer
 |-------------------------------------------------------------------------- */

it('takes the tenant from the receiver and ignores the company_id in the body', function () {
    [$tidA, $cidA] = makeTenantCompany();
    [$tidB, $cidB] = makeTenantCompany();
    mappedEmployee($tidA, $cidA);
    mappedEmployee($tidB, $cidB);           // same emp_code in a different customer
    [$url, $secret] = makeReceiver($tidA, $cidA);

    // company_id 999999 is SmartEPT's own integer and must change nothing here.
    pushToSmartprs($url, $secret, eptPunch(['company_id' => 999999]))->assertOk();

    expect(DB::table('attendance_logs')->where('tenant_id', $tidA)->count())->toBe(1);
    expect(DB::table('attendance_logs')->where('tenant_id', $tidB)->count())->toBe(0);
});

/* ---------------------------------------------------------------------------
 | time — the single most expensive thing to get wrong
 |-------------------------------------------------------------------------- */

it('converts an offset-bearing time into app-timezone wall clock instead of storing it raw', function () {
    config(['app.timezone' => 'Asia/Kolkata']);
    date_default_timezone_set('Asia/Kolkata');

    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid);

    // The same instant, sent as UTC. It is 09:41 in Kolkata.
    pushToSmartprs($url, $secret, eptPunch(['punched_at' => '2026-08-26T04:11:00Z']))->assertOk();

    $row = DB::table('attendance_logs')->first();

    expect($row)->not->toBeNull();
    expect(substr((string) $row->punch_at, 0, 19))->toBe('2026-08-26 09:41:00');
    expect((string) $row->log_date)->toStartWith('2026-08-26');
});

it('does not pass an offset through to the ingest service, which would reject it', function () {
    expect(SmarteptWebhook::toNaiveLocal('2026-08-26T09:41:00+05:30'))
        ->toMatch('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~');
});

it('refuses to guess at a value that is not a date', function () {
    expect(SmarteptWebhook::toNaiveLocal('now'))->toBeNull();
    expect(SmarteptWebhook::toNaiveLocal('+1 day'))->toBeNull();
    expect(SmarteptWebhook::toNaiveLocal(''))->toBeNull();
    expect(SmarteptWebhook::toNaiveLocal(null))->toBeNull();
});

/* ---------------------------------------------------------------------------
 | idempotency — SmartEPT sends no external_id, so we derive a stable one
 |-------------------------------------------------------------------------- */

it('stores a re-pushed punch once, even though SmartEPT sends no external_id', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch())->assertOk()->assertJsonPath('batch.accepted', 1);

    // Re-pushed with a later sent_at, exactly as a retry would look.
    pushToSmartprs($url, $secret, eptPunch(['sent_at' => '2026-08-26T10:15:00+05:30']))
        ->assertOk()
        ->assertJsonPath('batch.accepted', 0)
        ->assertJsonPath('batch.duplicates', 1);

    expect(DB::table('attendance_logs')->count())->toBe(1);
});

it('keeps IN and OUT as separate punches when they are at different times', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch(['punch_type' => 'IN', 'punched_at' => '2026-08-26T09:41:00+05:30']))->assertOk();
    pushToSmartprs($url, $secret, eptPunch(['punch_type' => 'OUT', 'punched_at' => '2026-08-26T18:20:00+05:30']))->assertOk();

    expect(DB::table('attendance_logs')->count())->toBe(2);
});

it('treats the same employee, instant and source as one punch even when the direction differs', function () {
    // attlog_natural_unique is (tenant_id, emp_code, punch_at, source) — direction
    // is deliberately NOT part of it, on the SBB path and therefore here too.
    //
    // Nobody clocks in and out in the same second. A sender that says so is
    // reporting ONE punch twice with a flipped flag, and the second must not
    // land — it would pair with itself and produce a zero-length shift in
    // payroll. The derived external_ids differ, so the tenant+external_id index
    // lets both through; the natural key is what actually stops this.
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch(['punch_type' => 'IN']))
        ->assertOk()->assertJsonPath('batch.accepted', 1);

    pushToSmartprs($url, $secret, eptPunch(['punch_type' => 'OUT']))
        ->assertOk()->assertJsonPath('batch.duplicates', 1);

    expect(DB::table('attendance_logs')->count())->toBe(1);
});

/* ---------------------------------------------------------------------------
 | attendance.daily
 |-------------------------------------------------------------------------- */

it('expands a daily summary into an IN and an OUT punch per employee', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid, 'EMP1043');
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, [
        'event' => 'attendance.daily',
        'company_id' => 7,
        'date' => '2026-08-25',
        'generated_at' => '2026-08-26T00:05:00+05:30',
        'count' => 1,
        'records' => [[
            'employee_code' => 'EMP1043',
            'employee_name' => 'Emp EMP1043',
            'work_date' => '2026-08-25',
            'status' => 'present',
            'first_in' => '2026-08-25T09:30:00+05:30',
            'last_out' => '2026-08-25T18:20:00+05:30',
            'worked_seconds' => 30600,
            'break_seconds' => 1200,
            'source' => 'AGENT',
        ]],
    ])->assertOk()->assertJsonPath('batch.accepted', 2);

    $rows = DB::table('attendance_logs')->orderBy('punch_at')->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->direction)->toBe('in');
    expect($rows[1]->direction)->toBe('out');
    expect(substr((string) $rows[0]->punch_at, 0, 19))->toBe('2026-08-25 09:30:00');
});

it('invents nothing for a record with no in or out time, and says how many it skipped', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid, 'EMP1043');
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, [
        'event' => 'attendance.daily',
        'company_id' => 7,
        'date' => '2026-08-25',
        'count' => 1,
        'records' => [[
            'employee_code' => 'EMP1043',
            'work_date' => '2026-08-25',
            'status' => 'absent',
            'first_in' => null,
            'last_out' => null,
        ]],
    ])->assertOk()->assertJsonPath('batch.skipped', 1);

    expect(DB::table('attendance_logs')->count())->toBe(0);
});

it('does not store the same attendance twice when the daily summary repeats a live punch', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid, 'EMP1043');
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch([
        'punch_type' => 'IN',
        'punched_at' => '2026-08-25T09:30:00+05:30',
    ]))->assertOk();

    pushToSmartprs($url, $secret, [
        'event' => 'attendance.daily',
        'company_id' => 7,
        'date' => '2026-08-25',
        'records' => [[
            'employee_code' => 'EMP1043',
            'work_date' => '2026-08-25',
            'status' => 'present',
            'first_in' => '2026-08-25T09:30:00+05:30',
            'last_out' => null,
        ]],
    ])->assertOk();

    expect(DB::table('attendance_logs')->count())->toBe(1);
});

/* ---------------------------------------------------------------------------
 | subscription and event handling
 |-------------------------------------------------------------------------- */

it('acknowledges an event it is not subscribed to instead of failing the sender', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid, events: ['attendance.daily']);

    pushToSmartprs($url, $secret, eptPunch())
        ->assertStatus(202)
        ->assertJsonPath('ignored', true);

    expect(DB::table('attendance_logs')->count())->toBe(0);
});

it('refuses a push whose event header disagrees with its body', function () {
    [$tid, $cid] = makeTenantCompany();
    [$url, $secret] = makeReceiver($tid, $cid);

    // Body says attendance.punch; the header claims attendance.daily.
    pushToSmartprs($url, $secret, eptPunch(), forceEvent: 'attendance.daily')
        ->assertStatus(422);
});

/* ---------------------------------------------------------------------------
 | unmatched employees are held, never dropped
 |-------------------------------------------------------------------------- */

it('quarantines a punch for an employee it cannot match, and says so', function () {
    [$tid, $cid] = makeTenantCompany();   // no employee created
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch(['employee_code' => 'NOBODY', 'biometric_employee_id' => 'NOBODY']))
        ->assertOk()
        ->assertJsonPath('batch.pending', 1)
        ->assertJsonPath('results.0.status', 'pending')
        ->assertJsonPath('results.0.reason', 'EMPLOYEE_NOT_MAPPED');

    expect(DB::table('attendance_logs')->count())->toBe(0);
    expect(DB::table('attendance_pending')->whereNull('resolved_at')->count())->toBe(1);
});

/* ---------------------------------------------------------------------------
 | delivery health, so the screen can answer "is it arriving?"
 |-------------------------------------------------------------------------- */

it('records the outcome of each delivery on the receiver row', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret, $id] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch())->assertOk();

    $row = DB::table('smartept_webhook_endpoints')->find($id);

    expect($row->last_received_at)->not->toBeNull();
    expect($row->last_event)->toBe('attendance.punch');
    expect($row->received_count)->toEqual(1);
    expect($row->accepted_count)->toEqual(1);
    expect((string) $row->last_status)->toStartWith('OK');
});

/* ---------------------------------------------------------------------------
 | the source string, which the natural-key index depends on
 |-------------------------------------------------------------------------- */

it('stores SmartEPT punches under their own source without disturbing the SBB path', function () {
    [$tid, $cid] = makeTenantCompany();
    mappedEmployee($tid, $cid);
    [$url, $secret] = makeReceiver($tid, $cid);

    pushToSmartprs($url, $secret, eptPunch())->assertOk();

    expect(DB::table('attendance_logs')->value('source'))->toBe('smartept');
});
