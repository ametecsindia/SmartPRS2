<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SaaS billing (rev 42, go-live hardening rev 51) — platform-side, super-admin
 * only. The platform bills each tenant for its plan: subscription → invoice →
 * payment. Razorpay is the live gateway:
 *
 *   • Checkout (create order + verify signature) — synchronous browser flow.
 *   • Webhook (payment.captured / order.paid) — async, signed with the WEBHOOK
 *     secret, so a payment is captured even if the payer closes the browser.
 *   • Manual "mark paid (test)" — keeps the flow verifiable without real money.
 *
 * Plus: branded GST tax-invoice PDF (emailed to the tenant), scheduled
 * auto-renewal invoicing (command billing:renew), and live-mode hardening
 * (gateway secrets encrypted at rest; env fallback for live keys).
 *
 * GST is 18%. All request endpoints fail soft (JSON {error}). The webhook
 * returns 2xx/4xx text so Razorpay can retry correctly.
 */
class BillingController extends Controller
{
    public const GST = 18.0;   // % (public rev 112: CouponService recomputes tax after discount)

    private function guard(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    private static function cycleMonths(?string $cycle): int
    {
        // 'monthly' kept only for legacy rows — new subscriptions are minimum
        // quarterly (3 months advance, per the published pricing).
        return ['monthly' => 1, 'quarterly' => 3, 'halfyear' => 6, 'annual' => 12][$cycle ?? 'quarterly'] ?? 3;
    }

    /** Advance-payment discount per the published pricing (6 mo 10%, 12 mo 25%). */
    private static function cycleDiscount(?string $cycle): float
    {
        return ['halfyear' => 0.10, 'annual' => 0.25][$cycle ?? ''] ?? 0.0;
    }

    /** Flat add-on per ADDITIONAL company (every plan includes 1). ₹/month. */
    public const COMPANY_FEE = 1000.0;

    /**
     * rev 187 (Ejaz): Indian FINANCIAL-year label (Apr–Mar) for a date —
     * Jul 2026 → '2026-27', Feb 2027 → '2026-27', Apr 2027 → '2027-28'.
     */
    public static function finYear($date = null): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $y = $d->month >= 4 ? $d->year : $d->year - 1;

        return $y.'-'.substr((string) ($y + 1), -2);
    }

    /**
     * rev 187 (Ejaz): STANDING invoice-number format —
     *     PRS-<financial year>-<month>-<count>   e.g. PRS-2026-27-07-0001
     * The count runs CONSECUTIVELY through the financial year (GST style,
     * resets on 1 April) and is ONE shared series across SaaS subscription
     * invoices (`invoices.number`) AND on-prem licence invoices
     * (`onprem_clients.invoice_no`). MAX(existing)+1 — deletions can never
     * cause a duplicate (invoices.number is UNIQUE).
     */
    public static function nextInvoiceNumber(): string
    {
        $prefix = 'PRS-'.self::finYear().'-';
        $max = 0;
        foreach ([['invoices', 'number'], ['onprem_clients', 'invoice_no']] as [$table, $col]) {
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (DB::table($table)->where($col, 'like', $prefix.'%')->pluck($col) as $n) {
                    if (preg_match('/-(\d+)$/', (string) $n, $m)) {
                        $max = max($max, (int) $m[1]);
                    }
                }
            } catch (\Throwable $e) {
                // fail-soft: a missing table never blocks invoicing
            }
        }

        return $prefix.now()->format('m').'-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Full price breakdown for a plan + headcount + cycle (+ companies). The
     * single source of pricing truth — used by subscriptions here AND the
     * public self-serve signup (SignupController) AND tenant self-renewal
     * (TenantBillingController).
     *
     * Pricing model (Jun 2026, multi-company rev 76): the plan's base price
     * INCLUDES up to seat_max employees AND 1 company. Employees beyond
     * seat_max are charged per_user_price each. Each ADDITIONAL company is a
     * flat ₹1,000/month on every plan. The employee limit is per TENANT —
     * companies never change it (25 subscribed = 25 across ALL companies).
     * The advance-payment discount applies to the WHOLE period (incl. the
     * company fee). Plans without a seat_max keep the legacy base +
     * per-user × all-seats behaviour.
     */
    public static function priceFor($plan, int $seats, string $cycle, int $companies = 1): array
    {
        $included = (int) ($plan->seat_max ?? 0);
        $extra = $included > 0 ? max(0, $seats - $included) : $seats;
        $months = self::cycleMonths($cycle);
        $discount = self::cycleDiscount($cycle);
        $companies = max(1, $companies);
        $companyFee = self::COMPANY_FEE * ($companies - 1);
        $perMonth = (float) ($plan->base_price ?? 0) + (float) ($plan->per_user_price ?? 0) * $extra + $companyFee;
        $amount = round($perMonth * $months * (1 - $discount), 2);
        $tax = round($amount * self::GST / 100, 2);

        return [
            'amount' => $amount, 'tax' => $tax, 'total' => round($amount + $tax, 2),
            'months' => $months, 'discount' => $discount, 'extra' => $extra, 'per_month' => $perMonth,
            'companies' => $companies, 'company_fee' => $companyFee,
        ];
    }

