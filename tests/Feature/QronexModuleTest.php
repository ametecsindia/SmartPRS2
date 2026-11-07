<?php

use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Models\Employee as QxEmployee;
use Ametecs\Qronex\Models\Gate;
use Ametecs\Qronex\Models\Office;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\QrToken;
use Illuminate\Support\Facades\DB;

/** QR attendance module inside SmartPRS: off by default, admin enables, employees sync, QR punch lands in attendance_logs for payroll. */
function qxSetup(): array
{
    [$tid, $cid] = makeTenantCompany();
    DB::table('tenants')->where('id', $tid)->update(['subdomain' => 'acme']);
    $eid = makeEmployee($tid, $cid, 'E100');
    DB::table('employees')->where('id', $eid)->update(['email' => 'e100@acme.test']);
    makeEmployee($tid, $cid, 'E200');

    return [$tid, $cid, $eid, makeUser('admin', $tid)];
}

function qxEnableWithGate($admin): Gate
{
    test()->actingAs($admin)->post('/qronex/settings/toggle', ['enabled' => 1])->assertRedirect('/qronex');
    test()->post('/qronex/offices', ['code' => 'HQ', 'name' => 'Head Office', 'lat' => 17.44862, 'lng' => 78.39081, 'geofence_radius_m' => 150, 'is_active' => 1])->assertSessionHasNoErrors();
    Qronex::setTenant(null);

    return Gate::withoutGlobalScopes()->firstOrFail();
}

function qxActivatePhone(QxEmployee $e): array
{
    $code = 'ABCD2345';
    $e->forceFill(['activation_code_hash' => bcrypt($code), 'activation_expires_at' => now()->addHour()])->save();
    $r = test()->postJson('/api/qronex/v1/activate', ['company' => 'acme', 'employee_code' => $e->employee_code, 'activation_code' => $code,
        'platform' => 'android', 'model' => 'Pixel 8', 'install_ref' => str_repeat('c3', 12)]);

    return [$r, $r->json('token')];
}

it('is off by default and changes nothing in SmartPRS until enabled', function () {
    [$tid, , , $admin] = qxSetup();
    $this->actingAs($admin)->get('/qronex')->assertRedirect('/qronex/settings');
    $this->get('/qronex/settings')->assertOk()->assertSee('QR attendance is OFF');
    $this->postJson('/api/qronex/v1/activate', ['company' => 'acme', 'employee_code' => 'E100', 'activation_code' => 'X', 'platform' => 'android', 'install_ref' => str_repeat('a', 20)])
        ->assertStatus(422);
    expect(DB::table('qx_employees')->count())->toBe(0);
});

it('lets only permitted roles in', function () {
    [$tid] = qxSetup();
    $this->actingAs(makeUser('employee', $tid))->get('/qronex/settings')->assertForbidden();
    $this->actingAs(makeUser('manager', $tid))->get('/qronex/settings')->assertForbidden(); // company-wide module: team managers not yet
    $this->actingAs(makeUser('hr_manager', $tid))->get('/qronex/settings')->assertOk();
    $this->post('/qronex/settings/toggle', ['enabled' => 1])->assertForbidden(); // only admins switch it on
    $this->actingAs(makeUser('admin', null))->get('/qronex/settings')->assertForbidden(); // no tenant and not super admin
});

