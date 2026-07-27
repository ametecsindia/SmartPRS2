<?php

use App\Services\GreetingService;

/**
 * F8 Greetings — pure-logic unit tests (no DB). Run: php artisan test.
 */
it('substitutes known template tokens', function () {
    $vars = ['name' => 'Asha Rao', 'first_name' => 'Asha', 'company' => 'Ametecs', 'years' => '3', 'date' => '26 Jul 2026'];
    expect(GreetingService::render('Happy Birthday, {{first_name}}!', $vars))->toBe('Happy Birthday, Asha!');
    expect(GreetingService::render('{{name}} — {{years}}y at {{company}}', $vars))->toBe('Asha Rao — 3y at Ametecs');
});

it('leaves unknown tokens blank rather than printing them', function () {
    expect(GreetingService::render('X {{missing}} Y', ['name' => 'A']))->toBe('X  Y');
});

it('derives first name and falls back to sensible defaults', function () {
    $v = GreetingService::vars(['name' => 'Ravi Kumar Singh'], 'Acme', 2, GreetingService::defaults());
    expect($v['first_name'])->toBe('Ravi');
    expect($v['years'])->toBe('2');
    expect($v['company'])->toBe('Acme');

    $blank = GreetingService::vars(['name' => 'Meera'], '', null, GreetingService::defaults());
    expect($blank['company'])->toBe('our company');
    expect($blank['years'])->toBe('');   // null years render as empty, not "0"
});

it('ships with birthday and anniversary templates and is opt-in by default', function () {
    $d = GreetingService::defaults();
    expect($d['enabled'])->toBeFalse();                 // opt-in: HR turns it on
    expect($d['birthday']['subject'])->toContain('{{first_name}}');
    expect($d['anniversary']['message'])->toContain('{{years}}');
    expect($d['anniversary']['min_years'])->toBe(1);
});
