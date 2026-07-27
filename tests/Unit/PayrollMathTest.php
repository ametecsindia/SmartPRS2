<?php

use App\Http\Controllers\AppDataController;

/*
 * Pure payroll math — no DB, no auth. Verifies the CTC → monthly breakdown the
 * whole payroll flow (Generate Payroll, payslips, vouchers) relies on.
 *
 * IMPORTANT (corrected 26 Jul 2026): Professional Tax is NOT a fixed figure.
 * It is set by each STATE, several states levy none at all, Maharashtra adds a
 * February uplift, and Maharashtra exempts women up to a threshold. The earlier
 * version of this file asserted a hard PT amount against the generic no-state
 * fallback slab and treated it as "PT ₹200", which is simply not how PT works —
 * that is why it failed. PT is now tested per state, and the CTC-breakdown
 * tests assert the structural invariant (net = gross − total deductions) rather
 * than a literal net that silently depends on a default slab.
 */

test('computeSlip splits a ₹6,00,000 CTC into the right monthly components', function () {
    $s = AppDataController::computeSlip(600000);

    expect($s['gross'])->toBe(50000.0)
        ->and($s['basic'])->toBe(25000.0)
        ->and($s['hra'])->toBe(10000.0)
        ->and($s['special'])->toBe(15000.0)
        ->and($s['pf'])->toBe(1800.0)      // min(25000, 15000 cap) × 12%
        ->and($s['esi'])->toBe(0.0);       // gross > 21,000 → not ESI-eligible

    // Structural invariant — holds whatever the PT slab happens to be.
    expect($s['net'])->toBe(round($s['gross'] - $s['total_ded'], 2));
});

test('ESI applies when gross is at/under the threshold', function () {
    $s = AppDataController::computeSlip(240000);   // gross 20,000 ≤ 21,000

    expect($s['gross'])->toBe(20000.0)
        ->and($s['pf'])->toBe(1200.0)      // min(10000, 15000 cap) × 12%
        ->and($s['esi'])->toBe(150.0);     // 20,000 × 0.75%

    expect($s['net'])->toBe(round($s['gross'] - $s['total_ded'], 2));
});

test('a zero CTC yields a zero slip', function () {
    $s = AppDataController::computeSlip(0);

    expect($s['gross'])->toBe(0.0)
        ->and($s['net'])->toBe(0.0)
        ->and($s['pt'])->toBe(0.0);        // PT only when gross > 0
});

/*
 * Professional Tax — state by state.
 */

test('PT follows the state slab, not a single national figure', function () {
    // Telangana / Andhra: nil to 15k, ₹150 to 20k, ₹200 above.
    expect(AppDataController::ptForGross(14000, [], 'Telangana'))->toBe(0.0)
        ->and(AppDataController::ptForGross(20000, [], 'Telangana'))->toBe(150.0)
        ->and(AppDataController::ptForGross(25000, [], 'Andhra Pradesh'))->toBe(200.0);

    // Karnataka: nil up to ₹24,999, then ₹200.
    expect(AppDataController::ptForGross(24999, [], 'Karnataka'))->toBe(0.0)
        ->and(AppDataController::ptForGross(25000, [], 'Karnataka'))->toBe(200.0);

    // Gujarat: nil to ₹12,000, then ₹200.
    expect(AppDataController::ptForGross(12000, [], 'Gujarat'))->toBe(0.0)
        ->and(AppDataController::ptForGross(18000, [], 'Gujarat'))->toBe(200.0);
});

test('states that levy no Professional Tax deduct nothing', function () {
    foreach (['Delhi', 'Uttar Pradesh', 'Haryana', 'Rajasthan'] as $state) {
        expect(AppDataController::ptForGross(90000, [], $state))->toBe(0.0);
    }
});

test('Maharashtra adds the February uplift only where PT is already at the top slab', function () {
    // MH slab: nil to 7,500 · ₹175 to 10,000 · ₹200 above.
    expect(AppDataController::ptForGross(9000, [], 'Maharashtra'))->toBe(175.0)
        ->and(AppDataController::ptForGross(15000, [], 'Maharashtra'))->toBe(200.0);

    // February: the ₹200 slab becomes ₹300 (annual ₹2,500 collected in the last month).
    expect(AppDataController::ptForGross(15000, [], 'Maharashtra', '2026-02'))->toBe(300.0);

    // …but a lower slab is untouched in February.
    expect(AppDataController::ptForGross(9000, [], 'Maharashtra', '2026-02'))->toBe(175.0);
});

test('Maharashtra exempts women up to the threshold', function () {
    // Women drawing up to ₹25,000/month are exempt under the MH Act.
    expect(AppDataController::ptForGross(20000, [], 'Maharashtra', null, 'female'))->toBe(0.0)
        ->and(AppDataController::ptForGross(25000, [], 'Maharashtra', null, 'Female'))->toBe(0.0);

    // Above the threshold the normal slab applies again.
    expect(AppDataController::ptForGross(30000, [], 'Maharashtra', null, 'female'))->toBe(200.0);

    // Men at the same salary are not exempt.
    expect(AppDataController::ptForGross(20000, [], 'Maharashtra', null, 'male'))->toBe(200.0);

    // The exemption is Maharashtra-specific — it must not leak into other states.
    expect(AppDataController::ptForGross(20000, [], 'Telangana', null, 'female'))->toBe(150.0);

    // And it can be switched off per company.
    expect(AppDataController::ptForGross(20000, ['pt_female_exempt' => 0], 'Maharashtra', null, 'female'))->toBe(200.0);
});