it('syncs employees, binds office and gate on approval, and writes QR punches into attendance_logs', function () {
    [$tid, $cid, $eid, $admin] = qxSetup();
    $gate = qxEnableWithGate($admin);
    expect(QxEmployee::withoutGlobalScopes()->where('tenant_id', $tid)->count())->toBe(2);
    $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $eid)->first();

    // activation needs an office first
    $this->post("/qronex/employees/{$e->id}/activation")->assertSessionHasErrors('office_id');
    $this->put("/qronex/employees/{$e->id}", ['office_id' => $gate->office_id, 'scan_scope' => 'office'])->assertSessionHasNoErrors();
    $this->post("/qronex/employees/{$e->id}/activation")->assertSessionHas('ok');

    [$r, $token] = qxActivatePhone($e->fresh());
    $r->assertCreated()->assertJsonPath('device.status', 'pending');
    $d = Device::withoutGlobalScopes()->first();
    $this->post("/qronex/devices/{$d->id}/approve", ['code' => $r->json('device.approval_code'), 'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate'])
        ->assertSessionHas('ok');
    expect($e->fresh()->gate_id)->toBe($gate->id);

    $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr'], 'lat' => 17.4488, 'lng' => 78.3909, 'accuracy' => 12, 'mocked' => false])
        ->assertOk()->assertJsonPath('event.type', 'IN');

    $log = DB::table('attendance_logs')->where('tenant_id', $tid)->first();
    expect($log->emp_code)->toBe('E100')->and($log->direction)->toBe('in')->and($log->source)->toBe('qronex')
        ->and($log->company_id)->toBe($cid)->and($log->log_date)->toBe(now('Asia/Kolkata')->toDateString());
    expect(DB::table('qx_attendance_events')->whereNotNull('host_synced_at')->count())->toBe(1);

    // a retry never duplicates the punch in SmartPRS
    \Ametecs\Qronex\Services\HostSync::push(\Ametecs\Qronex\Models\AttendanceEvent::withoutGlobalScopes()->first());
    expect(DB::table('attendance_logs')->where('tenant_id', $tid)->count())->toBe(1);
});

it('adds an approved missed punch to attendance_logs', function () {
    [$tid, , $eid, $admin] = qxSetup();
    $gate = qxEnableWithGate($admin);
    $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $eid)->first();
    $e->update(['office_id' => $gate->office_id]);
    [, $token] = qxActivatePhone($e);
    Device::withoutGlobalScopes()->update(['status' => 'approved']);

    $this->withToken($token)->postJson('/api/qronex/v1/corrections', ['type' => 'IN', 'date' => now('Asia/Kolkata')->subDay()->toDateString(), 'time' => '09:40', 'reason' => 'Forgot'])->assertCreated();
    $c = \Ametecs\Qronex\Models\PunchCorrection::withoutGlobalScopes()->first();
    $this->actingAs($admin)->post("/qronex/approvals/corrections/{$c->id}", ['decision' => 'approve'])->assertSessionHas('ok');

    $log = DB::table('attendance_logs')->where('tenant_id', $tid)->first();
    expect($log->punch_at)->toBe(now('Asia/Kolkata')->subDay()->toDateString().' 09:40:00')->and($log->source)->toBe('qronex');
});

it('stops phones and displays when disabled, keeping the data', function () {
    [$tid, , $eid, $admin] = qxSetup();
    $gate = qxEnableWithGate($admin);
    $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $eid)->first();
    $e->update(['office_id' => $gate->office_id]);
    [, $token] = qxActivatePhone($e);
    $this->withToken($token)->getJson('/api/qronex/v1/me')->assertOk();

    $this->actingAs($admin)->post('/qronex/settings/toggle', ['enabled' => 0])->assertRedirect('/qronex/settings');
    $this->withToken($token)->getJson('/api/qronex/v1/me')->assertForbidden()->assertJsonPath('reason_code', 'module_disabled');
    $this->getJson("/qronex/display/{$gate->id}/token?key={$gate->display_secret}")->assertOk()->assertJsonPath('active', false);
    $this->get('/qronex/offices')->assertRedirect('/qronex/settings');
    expect(Device::withoutGlobalScopes()->count())->toBe(1);
});

it('keeps tenants apart', function () {
    [, , , $admin] = qxSetup();
    $gate = qxEnableWithGate($admin);
    [$tid2] = makeTenantCompany();
    $other = makeUser('admin', $tid2);
    $this->actingAs($other)->post('/qronex/settings/toggle', ['enabled' => 1]);
    $this->get("/qronex/offices/{$gate->office_id}/edit")->assertNotFound();
    $this->post("/qronex/gates/{$gate->id}/reset")->assertNotFound();
});