    /** Compute a subscription's period amount from its plan + seats + cycle + companies. */
    private function computeAmount($plan, int $seats, string $cycle, int $companies = 1): float
    {
        return $plan ? self::priceFor($plan, $seats, $cycle, $companies)['amount'] : 0.0;
    }

    // ---- Schema self-heal (project convention) -----------------------------

    /** Add hardening columns that aren't in the original migration. */
    private static function ensureCols(): void
    {
        try {
            if (Schema::hasTable('payment_gateways') && ! Schema::hasColumn('payment_gateways', 'webhook_secret')) {
                Schema::table('payment_gateways', fn (Blueprint $t) => $t->text('webhook_secret')->nullable());
            }
            // Multi-company billing (rev 76): companies included in the subscription.
            if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'companies')) {
                Schema::table('subscriptions', fn (Blueprint $t) => $t->integer('companies')->default(1));
            }
            if (Schema::hasTable('invoices')) {
                if (! Schema::hasColumn('invoices', 'gateway_order_id')) {
                    Schema::table('invoices', fn (Blueprint $t) => $t->string('gateway_order_id')->nullable()->index());
                }
                if (! Schema::hasColumn('invoices', 'paid_on')) {
                    Schema::table('invoices', fn (Blueprint $t) => $t->date('paid_on')->nullable());
                }
                if (! Schema::hasColumn('invoices', 'emailed_at')) {
                    Schema::table('invoices', fn (Blueprint $t) => $t->timestamp('emailed_at')->nullable());
                }
            }
        } catch (\Throwable $e) {
            // non-fatal — fall back to whatever columns exist
        }
    }

    // ---- Subscriptions -----------------------------------------------------

    public function subscriptions(Request $request)
    {
        $this->guard($request);
        try {
            $plans = DB::table('plans')->get(['id', 'name', 'base_price', 'per_user_price', 'billing_cycle']);
            $planById = $plans->keyBy('id');
            $subs = DB::table('subscriptions')->get()->keyBy('tenant_id');

            $rows = DB::table('tenants')->whereNull('deleted_at')->orderBy('name')->get()
                ->map(function ($t) use ($subs, $planById) {
                    $s = $subs->get($t->id);
                    $plan = $s && $s->plan_id ? $planById->get($s->plan_id) : ($t->plan_id ? $planById->get($t->plan_id) : null);

                    // Lifecycle state (rev 75) for the super admin's expiry column.
                    $daysLeft = null;
                    $lifecycle = '';
                    if ($s && ! empty($s->current_period_end)) {
                        $daysLeft = (int) round(now()->startOfDay()->diffInDays(Carbon::parse($s->current_period_end)->startOfDay(), false));
                        $lifecycle = \App\Services\SubscriptionService::state($t->id)['state'];
                    }

                    return [
                        'tenantId' => $t->id,
                        'tenant' => $t->name,
                        'planId' => $s->plan_id ?? $t->plan_id,
                        'plan' => $plan->name ?? '—',
                        'seats' => (int) ($s->seats ?? $t->seats_licensed),
                        'companies' => (int) ($s->companies ?? 1),
                        'cycle' => $s->cycle ?? ($plan->billing_cycle ?? 'monthly'),
                        'amount' => (float) ($s->amount ?? 0),
                        'status' => $s->status ?? 'none',
                        'renewal' => ! empty($s->next_renewal) ? Carbon::parse($s->next_renewal)->format('d M Y') : '',
                        'daysLeft' => $daysLeft,
                        'lifecycle' => $lifecycle,   // active | grace | locked | ''
                    ];
                })->values();

            // Expiry-alert log (rev 75): what billing:alerts sent, to whom, when.
            $alerts = [];
            try {
                \App\Services\SubscriptionService::ensureAlertTable();
                $tenantNames = DB::table('tenants')->pluck('name', 'id');
                $alerts = DB::table('subscription_alerts')->orderByDesc('id')->limit(60)->get()
                    ->map(fn ($a) => [
                        'tenant' => $tenantNames[$a->tenant_id] ?? ('#'.$a->tenant_id),
                        'kind' => $a->kind, 'channel' => $a->channel, 'status' => $a->status,
                        'detail' => $a->detail ?: '', 'periodEnd' => $a->period_end ?: '',
                        'at' => Carbon::parse($a->created_at)->format('d M Y H:i'),
                    ])->values();
            } catch (\Throwable $e) {
            }

            return response()->json([
                'rows' => $rows,
                'plans' => $plans->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
                'alerts' => $alerts,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    public function saveSubscription(Request $request)
    {
        $this->guard($request);
        try {
            self::ensureCols();
            $v = $request->validate([
                'tenant_id' => ['required', 'integer'],
                'plan_id' => ['required', 'integer'],
                'seats' => ['required', 'integer', 'min:0'],
                'companies' => ['nullable', 'integer', 'min:1', 'max:100'],
                'cycle' => ['required', 'in:quarterly,halfyear,annual'],
                'status' => ['nullable', 'in:trialing,active,suspended,cancelled'],
            ]);
            $plan = DB::table('plans')->where('id', $v['plan_id'])->first();
            if (! $plan) {
                return response()->json(['ok' => false, 'error' => 'Plan not found'], 422);
            }
            $companies = max(1, (int) ($v['companies'] ?? 1));
            $amount = $this->computeAmount($plan, $v['seats'], $v['cycle'], $companies);
            $end = Carbon::now()->addMonths($this->cycleMonths($v['cycle']));
            $row = ApprovalService::safeRow('subscriptions', [
                'tenant_id' => $v['tenant_id'], 'plan_id' => $v['plan_id'], 'seats' => $v['seats'],
                'companies' => $companies,
                'cycle' => $v['cycle'], 'amount' => $amount, 'status' => $v['status'] ?? 'active',
                'current_period_end' => $end->toDateString(), 'next_renewal' => $end->toDateString(),
                'updated_at' => now(),
            ]);
            $existing = DB::table('subscriptions')->where('tenant_id', $v['tenant_id'])->first();
            if ($existing) {
                DB::table('subscriptions')->where('id', $existing->id)->update($row);
            } else {
                $row['created_at'] = now();
                DB::table('subscriptions')->insert($row);
            }
            // Keep the tenant's plan + MRR in sync.
            DB::table('tenants')->where('id', $v['tenant_id'])->update([
                'plan_id' => $v['plan_id'], 'seats_licensed' => $v['seats'],
                'mrr' => round($amount / $this->cycleMonths($v['cycle']), 2), 'updated_at' => now(),
            ]);

            return response()->json(['ok' => true, 'amount' => $amount]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Invoices ----------------------------------------------------------

    public function invoices(Request $request)
    {
        $this->guard($request);
        try {
            $tenants = DB::table('tenants')->pluck('name', 'id');
            $rows = DB::table('invoices')->orderByDesc('id')->limit(500)->get()->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'tenant' => $tenants[$i->tenant_id] ?? '—',
                'amount' => (float) $i->amount, 'tax' => (float) $i->tax, 'total' => (float) $i->amount + (float) $i->tax,
                'status' => $i->status, 'issued' => $i->issued_on, 'due' => $i->due_on, 'gateway' => $i->gateway ?: '',
            ])->values();

            $tenantList = DB::table('tenants')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values();

            return response()->json(['rows' => $rows, 'tenants' => $tenantList]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Shared invoice creation from a tenant's current subscription. Used by the
     * manual "Generate invoice" button AND the scheduled renewal command.
     * Returns the invoice row (stdClass) or throws \RuntimeException.
     */
    public static function createInvoiceForTenant(int $tenantId): \stdClass
    {
        self::ensureCols();
        $sub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
        if (! $sub || (float) $sub->amount <= 0) {
            throw new \RuntimeException('No priced subscription for this tenant — set the subscription first.');
        }
        $amount = (float) $sub->amount;
        $tax = round($amount * self::GST / 100, 2);
        // rev 187 (Ejaz): PRS-<FY>-<MM>-<count> — consecutive through the FY.
        $number = self::nextInvoiceNumber();
        $id = DB::table('invoices')->insertGetId(ApprovalService::safeRow('invoices', [
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'number' => $number,
            'amount' => $amount, 'tax' => $tax, 'status' => 'due',
            'issued_on' => now()->toDateString(), 'due_on' => now()->addDays(7)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return DB::table('invoices')->where('id', $id)->first();
    }

    public function generateInvoice(Request $request)
    {
        $this->guard($request);
        try {
            $v = $request->validate(['tenant_id' => ['required', 'integer']]);
            $inv = self::createInvoiceForTenant((int) $v['tenant_id']);
            // Email the invoice to the tenant (best-effort).
            $this->emailInvoice($inv->id, 'invoice.issued');

            return response()->json([
                'ok' => true, 'number' => $inv->number, 'id' => $inv->id,
                'total' => (float) $inv->amount + (float) $inv->tax,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Manual / test mark-paid (records a payment + flips the invoice to paid). */
    public function payInvoice(Request $request)
    {
        $this->guard($request);
        try {
            $v = $request->validate([
                'invoice_id' => ['required', 'integer'],
                'method' => ['nullable', 'string', 'max:40'],
            ]);
            $inv = DB::table('invoices')->where('id', $v['invoice_id'])->first();
            if (! $inv) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found'], 404);
            }
            $this->recordPayment($inv, 'manual', $v['method'] ?? 'manual', 'TEST-'.strtoupper(Str::random(10)));
            $this->emailInvoice($inv->id, 'invoice.receipt');

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Shared: write a success payment + mark the invoice paid. Idempotent on the
     * gateway transaction id (a webhook + a verify call for the same payment will
     * not double-record). Returns true if a NEW payment was recorded.
     */
    public static function recordPayment($inv, string $gateway, string $method, ?string $txn): bool
    {
        if ($txn) {
            $already = DB::table('payments')->where('gateway_txn_id', $txn)->where('status', 'success')->exists();
            if ($already) {
                return false;
            }
        }
        DB::table('payments')->insert(ApprovalService::safeRow('payments', [
            'uuid' => (string) Str::uuid(), 'tenant_id' => $inv->tenant_id, 'invoice_id' => $inv->id,
            'amount' => (float) $inv->amount + (float) $inv->tax, 'gateway' => $gateway, 'method' => $method,
            'gateway_txn_id' => $txn, 'status' => 'success', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', [
            'status' => 'paid', 'gateway' => $gateway, 'paid_on' => now()->toDateString(), 'updated_at' => now(),
        ]));

        return true;
    }

    /** rev 186: total successfully received against an invoice (all gateways). */
    public static function invoicePaidSum(int $invoiceId): float
    {
        return (float) DB::table('payments')->where('invoice_id', $invoiceId)
            ->where('status', 'success')->sum('amount');
    }

    /**
     * rev 186 (Ejaz): PARTIAL-aware payment recording — for credit-period
     * clients whose money arrives offline (bank transfer / UPI / cheque / cash)
     * or in instalments. Writes a success payment of the GIVEN amount and flips
     * the invoice to 'paid' only when the received total covers amount+tax
     * (the invoices.status ENUM has no 'partial' value — partial state is
     * always COMPUTED from the payments sum, never stored as a status).
     * Idempotent on the txn reference. Returns
     * ['recorded','paid','total','balance','settled'].
     */
    public static function applyPayment($inv, float $amount, string $gateway, string $method, ?string $txn): array
    {
        $total = round((float) $inv->amount + (float) $inv->tax, 2);
        if ($txn) {
            $already = DB::table('payments')->where('gateway_txn_id', $txn)->where('status', 'success')->exists();
            if ($already) {
                $paid = self::invoicePaidSum((int) $inv->id);

                return ['recorded' => false, 'paid' => $paid, 'total' => $total,
                    'balance' => max(0, round($total - $paid, 2)), 'settled' => $paid >= $total - 0.01];
            }
        }
        DB::table('payments')->insert(ApprovalService::safeRow('payments', [
            'uuid' => (string) Str::uuid(), 'tenant_id' => $inv->tenant_id, 'invoice_id' => $inv->id,
            'amount' => round($amount, 2), 'gateway' => $gateway, 'method' => $method,
            'gateway_txn_id' => $txn, 'status' => 'success', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $paid = self::invoicePaidSum((int) $inv->id);
        $settled = $paid >= $total - 0.01;
        if ($settled) {
            DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', [
                'status' => 'paid', 'gateway' => $gateway, 'paid_on' => now()->toDateString(), 'updated_at' => now(),
            ]));
        } else {
            // Balance still open — status stays 'due'; the screens show the balance.
            DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', [
                'gateway' => $gateway, 'updated_at' => now(),
            ]));
        }

        return ['recorded' => true, 'paid' => $paid, 'total' => $total,
            'balance' => max(0, round($total - $paid, 2)), 'settled' => $settled];
    }

    // ---- Payments ----------------------------------------------------------

    public function payments(Request $request)
    {
        $this->guard($request);
        try {
            $tenants = DB::table('tenants')->pluck('name', 'id');
            $invNo = DB::table('invoices')->pluck('number', 'id');
            $rows = DB::table('payments')->orderByDesc('id')->limit(500)->get()->map(fn ($p) => [
                'id' => $p->id, 'tenant' => $tenants[$p->tenant_id] ?? '—', 'invoice' => $invNo[$p->invoice_id] ?? '—',
                'amount' => (float) $p->amount, 'gateway' => $p->gateway ?: '', 'method' => $p->method ?: '',
                'txn' => $p->gateway_txn_id ?: '', 'status' => $p->status,
                'paidAt' => ! empty($p->paid_at) ? Carbon::parse($p->paid_at)->format('d M Y H:i') : '',
            ])->values();

            return response()->json(['rows' => $rows]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    // ---- Gateways ----------------------------------------------------------

    public function gateways(Request $request)
    {
        $this->guard($request);
        try {
            self::ensureCols();
            $g = DB::table('payment_gateways')->where('gateway', 'razorpay')->first();
            $mode = $g->mode ?? 'test';
            $keyId = $g->key_id ?? '';
            // Live readiness: live mode wants a live key id + a secret + a webhook secret.
            $liveReady = $mode === 'live'
                && str_starts_with((string) $keyId, 'rzp_live_')
                && ! empty($g->secret)
                && ! empty($g->webhook_secret ?? null);

            return response()->json(['gateway' => [
                'gateway' => 'razorpay',
                'mode' => $mode,
                'key_id' => $keyId,
                'hasSecret' => ! empty($g->secret),
                'hasWebhookSecret' => ! empty($g->webhook_secret ?? null),
                'status' => $g->status ?? 'active',
                'webhookUrl' => url('/webhooks/razorpay'),
                'liveReady' => $liveReady,
                'liveWarning' => $mode === 'live' && ! $liveReady
                    ? 'Live mode needs a live Key ID (rzp_live_…), a saved Secret, and a Webhook Secret.'
                    : '',
            ]]);
        } catch (\Throwable $e) {
            return response()->json(['gateway' => null, 'error' => $e->getMessage()]);
        }
    }

    public function saveGateway(Request $request)
    {
        $this->guard($request);
        try {
            self::ensureCols();
            $v = $request->validate([
                'mode' => ['required', 'in:test,live'],
                'key_id' => ['nullable', 'string', 'max:191'],
                'secret' => ['nullable', 'string', 'max:191'],
                'webhook_secret' => ['nullable', 'string', 'max:191'],
                'status' => ['nullable', 'in:active,inactive'],
            ]);
            $existing = DB::table('payment_gateways')->where('gateway', 'razorpay')->first();
            $row = ['gateway' => 'razorpay', 'mode' => $v['mode'], 'key_id' => $v['key_id'] ?? null,
                'status' => $v['status'] ?? 'active', 'updated_at' => now()];
            // Secrets are ENCRYPTED at rest. Blank = keep the stored value.
            if (! empty($v['secret'])) {
                $row['secret'] = Crypt::encryptString($v['secret']);
            }
            if (! empty($v['webhook_secret'])) {
                $row['webhook_secret'] = Crypt::encryptString($v['webhook_secret']);
            }
            if ($existing) {
                DB::table('payment_gateways')->where('id', $existing->id)->update(ApprovalService::safeRow('payment_gateways', $row));
            } else {
                $row['created_at'] = now();
                DB::table('payment_gateways')->insert(ApprovalService::safeRow('payment_gateways', $row));
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Razorpay ----------------------------------------------------------

    /** Decrypt a stored secret, tolerating legacy plaintext values. */
    private static function decryptOrRaw(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable $e) {
            return $v;   // legacy plaintext (pre-encryption) — still usable
        }
    }

    public static function razorpayCreds(): ?array
    {
        $g = DB::table('payment_gateways')->where('gateway', 'razorpay')->where('status', 'active')->first();
        if ($g && $g->key_id && $g->secret) {
            return ['key' => $g->key_id, 'secret' => self::decryptOrRaw($g->secret)];
        }
        // Fallback to env (RAZORPAY_KEY / RAZORPAY_SECRET) — handy for live deploys.
        if (env('RAZORPAY_KEY') && env('RAZORPAY_SECRET')) {
            return ['key' => env('RAZORPAY_KEY'), 'secret' => env('RAZORPAY_SECRET')];
        }

        return null;
    }

    /** The webhook signing secret (Razorpay Dashboard → Webhooks). */
    private function webhookSecret(): ?string
    {
        self::ensureCols();
        $g = DB::table('payment_gateways')->where('gateway', 'razorpay')->first();
        $s = $g ? self::decryptOrRaw($g->webhook_secret ?? null) : null;

        return $s ?: (env('RAZORPAY_WEBHOOK_SECRET') ?: null);
    }

    /** Create a Razorpay order for an invoice (returns order_id + key for checkout.js). */
    public function razorpayOrder(Request $request)
    {
        $this->guard($request);
        try {
            self::ensureCols();
            $v = $request->validate(['invoice_id' => ['required', 'integer']]);
            $inv = DB::table('invoices')->where('id', $v['invoice_id'])->first();
            if (! $inv) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found'], 404);
            }
            $creds = $this->razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Razorpay keys not set. Add keys in Billing → Payment Gateways (or use “Mark paid (test)”).'], 422);
            }
            $amountPaise = (int) round(((float) $inv->amount + (float) $inv->tax) * 100);
            $resp = Http::withBasicAuth($creds['key'], $creds['secret'])
                ->asForm()->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $amountPaise, 'currency' => 'INR', 'receipt' => $inv->number,
                    'notes' => ['invoice' => $inv->number, 'invoice_id' => (string) $inv->id],
                ]);
            if (! $resp->successful()) {
                return response()->json(['ok' => false, 'error' => 'Razorpay order failed: '.$resp->body()], 422);
            }
            $order = $resp->json();
            $orderId = $order['id'] ?? null;
            // Remember the order id so the webhook can match the payment → invoice.
            if ($orderId) {
                DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', [
                    'gateway_order_id' => $orderId, 'updated_at' => now(),
                ]));
            }

            return response()->json([
                'ok' => true, 'orderId' => $orderId, 'amount' => $amountPaise,
                'keyId' => $creds['key'], 'number' => $inv->number,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Verify a Razorpay checkout signature and mark the invoice paid. */
    public function razorpayVerify(Request $request)
    {
        $this->guard($request);
        try {
            $v = $request->validate([
                'invoice_id' => ['required', 'integer'],
                'razorpay_order_id' => ['required', 'string'],
                'razorpay_payment_id' => ['required', 'string'],
                'razorpay_signature' => ['required', 'string'],
            ]);
            $inv = DB::table('invoices')->where('id', $v['invoice_id'])->first();
            if (! $inv) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found'], 404);
            }
            $creds = $this->razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Razorpay keys not set.'], 422);
            }
            $expected = hash_hmac('sha256', $v['razorpay_order_id'].'|'.$v['razorpay_payment_id'], $creds['secret']);
            if (! hash_equals($expected, $v['razorpay_signature'])) {
                return response()->json(['ok' => false, 'error' => 'Signature verification failed.'], 422);
            }
            $new = $this->recordPayment($inv, 'razorpay', 'razorpay', $v['razorpay_payment_id']);
            if ($new) {
                $this->emailInvoice($inv->id, 'invoice.receipt');
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Razorpay WEBHOOK (public, CSRF-exempt). Verifies the X-Razorpay-Signature
     * over the raw body using the webhook secret, then on payment.captured /
     * order.paid records the payment idempotently and emails the receipt.
     * Returns plain text + a status code so Razorpay retries only on real errors.
     */
    public function razorpayWebhook(Request $request)
    {
        $raw = $request->getContent();
        $sig = $request->header('X-Razorpay-Signature');
        $secret = $this->webhookSecret();

        if (! $secret) {
            Log::warning('Razorpay webhook hit but no webhook secret configured.');

            return response('Webhook secret not configured', 503)->header('Content-Type', 'text/plain');
        }
        if (! $sig || ! hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
            return response('Invalid signature', 400)->header('Content-Type', 'text/plain');
        }

        try {
            $data = json_decode($raw, true) ?: [];
            $event = $data['event'] ?? '';
            if (! in_array($event, ['payment.captured', 'order.paid'], true)) {
                return response('Ignored event: '.$event, 200)->header('Content-Type', 'text/plain');
            }
            $pe = $data['payload']['payment']['entity'] ?? [];
            $orderId = $pe['order_id'] ?? ($data['payload']['order']['entity']['id'] ?? null);
            $paymentId = $pe['id'] ?? null;
            $notesInvId = $pe['notes']['invoice_id'] ?? null;

            $inv = null;
            if ($orderId) {
                $inv = DB::table('invoices')->where('gateway_order_id', $orderId)->first();
            }
            if (! $inv && $notesInvId) {
                $inv = DB::table('invoices')->where('id', (int) $notesInvId)->first();
            }
            if (! $inv) {
                // RENEWAL RECOVERY (rev 75): a self-serve renewal whose browser
                // closed after paying never reached /renew/complete — the order
                // lives in `renewals` (pending). Apply it here so the money and
                // the subscription never diverge. Idempotent (status=done +
                // recordPayment's txn check).
                if ($orderId && Schema::hasTable('renewals')) {
                    $ren = DB::table('renewals')->where('gateway_order_id', $orderId)->first();
                    if ($ren && $ren->status !== 'done') {
                        $end = TenantBillingController::applyRenewal($ren, $orderId, (string) $paymentId);
                        Log::info('Razorpay webhook: applied pending renewal #'.$ren->id.' (tenant '.$ren->tenant_id.') to '.$end->toDateString());

                        return response('Renewal applied', 200)->header('Content-Type', 'text/plain');
                    }
                    if ($ren) {
                        return response('Renewal already applied', 200)->header('Content-Type', 'text/plain');
                    }
                }
                // Acknowledge so Razorpay stops retrying an order we don't track.
                Log::info('Razorpay webhook: no matching invoice for order '.($orderId ?? '?'));

                return response('No matching invoice', 200)->header('Content-Type', 'text/plain');
            }

            $new = $this->recordPayment($inv, 'razorpay', 'razorpay-webhook', $paymentId);
            if ($new) {
                $this->emailInvoice($inv->id, 'invoice.receipt');
            }

            return response('OK', 200)->header('Content-Type', 'text/plain');
        } catch (\Throwable $e) {
            Log::error('Razorpay webhook processing failed: '.$e->getMessage());

            // 500 → Razorpay will retry, which is what we want on a transient error.
            return response('Processing error', 500)->header('Content-Type', 'text/plain');
        }
    }

    // ---- Invoice PDF + email ----------------------------------------------

    /** rev 96: public accessor so the quotation PDF can reuse the seller block. */
    public function publicSellerProfile(): array
    {
        return $this->sellerProfile();
    }

    /** Platform seller profile for the tax invoice (env overrides; rev 89:
     *  real Ametecs registered details as code defaults — Ejaz 6 Jun 2026 —
     *  so the GST invoice is compliant even before the VPS .env is filled). */
    private function sellerProfile(): array
    {
        return [
            'name' => env('BILLING_SELLER_NAME', 'Ametecs India Private Limited'),
            'gstin' => env('BILLING_SELLER_GSTIN', '36AAHCT0971F1ZB'),
            'address' => env('BILLING_SELLER_ADDRESS', 'Modern Profound Techpark, Ground Floor, Hive Space, opp. Google, Whitefields, Kondapur, Hyderabad, Telangana, India 500084'),
            'email' => env('BILLING_SELLER_EMAIL', env('MAIL_FROM_ADDRESS', 'sales@ametecsindia.com')),
            'phone' => env('BILLING_SELLER_PHONE', '+91 96666 12424'),
            'state' => env('BILLING_SELLER_STATE', 'Telangana (36)'),
            'sac' => env('BILLING_SAC_CODE', '998314'),   // IT software services (SAC)
            // 'intra' → CGST+SGST (same state); else IGST. Default inter-state (IGST).
            'gstMode' => env('BILLING_GST_MODE', 'inter'),
        ];
    }

    /**
     * Build the GST tax-invoice PDF for an invoice. Returns
     * ['pdf','inv','tenant','to','file'] or null. PUBLIC so the tenant-side
     * TenantBillingController can stream a tenant's OWN invoice (it enforces
     * ownership before calling).
     */
    public function buildInvoicePdf(int $invoiceId): ?array
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) {
            return null;
        }
        $tenant = DB::table('tenants')->where('id', $inv->tenant_id)->first();
        $sub = DB::table('subscriptions')->where('tenant_id', $inv->tenant_id)->first();
        $plan = $sub && $sub->plan_id ? DB::table('plans')->where('id', $sub->plan_id)->first() : null;

        $seller = $this->sellerProfile();
        $amount = (float) $inv->amount;
        $tax = (float) $inv->tax;
        // rev 90 (Ejaz): tax split decided PER CUSTOMER from their GSTIN/state —
        // Telangana buyer (same state as Ametecs) → CGST+SGST, else IGST.
        $intra = self::buyerIsIntraState($tenant, $seller);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice-pdf', [
            'inv' => $inv,
            'seller' => $seller,
            'tenant' => $tenant,
            'plan' => $plan,
            'sub' => $sub,
            'amount' => $amount,
            'tax' => $tax,
            'total' => $amount + $tax,
            'gstRate' => self::GST,
            'intra' => $intra,         // CGST+SGST vs IGST
            'cgst' => round($tax / 2, 2),
            'sgst' => round($tax / 2, 2),
            'igst' => $tax,
        ]);

        return [
            'pdf' => $pdf,
            'inv' => $inv,
            'tenant' => $tenant,
            'to' => $tenant->owner_email ?? null,
            'file' => $inv->number.'.pdf',
        ];
    }

    /**
     * rev 90: is this buyer in the SAME state as the seller (→ CGST+SGST)?
     * Priority: buyer GSTIN's first-2-digit state code → saved state name →
     * env BILLING_GST_MODE fallback (default inter/IGST). Seller code comes
     * from the "(NN)" in BILLING_SELLER_STATE, default 36 = Telangana.
     */
    public static function buyerIsIntraState($tenant, ?array $seller = null): bool
    {
        try {
            $sellerState = (string) ($seller['state'] ?? env('BILLING_SELLER_STATE', 'Telangana (36)'));
            $sellerCode = preg_match('/(\d{2})/', $sellerState, $m) ? $m[1] : '36';
            $gstin = strtoupper(trim((string) ($tenant->gstin ?? '')));
            if (preg_match('/^(\d{2})/', $gstin, $g)) {
                return $g[1] === $sellerCode;
            }
            $state = strtolower(trim((string) ($tenant->state ?? '')));
            if ($state !== '') {
                $sellerName = strtolower(preg_replace('/[^a-z ]/i', '', $sellerState));

                return trim($sellerName) !== '' && str_contains($state, trim(explode(' ', trim($sellerName))[0]));
            }
        } catch (\Throwable $e) {
            // fall through to the env default
        }

        return env('BILLING_GST_MODE', 'inter') === 'intra';
    }

    /** Stream the invoice PDF inline (super-admin preview / download). */
    public function invoicePdf(Request $request, int $id)
    {
        $this->guard($request);
        $b = $this->buildInvoicePdf($id);
        if (! $b) {
            return response('Invoice not found.', 404)->header('Content-Type', 'text/plain');
        }

        return $b['pdf']->stream($b['file']);
    }

    /** Manual "email invoice" button (super-admin). */
    public function emailInvoiceNow(Request $request)
    {
        $this->guard($request);
        try {
            $v = $request->validate(['invoice_id' => ['required', 'integer']]);
            $inv = DB::table('invoices')->where('id', $v['invoice_id'])->first();
            $kind = $inv && $inv->status === 'paid' ? 'invoice.receipt' : 'invoice.issued';
            $res = $this->emailInvoice((int) $v['invoice_id'], $kind);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => $res['error']], 422);
            }

            return response()->json(['ok' => true, 'message' => 'Invoice emailed to '.$res['to']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Email the invoice PDF to the tenant via the PLATFORM SMTP (the super-admin
     * tenant default, stored under tenant 0). Records the attempt in mail_log.
     * Fail-soft: returns ['ok'=>bool,'error'?,'to'?] and never throws.
     */
    public function emailInvoice(int $invoiceId, string $kind = 'invoice.issued'): array
    {
        try {
            $b = $this->buildInvoicePdf($invoiceId);
            if (! $b) {
                return ['ok' => false, 'error' => 'Invoice not found'];
            }
            if (empty($b['to'])) {
                return ['ok' => false, 'error' => 'Tenant has no billing email (set Owner email on the tenant).'];
            }
            // Platform SMTP = the tenant-wide default (company '0') under tenant 0.
            $m = ConfigController::mailConfigFor(null, '0');
            if (empty($m['host']) || empty($m['from_address'])) {
                $this->logMail($b, $kind, 'skipped', 'No platform SMTP configured (Settings → Email/SMTP → tenant default).');

                return ['ok' => false, 'error' => 'No platform SMTP configured. Set the tenant-default mail server in Settings → Email/SMTP.'];
            }
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $m['host'],
                'mail.mailers.smtp.port' => (int) $m['port'],
                'mail.mailers.smtp.username' => $m['username'] ?: null,
                'mail.mailers.smtp.password' => $m['password'] ?: null,
                'mail.mailers.smtp.encryption' => $m['encryption'] === 'none' ? null : $m['encryption'],
                'mail.from.address' => $m['from_address'],
                'mail.from.name' => $m['from_name'] ?: 'SmartPRS Billing',
            ]);

            $inv = $b['inv'];
            $tenantName = $b['tenant']->name ?? 'Customer';
            $total = number_format((float) $inv->amount + (float) $inv->tax, 2);
            $paid = $inv->status === 'paid';
            $subject = ($paid ? 'Payment receipt — ' : 'Invoice ').$inv->number;
            $intro = $paid
                ? 'Thank you — we have received your payment of ₹'.$total.'. Your tax invoice is attached.'
                : 'Please find attached your tax invoice '.$inv->number.' for ₹'.$total.', due by '
                    .($inv->due_on ? Carbon::parse($inv->due_on)->format('d M Y') : 'the due date').'.';
            $pdfData = $b['pdf']->output();

            // rev 170 (Ejaz): invoices/receipts now use the BRANDED email template
            // (same as the welcome mail) with the full Ametecs identity footer —
            // no more plain "thank you" mails.
            $html = view('emails.generic', [
                'brand' => [
                    'display_name' => 'SmartPRS by Ametecs',
                    'color' => '#f97316',
                    'logo' => url('/images/logo.png'),
                    'tagline' => '',
                ],
                'platform' => true,
                'heading' => $paid ? 'Payment received — thank you!' : 'Your SmartPRS invoice',
                'toName' => $tenantName,
                'intro' => $intro,
                'lines' => [
                    'Invoice' => $inv->number,
                    'Amount (incl. GST)' => '₹'.$total,
                    'Status' => $paid ? 'PAID' : ('Due by '.($inv->due_on ? Carbon::parse($inv->due_on)->format('d M Y') : '—')),
                ],
                'bodyText' => 'Your GST tax invoice is attached as a PDF for your records.',
                'ctaLabel' => 'Open your workspace',
                'ctaUrl' => url('/login'),
            ])->render();

            Mail::send([], [], function ($mail) use ($b, $pdfData, $subject, $tenantName, $html) {
                $mail->to($b['to'], $tenantName)->subject($subject)
                    ->html($html)
                    ->attachData($pdfData, $b['file'], ['mime' => 'application/pdf']);
            });

            DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', [
                'emailed_at' => now(), 'updated_at' => now(),
            ]));
            $this->logMail($b, $kind, 'sent', null);

            return ['ok' => true, 'to' => $b['to']];
        } catch (\Throwable $e) {
            Log::warning('emailInvoice failed: '.$e->getMessage());
            try {
                if (isset($b) && $b) {
                    $this->logMail($b, $kind, 'failed', $e->getMessage());
                }
            } catch (\Throwable $x) {
            }

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Record a billing email attempt in mail_log (audit visibility). */
    private function logMail(array $b, string $kind, string $status, ?string $error): void
    {
        try {
            \App\Services\MailService::ensureTable();
            DB::table('mail_log')->insert([
                'tenant_id' => $b['inv']->tenant_id ?? null,
                'company_id' => null,
                'kind' => $kind,
                'recipient' => $b['to'] ?? null,
                'subject' => ($b['inv']->status ?? '') === 'paid' ? 'Payment receipt — '.($b['inv']->number ?? '') : 'Invoice '.($b['inv']->number ?? ''),
                'status' => $status,
                'error' => $error ? mb_substr($error, 0, 1000) : null,
                'sent_at' => $status === 'sent' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // logging must never throw
        }
    }

    // ---- Scheduled auto-renewal (called by the billing:renew command) ------

    /**
     * Generate renewal invoices for active subscriptions due on/before today,
     * advance their period, and email each invoice. Returns a summary array.
     * Safe to run daily; only bills subscriptions whose next_renewal has arrived.
     */
    public static function runRenewals(?\Closure $log = null): array
    {
        self::ensureCols();
        $today = now()->toDateString();
        $due = DB::table('subscriptions')
            ->where('status', 'active')
            ->whereNotNull('next_renewal')
            ->whereDate('next_renewal', '<=', $today)
            ->where('amount', '>', 0)
            ->get();

        $made = 0;
        $emailed = 0;
        $errors = 0;
        $self = new self();
        foreach ($due as $sub) {
            try {
                $inv = self::createInvoiceForTenant((int) $sub->tenant_id);
                $made++;
                // Advance the billing period from the previous renewal date.
                $months = (new self())->cycleMonths($sub->cycle);
                $base = $sub->next_renewal ? Carbon::parse($sub->next_renewal) : now();
                $next = $base->copy()->addMonths($months);
                DB::table('subscriptions')->where('id', $sub->id)->update([
                    'current_period_end' => $next->toDateString(),
                    'next_renewal' => $next->toDateString(),
                    'updated_at' => now(),
                ]);
                $res = $self->emailInvoice($inv->id, 'invoice.issued');
                if ($res['ok']) {
                    $emailed++;
                }
                if ($log) {
                    $log("Tenant {$sub->tenant_id}: invoice {$inv->number} created".($res['ok'] ? ' + emailed' : ' (email: '.($res['error'] ?? 'skipped').')'));
                }
            } catch (\Throwable $e) {
                $errors++;
                if ($log) {
                    $log("Tenant {$sub->tenant_id}: FAILED — ".$e->getMessage());
                }
            }
        }

        return ['due' => $due->count(), 'invoices' => $made, 'emailed' => $emailed, 'errors' => $errors];
    }
}
