<?php

use App\Services\ProbationService;

/**
 * F7/F6 Probation — pure-logic unit tests (no DB). Run: php artisan test.
 */
it('detects probation only from the employment_stage flag', function () {
    expect(ProbationService::isOnProbation((object) ['employment_stage' => 'probation']))->toBeTrue();
    expect(ProbationService::isOnProbation((object) ['employment_stage' => 'PROBATION']))->toBeTrue();
    expect(ProbationService::isOnProbation((object) ['employment_stage' => '']))->toBeFalse();
    expect(ProbationService::isOnProbation((object) ['employment_stage' => 'internship']))->toBeFalse();
});

it('prefers an explicit probation_end over the derived date', function () {
    $emp = ['doj' => '2026-01-01', 'probation_end' => '2026-05-15', 'probation_months' => 6];
    expect(ProbationService::endDate($emp, ['default_months' => 6])->toDateString())->toBe('2026-05-15');
});

it('derives the end date from doj + per-employee months, else the company default', function () {
    // per-employee override wins
    $a = ['doj' => '2026-01-01', 'probation_months' => 3];
    expect(ProbationService::endDate($a, ['default_months' => 6])->toDateString())->toBe('2026-04-01');
    // falls back to the company default when no override
    $b = ['doj' => '2026-01-01'];
    expect(ProbationService::endDate($b, ['default_months' => 6])->toDateString())->toBe('2026-07-01');
});

it('returns null when there is no joining date to derive from', function () {
    expect(ProbationService::endDate(['doj' => null], ['default_months' => 6]))->toBeNull();
});

it('treats an employee as confirmed once confirmed_on is stamped', function () {
    expect(ProbationService::isConfirmed((object) ['probation_confirmed_on' => '2026-07-01']))->toBeTrue();
    expect(ProbationService::isConfirmed((object) ['probation_confirmed_on' => null]))->toBeFalse();
});

it('defaults to sensible reminder milestones', function () {
    $d = ProbationService::defaults();
    expect($d['default_months'])->toBe(6);
    expect($d['reminder_days'])->toBe([30, 15, 7, 0]);
    expect($d['overdue_nudge'])->toBeTrue();
});
