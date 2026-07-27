<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PUBLIC self-serve signup with Razorpay checkout (test or live, per the keys
 * configured in Billing → Payment Gateways).
 *
 * Flow:
 *   GET  /signup            → plan picker + live quote + company/admin form
 *   POST /signup/order      → server-side price, pending `signups` row, Razorpay order
 *   (Razorpay checkout.js collects the payment in the browser)
 *   POST /signup/complete   → HMAC signature verify → tenant provisioned
 *                             (SaasController::provisionTenantRecord) + subscription
 *                             + PAID invoice + payment row; admin invite emailed.
 *
 * Safety: all amounts are computed SERVER-side from the plans table
 * (BillingController::priceFor — the single pricing source of truth); the
 * signature check is the same HMAC the billing screen uses; completion is
 * idempotent per signup; admin-email uniqueness is enforced at both steps.
 */
class SignupController extends Controller
{
    private function ensure(): void
    {
        if (! Schema::hasTable('signups')) {
            Schema::create('signups', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->index();
                $t->string('company');
                $t->string('admin_name');
                $t->string('admin_email');
                $t->string('mobile')->nullable();
                $t->unsignedBigInteger('plan_id');
                $t->integer('seats')->default(0);
                $t->integer('companies')->default(1);
                $t->string('cycle', 20);
                $t->decimal('amount', 12, 2)->default(0);
                $t->decimal('tax', 12, 2)->default(0);
                $t->string('gateway_order_id')->nullable()->index();
                $t->string('status', 20)->default('pending'); // pending | provisioned
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->timestamp('terms_accepted_at')->nullable();
                $t->timestamps();
            });

            return;
        }
        // Self-heal: terms consent column for tables created before it existed.
        if (! Schema::hasColumn('signups', 'terms_accepted_at')) {
            try {
                Schema::table('signups', function (Blueprint $t) {
                    $t->timestamp('terms_accepted_at')->nullable();
                });
            } catch (\Throwable $e) {
            }
        }
        // Self-heal: multi-company billing (rev 76) — on signups AND subscriptions
        // (the latter so safeRow never drops the companies value on insert).
        if (! Schema::hasColumn('signups', 'companies')) {
            try {
                Schema::table('signups', function (Blueprint $t) {
                    $t->integer('companies')->default(1);
                });
            } catch (\Throwable $e) {
            }
        }
        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'companies')) {
            try {
                Schema::table('subscriptions', function (Blueprint $t) {
                    $t->integer('companies')->default(1);
                });
            } catch (\Throwable $e) {
            }
        }
        // rev 90: buyer GST profile captured at signup (GSTIN + state) — drives
        // the CGST+SGST vs IGST split on invoices. Also ensured on tenants.
        // rev 108: + the client-chosen web address (slug, max 10 chars).
        foreach (['gstin' => 20, 'state' => 60, 'subdomain' => 30] as $col => $len) {
            if (! Schema::hasColumn('signups', $col)) {
                try {
                    Schema::table('signups', fn (Blueprint $t) => $t->string($col, $len)->nullable());
                } catch (\Throwable $e) {
                }
            }
        }
        // rev 96: quotation flow — a quoted signup gets a public token + number,
        // can be paid later from /quote/{token}. status can be 'quoted'.
        foreach (['quote_token' => 64, 'quote_no' => 30] as $col => $len) {
            if (! Schema::hasColumn('signups', $col)) {
                try {
                    Schema::table('signups', fn (Blueprint $t) => $t->string($col, $len)->nullable());
                } catch (\Throwable $e) {
                }
            }
        }
        if (! Schema::hasColumn('signups', 'quoted_at')) {
            try {
                Schema::table('signups', fn (Blueprint $t) => $t->timestamp('quoted_at')->nullable());
            } catch (\Throwable $e) {
            }
        }
        // rev 112: discount coupons.
        if (! Schema::hasColumn('signups', 'coupon_code')) {
            try {
                Schema::table('signups', function (Blueprint $t) {
                    $t->string('coupon_code', 40)->nullable();
                    $t->decimal('coupon_discount', 12, 2)->default(0);
                });
            } catch (\Throwable $e) {
            }
        }
        SaasController::ensureGstCols();
    }

    private function activePlans()
    {
        return DB::table('plans')->where('status', 'active')
            ->whereIn('name', ['Starter', 'Growth', 'Professional'])
            ->orderBy('base_price')
            ->get(['id', 'name', 'base_price', 'per_user_price', 'seat_max']);
    }

    public function show(Request $request)
    {
        $this->ensure();

        return view('signup', [
            'plans' => $this->activePlans(),
            'pick' => (string) $request->query('plan', 'Growth'),
            // rev 113b: coupon box shows only when public coupons are live;
            // exclusive offers reveal it themselves on email match.
            'couponsEnabled' => \App\Services\CouponService::publicCouponsExist('signup'),
        ]);
    }

    public function createOrder(Request $request)
    {
        try {
            $this->ensure();
            $v = $request->validate([
                'company' => ['required', 'string', 'max:150'],
                'admin_name' => ['required', 'string', 'max:120'],
                'admin_email' => ['required', 'email', 'max:191'],
                'mobile' => ['nullable', 'string', 'max:20'],
                'plan_id' => ['required', 'integer'],
                'seats' => ['required', 'integer', 'min:1', 'max:100000'],
                'companies' => ['nullable', 'integer', 'min:1', 'max:100'],
                'cycle' => ['required', 'in:quarterly,halfyear,annual'],
                'terms_accepted' => ['accepted'],   // T&C + refund policy consent (recorded below)
                // rev 90: GST profile — state REQUIRED (decides CGST+SGST vs IGST),
                // GSTIN optional (unregistered businesses) but format-checked if given.
                'state' => ['required', 'string', 'max:60'],
                'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/i'],
                'subdomain' => ['nullable', 'string', 'max:30'],
                'coupon' => ['nullable', 'string', 'max:40'],   // rev 112: discount coupon
            ], [
                'gstin.regex' => 'GSTIN must be 15 characters, starting with the 2-digit state code (e.g. 36AAHCT0971F1ZB).',
                'state.required' => 'Please select your state — it decides how GST appears on your tax invoice.',
            ]);
            // rev 108: optional client-chosen web address (smartprs.com/c/<this>).
            $slugReq = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($v['subdomain'] ?? '')));
            if ($slugReq !== '') {
                if (strlen($slugReq) < 3 || strlen($slugReq) > 10) {
                    return response()->json(['ok' => false, 'error' => 'Your web address must be 3 to 10 letters/numbers (no spaces or symbols).'], 422);
                }
                if (DB::table('tenants')->where('subdomain', $slugReq)->exists()) {
                    return response()->json(['ok' => false, 'error' => 'The web address "'.$slugReq.'" is already taken — please pick another.'], 422);
                }
            }
            $companies = max(1, (int) ($v['companies'] ?? 1));
            $gstin = strtoupper(trim((string) ($v['gstin'] ?? ''))) ?: null;
            // GSTIN state code must match the chosen state's code when both given.
            if ($gstin && preg_match('/\((\d{2})\)/', $v['state'], $sm) && substr($gstin, 0, 2) !== $sm[1]) {
                return response()->json(['ok' => false, 'error' => 'Your GSTIN starts with state code '.substr($gstin, 0, 2).' but you selected '.$v['state'].'. Please correct one of them.'], 422);
            }

            $email = strtolower(trim($v['admin_email']));
            if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return response()->json(['ok' => false, 'error' => 'That email already has a SmartPRS account. Sign in instead, or use a different email.'], 422);
            }
            $plan = DB::table('plans')->where('id', $v['plan_id'])->where('status', 'active')->first();
            if (! $plan) {
                return response()->json(['ok' => false, 'error' => 'Plan not found.'], 422);
            }
            $creds = BillingController::razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Online payment is not configured yet. Please write to sales@ametecsindia.com and we will set you up the same day.'], 422);
            }

            // SERVER-side price — never trust the browser's numbers.
            $price = BillingController::priceFor($plan, (int) $v['seats'], $v['cycle'], $companies);
            // rev 112: coupon — validated server-side, applied AFTER the cycle discount.
            $couponCode = null;
            if (! empty($v['coupon'])) {
                [$coupon, $cErr] = \App\Services\CouponService::validate($v['coupon'], (int) $plan->id, $v['cycle'], 'signup', $email);
                if ($cErr) {
                    return response()->json(['ok' => false, 'error' => $cErr], 422);
                }
                $price = \App\Services\CouponService::apply($price, $coupon);
                $couponCode = $coupon->code;
            }
            $paise = (int) round($price['total'] * 100);

            $uuid = (string) Str::uuid();
            $signupId = DB::table('signups')->insertGetId(ApprovalService::safeRow('signups', [
                'uuid' => $uuid, 'company' => $v['company'], 'admin_name' => $v['admin_name'],
                'admin_email' => $email, 'mobile' => $v['mobile'] ?? null,
                'gstin' => $gstin, 'state' => $v['state'], 'subdomain' => $slugReq ?: null,
                'plan_id' => $plan->id, 'seats' => (int) $v['seats'], 'companies' => $companies, 'cycle' => $v['cycle'],
                'amount' => $price['amount'], 'tax' => $price['tax'],
                'coupon_code' => $couponCode, 'coupon_discount' => $price['coupon_discount'] ?? 0,
                'terms_accepted_at' => now(),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]));

            $resp = Http::withBasicAuth($creds['key'], $creds['secret'])
                ->asForm()->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $paise, 'currency' => 'INR', 'receipt' => 'SIGNUP-'.$signupId,
                    'notes' => ['signup_uuid' => $uuid, 'company' => $v['company']],
                ]);
            if (! $resp->successful()) {
                return response()->json(['ok' => false, 'error' => 'Could not start the payment: '.$resp->body()], 422);
            }
            $orderId = $resp->json()['id'] ?? null;
            DB::table('signups')->where('id', $signupId)->update(['gateway_order_id' => $orderId, 'updated_at' => now()]);

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

    public function complete(Request $request)
    {
        try {
            $this->ensure();
            $v = $request->validate([
                'uuid' => ['required', 'string'],
                'razorpay_order_id' => ['required', 'string'],
                'razorpay_payment_id' => ['required', 'string'],
                'razorpay_signature' => ['required', 'string'],
            ]);

            $s = DB::table('signups')->where('uuid', $v['uuid'])->first();
            if (! $s) {
                return response()->json(['ok' => false, 'error' => 'Signup not found.'], 404);
            }
            if ($s->status === 'provisioned') {
                // Idempotent: a refresh / double-click must not provision twice.
                return response()->json([
                    'ok' => true,
                    'message' => 'Your workspace is ready — check your email for the set-password link.',
                    'redirect' => \Illuminate\Support\Facades\Auth::check() ? url('/app') : url('/login'),
                ]);
            }
            if (! $s->gateway_order_id || $s->gateway_order_id !== $v['razorpay_order_id']) {
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

            return $this->provisionPaidSignup($s, $request, $v['razorpay_order_id'], $v['razorpay_payment_id']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 96: shared post-payment provisioning — used by both the direct signup
     * (complete) and the quotation public-pay flow (quoteComplete). Builds the
     * tenant + subscription + paid invoice, emails the receipt, auto-signs-in
     * the browser session and fires the WhatsApp welcome + payment messages.
     */
    private function provisionPaidSignup($s, Request $request, string $orderId, string $paymentId)
    {
        try {
            // Price summary first — included in the welcome email + WhatsApp.
            $plan = DB::table('plans')->where('id', $s->plan_id)->first();
            $sCompanies = max(1, (int) ($s->companies ?? 1));
            $price = BillingController::priceFor($plan, (int) $s->seats, $s->cycle, $sCompanies);
            $end = now()->addMonths($price['months']);
            $cycleLabel = ['quarterly' => 'Quarterly (3 months)', 'halfyear' => 'Half-yearly (6 months)', 'annual' => 'Annual (12 months)'][$s->cycle] ?? $s->cycle;
            $fmt = fn ($n) => '₹'.number_format((float) $n, 2);
            // rev 112: the signup row holds the COUPON-DISCOUNTED amount/tax (what
            // was actually paid); $price stays the FULL rate for the subscription.
            $couponDisc = (float) ($s->coupon_discount ?? 0);
            $paidAmount = $couponDisc > 0 ? (float) $s->amount : $price['amount'];
            $paidTax = $couponDisc > 0 ? (float) $s->tax : $price['tax'];
            $paymentLines = [
                'Plan' => ($plan->name ?? 'Plan').' — includes up to '.($plan->seat_max ?? '—').' employees',
                'Employees' => $s->seats.' (total across all your companies)',
                'Companies' => $sCompanies.($sCompanies > 1 ? ' (1 included + '.($sCompanies - 1).' × ₹1,000/mo)' : ' (included)'),
                'Billing period' => $cycleLabel.($price['discount'] > 0 ? ' · '.($price['discount'] * 100).'% advance discount applied' : ''),
            ];
            if ($couponDisc > 0) {
                $paymentLines['Coupon ('.$s->coupon_code.')'] = '− '.$fmt($couponDisc);
            }
            $paymentLines += [
                'Subscription amount' => $fmt($paidAmount),
                'GST (18%)' => $fmt($paidTax),
                'Total paid' => $fmt(round($paidAmount + $paidTax, 2)),
                'Payment reference' => $paymentId,
                'Active until' => $end->format('d M Y'),
            ];

            // 1) Tenant + first company + admin (welcome email with credentials +
            //    the full plan/payment summary above) + starter content.
            $res = SaasController::provisionTenantRecord([
                'name' => $s->company, 'company_name' => $s->company,
                'admin_name' => $s->admin_name, 'admin_email' => $s->admin_email,
                'plan_id' => $s->plan_id, 'seats_licensed' => $s->seats,
                'gstin' => $s->gstin ?? null, 'state' => $s->state ?? null,   // rev 90: GST profile → invoice tax split
                'subdomain' => $s->subdomain ?? null,                         // rev 108: client-chosen web address
                'email_credentials' => true,   // paid signup → email login + temp password
                'extra_lines' => $paymentLines,
            ]);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => $res['error']], 422);
            }
            $tid = (int) $res['tenant_id'];
            DB::table('subscriptions')->insert(ApprovalService::safeRow('subscriptions', [
                'tenant_id' => $tid, 'plan_id' => $s->plan_id, 'seats' => $s->seats,
                'companies' => $sCompanies,
                'cycle' => $s->cycle, 'amount' => $price['amount'], 'status' => 'active',
                'current_period_end' => $end->toDateString(), 'next_renewal' => $end->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::table('tenants')->where('id', $tid)->update(ApprovalService::safeRow('tenants', [
                'mrr' => round($price['amount'] / max(1, $price['months']), 2), 'updated_at' => now(),
            ]));

            // 3) Invoice (created due, then marked paid with the gateway txn).
            //    rev 112: with a coupon, the invoice must show what was ACTUALLY
            //    charged (discounted) — the subscription itself keeps full rate.
            $inv = BillingController::createInvoiceForTenant($tid);
            $invPatch = ['gateway_order_id' => $orderId, 'updated_at' => now()];
            if ($couponDisc > 0) {
                $invPatch['amount'] = $paidAmount;
                $invPatch['tax'] = $paidTax;
            }
            DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', $invPatch));
            $inv = DB::table('invoices')->where('id', $inv->id)->first();
            BillingController::recordPayment($inv, 'razorpay', 'razorpay', $paymentId);
            // Coupon redemption — confirmed payment only.
            if ($couponDisc > 0 && ! empty($s->coupon_code)) {
                try {
                    $cpn = DB::table('coupons')->where('code', $s->coupon_code)->first();
                    if ($cpn) {
                        \App\Services\CouponService::redeem($cpn, 'signup', $couponDisc, $s->admin_email, $tid, $s->company);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('signup coupon redeem: '.$e->getMessage());
                }
            }

            // Payment-receipt email with the GST tax-invoice PDF attached
            // (platform SMTP; best-effort — the workspace is already live).
            try {
                (new BillingController())->emailInvoice($inv->id, 'invoice.receipt');
            } catch (\Throwable $e) {
                // receipt email is best-effort
            }

            DB::table('signups')->where('id', $s->id)->update([
                'status' => 'provisioned', 'tenant_id' => $tid, 'updated_at' => now(),
            ]);

            // AUTO SIGN-IN the new admin (they just paid in THIS browser session)
            // so the success panel can open the workspace directly — no waiting
            // for the credentials email. Best-effort: if it fails they still get
            // the email + sign-in link.
            $autoIn = false;
            try {
                $uid = DB::table('users')->where('tenant_id', $tid)
                    ->whereRaw('LOWER(email) = ?', [strtolower($s->admin_email)])->value('id');
                if ($uid) {
                    \Illuminate\Support\Facades\Auth::loginUsingId($uid);
                    $request->session()->regenerate();
                    $autoIn = true;
                }
            } catch (\Throwable $e) {
                // fall back to the emailed credentials
            }

            // rev 171 (Ejaz): every "sign in here" pointer uses the BRANDED
            // company page /c/{slug}, not the generic /login.
            $slug = DB::table('tenants')->where('id', $tid)->value('subdomain');
            $loginUrl = $slug ? url('/c/'.$slug) : url('/login');

            // WhatsApp welcome via Interakt (best-effort; never blocks signup).
            // Set SMARTPRS_WA_SEND_PASSWORD=false to keep the password email-only.
            try {
                if (! empty($s->mobile)) {
                    $sendPw = filter_var(env('SMARTPRS_WA_SEND_PASSWORD', true), FILTER_VALIDATE_BOOLEAN);
                    \App\Services\WaService::sendTemplate([
                        'tenant_id' => $tid,
                        'mobile' => $s->mobile,
                        'kind' => 'signup.welcome',
                        'template' => \App\Services\WaService::templateNameFor('welcome'),
                        'bodyValues' => [
                            $s->admin_name,                                   // {{1}} name
                            $s->company,                                      // {{2}} company
                            $loginUrl,                                        // {{3}} sign-in URL (branded /c/{slug})
                            $s->admin_email,                                  // {{4}} login email
                            $sendPw ? (string) ($res['temp_password'] ?? '') : 'sent to your email',  // {{5}} temp password
                        ],
                    ]);

                    // Second message: full payment + plan confirmation.
                    \App\Services\WaService::sendTemplate([
                        'tenant_id' => $tid,
                        'mobile' => $s->mobile,
                        'kind' => 'signup.payment',
                        'template' => \App\Services\WaService::templateNameFor('payment'),
                        'bodyValues' => [
                            $s->admin_name,                          // {{1}} name
                            ($plan->name ?? 'Plan'),                 // {{2}} plan
                            (string) $s->seats,                      // {{3}} employees
                            $cycleLabel,                             // {{4}} billing period
                            'Rs '.number_format($price['total'], 2), // {{5}} total paid (incl. GST)
                            $paymentId,                              // {{6}} payment reference
                            $end->format('d M Y'),                   // {{7}} active until
                        ],
                    ]);
                }
            } catch (\Throwable $e) {
                // WhatsApp is best-effort
            }

            return response()->json([
                'ok' => true,
                'message' => $autoIn
                    ? 'Payment received and your workspace is ready! Taking you in now — you will create your own password in the next step. (A backup copy of your login details was also emailed to '.$s->admin_email.'.)'
                    : 'Payment received and your workspace is ready! Sign in at '.$loginUrl.' using your registration email '.$s->admin_email.' — you will create your own password on first entry (details also emailed to you).',
                'redirect' => $autoIn ? url('/app') : $loginUrl,
                'autoIn' => $autoIn,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ======================================================================
    // rev 96 — QUOTATION FLOW (Ejaz): "Send a Quotation" on signup. Emails a
    // branded quotation PDF + a PUBLIC pay link; finance approves, then anyone
    // with the link pays securely and the workspace is auto-created.
    // ======================================================================

    private const QUOTE_VALID_DAYS = 15;

    /** Shared validation for both the pay-now order and the quotation. */
    private function validateSignup(Request $request): array
    {
        $v = $request->validate([
            'company' => ['required', 'string', 'max:150'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'plan_id' => ['required', 'integer'],
            'seats' => ['required', 'integer', 'min:1', 'max:100000'],
            'companies' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cycle' => ['required', 'in:quarterly,halfyear,annual'],
            'state' => ['required', 'string', 'max:60'],
            'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/i'],
            'subdomain' => ['nullable', 'string', 'max:30'],
        ], [
            'gstin.regex' => 'GSTIN must be 15 characters, starting with the 2-digit state code (e.g. 36AAHCT0971F1ZB).',
            'state.required' => 'Please select your state.',
        ]);

        return $v;
    }

    /**
     * rev 112: POST /signup/coupon-check — live validation for the signup page.
     * Returns the discounted summary so the quote box can update instantly.
     */
    public function couponCheck(Request $request)
    {
        try {
            $v = $request->validate([
                'coupon' => ['required', 'string', 'max:40'],
                'plan_id' => ['required', 'integer'],
                'seats' => ['required', 'integer', 'min:1', 'max:100000'],
                'companies' => ['nullable', 'integer', 'min:1', 'max:100'],
                'cycle' => ['required', 'in:quarterly,halfyear,annual'],
                'email' => ['nullable', 'email', 'max:191'],
            ]);
            $plan = DB::table('plans')->where('id', $v['plan_id'])->where('status', 'active')->first();
            if (! $plan) {
                return response()->json(['ok' => false, 'error' => 'Plan not found.'], 422);
            }
            [$coupon, $err] = \App\Services\CouponService::validate($v['coupon'], (int) $plan->id, $v['cycle'], 'signup', $v['email'] ?? null);
            if ($err) {
                return response()->json(['ok' => false, 'error' => $err], 422);
            }
            $price = BillingController::priceFor($plan, (int) $v['seats'], $v['cycle'], max(1, (int) ($v['companies'] ?? 1)));
            $price = \App\Services\CouponService::apply($price, $coupon);
            $label = $coupon->type === 'flat' ? '₹'.number_format((float) $coupon->value).' off' : rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.').'% off';

            return response()->json([
                'ok' => true, 'summary' => $price, 'code' => $coupon->code, 'label' => $label,
                // type+value let the page recompute the display when seats/cycle change;
                // the ORDER is always re-priced and re-validated server-side.
                'ctype' => $coupon->type, 'cvalue' => (float) $coupon->value,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 113: POST /signup/exclusive-check — the cart "catches" the email and
     * looks for an exclusive offer sent to it; if found it is AUTO-APPLIED.
     */
    public function exclusiveCheck(Request $request)
    {
        try {
            $v = $request->validate([
                'email' => ['required', 'email', 'max:191'],
                'plan_id' => ['required', 'integer'],
                'seats' => ['required', 'integer', 'min:1', 'max:100000'],
                'companies' => ['nullable', 'integer', 'min:1', 'max:100'],
                'cycle' => ['required', 'in:quarterly,halfyear,annual'],
            ]);
            $plan = DB::table('plans')->where('id', $v['plan_id'])->where('status', 'active')->first();
            if (! $plan) {
                return response()->json(['ok' => false]);
            }
            $coupon = \App\Services\CouponService::forEmail($v['email'], (int) $plan->id, $v['cycle'], 'signup');
            if (! $coupon) {
                return response()->json(['ok' => false]);
            }
            $price = BillingController::priceFor($plan, (int) $v['seats'], $v['cycle'], max(1, (int) ($v['companies'] ?? 1)));
            $price = \App\Services\CouponService::apply($price, $coupon);
            $label = $coupon->type === 'flat' ? '₹'.number_format((float) $coupon->value).' off' : rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.').'% off';

            return response()->json([
                'ok' => true, 'summary' => $price, 'code' => $coupon->code, 'label' => $label,
                'ctype' => $coupon->type, 'cvalue' => (float) $coupon->value, 'exclusive' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false]);
        }
    }

    /** POST /signup/quote — create a quotation, email the PDF + public pay link. */
    public function quote(Request $request)
    {
        try {
            $this->ensure();
            $v = $this->validateSignup($request);
            $companies = max(1, (int) ($v['companies'] ?? 1));
            $gstin = strtoupper(trim((string) ($v['gstin'] ?? ''))) ?: null;
            $email = strtolower(trim($v['admin_email']));

            if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return response()->json(['ok' => false, 'error' => 'That email already has a SmartPRS account. Sign in instead, or use a different email.'], 422);
            }
            $plan = DB::table('plans')->where('id', $v['plan_id'])->where('status', 'active')->first();
            if (! $plan) {
                return response()->json(['ok' => false, 'error' => 'Plan not found.'], 422);
            }
            $price = BillingController::priceFor($plan, (int) $v['seats'], $v['cycle'], $companies);
            // rev 113: coupon on quotations — typed code wins; otherwise the email
            // is caught and any exclusive offer auto-applies. LOCKED into the quote.
            $couponCode = null;
            $reqCoupon = trim((string) $request->input('coupon', ''));
            if ($reqCoupon !== '') {
                [$coupon, $cErr] = \App\Services\CouponService::validate($reqCoupon, (int) $plan->id, $v['cycle'], 'signup', $email);
                if ($cErr) {
                    return response()->json(['ok' => false, 'error' => $cErr], 422);
                }
                $price = \App\Services\CouponService::apply($price, $coupon);
                $couponCode = $coupon->code;
            } else {
                $auto = \App\Services\CouponService::forEmail($email, (int) $plan->id, $v['cycle'], 'signup');
                if ($auto) {
                    $price = \App\Services\CouponService::apply($price, $auto);
                    $couponCode = $auto->code;
                }
            }

            // rev 108: optional client-chosen web address (validated lightly here;
            // final uniqueness is re-checked at provisioning with auto fallback).
            $slugReq = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($v['subdomain'] ?? '')));
            if ($slugReq !== '' && (strlen($slugReq) < 3 || strlen($slugReq) > 10)) {
                return response()->json(['ok' => false, 'error' => 'Your web address must be 3 to 10 letters/numbers (no spaces or symbols).'], 422);
            }

            $uuid = (string) Str::uuid();
            $token = Str::random(48);
            // rev 187 (Ejaz): quotation numbers follow the PRS format with a Q
            // marker — PRS-Q-<FY>-<MM>-<count>, consecutive through the FY
            // (own series; quotes are not tax documents).
            $qPrefix = 'PRS-Q-'.BillingController::finYear().'-';
            $qMax = 0;
            foreach (DB::table('signups')->where('quote_no', 'like', $qPrefix.'%')->pluck('quote_no') as $qn) {
                if (preg_match('/-(\d+)$/', (string) $qn, $qm)) {
                    $qMax = max($qMax, (int) $qm[1]);
                }
            }
            $quoteNo = $qPrefix.now()->format('m').'-'.str_pad((string) ($qMax + 1), 4, '0', STR_PAD_LEFT);
            $signupId = DB::table('signups')->insertGetId(ApprovalService::safeRow('signups', [
                'uuid' => $uuid, 'company' => $v['company'], 'admin_name' => $v['admin_name'],
                'admin_email' => $email, 'mobile' => $v['mobile'] ?? null,
                'gstin' => $gstin, 'state' => $v['state'], 'subdomain' => $slugReq ?: null,
                'plan_id' => $plan->id, 'seats' => (int) $v['seats'], 'companies' => $companies, 'cycle' => $v['cycle'],
                'amount' => $price['amount'], 'tax' => $price['tax'],
                'coupon_code' => $couponCode, 'coupon_discount' => $price['coupon_discount'] ?? 0,
                'status' => 'quoted', 'quote_token' => $token, 'quote_no' => $quoteNo, 'quoted_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]));

            // Email the quotation PDF + the public pay link (best-effort).
            $link = url('/quote/'.$token);
            try {
                $pdf = $this->buildQuotationPdf($signupId);
                $cycleLabel = ['quarterly' => 'Quarterly (3 months)', 'halfyear' => 'Half-yearly (6 months)', 'annual' => 'Annual (12 months)'][$v['cycle']] ?? $v['cycle'];
                \App\Services\MailService::queue([
                    'tenant_id' => null, 'kind' => 'quotation',
                    'to' => $email,
                    'subject' => 'Your SmartPRS quotation '.$quoteNo.' — '.$v['company'],
                    'heading' => 'SmartPRS quotation for '.$v['company'],
                    'intro' => 'Thank you for your interest in SmartPRS by Ametecs — the complete HR, payroll and field-force platform for India\'s collections & recovery industry. Please find your quotation attached. When approved, you (or your finance team) can complete payment securely and your workspace is created instantly using the button below.',
                    'lines' => array_filter([
                        'Quotation' => $quoteNo,
                        'Plan' => ($plan->name ?? 'Plan').' — all 16 modules',
                        'Employees' => (string) $v['seats'],
                        'Companies' => (string) $companies,
                        'Billing period' => $cycleLabel,
                        // rev 113: the discount is stated CLEARLY on the quotation.
                        'Discount (coupon '.($couponCode ?: '—').')' => $couponCode ? '− ₹'.number_format((float) ($price['coupon_discount'] ?? 0), 2) : null,
                        'Subscription amount' => '₹'.number_format($price['amount'], 2),
                        'GST (18%)' => '₹'.number_format($price['tax'], 2),
                        'Total payable' => '₹'.number_format($price['total'], 2),
                        'Valid until' => now()->addDays(self::QUOTE_VALID_DAYS)->format('d M Y'),
                    ]),
                    'cta_label' => 'Pay securely & create workspace',
                    'cta_url' => $link,
                    'attach_b64' => base64_encode($pdf->output()),
                    'attach_name' => $quoteNo.'.pdf',
                    'attach_mime' => 'application/pdf',
                    'sync' => true,       // rev 170: quotations must not wait on a queue worker
                    'platform' => true,   // rev 170: full Ametecs identity + contact footer
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Quotation email failed: '.$e->getMessage());
            }

            return response()->json([
                'ok' => true,
                'message' => 'Quotation '.$quoteNo.' has been emailed to '.$email.' with a secure payment link. Share it with your finance team — the workspace is created the moment payment is made.',
                'link' => $link, 'pdf' => url('/quote/'.$token.'/pdf'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 113: the price a quotation is LOCKED at. A coupon captured at quote
     * time (typed or exclusive auto-applied) stays on the quote — the signups
     * row holds the discounted amount/tax, which always win over a recompute.
     */
    private static function quoteLockedPrice(object $s, object $plan): array
    {
        $price = BillingController::priceFor($plan, (int) $s->seats, $s->cycle, max(1, (int) ($s->companies ?? 1)));
        $disc = (float) ($s->coupon_discount ?? 0);
        if ($disc > 0) {
            $price['amount_before_coupon'] = $price['amount'];
            $price['coupon_code'] = $s->coupon_code;
            $price['coupon_discount'] = $disc;
            $price['amount'] = (float) $s->amount;
            $price['tax'] = (float) $s->tax;
            $price['total'] = round($price['amount'] + $price['tax'], 2);
        }

        return $price;
    }

    /** Load a quote by its public token (must still be payable). */
    private function findQuote(string $token)
    {
        $this->ensure();

        return DB::table('signups')->where('quote_token', $token)->first();
    }

    /**
     * rev 186: the FIRST invoice of a quote's tenant (the signup invoice) plus
     * how much of it has actually been received. Partial state is always
     * COMPUTED from the payments ledger — never stored as a status.
     */
    private function quoteBalance(object $s): ?array
    {
        if (empty($s->tenant_id)) {
            return null;
        }
        $inv = DB::table('invoices')->where('tenant_id', $s->tenant_id)->orderBy('id')->first();
        if (! $inv) {
            return null;
        }
        $total = round((float) $inv->amount + (float) $inv->tax, 2);
        $paid = BillingController::invoicePaidSum((int) $inv->id);

        return ['inv' => $inv, 'total' => $total, 'paid' => $paid, 'balance' => max(0, round($total - $paid, 2))];
    }

    /** GET /quote/{token} — public quotation + pay page. */
    public function showQuote(string $token)
    {
        $s = $this->findQuote($token);
        if (! $s) {
            abort(404);
        }
        $plan = DB::table('plans')->where('id', $s->plan_id)->first();
        $price = self::quoteLockedPrice($s, $plan);   // rev 113: coupon locked into the quote
        $expired = $s->quoted_at ? \Illuminate\Support\Carbon::parse($s->quoted_at)->addDays(self::QUOTE_VALID_DAYS)->isPast() : false;

        // rev 186: credit-provisioned workspace → the page becomes a BALANCE
        // payment page (validity no longer matters; the workspace is live).
        $balance = null;
        if ($s->status === 'provisioned') {
            $b = $this->quoteBalance($s);
            if ($b && $b['balance'] > 0.01) {
                $balance = [
                    'total' => $b['total'], 'paid' => $b['paid'], 'balance' => $b['balance'],
                    'dueOn' => ! empty($b['inv']->due_on) ? \Illuminate\Support\Carbon::parse($b['inv']->due_on)->format('d M Y') : null,
                ];
            }
        }

        return view('quote-public', [
            's' => $s, 'plan' => $plan, 'price' => $price, 'token' => $token,
            'balance' => $balance,
            'expired' => $expired, 'paid' => $s->status === 'provisioned',
            'validUntil' => $s->quoted_at ? \Illuminate\Support\Carbon::parse($s->quoted_at)->addDays(self::QUOTE_VALID_DAYS)->format('d M Y') : '',
            'razorpayKey' => optional(BillingController::razorpayCreds())['key'] ?? null,
        ]);
    }

    /** GET /quote/{token}/pdf — stream the quotation PDF (public). */
    public function quotePdf(string $token)
    {
        $s = $this->findQuote($token);
        if (! $s) {
            abort(404);
        }
        $pdf = $this->buildQuotationPdf($s->id);

        return $pdf->stream(($s->quote_no ?: 'quotation').'.pdf');
    }

    /** POST /quote/{token}/order — create the Razorpay order for a quote. */
    public function quoteOrder(Request $request, string $token)
    {
        try {
            $s = $this->findQuote($token);
            if (! $s) {
                return response()->json(['ok' => false, 'error' => 'Quotation not found.'], 404);
            }
            if ($s->status === 'provisioned') {
                // rev 186 (Ejaz): a workspace provisioned ON CREDIT (admin payment
                // entry: partial / due) keeps this link alive so the client's
                // finance team can pay the remaining BALANCE online.
                $b = $this->quoteBalance($s);
                if (! $b || $b['balance'] <= 0.01) {
                    return response()->json(['ok' => false, 'error' => 'This quotation has already been paid.'], 422);
                }
                $creds = BillingController::razorpayCreds();
                if (! $creds) {
                    return response()->json(['ok' => false, 'error' => 'Online payment is not configured yet. Please write to sales@ametecsindia.com.'], 422);
                }
                $paise = (int) round($b['balance'] * 100);
                $resp = Http::withBasicAuth($creds['key'], $creds['secret'])->asForm()
                    ->post('https://api.razorpay.com/v1/orders', [
                        'amount' => $paise, 'currency' => 'INR', 'receipt' => 'QUOTE-'.$s->id.'-BAL',
                        'notes' => ['quote' => $s->quote_no, 'company' => $s->company, 'balance' => 'yes'],
                    ]);
                if (! $resp->successful()) {
                    return response()->json(['ok' => false, 'error' => 'Could not start the payment: '.$resp->body()], 422);
                }
                $orderId = $resp->json()['id'] ?? null;
                DB::table('signups')->where('id', $s->id)->update(['gateway_order_id' => $orderId, 'updated_at' => now()]);

                return response()->json(['ok' => true, 'orderId' => $orderId, 'keyId' => $creds['key'], 'amountPaise' => $paise, 'balance' => true]);
            }
            $plan = DB::table('plans')->where('id', $s->plan_id)->first();
            $price = self::quoteLockedPrice($s, $plan);   // rev 113: pay link honours the quoted (discounted) figure
            $paise = (int) round($price['total'] * 100);
            $creds = BillingController::razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Online payment is not configured yet. Please write to sales@ametecsindia.com.'], 422);
            }
            $resp = Http::withBasicAuth($creds['key'], $creds['secret'])->asForm()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $paise, 'currency' => 'INR', 'receipt' => 'QUOTE-'.$s->id,
                    'notes' => ['quote' => $s->quote_no, 'company' => $s->company],
                ]);
            if (! $resp->successful()) {
                return response()->json(['ok' => false, 'error' => 'Could not start the payment: '.$resp->body()], 422);
            }
            $orderId = $resp->json()['id'] ?? null;
            DB::table('signups')->where('id', $s->id)->update(['gateway_order_id' => $orderId, 'updated_at' => now()]);

            return response()->json(['ok' => true, 'orderId' => $orderId, 'keyId' => $creds['key'], 'amountPaise' => $paise]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /quote/{token}/complete — verify payment + provision. */
    public function quoteComplete(Request $request, string $token)
    {
        try {
            $s = $this->findQuote($token);
            if (! $s) {
                return response()->json(['ok' => false, 'error' => 'Quotation not found.'], 404);
            }
            if ($s->status === 'provisioned' && ($b = $this->quoteBalance($s)) && $b['balance'] > 0.01) {
                // rev 186: BALANCE payment on a credit-provisioned workspace —
                // verify the signature, then credit the invoice partial-aware.
                $v = $request->validate([
                    'razorpay_order_id' => ['required', 'string'],
                    'razorpay_payment_id' => ['required', 'string'],
                    'razorpay_signature' => ['required', 'string'],
                ]);
                if (! $s->gateway_order_id || $s->gateway_order_id !== $v['razorpay_order_id']) {
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
                // The amount actually charged = the Razorpay ORDER's amount (authoritative
                // even if an offline entry was recorded between order and completion).
                $amt = 0.0;
                try {
                    $ord = Http::withBasicAuth($creds['key'], $creds['secret'])
                        ->get('https://api.razorpay.com/v1/orders/'.$v['razorpay_order_id']);
                    if ($ord->successful()) {
                        $amt = round(((int) ($ord->json()['amount'] ?? 0)) / 100, 2);
                    }
                } catch (\Throwable $e) {
                }
                if ($amt <= 0) {
                    $amt = $b['balance'];
                }
                $res = BillingController::applyPayment($b['inv'], $amt, 'razorpay', 'razorpay', $v['razorpay_payment_id']);
                if ($res['settled']) {
                    try {
                        (new BillingController())->emailInvoice($b['inv']->id, 'invoice.receipt');
                    } catch (\Throwable $e) {
                    }
                }

                return response()->json(['ok' => true,
                    'message' => $res['settled']
                        ? 'Balance received — your subscription is now fully paid. Thank you! A receipt with the tax invoice has been emailed.'
                        : 'Payment received. Remaining balance: ₹'.number_format($res['balance'], 2).'.',
                    'redirect' => url('/login')]);
            }
            if ($s->status === 'provisioned') {
                return response()->json(['ok' => true, 'message' => 'Your workspace is ready — check your email for the set-password link.', 'redirect' => url('/login')]);
            }
            $v = $request->validate([
                'razorpay_order_id' => ['required', 'string'],
                'razorpay_payment_id' => ['required', 'string'],
                'razorpay_signature' => ['required', 'string'],
            ]);
            if (! $s->gateway_order_id || $s->gateway_order_id !== $v['razorpay_order_id']) {
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

            return $this->provisionPaidSignup($s, $request, $v['razorpay_order_id'], $v['razorpay_payment_id']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Build the branded quotation PDF (DomPDF). */
    private function buildQuotationPdf(int $signupId)
    {
        $s = DB::table('signups')->where('id', $signupId)->first();
        $plan = DB::table('plans')->where('id', $s->plan_id)->first();
        $companies = max(1, (int) ($s->companies ?? 1));
        $price = self::quoteLockedPrice($s, $plan);   // rev 113: discount shown clearly on the PDF
        $seller = (new BillingController())->publicSellerProfile();
        $intra = BillingController::buyerIsIntraState($s, $seller);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('quotation-pdf', [
            's' => $s, 'plan' => $plan, 'price' => $price, 'seller' => $seller,
            'companies' => $companies,
            'cycleLabel' => ['quarterly' => 'Quarterly (3 months)', 'halfyear' => 'Half-yearly (6 months · 10% off)', 'annual' => 'Annual (12 months · 25% off)'][$s->cycle] ?? $s->cycle,
            'intra' => $intra, 'cgst' => round($price['tax'] / 2, 2), 'igst' => $price['tax'],
            'validUntil' => $s->quoted_at ? \Illuminate\Support\Carbon::parse($s->quoted_at)->addDays(self::QUOTE_VALID_DAYS)->format('d M Y') : now()->addDays(self::QUOTE_VALID_DAYS)->format('d M Y'),
            'payLink' => url('/quote/'.$s->quote_token),
        ]);
    }

    /** GET /admin/quotations — super-admin quotation tracker. */
    public function quotations(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
        $this->ensure();
        $rows = DB::table('signups')->where('status', 'quoted')
            ->orderByDesc('id')->limit(300)->get();
        $planNames = DB::table('plans')->pluck('name', 'id');

        // rev 186: workspaces created ON CREDIT (admin payment entry) whose
        // balance is still outstanding — the Super Admin's follow-up list.
        $credit = [];
        try {
            $prov = DB::table('signups')->where('status', 'provisioned')
                ->whereNotNull('tenant_id')->whereNotNull('quote_no')
                ->orderByDesc('id')->limit(300)->get();
            foreach ($prov as $c) {
                $b = $this->quoteBalance($c);
                if (! $b || $b['balance'] <= 0.01) {
                    continue;
                }
                $c->inv_number = $b['inv']->number;
                $c->inv_total = $b['total'];
                $c->inv_paid = $b['paid'];
                $c->inv_balance = $b['balance'];
                $c->inv_due_on = $b['inv']->due_on;
                $credit[] = $c;
            }
        } catch (\Throwable $e) {
        }

        return view('admin.quotations', [
            'rows' => $rows, 'planNames' => $planNames, 'validDays' => self::QUOTE_VALID_DAYS,
            'credit' => $credit,
        ]);
    }

    // ======================================================================
    // rev 186 (Ejaz) — MANUAL PAYMENT ENTRY / CREDIT-PERIOD PROVISIONING.
    // Real business does not always pay online first: some clients get a
    // credit period. Super Admin records the payment against a quotation
    // (Paid / Partial / Due) and the workspace is created IMMEDIATELY —
    // same tenant + subscription + invoice + payments chain as the online
    // flow. The quote's public pay link stays alive for the balance.
    // ======================================================================

    /** POST /admin/quotations/{id}/payment — record payment (+ provision). */
    public function adminPayment(Request $request, int $id)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
        try {
            $this->ensure();
            $s = DB::table('signups')->where('id', $id)->first();
            if (! $s) {
                return response()->json(['ok' => false, 'error' => 'Quotation not found.'], 404);
            }

            // ---- BALANCE instalment on an already-created credit workspace ----
            if ($s->status === 'provisioned') {
                $v = $request->validate([
                    'amount' => ['required', 'numeric', 'min:0.01'],
                    'method' => ['required', 'string', 'max:40'],
                    'reference' => ['nullable', 'string', 'max:100'],
                ]);
                $b = $this->quoteBalance($s);
                if (! $b || $b['balance'] <= 0.01) {
                    return response()->json(['ok' => false, 'error' => 'Nothing due — this client is already fully paid.'], 422);
                }
                if ((float) $v['amount'] > $b['balance'] + 0.01) {
                    return response()->json(['ok' => false, 'error' => 'That is more than the outstanding balance of ₹'.number_format($b['balance'], 2).'.'], 422);
                }
                $txn = trim((string) ($v['reference'] ?? '')) ?: 'MAN-'.strtoupper(Str::random(10));
                $res = BillingController::applyPayment($b['inv'], (float) $v['amount'], 'manual', $v['method'], $txn);
                if ($res['settled']) {
                    try {
                        (new BillingController())->emailInvoice($b['inv']->id, 'invoice.receipt');
                    } catch (\Throwable $e) {
                    }
                }

                return response()->json(['ok' => true, 'settled' => $res['settled'], 'balance' => $res['balance'],
                    'message' => $res['settled']
                        ? 'Balance cleared — invoice '.$b['inv']->number.' is now fully PAID and the receipt was emailed.'
                        : 'Payment recorded against '.$b['inv']->number.'. Balance remaining: ₹'.number_format($res['balance'], 2).'.']);
            }

            if (! in_array($s->status, ['quoted', 'pending'], true)) {
                return response()->json(['ok' => false, 'error' => 'This signup is not open (status: '.$s->status.').'], 422);
            }

            // ---- FIRST payment entry → provision the workspace ----------------
            $v = $request->validate([
                'pay_status' => ['required', 'in:paid,partial,due'],
                'amount' => ['nullable', 'numeric', 'min:0'],
                'method' => ['required_unless:pay_status,due', 'nullable', 'string', 'max:40'],
                'reference' => ['nullable', 'string', 'max:100'],
                'due_date' => ['required_unless:pay_status,paid', 'nullable', 'date'],
            ], [
                'due_date.required_unless' => 'Please give the credit due date — when should the balance be paid by?',
                'method.required_unless' => 'Please pick how the money was received.',
            ]);
            $plan = DB::table('plans')->where('id', $s->plan_id)->first();
            if (! $plan) {
                return response()->json(['ok' => false, 'error' => 'Plan not found.'], 422);
            }
            $price = self::quoteLockedPrice($s, $plan);
            $total = round((float) $price['total'], 2);
            $received = $v['pay_status'] === 'paid' ? $total
                : ($v['pay_status'] === 'partial' ? round((float) ($v['amount'] ?? 0), 2) : 0.0);
            if ($v['pay_status'] === 'partial' && ($received <= 0 || $received >= $total)) {
                return response()->json(['ok' => false, 'error' => 'A partial payment must be above ₹0 and below the total ₹'.number_format($total, 2).' — use Paid (full) or Due otherwise.'], 422);
            }
            $dueDate = $v['pay_status'] === 'paid' ? null : \Illuminate\Support\Carbon::parse($v['due_date'])->toDateString();
            $reference = trim((string) ($v['reference'] ?? '')) ?: null;

            return $this->provisionOfflineSignup($s, $plan, $price, $v['pay_status'], $received, $v['method'] ?? 'credit', $reference, $dueDate);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev 186: provisioning for an ADMIN-recorded offline payment (paid /
     * partial / due-credit) — mirrors provisionPaidSignup minus Razorpay and
     * minus the browser auto-sign-in (the admin records it, not the client).
     */
    private function provisionOfflineSignup(object $s, object $plan, array $price, string $payStatus, float $received, string $method, ?string $reference, ?string $dueDate)
    {
        $sCompanies = max(1, (int) ($s->companies ?? 1));
        // Subscription keeps the FULL rate — a coupon only discounts the first
        // invoice (same rule as the online flow, rev 112).
        $full = BillingController::priceFor($plan, (int) $s->seats, $s->cycle, $sCompanies);
        $end = now()->addMonths($full['months']);
        $cycleLabel = ['quarterly' => 'Quarterly (3 months)', 'halfyear' => 'Half-yearly (6 months)', 'annual' => 'Annual (12 months)'][$s->cycle] ?? $s->cycle;
        $fmt = fn ($n) => '₹'.number_format((float) $n, 2);
        $total = round((float) $price['total'], 2);
        $balance = max(0, round($total - $received, 2));
        $couponDisc = (float) ($s->coupon_discount ?? 0);
        $dueLabel = $dueDate ? \Illuminate\Support\Carbon::parse($dueDate)->format('d M Y') : null;

        $paymentLines = [
            'Plan' => ($plan->name ?? 'Plan').' — includes up to '.($plan->seat_max ?? '—').' employees',
            'Employees' => $s->seats.' (total across all your companies)',
            'Companies' => $sCompanies.($sCompanies > 1 ? ' (1 included + '.($sCompanies - 1).' × ₹1,000/mo)' : ' (included)'),
            'Billing period' => $cycleLabel.($full['discount'] > 0 ? ' · '.($full['discount'] * 100).'% advance discount applied' : ''),
        ];
        if ($couponDisc > 0) {
            $paymentLines['Coupon ('.$s->coupon_code.')'] = '− '.$fmt($couponDisc);
        }
        $paymentLines += [
            'Subscription amount' => $fmt($price['amount']),
            'GST (18%)' => $fmt($price['tax']),
            'Total' => $fmt($total),
            'Amount received' => $fmt($received).($received > 0 ? ' ('.$method.($reference ? ' · '.$reference : '').')' : ''),
        ];
        if ($balance > 0) {
            $paymentLines['Balance payable'] = $fmt($balance).($dueLabel ? ' — by '.$dueLabel : '');
        }
        $paymentLines['Active until'] = $end->format('d M Y');

        // 1) Tenant + first company + admin (welcome email with credentials).
        $res = SaasController::provisionTenantRecord([
            'name' => $s->company, 'company_name' => $s->company,
            'admin_name' => $s->admin_name, 'admin_email' => $s->admin_email,
            'plan_id' => $s->plan_id, 'seats_licensed' => $s->seats,
            'gstin' => $s->gstin ?? null, 'state' => $s->state ?? null,
            'subdomain' => $s->subdomain ?? null,
            'email_credentials' => true,
            'extra_lines' => $paymentLines,
        ]);
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'error' => $res['error']], 422);
        }
        $tid = (int) $res['tenant_id'];

        // 2) Subscription at the full rate + tenant MRR (same as online flow).
        DB::table('subscriptions')->insert(ApprovalService::safeRow('subscriptions', [
            'tenant_id' => $tid, 'plan_id' => $s->plan_id, 'seats' => $s->seats,
            'companies' => $sCompanies,
            'cycle' => $s->cycle, 'amount' => $full['amount'], 'status' => 'active',
            'current_period_end' => $end->toDateString(), 'next_renewal' => $end->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        DB::table('tenants')->where('id', $tid)->update(ApprovalService::safeRow('tenants', [
            'mrr' => round($full['amount'] / max(1, $full['months']), 2), 'updated_at' => now(),
        ]));

        // 3) Invoice at the QUOTED (possibly coupon-discounted) figure; the
        //    credit due date becomes the invoice's due date.
        $inv = BillingController::createInvoiceForTenant($tid);
        $invPatch = ['updated_at' => now()];
        if ($couponDisc > 0) {
            $invPatch['amount'] = (float) $s->amount;
            $invPatch['tax'] = (float) $s->tax;
        }
        if ($dueDate) {
            $invPatch['due_on'] = $dueDate;
        }
        DB::table('invoices')->where('id', $inv->id)->update(ApprovalService::safeRow('invoices', $invPatch));
        $inv = DB::table('invoices')->where('id', $inv->id)->first();

        // 4) The payment entry (if any money came in) — partial-aware.
        if ($received > 0) {
            $txn = $reference ?: 'MAN-'.strtoupper(Str::random(10));
            BillingController::applyPayment($inv, $received, 'manual', $method, $txn);
            $inv = DB::table('invoices')->where('id', $inv->id)->first();
        }

        // Coupon redemption — the quote locked it in; honour it now.
        if ($couponDisc > 0 && ! empty($s->coupon_code)) {
            try {
                $cpn = DB::table('coupons')->where('code', $s->coupon_code)->first();
                if ($cpn) {
                    \App\Services\CouponService::redeem($cpn, 'signup', $couponDisc, $s->admin_email, $tid, $s->company);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('offline signup coupon redeem: '.$e->getMessage());
            }
        }

        // Invoice email: fully paid → receipt; credit → issued invoice w/ due date.
        try {
            (new BillingController())->emailInvoice($inv->id, $inv->status === 'paid' ? 'invoice.receipt' : 'invoice.issued');
        } catch (\Throwable $e) {
            // best-effort — the workspace is already live
        }

        DB::table('signups')->where('id', $s->id)->update([
            'status' => 'provisioned', 'tenant_id' => $tid, 'updated_at' => now(),
        ]);

        // rev 171 (Ejaz): sign-in pointers use the BRANDED company page.
        $slug = DB::table('tenants')->where('id', $tid)->value('subdomain');
        $loginUrl = $slug ? url('/c/'.$slug) : url('/login');

        // WhatsApp welcome via Interakt (best-effort; never blocks provisioning).
        try {
            if (! empty($s->mobile)) {
                $sendPw = filter_var(env('SMARTPRS_WA_SEND_PASSWORD', true), FILTER_VALIDATE_BOOLEAN);
                \App\Services\WaService::sendTemplate([
                    'tenant_id' => $tid,
                    'mobile' => $s->mobile,
                    'kind' => 'signup.welcome',
                    'template' => \App\Services\WaService::templateNameFor('welcome'),
                    'bodyValues' => [
                        $s->admin_name, $s->company, $loginUrl, $s->admin_email,
                        $sendPw ? (string) ($res['temp_password'] ?? '') : 'sent to your email',
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            // WhatsApp is best-effort
        }

        $label = $payStatus === 'paid'
            ? 'fully PAID'
            : ($payStatus === 'partial'
                ? 'PARTIALLY paid — balance '.$fmt($balance).($dueLabel ? ' due by '.$dueLabel : '')
                : 'on CREDIT — '.$fmt($balance).($dueLabel ? ' due by '.$dueLabel : ''));

        return response()->json([
            'ok' => true,
            'message' => 'Workspace created for '.$s->company.' ('.$label.'). Invoice '.$inv->number.' recorded; welcome email with sign-in details sent to '.$s->admin_email.'.',
        ]);
    }
}
