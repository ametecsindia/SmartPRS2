<?php

use App\Services\StatutoryConfig;

/*
 * F1 — StatutoryConfig merge rules, as Pest unit tests. Pure: no DB, no auth.
 *
 * The real merge/resolution logic is exercised through its private methods by
 * reflection, with the per-request row cache pre-seeded so rowsInForce() never
 * reaches a database (it returns early on a cache hit). This mirrors the
 * standalone tests/manual/check_statutory_config.php one-to-one, so the two can
 * never drift. Covers the no-config safety invariant, per-kind resolution,
 * effective dating, the full scope precedence (branch > location > company >
 * state > tenant), per-state PT slab merging, slab validation and loose state
 * matching.
 */

function scRef(): ReflectionClass
{
    return new ReflectionClass(StatutoryConfig::class);
}

function scCall(string $method, array $args)
{
    $x = scRef()->getMethod($method);
    $x->setAccessible(true);

    return $x->invokeArgs(null, $args);
}

/** Seed the row cache under the 2026-07-01 key and mark the install as having rows. */
function scSeed(array $rows): void
{
    $r = scRef();
    $p = $r->getProperty('rowCache');
    $p->setAccessible(true);
    $p->setValue(null, ['1|2026-07-01' => array_map(fn ($x) => (object) $x, $rows)]);
    $m = $r->getProperty('mergeCache');
    $m->setAccessible(true);
    $m->setValue(null, []);
    $a = $r->getProperty('anyRows');
    $a->setAccessible(true);
    $a->setValue(null, true);
}

/** Force the empty-install path: no rows, so applyScope short-circuits without a query. */
function scNoRows(): void
{
    $r = scRef();
    foreach (['rowCache' => [], 'mergeCache' => []] as $prop => $v) {
        $x = $r->getProperty($prop);
        $x->setAccessible(true);
        $x->setValue(null, $v);
    }
    $a = $r->getProperty('anyRows');
    $a->setAccessible(true);
    $a->setValue(null, false);
}

test('no override rows leave the rates untouched (the safety invariant)', function () {
    scNoRows();
    $base = ['pf_rate' => 12, 'pt_amount' => 200];
    expect(StatutoryConfig::applyScope($base, ['tenant_id' => 1, 'month' => '2026-07']))->toBe($base);
});

test('a PT row does not shadow a PF row (per-kind resolution)', function () {
    scSeed([
        ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":13}', 'effective_from' => '2026-01-01', 'id' => 1],
        ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pt', 'payload' => '{"pt_amount":150}', 'effective_from' => '2026-02-01', 'id' => 2],
    ]);
    $got = scCall('mergedOverrides', [1, null, '', null, '', '2026-07-01']);
    expect($got['pf_rate'])->toEqual(13)->and($got['pt_amount'])->toEqual(150);
});

test('the newest in-force row of a kind wins (effective dating)', function () {
    scSeed([
        ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":12}', 'effective_from' => '2026-01-01', 'id' => 1],
        ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":13}', 'effective_from' => '2026-04-01', 'id' => 2],
    ]);
    expect(scCall('mergedOverrides', [1, null, '', null, '', '2026-07-01'])['pf_rate'])->toEqual(13);
});

test('scope precedence: company beats state beats tenant', function () {
    scSeed([
        ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":10}', 'effective_from' => '2026-01-01', 'id' => 1],
        ['scope' => 'state', 'scope_value' => 'Telangana', 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":12}', 'effective_from' => '2026-01-01', 'id' => 3],
        ['scope' => 'company', 'scope_value' => null, 'company_id' => 5, 'kind' => 'pf', 'payload' => '{"pf_rate":13}', 'effective_from' => '2026-01-01', 'id' => 4],
    ]);
    expect(scCall('mergedOverrides', [1, 5, 'Telangana', null, '', '2026-07-01'])['pf_rate'])->toEqual(13);
    expect(scCall('mergedOverrides', [1, null, 'Telangana', null, '', '2026-07-01'])['pf_rate'])->toEqual(12);
    expect(scCall('mergedOverrides', [1, null, '', null, '', '2026-07-01'])['pf_rate'])->toEqual(10);
});

test('scope precedence: branch beats location beats company beats tenant', function () {
    scSeed([
        ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":10}', 'effective_from' => '2026-01-01', 'id' => 1],
        ['scope' => 'company', 'scope_value' => null, 'company_id' => 5, 'kind' => 'pf', 'payload' => '{"pf_rate":13}', 'effective_from' => '2026-01-01', 'id' => 2],
        ['scope' => 'location', 'scope_value' => 'hyderabad', 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":14}', 'effective_from' => '2026-01-01', 'id' => 3],
        ['scope' => 'branch', 'scope_value' => '7', 'company_id' => null, 'kind' => 'pf', 'payload' => '{"pf_rate":15}', 'effective_from' => '2026-01-01', 'id' => 4],
    ]);
    expect(scCall('mergedOverrides', [1, 5, '', 7, 'Hyderabad', '2026-07-01'])['pf_rate'])->toEqual(15);
    expect(scCall('mergedOverrides', [1, 5, '', null, 'Hyderabad', '2026-07-01'])['pf_rate'])->toEqual(14);
    expect(scCall('mergedOverrides', [1, 5, '', 9, 'chennai', '2026-07-01'])['pf_rate'])->toEqual(13);
    expect(scCall('mergedOverrides', [1, null, '', 9, 'chennai', '2026-07-01'])['pf_rate'])->toEqual(10);
});

test('PT slab maps merge per state, not wholesale', function () {
    scSeed([
        ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pt_slabs', 'payload' => '{"pt_state_slabs":{"kerala":[[2000,0],[0,208]]}}', 'effective_from' => '2026-01-01', 'id' => 1],
        ['scope' => 'company', 'scope_value' => null, 'company_id' => 5, 'kind' => 'pt_slabs', 'payload' => '{"pt_state_slabs":{"bihar":[[25000,0],[0,208]]}}', 'effective_from' => '2026-01-01', 'id' => 2],
    ]);
    $slabs = scCall('mergedOverrides', [1, 5, '', null, '', '2026-07-01'])['pt_state_slabs'];
    expect($slabs)->toHaveKeys(['kerala', 'bihar']);
});

test('cleanSlabs rejects bad input and sorts bands with the open band last', function () {
    expect(scCall('cleanSlabs', ['not an array']))->toBeNull();
    expect(scCall('cleanSlabs', [['mystate' => [[5000, -5]]]]))->toBeNull();
    expect(scCall('cleanSlabs', [['MyState' => [[0, 200], [10000, 0], [5000, 100]]]]))
        ->toBe(['mystate' => [[5000.0, 100.0], [10000.0, 0.0], [0.0, 200.0]]]);
});

test('looseMatch compares human-typed state names leniently', function () {
    expect(scCall('looseMatch', ['Andhra Pradesh', 'andhra']))->toBeTrue();
    expect(scCall('looseMatch', ['Telangana', 'Kerala']))->toBeFalse();
    expect(scCall('looseMatch', ['', 'kerala']))->toBeFalse();
});
