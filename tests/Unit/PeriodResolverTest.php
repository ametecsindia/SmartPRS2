<?php

use App\Services\PeriodResolver as P;

/**
 * F5 / rev182 — payroll period maths. Pure, no DB. `php artisan test`.
 *
 * The FIRST group is the regression gate for P1: with no configuration the
 * resolver must describe exactly the calendar month the engine already uses.
 * If any of those fail, do not wire this into PayrollGenController.
 */
it('describes a plain calendar month when nothing is configured', function () {
    $c = P::resolve('2026-07', null);
    expect($c['start'])->toBe('2026-07-01');
    expect($c['end'])->toBe('2026-07-31');
    expect($c['days'])->toBe(31);
    expect($c['mode'])->toBe('calendar');

    expect(P::resolve('2026-02', null)['end'])->toBe('2026-02-28');
    expect(P::resolve('2028-02', null)['end'])->toBe('2028-02-29');   // leap
});

it('resolves the 21st-to-20th retention window', function () {
    $w = P::resolve('2026-07', 20);
    expect($w['start'])->toBe('2026-06-21');
    expect($w['end'])->toBe('2026-07-20');
    expect($w['days'])->toBe(30);
    expect($w['label'])->toBe('21 Jun – 20 Jul 2026 (30 days)');
});

it('crosses the year boundary correctly', function () {
    $j = P::resolve('2027-01', 20);
    expect($j['start'])->toBe('2026-12-21');
    expect($j['end'])->toBe('2027-01-20');
});

it('clamps a cutoff day that does not exist to the last day of the month', function () {
    // decision #3 — Feb "30" becomes 28, or 29 in a leap year.
    expect(P::resolve('2026-02', 30)['end'])->toBe('2026-02-28');
    expect(P::resolve('2028-02', 30)['end'])->toBe('2028-02-29');
    expect(P::resolve('2026-03', 30)['start'])->toBe('2026-03-01');
});

it('pays on the first occurrence of the pay day on or after the period end', function () {
    // the rule change: a 21→20 window with pay day 30 pays in the SAME month
    expect(P::payDate('2026-07-20', 30))->toBe('2026-07-30');
    expect(P::payDate('2026-07-31', 7))->toBe('2026-08-07');
    expect(P::payDate('2026-07-20', 5))->toBe('2026-08-05');
    expect(P::payDate('2026-02-20', 31))->toBe('2026-02-28');   // clamped
    expect(P::payDate('2026-07-20', null))->toBeNull();
});

it('maps a calendar date to the payroll month that will pay it', function () {
    expect(P::payrollMonthFor('2026-06-25', 20))->toBe('2026-07');
    expect(P::payrollMonthFor('2026-06-20', 20))->toBe('2026-06');
    expect(P::payrollMonthFor('2026-12-31', 20))->toBe('2027-01');
    expect(P::payrollMonthFor('2026-06-25', null))->toBe('2026-06');
});

it('keeps consecutive periods continuous — no gaps, no overlaps', function () {
    // decision #4
    expect(P::validateContinuity('2026-01', 14, null)['ok'])->toBeTrue();
    expect(P::validateContinuity('2026-01', 14, 20)['ok'])->toBeTrue();
    expect(P::validateContinuity('2028-01', 4, 30)['ok'])->toBeTrue();   // through leap Feb
});

it('produces a one-off bridging period when a company switches cycle', function () {
    // last paid 1–31 Jul on calendar, first window month is Sep → 1–20 Aug is
    // the gap that must be run once, or those days go unpaid.
    $t = P::transitionStub('2026-07-31', '2026-09', 20);
    expect($t['start'])->toBe('2026-08-01');
    expect($t['end'])->toBe('2026-08-20');
    expect($t['days'])->toBe(20);

    expect(P::transitionStub('2026-07-20', '2026-08', 20))->toBeNull();   // contiguous
    expect(P::transitionStub(null, '2026-08', 20))->toBeNull();           // nothing paid yet
});

it('flags a pay gap wider than the statutory week', function () {
    expect(P::payGapCheck('2026-07-20', '2026-07-30')['ok'])->toBeFalse();
    expect(P::payGapCheck('2026-07-31', '2026-08-05')['ok'])->toBeTrue();
});
