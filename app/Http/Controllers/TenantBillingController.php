<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TENANT-side subscription self-service ("My Subscription", under
 * Administration). The platform-side BillingController is super-admin only;
 * this controller is the tenant ADMIN's window onto their own account:
 *
 *   GET  /app/my-subscription                → plan, seats, expiry, invoices
 *   POST /app/my-subscription/quote          → server-priced quote (renew dialog)
 *   POST /app/my-subscription/renew/order    → pending `renewals` row + Razorpay order
 *   POST /app/my-subscription/renew/complete → HMAC verify → extend subscription
 *                                              + PAID invoice + payment + receipt
 *   GET  /app/my-subscription/invoice/{id}/pdf → own tax-invoice PDF only
 *
 * Same safety rails as the public signup: amounts are ALWAYS computed
 * server-side from the plans table (BillingController::priceFor — the single
 * pricing source of truth), the Razorpay signature is verified, and
 * completion is idempotent per renewal row. Renewing before expiry never
 * loses days: the new period starts when the current one ends.
 */
class TenantBillingController extends Controller
{
    /** Tenant admins only (the workspace owner side of billing). */
    private function guard(Request $request): array
    {
        $u = $request->user();
        abort_unless($u && $u->tenant_id, 403, 'This screen is for tenant accounts.');
        abort_unless($u->hasRole('admin') || $u->hasRole('super_admin'), 403, 'Admin only.');

        return [(int) $u->tenant_id, $u];
    }

