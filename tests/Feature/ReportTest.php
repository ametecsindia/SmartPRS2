<?php

/*
 * Reports preview + CSV export over live data.
 */

test('reports preview returns the employees dataset with columns', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);
    makeEmployee($tid, $cid, 'E001');

    $j = $this->actingAs($admin)->getJson('/app/reports/preview?dataset=employees')
        ->assertOk()->assertJson(['ok' => true])->json();

    expect($j['columns'])->toContain('Code')
        ->and($j['count'])->toBeGreaterThanOrEqual(1);
});

test('reports export streams a CSV', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);
    makeEmployee($tid, $cid, 'E001');

    $resp = $this->actingAs($admin)->get('/app/reports/export?dataset=employees');
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('text/csv');
});
