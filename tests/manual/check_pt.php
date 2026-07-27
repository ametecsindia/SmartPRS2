<?php

/*
 * Behavioural check for the F1 PT changes, run against the REAL ptForGross /
 * ptWalkSlab code extracted from the live AppDataController.
 *
 * The first section is the one that matters: with no configuration present,
 * every existing case must return exactly what it returned before F1.
 */

/*
 * pt_extract.php is a SNAPSHOT of the PT code lifted out of AppDataController
 * (the PT_FREE_STATES / PT_STATE_SLABS consts, ptStateSlabs(), ptFreeStates(),
 * ptWalkSlab() and ptForGross()), wrapped in a bare class so it can run with no
 * Laravel and no database. If you change any of those in AppDataController,
 * re-extract: copy the const block through the end of ptForGross() into
 * pt_extract.php between "<?php class PT {" and "}".
 */
require __DIR__.'/pt_extract.php';

$pass = 0;
$fail = 0;
function is_(float $got, float $want, string $what)
{
    global $pass, $fail;
    if (abs($got - $want) < 0.005) { $pass++; echo "  ok   $what  = $got\n"; }
    else { $fail++; echo "  FAIL $what  got $got want $want\n"; }
}

echo "\n1. NO CONFIG — behaviour must be byte-identical to before F1\n";
is_(PT::ptForGross(14000, [], 'Telangana'), 0.0, 'Telangana 14,000');
is_(PT::ptForGross(20000, [], 'Telangana'), 150.0, 'Telangana 20,000');
is_(PT::ptForGross(25000, [], 'Andhra Pradesh'), 200.0, 'Andhra 25,000');
is_(PT::ptForGross(24999, [], 'Karnataka'), 0.0, 'Karnataka 24,999');
is_(PT::ptForGross(25000, [], 'Karnataka'), 200.0, 'Karnataka 25,000');
is_(PT::ptForGross(12000, [], 'Gujarat'), 0.0, 'Gujarat 12,000');
is_(PT::ptForGross(18000, [], 'Gujarat'), 200.0, 'Gujarat 18,000');
is_(PT::ptForGross(90000, [], 'Delhi'), 0.0, 'Delhi is PT-free');
is_(PT::ptForGross(90000, [], 'Uttar Pradesh'), 0.0, 'UP is PT-free');
is_(PT::ptForGross(9000, [], 'Maharashtra'), 175.0, 'Maharashtra 9,000');
is_(PT::ptForGross(15000, [], 'Maharashtra'), 200.0, 'Maharashtra 15,000');
is_(PT::ptForGross(15000, [], 'Maharashtra', '2026-02'), 300.0, 'Maharashtra February uplift');
is_(PT::ptForGross(9000, [], 'Maharashtra', '2026-02'), 175.0, 'February leaves the lower band alone');
is_(PT::ptForGross(20000, [], 'Maharashtra', null, 'female'), 0.0, 'MH female exemption');
is_(PT::ptForGross(25000, [], 'Maharashtra', null, 'Female'), 0.0, 'MH female exemption at the ceiling');
is_(PT::ptForGross(30000, [], 'Maharashtra', null, 'female'), 200.0, 'above the ceiling the slab returns');
is_(PT::ptForGross(20000, [], 'Maharashtra', null, 'male'), 200.0, 'men are not exempt');
is_(PT::ptForGross(20000, [], 'Telangana', null, 'female'), 150.0, 'the exemption does not leak to Telangana');
is_(PT::ptForGross(20000, ['pt_female_exempt' => 0], 'Maharashtra', null, 'female'), 200.0, 'exemption switchable off');
is_(PT::ptForGross(18000, [], ''), 150.0, 'no state falls back to the generic slab');
is_(PT::ptForGross(0, [], 'Telangana'), 0.0, 'zero gross pays nothing');

echo "\n2. Correcting a slab a state has changed\n";
$cfg = ['pt_state_slabs' => ['karnataka' => [[30000, 0], [0, 250]]]];
is_(PT::ptForGross(25000, $cfg, 'Karnataka'), 0.0, 'Karnataka nil band raised to 30,000');
is_(PT::ptForGross(35000, $cfg, 'Karnataka'), 250.0, 'Karnataka top band raised to 250');
is_(PT::ptForGross(25000, $cfg, 'Gujarat'), 200.0, 'other states are untouched by that override');

echo "\n3. Adding a state that is not compiled in\n";
$cfg = ['pt_state_slabs' => ['sikkim' => [[20000, 0], [30000, 125], [0, 200]]]];
is_(PT::ptForGross(15000, $cfg, 'Sikkim'), 0.0, 'Sikkim nil band');
is_(PT::ptForGross(28000, $cfg, 'Sikkim'), 125.0, 'Sikkim middle band');
is_(PT::ptForGross(50000, $cfg, 'Sikkim'), 200.0, 'Sikkim top band');

echo "\n4. A PT-free state that starts levying PT\n";
$cfg = ['pt_state_slabs' => ['haryana' => [[15000, 0], [0, 200]]]];
is_(PT::ptForGross(50000, $cfg, 'Haryana'), 200.0, 'an explicit slab outranks the built-in free list');
is_(PT::ptForGross(50000, [], 'Haryana'), 0.0, '...and without it Haryana is still free');

echo "\n5. Declaring a further state PT-free\n";
$cfg = ['pt_free_states' => ['goa']];
is_(PT::ptForGross(50000, $cfg, 'Goa'), 0.0, 'Goa switched to PT-free by config');
is_(PT::ptForGross(50000, [], 'Goa'), 200.0, '...and it still charges without the config');

echo "\n6. A configured Maharashtra slab keeps the February rule\n";
$cfg = ['pt_state_slabs' => ['maharashtra' => [[8000, 0], [0, 200]]]];
is_(PT::ptForGross(50000, $cfg, 'Maharashtra', '2026-02'), 300.0, 'February uplift still applies');
is_(PT::ptForGross(50000, $cfg, 'Maharashtra', '2026-03'), 200.0, 'March does not');
is_(PT::ptForGross(20000, $cfg, 'Maharashtra', null, 'female'), 0.0, 'female exemption still wins over a configured slab');

echo "\n7. Malformed configuration must not break payroll\n";
is_(PT::ptForGross(20000, ['pt_state_slabs' => 'rubbish'], 'Telangana'), 150.0, 'a non-array slab map is ignored');
is_(PT::ptForGross(20000, ['pt_state_slabs' => ['' => [[0, 500]]]], 'Telangana'), 150.0, 'a blank state key is ignored');
is_(PT::ptForGross(20000, ['pt_state_slabs' => ['telangana' => []]], 'Telangana'), 150.0, 'an empty band list falls through to the built-in');
is_(PT::ptForGross(20000, ['pt_state_slabs' => ['telangana' => [[15000]]]], 'Telangana'), 0.0, 'a short band row is skipped, leaving 0');
is_(PT::ptForGross(20000, ['pt_free_states' => 'rubbish'], 'Telangana'), 150.0, 'a non-array free list is ignored');

echo "\n".($fail ? "FAILED: $fail" : 'ALL PASSED')."  ($pass assertions)\n";
exit($fail ? 1 : 0);
