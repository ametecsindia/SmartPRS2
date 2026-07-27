<?php

use App\Services\AttendanceCorrectionService as Svc;

/** F2 — pure helper/config tests (no DB). */
it('exposes seven correction sub-types and five statuses', function () {
    expect(Svc::SUB_TYPES)->toHaveCount(7);
    expect(Svc::STATUSES)->toBe(['pending', 'approved', 'rejected', 'cancelled', 'applied']);
});

it('normalises times to H:i and rejects nonsense', function () {
    expect(Svc::hm('9:5'))->toBeNull();          // minutes must be two digits
    expect(Svc::hm('9:05'))->toBe('09:05');
    expect(Svc::hm('18:30'))->toBe('18:30');
    expect(Svc::hm('18:30:00'))->toBe('18:30');
    expect(Svc::hm('25:00'))->toBeNull();
    expect(Svc::hm('abc'))->toBeNull();
    expect(Svc::hm(''))->toBeNull();
});

it('normalises dates to Y-m-d', function () {
    expect(Svc::ymd('2026-07-25'))->toBe('2026-07-25');
    expect(Svc::ymd(''))->toBeNull();
});