    /** Self-creating `renewals` table (project convention — no manual migration). */
    private function ensure(): void
    {
        // Self-heal: multi-company billing (rev 76) + upgrade mode (rev 81).
        try {
            if (Schema::hasTable('renewals') && ! Schema::hasColumn('renewals', 'companies')) {
                Schema::table('renewals', fn (Blueprint $t) => $t->integer('companies')->default(1));
            }
            if (Schema::hasTable('renewals') && ! Schema::hasColumn('renewals', 'mode')) {
                Schema::table('renewals', fn (Blueprint $t) => $t->string('mode', 12)->default('renew'));
            }
            // rev 112: discount coupons on renewals.
            if (Schema::hasTable('renewals') && ! Schema::hasColumn('renewals', 'coupon_code')) {
                Schema::table('renewals', function (Blueprint $t) {
                    $t->string('coupon_code', 40)->nullable();
                    $t->decimal('coupon_discount', 12, 2)->default(0);
                });
            }
            if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'companies')) {
                Schema::table('subscriptions', fn (Blueprint $t) => $t->integer('companies')->default(1));
            }
        } catch (\Throwable $e) {
        }
        if (Schema::hasTable('renewals')) {
            return;
        }
        Schema::create('renewals', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->index();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('plan_id');
            $t->integer('seats')->default(0);
            $t->integer('companies')->default(1);
            $t->string('mode', 12)->default('renew');   // renew (extend) | upgrade (pro-rata, period unchanged)
            $t->string('cycle', 20);
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->string('gateway_order_id')->nullable()->index();
            $t->string('status', 20)->default('pending'); // pending | done
            $t->timestamps();
        });
    }

    /** The publicly sold plans (same set as the signup page). */
    private function activePlans()
    {
        return DB::table('plans')->where('status', 'active')
            ->whereIn('name', ['Starter', 'Growth', 'Professional'])
            ->orderBy('base_price')
            ->get(['id', 'name', 'base_price', 'per_user_price', 'seat_max']);
    }

    public function index(Request $request)
    {
        [$tid] = $this->guard($request);
        try {
            $sub = DB::table('subscriptions')->where('tenant_id', $tid)->first();
            $tenant = DB::table('tenants')->where('id', $tid)->first();
            $plan = null;
            $planId = $sub->plan_id ?? $tenant->plan_id ?? null;
            if ($planId) {
                $plan = DB::table('plans')->where('id', $planId)->first();
            }

            $end = ! empty($sub->current_period_end) ? Carbon::parse($sub->current_period_end) : null;
            $daysLeft = $end ? (int) round(now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false)) : null;

            $empCount = 0;
            try {
                $empCount = DB::table('employees')->where('tenant_id', $tid)->whereNull('deleted_at')->count();
            } catch (\Throwable $e) {
                try {
                    $empCount = DB::table('employees')->where('tenant_id', $tid)->count();
                } catch (\Throwable $e2) {
                }
            }

            $invoices = DB::table('invoices')->where('tenant_id', $tid)
                ->orderByDesc('id')->limit(24)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'total' => round((float) $i->amount + (float) $i->tax, 2),
                    'status' => $i->status,
                    'issued' => ! empty($i->issued_on) ? Carbon::parse($i->issued_on)->format('d M Y') : '',
                    'pdf' => url('/app/my-subscription/invoice/'.$i->id.'/pdf'),
                ])->values();

            $current = null;
            if ($plan && $sub) {
                $current = BillingController::priceFor($plan, (int) $sub->seats, (string) $sub->cycle, max(1, (int) ($sub->companies ?? 1)));
            }

            $coUse = \App\Services\SubscriptionService::companyUsage($tid);

            return response()->json([
                'plan' => $plan ? [
                    'id' => $plan->id, 'name' => $plan->name,
                    'base_price' => (float) $plan->base_price,
                    'per_user_price' => (float) $plan->per_user_price,
                    'seat_max' => (int) ($plan->seat_max ?? 0),
                ] : null,
                'sub' => $sub ? [
                    'seats' => (int) $sub->seats,
                    'companies' => max(1, (int) ($sub->companies ?? 1)),
                    'cycle' => $sub->cycle,
                    'amount' => (float) $sub->amount,
                    'status' => $sub->status,
                    'end' => $end ? $end->format('d M Y') : '',
                    'daysLeft' => $daysLeft,
                ] : null,
                'companiesUsed' => $coUse['used'],
                'price' => $current,
                'employees' => $empCount,
                'invoices' => $invoices,
                'plans' => $this->activePlans(),
                'canPay' => (bool) BillingController::razorpayCreds(),
                // rev 113b: coupon input in the renew dialog only when public
                // coupons are live; an exclusive auto-apply reveals itself anyway.
                'couponsEnabled' => \App\Services\CouponService::publicCouponsExist('renewal'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * PRO-RATA mid-term upgrade maths (rev 81, team test #3): the customer
     * already paid the current period; adding employees/companies NOW should
     * cost only the per-month DIFFERENCE × the remaining part of the period
     * (same advance discount as the running cycle). The period end does NOT
     * move. Returns null when the current subscription cannot be priced.
     */
    private function prorataFor(object $sub, object $plan, int $seats, int $companies): ?array
    {
        if (empty($sub->current_period_end)) {
            return null;
        }
        $end = Carbon::parse($sub->current_period_end)->endOfDay();
        $remainingDays = max(1, (int) ceil(now()->floatDiffInDays($end, false)));
        $curPlan = $sub->plan_id ? DB::table('plans')->where('id', $sub->plan_id)->first() : $plan;
        $oldPm = BillingController::priceFor($curPlan ?: $plan, (int) $sub->seats, (string) $sub->cycle, max(1, (int) ($sub->companies ?? 1)))['per_month'];
        $new = BillingController::priceFor($plan, $seats, (string) $sub->cycle, $companies);
        $diffPm = max(0.0, $new['per_month'] - $oldPm);
        $months = $remainingDays / 30.44;
        $amount = round($diffPm * $months * (1 - $new['discount']), 2);
        $tax = round($amount * 18 / 100, 2);

        return [
            'amount' => $amount, 'tax' => $tax, 'total' => round($amount + $tax, 2),
            'months' => 0, 'discount' => $new['discount'],
            'per_month' => $new['per_month'], 'diff_per_month' => round($diffPm, 2),
            'remaining_days' => $remainingDays, 'companies' => $companies,
            'company_fee' => $new['company_fee'],
            'new_period_amount' => $new['amount'],   // future renewals bill at this
            'end' => $end->format('d M Y'),
        ];
    }

    /** Server-priced quote for the renew/upgrade dialog (mode: renew | upgrade). */
    public function quote(Request $request)
    {
        [$tid] = $this->guard($request);
        try {
            $v = $request->validate([
                'plan_id' => ['required', 'integer'],
                'seats' => ['required', 'integer', 'min:1', 'max:100000'],
                'companies' => ['nullable', 'integer', 'min:1', 'max:100'],
                'cycle' => ['required', 'in:quarterly,halfyear,annual'],
                'mode' => ['nullable', 'in:renew,upgrade'],
                'coupon' => ['nullable', 'string', 'max:40'],   // rev 112: renew mode only
            ]);
            $plan = DB::table('plans')->where('id', $v['plan_id'])->where('status', 'active')->first();
            if (! $plan) {
                return response()->json(['ok' => false, 'error' => 'Plan not found.'], 422);
            }
            $companies = max(1, (int) ($v['companies'] ?? 1));
            if (($v['mode'] ?? 'renew') === 'upgrade') {
                $sub = DB::table('subscriptions')->where('tenant_id', $tid)->first();
                if (! $sub) {
                    return response()->json(['ok' => false, 'error' => 'No active subscription — use Renew instead.'], 422);
                }
                $pp = $this->prorataFor($sub, $plan, (int) $v['seats'], $companies);
                if (! $pp) {
                    return response()->json(['ok' => false, 'error' => 'Current period has no end date — use Renew instead.'], 422);
                }
                if ($pp['total'] <= 0) {
                    return response()->json(['ok' => false, 'error' => 'Nothing extra selected — increase employees or companies (downgrades apply at renewal).'], 422);
                }

                return response()->json(['ok' => true, 'summary' => $pp, 'plan' => $plan->name, 'mode' => 'upgrade']);
            }
            $price = BillingController::priceFor($plan, (int) $v['seats'], $v['cycle'], $companies);
            // rev 112: coupon (renew mode only — upgrades pay a pro-rata difference).
            // rev 113: no code given → AUTO-APPLY any exclusive offer sent to the
            // workspace owner's email (unless the user removed it — no_auto).
            $ownerEmail = DB::table('tenants')->where('id', $tid)->value('owner_email');
            if (! empty($v['coupon'])) {
                [$coupon, $cErr] = \App\Services\CouponService::validate($v['coupon'], (int) $plan->id, $v['cycle'], 'renewal', $ownerEmail, $tid);
                if ($cErr) {
                    return response()->json(['ok' => false, 'error' => $cErr], 422);
                }
                $price = \App\Services\CouponService::apply($price, $coupon);
            } elseif (! $request->boolean('no_auto')) {
                $auto = \App\Services\CouponService::forEmail($ownerEmail, (int) $plan->id, $v['cycle'], 'renewal', $tid);
                if ($auto) {
                    $price = \App\Services\CouponService::apply($price, $auto);
                    $price['coupon_auto'] = 1;
                }
            }

            return response()->json(['ok' => true, 'summary' => $price, 'plan' => $plan->name, 'mode' => 'renew']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Start a renewal payment: pending renewals row + Razorpay order. */
    public function renewOrder(Request $request)
    {
        [$tid] = $this->guard($request);
        try {
            $this->ensure();
            $v = $request->validate([
                'plan_id' => ['required', 'integer'],
                'seats' => ['required', 'integer', 'min:1', 'max:100000'],
                'companies' => ['nullable', 'integer', 'min:1', 'max:100'],
                'cycle' => ['required', 'in:quarterly,halfyear,annual'],
                'mode' => ['nullable', 'in:renew,upgrade'],
                'coupon' => ['nullable', 'string', 'max:40'],   // rev 112: renew mode only
            ]);
            $mode = $v['mode'] ?? 'renew';
            $plan = DB::table('plans')->where('id', $v['plan_id'])->where('status', 'active')->first();
            if (! $plan) {
                return response()->json(['ok' => false, 'error' => 'Plan not found.'], 422);
            }
            $creds = BillingController::razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Online payment is not configured. Please write to sales@ametecsindia.com.'], 422);
            }

            // STRICT (rev 76): can't renew below what is actually in use.
            $companies = max(1, (int) ($v['companies'] ?? 1));
            $coUse = \App\Services\SubscriptionService::companyUsage($tid);
            if ($coUse['used'] > 0 && $companies < $coUse['used']) {
                return response()->json(['ok' => false, 'error' => 'You currently have '.$coUse['used'].' companies in your workspace — the renewal must cover at least that many (each additional company is ₹1,000/month).'], 422);
            }
            $seatUse = \App\Services\SubscriptionService::seatUsage($tid);
            if ($seatUse['used'] > 0 && (int) $v['seats'] < $seatUse['used']) {
                return response()->json(['ok' => false, 'error' => 'You currently have '.$seatUse['used'].' active employees — the renewal must cover at least that many (exited employees free their seat).'], 422);
            }

            // SERVER-side price — never trust the browser's numbers.
            if ($mode === 'upgrade') {
                // rev 81: pro-rata mid-term upgrade — pay only the difference
                // for the remaining days; the period end does not move.
                $sub = DB::table('subscriptions')->where('tenant_id', $tid)->first();
                $pp = $sub ? $this->prorataFor($sub, $plan, (int) $v['seats'], $companies) : null;
                if (! $pp || $pp['total'] <= 0) {
                    return response()->json(['ok' => false, 'error' => 'Nothing extra to charge — increase employees or companies, or use Renew.'], 422);
                }
                $price = $pp;
                $cycleStored = (string) $sub->cycle;
            } else {
                $price = BillingController::priceFor($plan, (int) $v['seats'], $v['cycle'], $companies);
                $cycleStored = $v['cycle'];
                // rev 112: coupon (renew mode only) — re-validated here, never trusted from quote.
                // rev 113: empty code + no dismissal → auto-apply the exclusive offer
                // (mirrors quote() so the paid amount always equals the quoted amount).
                $ownerEmail = DB::table('tenants')->where('id', $tid)->value('owner_email');
                if (! empty($v['coupon'])) {
                    [$coupon, $cErr] = \App\Services\CouponService::validate($v['coupon'], (int) $plan->id, $v['cycle'], 'renewal', $ownerEmail, $tid);
                    if ($cErr) {
                        return response()->json(['ok' => false, 'error' => $cErr], 422);
                    }
                    $price = \App\Services\CouponService::apply($price, $coupon);
                } elseif (! $request->boolean('no_auto')) {
                    $auto = \App\Services\CouponService::forEmail($ownerEmail, (int) $plan->id, $v['cycle'], 'renewal', $tid);
                    if ($auto) {
                        $price = \App\Services\CouponService::apply($price, $auto);
                    }
                }
            }
            $paise = (int) round($price['total'] * 100);

            $uuid = (string) Str::uuid();
            $renewalId = DB::table('renewals')->insertGetId(ApprovalService::safeRow('renewals', [
                'uuid' => $uuid, 'tenant_id' => $tid, 'plan_id' => $plan->id,
                'seats' => (int) $v['seats'], 'companies' => $companies, 'mode' => $mode, 'cycle' => $cycleStored,
                'amount' => $price['amount'], 'tax' => $price['tax'],
                'coupon_code' => $price['coupon_code'] ?? null, 'coupon_discount' => $price['coupon_discount'] ?? 0,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]));

            $resp = Http::withBasicAuth($creds['key'], $creds['secret'])
                ->asForm()->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $paise, 'currency' => 'INR', 'receipt' => 'RENEW-'.$renewalId,
                    'notes' => ['renewal_uuid' => $uuid, 'tenant_id' => (string) $tid, 'kind' => 'renewal'],
                ]);
            if (! $resp->successful()) {
                return response()->json(['ok' => false, 'error' => 'Could not start the payment: '.$resp->body()], 422);
            }
            $orderId = $resp->json()['id'] ?? null;
            DB::table('renewals')->where('id', $renewalId)->update(['gateway_order_id' => $orderId, 'updated_at' => now()]);

            return response()->json([
                'ok' => true, 'orderId' => $orderId, 'keyId' => $creds['key'],
                'amountPaise' => $paise, 'uuid' => $uuid, 'summary' => $price,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Verify the Razorpay signature, then extend the subscription + paid invoice. */
    public function renewComplete(Request $request)
    {
        [$tid] = $this->guard($request);
        try {
            $this->ensure();
            $v = $request->validate([
                'uuid' => ['required', 'string'],
                'razorpay_order_id' => ['required', 'string'],
                'razorpay_payment_id' => ['required', 'string'],
                'razorpay_signature' => ['required', 'string'],
            ]);

            $r = DB::table('renewals')->where('uuid', $v['uuid'])->where('tenant_id', $tid)->first();
            if (! $r) {
                return response()->json(['ok' => false, 'error' => 'Renewal not found.'], 404);
            }
            if ($r->status === 'done') {
                // Idempotent: a refresh / double-click must not extend twice.
                return response()->json(['ok' => true, 'message' => 'This payment is already applied to your subscription.']);
            }
            if (! $r->gateway_order_id || $r->gateway_order_id !== $v['razorpay_order_id']) {
                return response()->json(['ok' => false, 'error' => 'Payment/order mismatch.'], 422);
            }
            $creds = BillingController::razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Payment gateway not configured.'], 422);
            }
            $expected = hash_hmac('sha256', $v['razorpay_order_id'].'|'.$v['razorpay_payment_id'], $creds['secret']);
            if (! hash_equals($expected, $v['razorpay_signature'])) {
                return response()->json(['ok' => false, 'error' => 'Payment signature verification failed.'], 422);
            }

            $end = self::applyRenewal($r, $v['razorpay_order_id'], $v['razorpay_payment_id']);

            return response()->json([
                'ok' => true,
                'message' => ($r->mode ?? 'renew') === 'upgrade'
                    ? 'Payment received — your upgrade is live: the new employee/company limits apply immediately. Your period still runs until '.$end->format('d M Y').'.'
                    : 'Payment received — your subscription is active until '.$end->format('d M Y').'. The tax invoice has been emailed.',
                'end' => $end->format('d M Y'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Apply a VERIFIED renewal payment: extend the subscription, sync the
     * tenant (plan/seats/MRR + status back to active), raise + pay the GST
     * invoice, mark the renewal done, email the receipt. STATIC + shared so
     * the Razorpay WEBHOOK can recover a renewal whose browser closed after
     * paying but before the confirmation call. Caller must have verified the
     * payment (HMAC signature or webhook signature) first.
     */
    public static function applyRenewal(object $r, string $orderId, string $paymentId): Carbon
    {
        $tid = (int) $r->tenant_id;
        $plan = DB::table('plans')->where('id', $r->plan_id)->first();
        $rCompanies = max(1, (int) ($r->companies ?? 1));
        $isUpgrade = ($r->mode ?? 'renew') === 'upgrade';
        $price = BillingController::priceFor($plan, (int) $r->seats, $r->cycle, $rCompanies);

        $sub = DB::table('subscriptions')->where('tenant_id', $tid)->first();
        if ($isUpgrade && $sub && ! empty($sub->current_period_end)) {
            // rev 81: PRO-RATA upgrade — limits rise NOW, period end unchanged;
            // subscription amount becomes the NEW full-period rate so the next
            // renewal bills correctly.
            $end = Carbon::parse($sub->current_period_end);
        } else {
            // Renewing early never loses days — extend from the current period end.
            $isUpgrade = false;   // no period to upgrade against → treat as renew
            $base = now();
            if ($sub && ! empty($sub->current_period_end)) {
                $cur = Carbon::parse($sub->current_period_end);
                if ($cur->isFuture()) {
                    $base = $cur;
                }
            }
            $end = $base->copy()->addMonths($price['months']);
        }

        $row = ApprovalService::safeRow('subscriptions', [
            'tenant_id' => $tid, 'plan_id' => $r->plan_id, 'seats' => (int) $r->seats,
            'companies' => $rCompanies,
            'cycle' => $r->cycle, 'amount' => $price['amount'], 'status' => 'active',
            'current_period_end' => $end->toDateString(), 'next_renewal' => $end->toDateString(),
            'updated_at' => now(),
        ]);
        if ($sub) {
            DB::table('subscriptions')->where('id', $sub->id)->update($row);
        } else {
            $row['created_at'] = now();
            DB::table('subscriptions')->insert($row);
        }
        // Tenant back in good standing (clears an auto-suspend from expiry).
        DB::table('tenants')->where('id', $tid)->update(ApprovalService::safeRow('tenants', [
            'plan_id' => $r->plan_id, 'seats_licensed' => (int) $r->seats, 'status' => 'active',
            'mrr' => round($price['amount'] / max(1, $price['months']), 2), 'updated_at' => now(),
        ]));

        if ($isUpgrade) {
            // Invoice for exactly the pro-rata difference held on the renewals row.
            // rev 187 (Ejaz): PRS-<FY>-<MM>-<count> — consecutive through the FY.
            $invId = DB::table('invoices')->insertGetId(ApprovalService::safeRow('invoices', [
                'uuid' => (string) Str::uuid(), 'tenant_id' => $tid,
                'number' => BillingController::nextInvoiceNumber(),
                'amount' => (float) $r->amount, 'tax' => (float) $r->tax, 'status' => 'due',
                'issued_on' => now()->toDateString(), 'due_on' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $inv = DB::table('invoices')->where('id', $invId)->first();
        } else {
            // Invoice for the renewed period (created due, then marked paid).
            $inv = BillingController::createInvoiceForTenant($tid);
        }
        // rev 112: with a coupon the invoice must show what was ACTUALLY charged
        // (the renewals row holds the discounted amount/tax); the subscription
        // row above keeps the FULL rate so the NEXT renewal bills full price.
        $couponDisc = (float) ($r->coupon_discount ?? 0);
        $invPatch = ['gateway_order_id' => $orderId, 'updated_at' => now()];
        if (! $isUpgrade && $couponDisc > 0) {
            $invPatch['amount'] = (float) $r->amount;
            $invPatch['tax'] = (float) $r->tax;
        }
        DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', $invPatch));
        $inv = DB::table('invoices')->where('id', $inv->id)->first();
        BillingController::recordPayment($inv, 'razorpay', 'razorpay', $paymentId);
        if (! $isUpgrade && $couponDisc > 0 && ! empty($r->coupon_code)) {
            try {
                $cpn = DB::table('coupons')->where('code', $r->coupon_code)->first();
                $ownerEmail = DB::table('tenants')->where('id', $tid)->value('owner_email');
                if ($cpn) {
                    \App\Services\CouponService::redeem($cpn, 'renewal', $couponDisc, $ownerEmail, $tid);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('renewal coupon redeem: '.$e->getMessage());
            }
        }

        DB::table('renewals')->where('id', $r->id)->update(['status' => 'done', 'updated_at' => now()]);

        // Receipt email with the GST tax-invoice PDF (best-effort).
        try {
            (new BillingController())->emailInvoice($inv->id, 'invoice.receipt');
        } catch (\Throwable $e) {
        }

        return $end;
    }

    /** Stream one of the tenant's OWN invoices as PDF (ownership enforced). */
    public function invoicePdf(Request $request, int $id)
    {
        [$tid] = $this->guard($request);
        $inv = DB::table('invoices')->where('id', $id)->first();
        if (! $inv || (int) $inv->tenant_id !== $tid) {
            return response('Invoice not found.', 404)->header('Content-Type', 'text/plain');
        }
        $b = (new BillingController())->buildInvoicePdf($id);
        if (! $b) {
            return response('Invoice not found.', 404)->header('Content-Type', 'text/plain');
        }

        return $b['pdf']->stream($b['file']);
    }
}
