<?php

use Illuminate\Support\Facades\DB;

/*
 * Master-data CRUD + the server-side role guard. An admin can create master
 * records; a plain employee cannot (the row must never be written).
 */

test('an admin can create a department', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);

    $this->actingAs($admin)
        ->postJson('/app/master/departments', ['item' => ['name' => 'Operations', 'company_name' => 'Test Co']])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(DB::table('departments')->where('name', 'Operations')->exists())->toBeTrue();
});

test('a plain employee cannot create a department', function () {
    [$tid, $cid] = makeTenantCompany();
    $emp = makeUser('employee', $tid);

    $this->actingAs($emp)
        ->postJson('/app/master/departments', ['item' => ['name' => 'ShouldNotExist', 'company_name' => 'Test Co']]);

    // The security property that matters: the row was never written.
    expect(DB::table('departments')->where('name', 'ShouldNotExist')->exists())->toBeFalse();
});

test('a holiday saves with its date', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);

    $this->actingAs($admin)
        ->postJson('/app/master/holidays', ['item' => ['name' => 'Republic Day', 'date' => '2026-01-26']])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(DB::table('holidays')->where('name', 'Republic Day')->exists())->toBeTrue();
});

test('a team resolves manager + leader names to FK ids (emp_map)', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);
    makeEmployee($tid, $cid, 'M001');   // name = "Emp M001"

    $this->actingAs($admin)
        ->postJson('/app/master/teams', ['item' => ['name' => 'Recovery A', 'company_name' => 'Test Co', 'manager' => 'Emp M001']])
        ->assertOk()->assertJson(['ok' => true]);

    $team = DB::table('teams')->where('name', 'Recovery A')->first();
    expect($team)->not->toBeNull()
        ->and($team->manager_id)->not->toBeNull();
});

test('an offer letter is pinned to letter_type=offer (fixed)', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);

    // Offer letters are addressed to a CANDIDATE (from Recruitment), not an employee.
    $this->actingAs($admin)
        ->postJson('/app/master/letters-offer', ['item' => ['candidate' => 'Asha Candidate', 'company_name' => 'Test Co', 'status' => 'issued']])
        ->assertOk()->assertJson(['ok' => true]);

    expect(DB::table('letters')->where('letter_type', 'offer')->exists())->toBeTrue();
});

test('a letter template saves with a title + body (no employee)', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);

    $this->actingAs($admin)
        ->postJson('/app/master/letters-templates', ['item' => ['title' => 'Standard Offer', 'letter_type' => 'offer', 'body' => 'Dear {{employee_name}}, welcome.', 'status' => 'active']])
        ->assertOk()->assertJson(['ok' => true]);

    expect(DB::table('letters')->where('is_template', 1)->where('title', 'Standard Offer')->exists())->toBeTrue();
});

test('an issued offer letter generates a merged PDF from its template', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);
    makeEmployee($tid, $cid, 'E001', 600000);

    $this->actingAs($admin)->postJson('/app/master/letters-templates', ['item' => ['title' => 'Offer', 'letter_type' => 'offer', 'body' => 'Dear {{candidate_name}}, welcome aboard.', 'status' => 'active']])->assertOk();
    $this->actingAs($admin)->postJson('/app/master/letters-offer', ['item' => ['candidate' => 'Ravi Candidate', 'company_name' => 'Test Co', 'status' => 'issued']])->assertOk();

    $lid = DB::table('letters')->where('is_template', 0)->where('letter_type', 'offer')->value('id');
    expect($lid)->not->toBeNull();

    $resp = $this->actingAs($admin)->get('/app/letters/'.$lid.'/pdf');
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('pdf');
});
