<?php

use App\Services\EmployeeImportService as Svc;

/** F9 — mapping + validation helpers (no DB). */
it('auto-maps common header spellings to canonical fields', function () {
    $map = Svc::suggestMapping(['Employee Code', 'Full Name', 'E-mail', 'Date of Joining', 'Mystery']);
    expect($map['Employee Code'])->toBe('emp_code');
    expect($map['Full Name'])->toBe('name');
    expect($map['E-mail'])->toBe('email');
    expect($map['Date of Joining'])->toBe('doj');
    expect($map['Mystery'])->toBeNull();          // unknown headers are left for the user
});

it('treats DRA and DPA headers as the same declaration column', function () {
    expect(Svc::suggestMapping(['DRA'])['DRA'])->toBe('dpa');
    expect(Svc::suggestMapping(['DPA'])['DPA'])->toBe('dpa');
    expect(Svc::suggestMapping(['PCC'])['PCC'])->toBe('pcc');
});

it('parses Yes/No the same way the CSV importer does', function () {
    expect(Svc::yesNo('Yes'))->toBeTrue();
    expect(Svc::yesNo('n'))->toBeFalse();
    expect(Svc::yesNo(''))->toBeNull();
    expect(Svc::yesNo('maybe'))->toBe('invalid');
});

it('reads Indian day-first dates and rejects impossible ones', function () {
    expect(Svc::date('13/05/2024'))->toBe('2024-05-13');
    expect(Svc::date('2024-05-13'))->toBe('2024-05-13');
    expect(Svc::date('32/01/2024'))->toBeNull();
    expect(Svc::date('2024-02-30'))->toBeNull();
    expect(Svc::date(''))->toBeNull();
});