it('renders every module screen', function () {
    [, , , $admin] = qxSetup();
    $gate = qxEnableWithGate($admin);
    $e = QxEmployee::withoutGlobalScopes()->first();
    foreach (['/qronex', '/qronex/attendance', '/qronex/attendance?tab=rejected', '/qronex/attendance/export.csv', '/qronex/devices', '/qronex/employees', "/qronex/employees/{$e->id}/edit",
        '/qronex/offices', '/qronex/offices/create', "/qronex/offices/{$gate->office_id}/edit", '/qronex/approvals', '/qronex/remote', '/qronex/settings'] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
    $this->get("/qronex/display/{$gate->id}?key={$gate->display_secret}")->assertOk()->assertSee('qrcode.min.js', false);
    $this->get('/qronex/asset?f=qronex.css')->assertOk(); expect(strtolower($this->get('/qronex/asset?f=qronex.css')->headers->get('Content-Type')))->toStartWith('text/css');
    $this->get('/qronex/asset?f=../../../.env')->assertNotFound();
});

it('applies the QRONEX scan rules: gate binding, geofence, expiry, one phone, remote work', function () {
    [$tid, , $eid, $admin] = qxSetup();
    $gate = qxEnableWithGate($admin);
    $g2 = $gate->office->gates()->create(['tenant_id' => $tid, 'code' => 'g2', 'name' => 'Back']);
    $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $eid)->first();
    $e->update(['office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate']);
    [, $token] = qxActivatePhone($e);
    Device::withoutGlobalScopes()->update(['status' => 'approved']);
    $scan = fn ($g, $over = []) => $this->withToken($token)->postJson('/api/qronex/v1/scan', $over + ['qr' => QrToken::make($g, 3)['qr'], 'lat' => 17.4488, 'lng' => 78.3909, 'accuracy' => 12, 'mocked' => false, 'type' => 'IN']);

    $scan($g2)->assertStatus(422)->assertJsonPath('reason_code', 'OFFICE_NOT_ASSIGNED');
    $scan($gate, ['lat' => 17.46502])->assertStatus(422)->assertJsonPath('reason_code', 'OUTSIDE_GEOFENCE');
    $scan($gate, ['mocked' => true])->assertStatus(422)->assertJsonPath('reason_code', 'MOCK_LOCATION');
    $scan($gate, ['qr' => QrToken::make($gate, 3, time() - 60)['qr']])->assertStatus(422)->assertJsonPath('reason_code', 'QR_EXPIRED');
    expect(DB::table('attendance_logs')->count())->toBe(0);

    // second phone = replacement request; approving revokes the first (one phone per employee)
    $e->forceFill(['activation_code_hash' => bcrypt('WXYZ2345'), 'activation_expires_at' => now()->addHour()])->save();
    $this->postJson('/api/qronex/v1/activate', ['company' => 'acme', 'employee_code' => 'E100', 'activation_code' => 'WXYZ2345', 'platform' => 'android', 'model' => 'New', 'install_ref' => str_repeat('d4', 12)])->assertCreated();
    $new = Device::withoutGlobalScopes()->where('install_ref', str_repeat('d4', 12))->first();
    expect($new->replaces_device_id)->not->toBeNull();
    $this->actingAs($admin)->post("/qronex/devices/{$new->id}/approve", ['code' => $new->approval_code])->assertSessionHas('ok');
    expect(Device::withoutGlobalScopes()->where('status', 'approved')->count())->toBe(1);

    // remote work: granted by the approver, punched from home, recorded in SmartPRS
    $this->post('/qronex/remote', ['employee_id' => $e->id, 'type' => 'wfh', 'from_date' => now('Asia/Kolkata')->toDateString(), 'to_date' => now('Asia/Kolkata')->toDateString(),
        'lat' => 17.40, 'lng' => 78.40, 'radius_m' => 300, 'reason' => 'Plumber visit'])->assertSessionHas('ok');
    $new->update(['token_hash' => hash('sha256', 'tok-new')]);
    $this->withToken('tok-new')->postJson('/api/qronex/v1/remote-scan', ['lat' => 17.4001, 'lng' => 78.4001, 'accuracy' => 15, 'mocked' => false])->assertOk();
    expect(DB::table('attendance_logs')->where('tenant_id', $tid)->value('source'))->toBe('qronex');
});


