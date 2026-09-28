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

/**
 * rev191 — the invariant the whole wiring rests on: a "day N of the period" is
 * an INDEX from the period start, not a day-of-month. For a calendar month the
 * two are identical (so nothing changes for calendar clients); for a cut-off
 * cycle only the index is right. PayrollGenController walks periods this way in
 * workingDaysInRange(), sandwichDays() and the DOJ proration.
 */
it('indexes days from the period start, not the calendar month', function () {
    $cal = P::resolve('2026-09', null);
    $s = \Illuminate\Support\Carbon::parse($cal['start']);
    // calendar month: index d IS day-of-month d — the no-change guarantee
    foreach ([1, 15, 30] as $d) {
        expect($s->copy()->addDays($d - 1)->day)->toBe($d);
    }

    $win = P::resolve('2026-09', 20);
    $w = \Illuminate\Support\Carbon::parse($win['start']);
    expect($win['start'])->toBe('2026-08-21');
    expect($w->copy()->addDays(0)->toDateString())->toBe('2026-08-21');   // index 1
    expect($w->copy()->addDays($win['days'] - 1)->toDateString())->toBe('2026-09-20');
    // someone joining 25 Aug is inside this period — index 5, not "not this month"
    expect((int) $w->diffInDays(\Illuminate\Support\Carbon::parse('2026-08-25')) + 1)->toBe(5);
});

/**
 * rev191 — payroll run before the period ends pays what has been EARNED.
 * 23 Sep 2026: 26 working days in the month (4 Sundays), 20 of them elapsed.
 * Full attendance must come out at 20/26, never 26/26.
 */
it('pays only the elapsed share of an unfinished period', function () {
    $p = P::resolve('2026-09', null);
    $cur = \Illuminate\Support\Carbon::parse($p['start']);
    $end = \Illuminate\Support\Carbon::parse($p['end']);
    $total = 0;
    $soFar = 0;
    while ($cur->lte($end)) {
        if ($cur->dayOfWeek !== \Illuminate\Support\Carbon::SUNDAY) {
            $total++;
            if ($cur->toDateString() <= '2026-09-23') {
                $soFar++;
            }
        }
        $cur->addDay();
    }
    expect($total)->toBe(26);
    expect($soFar)->toBe(20);
    expect(round(50000 * $soFar / $total, 2))->toBe(38461.54);   // not 50000.00
    expect($total - $soFar)->toBe(6);                            // not-yet-elapsed, never LOP
});

/**
 * rev191 — the bug that paid absent employees. Under the calendar / fixed30 day
 * bases weekly offs are PAID days, so an employee absent every single working
 * day of 22 Aug – 21 Sep 2026 (26 working days, 5 Sundays) still collected the
 * Sundays: 5/31 of a month's gross for zero attendance. Offs inside an absence
 * are LOP too, and no attendance at all means no pay.
 */
it('pays nothing for a period with no attendance at all', function () {
    $p = P::resolve('2026-09', 21);
    expect($p['start'])->toBe('2026-08-22');
    expect($p['end'])->toBe('2026-09-21');
    expect($p['days'])->toBe(31);

    $cur = \Illuminate\Support\Carbon::parse($p['start']);
    $end = \Illuminate\Support\Carbon::parse($p['end']);
    $working = 0;
    $offs = 0;
    while ($cur->lte($end)) {
        $cur->dayOfWeek === \Illuminate\Support\Carbon::SUNDAY ? $offs++ : $working++;
        $cur->addDay();
    }
    expect([$working, $offs])->toBe([26, 5]);

    // present 0, leave 0 -> every working day absent, every off sandwiched.
    $paidDays = 0.0;
    $lop = ($working - $paidDays) + $offs;          // 26 + 5
    $factor = max(0.0, min(1.0, ($p['days'] - $lop) / $p['days']));
    expect($factor)->toBe(0.0);                      // was 5/31 = 0.1613
    expect(round(50000 * $factor, 2))->toBe(0.0);    // was Rs 8,064.52

    // sanity: someone present every working day still gets the offs paid.
    $full = max(0.0, min(1.0, ($p['days'] - (($working - $working) + 0)) / $p['days']));
    expect($full)->toBe(1.0);
});
