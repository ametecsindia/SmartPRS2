<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev 112 (8 Jun 2026): DISCOUNT COUPONS — marketing weapon for signup + renewals.
 * Ejaz's confirmed design (AskUserQuestion batch):
 *   - Coupons work on SIGNUP and RENEWALS (not on-prem licence sales v1).
 *   - Types: percent OR flat ₹ (per coupon).
 *   - STACKS ON TOP of the advance-payment cycle discount (applied AFTER priceFor).
 *   - Limits, all per coupon: expiry date, max total uses, min cycle, plan
 *     restriction, one-use-per-customer.
 * Subscription rows always keep the FULL price (next renewal bills full);
 * only the paid amount + the invoice carry the discount.
 *
 * Tables self-created (project convention): coupons, coupon_redemptions.
 */
class CouponService
{
    public static function ensure(): void
    {
        try {
            if (! Schema::hasTable('coupons')) {
                Schema::create('coupons', function (Blueprint $t) {
                    $t->id();
                    $t->string('code', 40)->unique();          // stored UPPERCASE
                    $t->string('type', 10)->default('percent'); // percent | flat
                    $t->decimal('value', 10, 2)->default(0);    // 25 (%) or 5000 (₹)
                    $t->date('valid_till')->nullable();         // null = no expiry
                    $t->integer('max_uses')->nullable();        // null = unlimited
                    $t->integer('used_count')->default(0);
                    $t->string('min_cycle', 12)->nullable();    // null|quarterly|halfyear|annual
                    $t->string('plan_ids', 100)->nullable();    // CSV of plan ids; null = all plans
                    $t->boolean('once_per_customer')->default(1);
                    $t->string('applies_to', 20)->default('both'); // signup | renewal | both
                    $t->string('exclusive_email', 191)->nullable()->index(); // rev 113: locked to one email
                    $t->string('status', 12)->default('active');   // active | disabled
                    $t->string('notes', 255)->nullable();          // campaign name etc.
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('coupon_redemptions')) {
                Schema::create('coupon_redemptions', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('coupon_id')->index();
                    $t->string('code', 40)->index();
                    $t->string('context', 12)->default('signup');  // signup | renewal
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->string('email', 191)->nullable()->index(); // lowercased buyer email
                    $t->string('company', 150)->nullable();
                    $t->decimal('amount_discounted', 12, 2)->default(0);
                    $t->timestamps();
                });
            }
            // rev 113: EMAIL-EXCLUSIVE coupons — sent personally, locked to one
            // email, auto-applied on the cart when that email is caught.
            if (Schema::hasTable('coupons') && ! Schema::hasColumn('coupons', 'exclusive_email')) {
                Schema::table('coupons', fn (Blueprint $t) => $t->string('exclusive_email', 191)->nullable()->index());
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('CouponService ensure: '.$e->getMessage());
        }
    }

    private const CYCLE_RANK = ['quarterly' => 1, 'halfyear' => 2, 'annual' => 3];

    /**
     * Validate a code for a purchase. Returns [coupon, null] or [null, friendly error].
     * $context: 'signup' | 'renewal'. $email + $tenantId drive once-per-customer.
     */
    public static function validate(string $code, int $planId, string $cycle, string $context, ?string $email = null, ?int $tenantId = null): array
    {
        self::ensure();
        $code = strtoupper(trim($code));
        if ($code === '') {
            return [null, 'Enter a coupon code.'];
        }
        $c = DB::table('coupons')->where('code', $code)->first();
        if (! $c || $c->status !== 'active') {
            return [null, 'This coupon code is not valid.'];
        }
        // rev 113: email-exclusive coupon — works ONLY for the email it was sent to.
        if (! empty($c->exclusive_email)) {
            if (! $email || strtolower(trim($email)) !== strtolower(trim($c->exclusive_email))) {
                return [null, 'This coupon is an exclusive offer linked to a specific email address. Please use the email it was sent to.'];
            }
        }
        if (! in_array($c->applies_to, ['both', $context], true)) {
            return [null, $context === 'signup' ? 'This coupon is only for renewals.' : 'This coupon is only for new signups.'];
        }
        if ($c->valid_till && now()->startOfDay()->gt(\Carbon\Carbon::parse($c->valid_till)->endOfDay())) {
            return [null, 'This coupon expired on '.\Carbon\Carbon::parse($c->valid_till)->format('d M Y').'.'];
        }
        if ($c->max_uses !== null && (int) $c->used_count >= (int) $c->max_uses) {
            return [null, 'This coupon has been fully used up.'];
        }
        if ($c->min_cycle && (self::CYCLE_RANK[$cycle] ?? 0) < (self::CYCLE_RANK[$c->min_cycle] ?? 0)) {
            $lbl = ['quarterly' => 'quarterly', 'halfyear' => 'half-yearly', 'annual' => 'annual'][$c->min_cycle] ?? $c->min_cycle;
            return [null, 'This coupon needs a minimum '.$lbl.' payment.'];
        }
        if ($c->plan_ids) {
            $ids = array_filter(array_map('intval', explode(',', $c->plan_ids)));
            if ($ids && ! in_array($planId, $ids, true)) {
                return [null, 'This coupon is not valid for the selected plan.'];
            }
        }
        if ($c->once_per_customer) {
            $q = DB::table('coupon_redemptions')->where('coupon_id', $c->id);
            $q->where(function ($w) use ($email, $tenantId) {
                $hit = false;
                if ($email) {
                    $w->whereRaw('LOWER(email) = ?', [strtolower($email)]);
                    $hit = true;
                }
                if ($tenantId) {
                    $hit ? $w->orWhere('tenant_id', $tenantId) : $w->where('tenant_id', $tenantId);
                    $hit = true;
                }
                if (! $hit) {
                    $w->whereRaw('1 = 0');
                }
            });
            if (($email || $tenantId) && $q->exists()) {
                return [null, 'This coupon was already used by your account.'];
            }
        }

        return [$c, null];
    }

    /**
     * Apply a coupon to a BillingController::priceFor() summary.
     * Discount hits the pre-GST amount (AFTER the cycle discount — stacking),
     * GST is recomputed. Returns the summary + coupon_code/coupon_discount keys.
     */
    public static function apply(array $price, object $coupon): array
    {
        $amount = (float) $price['amount'];
        $disc = $coupon->type === 'flat'
            ? min((float) $coupon->value, $amount)
            : round($amount * (float) $coupon->value / 100, 2);
        $disc = max(0, round($disc, 2));
        $newAmount = round($amount - $disc, 2);
        $tax = round($newAmount * \App\Http\Controllers\BillingController::GST / 100, 2);

        $price['amount_before_coupon'] = $amount;
        $price['coupon_code'] = $coupon->code;
        $price['coupon_discount'] = $disc;
        $price['amount'] = $newAmount;
        $price['tax'] = $tax;
        $price['total'] = round($newAmount + $tax, 2);

        return $price;
    }

    /**
     * rev 113: find the best ACTIVE exclusive coupon for an email — used to
     * AUTO-APPLY the offer on the cart the moment the email is caught.
     * Returns the validated coupon object or null (never throws).
     */
    public static function forEmail(?string $email, int $planId, string $cycle, string $context, ?int $tenantId = null): ?object
    {
        try {
            if (! $email || trim($email) === '') {
                return null;
            }
            self::ensure();
            $rows = DB::table('coupons')
                ->whereRaw('LOWER(exclusive_email) = ?', [strtolower(trim($email))])
                ->where('status', 'active')->orderByDesc('id')->limit(10)->get();
            foreach ($rows as $c) {
                // Reuse the full rule set (expiry, uses, cycle, plan, context, once-per).
                [$ok, $err] = self::validate($c->code, $planId, $cycle, $context, $email, $tenantId);
                if ($ok) {
                    return $ok;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('CouponService forEmail: '.$e->getMessage());
        }

        return null;
    }

    /**
     * rev 113b (Ejaz): the coupon box on the cart shows ONLY when coupons are
     * "enabled from the backend" = at least one ACTIVE, in-date, not-used-up
     * PUBLIC (non-exclusive) coupon exists for this context. Exclusive offers
     * are independent — they enable themselves when the email matches.
     */
    public static function publicCouponsExist(string $context): bool
    {
        try {
            self::ensure();

            return DB::table('coupons')
                ->where('status', 'active')
                ->where(function ($w) {
                    $w->whereNull('exclusive_email')->orWhere('exclusive_email', '');
                })
                ->whereIn('applies_to', ['both', $context])
                ->where(function ($w) {
                    $w->whereNull('valid_till')->orWhere('valid_till', '>=', now()->toDateString());
                })
                ->where(function ($w) {
                    $w->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
                })
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Record a confirmed redemption (call ONLY after payment success). Idempotent per context+email/tenant best-effort. */
    public static function redeem(object $coupon, string $context, float $discounted, ?string $email = null, ?int $tenantId = null, ?string $company = null): void
    {
        try {
            self::ensure();
            DB::table('coupon_redemptions')->insert([
                'coupon_id' => $coupon->id, 'code' => $coupon->code, 'context' => $context,
                'tenant_id' => $tenantId, 'email' => $email ? strtolower($email) : null,
                'company' => $company, 'amount_discounted' => $discounted,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('coupons')->where('id', $coupon->id)->increment('used_count');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('CouponService redeem: '.$e->getMessage());
        }
    }
}