it('closes the review findings: copied install ref, double decisions, decided remote edits, failed approval binding', function () {
    [$tid, , $eid, $admin] = qxSetup();
    $gate = qxEnableWithGate($admin);
    $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $eid)->first();
    $e->update(['office_id' => $gate->office_id]);
    [, $token] = qxActivatePhone($e);
    Device::withoutGlobalScopes()->update(['status' => 'approved']);

    // another handset re-using the approved phone's install_ref (no matching hardware key) does not inherit the approval
    $e->forceFill(['activation_code_hash' => bcrypt('QQQQ2345'), 'activation_expires_at' => now()->addHour()])->save();
    $this->postJson('/api/qronex/v1/activate', ['company' => 'acme', 'employee_code' => 'E100', 'activation_code' => 'QQQQ2345', 'platform' => 'android', 'install_ref' => str_repeat('c3', 12)])
        ->assertCreated()->assertJsonPath('device.status', 'pending');

    // a correction decided twice adds one punch only
    Device::withoutGlobalScopes()->update(['status' => 'approved', 'token_hash' => hash('sha256', 'tk')]);
    $this->withToken('tk')->postJson('/api/qronex/v1/corrections', ['type' => 'IN', 'date' => now('Asia/Kolkata')->subDay()->toDateString(), 'time' => '09:40', 'reason' => 'Forgot'])->assertCreated();
    $c = \Ametecs\Qronex\Models\PunchCorrection::withoutGlobalScopes()->first();
    $this->actingAs($admin)->post("/qronex/approvals/corrections/{$c->id}", ['decision' => 'approve'])->assertSessionHas('ok');
    $this->post("/qronex/approvals/corrections/{$c->id}", ['decision' => 'approve'])->assertSessionHasErrors('correction');
    expect(DB::table('attendance_logs')->where('tenant_id', $tid)->count())->toBe(1);

    // an approved remote permission can't be edited through the approve form
    $this->post('/qronex/remote', ['employee_id' => $e->id, 'type' => 'wfh', 'from_date' => now('Asia/Kolkata')->toDateString(), 'to_date' => now('Asia/Kolkata')->toDateString(),
        'lat' => 17.40, 'lng' => 78.40, 'reason' => 'Home'])->assertSessionHas('ok');
    $p = \Ametecs\Qronex\Models\RemotePermission::withoutGlobalScopes()->first();
    $this->post("/qronex/approvals/remote/{$p->id}", ['decision' => 'approve', 'lat' => 12.9, 'lng' => 77.6, 'location_waived' => 1])->assertSessionHasErrors('remote');
    expect($p->fresh()->lat)->toBe(17.40)->and($p->fresh()->location_waived)->toBeFalse();

    // a failed approval (phone limit) leaves the employee's binding unchanged
    $g2 = $gate->office->gates()->create(['tenant_id' => $tid, 'code' => 'g2', 'name' => 'Back']);
    $extra = Device::create(['tenant_id' => $tid, 'employee_id' => $e->id, 'platform' => 'android', 'install_ref' => str_repeat('f6', 12), 'status' => 'pending']);
    $this->post("/qronex/devices/{$extra->id}/approve", ['office_id' => $gate->office_id, 'gate_id' => $g2->id, 'scan_scope' => 'gate'])->assertSessionHasErrors('device');
    expect($e->fresh()->gate_id)->toBeNull();
});
