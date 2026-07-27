<?php

/*
 * Standalone check for the StatutoryConfig merge rules — no Laravel, no DB.
 * Runs the real private methods by reflection, with the row cache pre-seeded so
 * rowsInForce() never touches a database.
 *
 *   php check_statutory_config.php
 */

// --- the tiny slice of Laravel the class touches at parse time --------------
namespace App\Support { class SchemaHelper { public static function ensureTable($a, $b) {} } }
namespace Illuminate\Support\Facades {
    class DB { public static function table($t) { throw new \RuntimeException('DB must not be reached in this check'); } }
    class Log { public static function warning($m) {} }
    class Schema { public static function hasTable($t) { return false; } }
}
namespace App\Services {
    class EffectiveDated {
        public static function toDate($on = null): string {
            if ($on === null || $on === '') { return '2026-07-01'; }
            return substr((string) $on, 0, 10);
        }
    }
}

namespace {

require __DIR__.'/../../app/Services/StatutoryConfig.php';

use App\Services\StatutoryConfig;

$pass = 0; $fail = 0;
function ok(bool $cond, string $what) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $what\n"; }
    else { $fail++; echo "  FAIL $what\n"; }
}

$ref = new ReflectionClass(StatutoryConfig::class);
$call = function (string $m, array $args) use ($ref) {
    $x = $ref->getMethod($m); $x->setAccessible(true);
    return $x->invokeArgs(null, $args);
};
$seed = function (array $rows) use ($ref) {
    $p = $ref->getProperty('rowCache'); $p->setAccessible(true);
    $p->setValue(null, ['1|2026-07-01' => array_map(fn ($r) => (object) $r, $rows)]);
    $m = $ref->getProperty('mergeCache'); $m->setAccessible(true); $m->setValue(null, []);
    $a = $ref->getProperty('anyRows'); $a->setAccessible(true); $a->setValue(null, true);
};

echo "\n1. No rows means no change (the whole safety story)\n";
$seed([]);
$base = ['pf_rate' => 12, 'pt_amount' => 200];
ok($call('mergedOverrides', [1, 5, 'Telangana', null, '', '2026-07-01']) === [],
   'no rows -> no overrides');
ok(StatutoryConfig::applyScope($base, ['tenant_id' => 1, 'month' => '2026-07']) === $base,
   'applyScope returns the input untouched');

echo "\n2. Per-kind resolution — a PT row must not shadow a PF row\n";
$seed([
    ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":13}', 'effective_from' => '2026-01-01', 'id' => 1],
    ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pt',
     'payload' => '{"pt_amount":150}', 'effective_from' => '2026-02-01', 'id' => 2],
]);
$got = $call('mergedOverrides', [1, null, '', null, '', '2026-07-01']);
ok(($got['pf_rate'] ?? null) == 13, 'the PF row survives the later PT row');
ok(($got['pt_amount'] ?? null) == 150, 'the PT row applies too');

echo "\n3. Effective dating — the newest row of a kind wins\n";
$seed([
    ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":12}', 'effective_from' => '2026-01-01', 'id' => 1],
    ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":13}', 'effective_from' => '2026-04-01', 'id' => 2],
]);
ok(($call('mergedOverrides', [1, null, '', null, '', '2026-07-01'])['pf_rate'] ?? null) == 13,
   'April row beats January row');

