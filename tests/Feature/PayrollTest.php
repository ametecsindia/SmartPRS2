<?php

use Illuminate\Support\Facades\DB;

/*
 * The core money path: generating a payroll run creates a real draft run plus a
 * payslip per active employee, which then appears in Salary Approval.
 */

test('an admin generates a real payroll run with one payslip per employee', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);
    makeEmployee($tid, $cid, 'E001', 600000);
    makeEmployee($tid, $cid, 'E002', 360000);

    $this->actingAs($admin)
        ->postJson('/app/payroll/generate', ['company_id' => $cid, 'month' => '2026-05'])
        ->assertOk()
        ->assertJson(['ok' => true, 'count' => 2]);

    expect(DB::table('payroll_runs')->where('company_id', $cid)->where('cycle_label', '2026-05')->count())->toBe(1)
        ->and(DB::table('payslips')->where('company_id', $cid)->count())->toBe(2);
});

test('the generated run shows up in Salary Approval', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);
    makeEmployee($tid, $cid, 'E001', 600000);

    $this->actingAs($admin)->postJson('/app/payroll/generate', ['company_id' => $cid, 'month' => '2026-05'])->assertOk();

    $runs = $this->actingAs($admin)->getJson('/app/salary-runs')->assertOk()->json('rows');
    expect(count($runs))->toBeGreaterThanOrEqual(1);
});

test('generating the same month twice needs confirmation (no duplicate)', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);
    makeEmployee($tid, $cid, 'E001', 600000);

    $this->actingAs($admin)->postJson('/app/payroll/generate', ['company_id' => $cid, 'month' => '2026-05'])->assertOk();
    // Second attempt without regenerate is refused.
    $this->actingAs($admin)->postJson('/app/payroll/generate', ['company_id' => $cid, 'month' => '2026-05'])
        ->assertStatus(409);

    expect(DB::table('payroll_runs')->where('company_id', $cid)->where('cycle_label', '2026-05')->count())->toBe(1);
});
