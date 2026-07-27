<?php

use App\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * SaaS billing (super admin): set a subscription, generate an invoice, and mark
 * it paid (the test/manual path that needs no gateway keys).
 */

test('super admin sets a subscription and the amount is computed', function () {
    [$tid, $cid] = makeTenantCompany();
    $super = makeUser('super_admin', null);
    $planId = makePlan('Growth', 2500, 50, 75);

    // 80 employees on Growth (75 included, 5 extra × ₹50), quarterly, no discount:
    // (2500 + 250) × 3 = 8250.
    $this->actingAs($super)
        ->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 80, 'cycle' => 'quarterly'])
        ->assertOk()
        ->assertJson(['ok' => true, 'amount' => 8250]);

    // Annual advance earns 25% off: (2500 + 250) × 12 × 0.75 = 24750.
    $this->actingAs($super)
        ->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 80, 'cycle' => 'annual'])
        ->assertOk()
        ->assertJson(['ok' => true, 'amount' => 24750]);

    // Half-yearly advance earns 10% off, headcount within the included 75:
    // 2500 × 6 × 0.9 = 13500.
    $this->actingAs($super)
        ->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 40, 'cycle' => 'halfyear'])
        ->assertOk()
        ->assertJson(['ok' => true, 'amount' => 13500]);

    // Monthly is no longer offered (minimum 3 months advance).
    $this->actingAs($super)
        ->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 40, 'cycle' => 'monthly'])
        ->assertStatus(422);

    expect(DB::table('subscriptions')->where('tenant_id', $tid)->exists())->toBeTrue();
});

test('an invoice can be generated and marked paid', function () {
    [$tid, $cid] = makeTenantCompany();
    $super = makeUser('super_admin', null);
    $planId = makePlan('Growth', 5000, 100);

    $this->actingAs($super)->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 5, 'cycle' => 'quarterly'])->assertOk();

    $inv = $this->actingAs($super)->postJson('/app/billing/invoices/generate', ['tenant_id' => $tid])
        ->assertOk()->assertJson(['ok' => true])->json();

    $invId = DB::table('invoices')->where('tenant_id', $tid)->value('id');
    expect($invId)->not->toBeNull();

    $this->actingAs($super)->postJson('/app/billing/invoices/pay', ['invoice_id' => $invId, 'method' => 'manual'])
        ->assertOk()->assertJson(['ok' => true]);

    expect(DB::table('invoices')->where('id', $invId)->value('status'))->toBe('paid')
        ->and(DB::table('payments')->where('invoice_id', $invId)->where('status', 'success')->exists())->toBeTrue();
});

test('a company admin cannot reach platform billing', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);

    $this->actingAs($admin)->getJson('/app/billing/subscriptions')->assertStatus(403);
});

test('the gateway secret is encrypted at rest and live-readiness is reported', function () {
    $super = makeUser('super_admin', null);

    $this->actingAs($super)->postJson('/app/billing/gateways', [
        'mode' => 'live', 'key_id' => 'rzp_live_abc', 'secret' => 'topsecret', 'webhook_secret' => 'whsec123',
    ])->assertOk()->assertJson(['ok' => true]);

    $row = DB::table('payment_gateways')->where('gateway', 'razorpay')->first();
    // Stored value must NOT be the plaintext, and must decrypt back to it.
    expect($row->secret)->not->toBe('topsecret');
    expect(Crypt::decryptString($row->secret))->toBe('topsecret');
    expect(Crypt::decryptString($row->webhook_secret))->toBe('whsec123');

    $g = $this->actingAs($super)->getJson('/app/billing/gateways')->assertOk()->json('gateway');
    expect($g['liveReady'])->toBeTrue()
        ->and($g['hasSecret'])->toBeTrue()
        ->and($g['hasWebhookSecret'])->toBeTrue();
});

test('the razorpay webhook captures a payment with a valid signature (idempotent)', function () {
    [$tid, $cid] = makeTenantCompany();
    $super = makeUser('super_admin', null);
    $planId = makePlan('Growth', 5000, 100);
    $this->actingAs($super)->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 5, 'cycle' => 'quarterly'])->assertOk();
    $this->actingAs($super)->postJson('/app/billing/gateways', ['mode' => 'test', 'key_id' => 'rzp_test_x', 'secret' => 'sk', 'webhook_secret' => 'whsec'])->assertOk();

    $inv = BillingController::createInvoiceForTenant($tid);
    DB::table('invoices')->where('id', $inv->id)->update(['gateway_order_id' => 'order_TEST123']);

    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => ['payment' => ['entity' => ['id' => 'pay_TEST999', 'order_id' => 'order_TEST123', 'notes' => ['invoice_id' => (string) $inv->id]]]],
    ]);
    $sig = hash_hmac('sha256', $payload, 'whsec');

    // Valid signature → 200 + invoice paid.
    $this->call('POST', '/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertOk();
    expect(DB::table('invoices')->where('id', $inv->id)->value('status'))->toBe('paid')
        ->and(DB::table('payments')->where('gateway_txn_id', 'pay_TEST999')->count())->toBe(1);

    // Replay the same webhook → must NOT double-record (idempotent).
    $this->call('POST', '/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertOk();
    expect(DB::table('payments')->where('gateway_txn_id', 'pay_TEST999')->count())->toBe(1);
});

test('the razorpay webhook rejects a bad signature', function () {
    $super = makeUser('super_admin', null);
    $this->actingAs($super)->postJson('/app/billing/gateways', ['mode' => 'test', 'key_id' => 'rzp_test_x', 'secret' => 'sk', 'webhook_secret' => 'whsec'])->assertOk();

    $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'p1', 'order_id' => 'o1']]]]);
    $this->call('POST', '/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => 'deadbeef', 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertStatus(400);
});

test('the invoice PDF renders for super admin', function () {
    [$tid, $cid] = makeTenantCompany();
    $super = makeUser('super_admin', null);
    $planId = makePlan('Growth', 5000, 100);
    $this->actingAs($super)->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 5, 'cycle' => 'quarterly'])->assertOk();
    $inv = BillingController::createInvoiceForTenant($tid);

    $resp = $this->actingAs($super)->get('/app/billing/invoices/'.$inv->id.'/pdf');
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('application/pdf');
});

test('auto-renewal raises an invoice for a due subscription and advances the period', function () {
    [$tid, $cid] = makeTenantCompany();
    $super = makeUser('super_admin', null);
    $planId = makePlan('Growth', 5000, 100);
    $this->actingAs($super)->postJson('/app/billing/subscriptions', ['tenant_id' => $tid, 'plan_id' => $planId, 'seats' => 5, 'cycle' => 'quarterly'])->assertOk();

    // Force the subscription to be due yesterday.
    DB::table('subscriptions')->where('tenant_id', $tid)->update(['next_renewal' => now()->subDay()->toDateString(), 'status' => 'active']);

    $before = DB::table('invoices')->where('tenant_id', $tid)->count();
    $summary = BillingController::runRenewals();
    $after = DB::table('invoices')->where('tenant_id', $tid)->count();

    expect($summary['invoices'])->toBe(1)
        ->and($after)->toBe($before + 1)
        ->and(DB::table('subscriptions')->where('tenant_id', $tid)->value('next_renewal'))->toBe(now()->subDay()->addMonths(3)->toDateString());
});