echo "\n4. Scope precedence — company beats state beats tenant\n";
$seed([
    ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":10}', 'effective_from' => '2026-01-01', 'id' => 1],
    ['scope' => 'state', 'scope_value' => 'Telangana', 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":12}', 'effective_from' => '2026-01-01', 'id' => 3],
    ['scope' => 'company', 'scope_value' => null, 'company_id' => 5, 'kind' => 'pf',
     'payload' => '{"pf_rate":13}', 'effective_from' => '2026-01-01', 'id' => 4],
]);
ok(($call('mergedOverrides', [1, 5, 'Telangana', null, '', '2026-07-01'])['pf_rate'] ?? null) == 13, 'company wins over state and tenant');
ok(($call('mergedOverrides', [1, null, 'Telangana', null, '', '2026-07-01'])['pf_rate'] ?? null) == 12, 'state wins when no company row applies');
ok(($call('mergedOverrides', [1, null, '', null, '', '2026-07-01'])['pf_rate'] ?? null) == 10, 'tenant is the floor');
ok(($call('mergedOverrides', [1, 9, 'Kerala', null, '', '2026-07-01'])['pf_rate'] ?? null) == 10,
   'a different company/state falls back to tenant');

echo "\n5. PT slab maps merge per state, not wholesale\n";
$seed([
    ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pt_slabs',
     'payload' => '{"pt_state_slabs":{"kerala":[[2000,0],[0,208]]}}', 'effective_from' => '2026-01-01', 'id' => 1],
    ['scope' => 'company', 'scope_value' => null, 'company_id' => 5, 'kind' => 'pt_slabs',
     'payload' => '{"pt_state_slabs":{"bihar":[[25000,0],[0,208]]}}', 'effective_from' => '2026-01-01', 'id' => 2],
]);
$slabs = $call('mergedOverrides', [1, 5, '', null, '', '2026-07-01'])['pt_state_slabs'] ?? [];
ok(isset($slabs['kerala']) && isset($slabs['bihar']),
   'the company row adding Bihar does not wipe the tenant row fixing Kerala');

echo "\n6. Slab validation\n";
ok($call('cleanSlabs', ['not an array']) === null, 'a non-array is rejected');
ok($call('cleanSlabs', [['mystate' => [[5000, -5]]]]) === null, 'a negative PT amount is rejected');
$sorted = $call('cleanSlabs', [['MyState' => [[0, 200], [10000, 0], [5000, 100]]]]);
ok($sorted === ['mystate' => [[5000.0, 100.0], [10000.0, 0.0], [0.0, 200.0]]],
   'bands sort ascending with the open-ended band last, key lower-cased');

echo "\n7. Loose state matching (humans type state names)\n";
ok($call('looseMatch', ['Andhra Pradesh', 'andhra']) === true, 'Andhra Pradesh matches andhra');
ok($call('looseMatch', ['Telangana', 'Kerala']) === false, 'unrelated states do not match');
ok($call('looseMatch', ['', 'kerala']) === false, 'a blank context never matches');

echo "\n8. Branch and location scope (branch > location > company > tenant)\n";
$seed([
    ['scope' => 'tenant', 'scope_value' => null, 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":10}', 'effective_from' => '2026-01-01', 'id' => 1],
    ['scope' => 'company', 'scope_value' => null, 'company_id' => 5, 'kind' => 'pf',
     'payload' => '{"pf_rate":13}', 'effective_from' => '2026-01-01', 'id' => 2],
    ['scope' => 'location', 'scope_value' => 'hyderabad', 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":14}', 'effective_from' => '2026-01-01', 'id' => 3],
    ['scope' => 'branch', 'scope_value' => '7', 'company_id' => null, 'kind' => 'pf',
     'payload' => '{"pf_rate":15}', 'effective_from' => '2026-01-01', 'id' => 4],
]);
ok(($call('mergedOverrides', [1, 5, '', 7, 'Hyderabad', '2026-07-01'])['pf_rate'] ?? null) == 15,
   'branch wins over location, company and tenant');
ok(($call('mergedOverrides', [1, 5, '', null, 'Hyderabad', '2026-07-01'])['pf_rate'] ?? null) == 14,
   'location (branch city) wins when no branch row applies, matched loosely');
ok(($call('mergedOverrides', [1, 5, '', 9, 'chennai', '2026-07-01'])['pf_rate'] ?? null) == 13,
   'a non-matching branch id and city fall back to company');
ok(($call('mergedOverrides', [1, null, '', 9, 'chennai', '2026-07-01'])['pf_rate'] ?? null) == 10,
   'and to tenant when no company applies either');

echo "\n".($fail ? "FAILED: $fail" : "ALL PASSED")."  ($pass assertions)\n";
exit($fail ? 1 : 0);

}
