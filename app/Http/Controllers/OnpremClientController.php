<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * rev 107 — ON-PREM CLIENTS module in the SaaS admin panel (SRS FR-11).
 *
 * The perpetual-licence sales desk: client record → payments (manual entry;
 * gateway payments can be added later) → THE GATE (full payment = key
 * auto-eligible; partial = only with the "Activate on partial payment" tick,
 * Q2 default) → key generation with AMC expiry → key email to the client.
 * AMC renewals extend licences from here too.
 */
class OnpremClientController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    /** rev 107b: invoice + online-payment columns (Schema-guard convention). */
    public static function ensureSaleCols(): void
    {
        try {
            foreach ([
                'invoice_no' => fn ($t) => $t->string('invoice_no', 30)->nullable(),
                'invoice_token' => fn ($t) => $t->string('invoice_token', 64)->nullable(),
                'gateway_order_id' => fn ($t) => $t->string('gateway_order_id', 64)->nullable(),
                // rev140 — Super-Admin-set licence validity (drives amc_expires_on
                // at key generation, which the on-prem LC login gate enforces).
                'licence_term_months' => fn ($t) => $t->integer('licence_term_months')->nullable(),
                'licence_expires_on' => fn ($t) => $t->date('licence_expires_on')->nullable(),
                // rev141 — what the client sees at login once the licence expires:
                // 'renew' = the License Code field reappears; 'notify' = an
                // "LC Expired" notice only (renewal handled by Ametecs).
                'expiry_mode' => fn ($t) => $t->string('expiry_mode', 12)->default('renew'),
            ] as $col => $def) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('onprem_clients', $col)) {
                    \Illuminate\Support\Facades\Schema::table('onprem_clients', $def);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** GST-inclusive total: licence price + 18% (CGST+SGST or IGST by state). */
    public static function totals(object $c): array
    {
        $price = (float) $c->price;
        $tax = round($price * 0.18, 2);
        $seller = (new BillingController)->publicSellerProfile();
        $intra = false;
        try {
            $intra = BillingController::buyerIsIntraState($c, $seller);
        } catch (\Throwable $e) {
        }

        return [
            'price' => $price, 'tax' => $tax, 'total' => round($price + $tax, 2),
            'intra' => $intra, 'seller' => $seller,
            'balance' => max(0, round($price + $tax - (float) $c->paid_total, 2)),
        ];
    }

    /** A client is fully paid when payments cover price + GST. */
    public static function fullyPaid(object $c): bool
    {
        return $c->price > 0 && (float) $c->paid_total >= self::totals($c)['total'];
    }

    /**
     * rev140 — the licence expiry date the Super Admin chose for this client.
     * Priority: an explicit expiry date (if set and not already past) → a
     * duration in months from today → a one-year default (back-compat).
     * This becomes licences.amc_expires_on, which the on-prem login LC gate
     * reads to decide access.
     */
    public static function resolveExpiry(object $c): string
    {
        $today = now()->toDateString();
        $exp = $c->licence_expires_on ?? null;
        if ($exp) {
            $d = substr((string) $exp, 0, 10);
            if ($d >= $today) {
                return $d;
            }
        }
        $months = (int) ($c->licence_term_months ?? 0);
        if ($months > 0) {
            return now()->addMonths($months)->toDateString();
        }

        return now()->addYear()->toDateString();
    }

    public function index(Request $request)
    {
        $this->guard($request);
        LicenseService::ensureTables();
        self::ensureSaleCols();
        // rev168 — search across company / contact / email / mobile / GSTIN.
        $q = trim((string) $request->query('q', ''));
        $clients = DB::table('onprem_clients')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($w) use ($like) {
                    $w->where('company', 'like', $like)
                        ->orWhere('contact_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('gstin', 'like', $like);
                });
            })
            ->orderByDesc('id')->limit(300)->get();
        $licences = DB::table('licences')->orderByDesc('id')->get()->groupBy('client_id');
        $payments = DB::table('onprem_payments')->orderByDesc('id')->get()->groupBy('client_id');

        return view('admin.onprem', [
            'clients' => $clients, 'licences' => $licences, 'payments' => $payments,
            'revealId' => (int) $request->query('reveal', 0),
            'q' => $q,
        ]);
    }

    public function save(Request $request)
    {
        $this->guard($request);
        LicenseService::ensureTables();
        self::ensureSaleCols();
        $v = $request->validate([
            'id' => ['nullable', 'integer'],
            'company' => ['required', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:2000'],
            'edition' => ['required', 'in:l1,l2,l3'],
            'employee_band' => ['nullable', 'string', 'max:30'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'amc_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // rev140 — how long the client may use the app: a duration in
            // months, OR an exact expiry date (the date wins if both given).
            'licence_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'licence_expires_on' => ['nullable', 'date'],
            'expiry_mode' => ['nullable', 'in:renew,notify'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        if (empty($v['expiry_mode'])) {
            $v['expiry_mode'] = 'renew';
        }
        $row = $v;
        unset($row['id']);
        $row['gstin'] = strtoupper(trim((string) ($row['gstin'] ?? ''))) ?: null;
        $row['updated_at'] = now();
        if (! empty($v['id'])) {
            DB::table('onprem_clients')->where('id', $v['id'])->update($row);
            $id = (int) $v['id'];
        } else {
            $row['created_at'] = now();
            $id = DB::table('onprem_clients')->insertGetId($row);
        }

        // rev141 — keep a live licence's expiry behaviour in step with the
        // client setting, so changing it takes effect on the next sync.
        try {
            DB::table('licences')->where('client_id', $id)
                ->whereIn('status', ['pending', 'active'])
                ->update(['expiry_mode' => $v['expiry_mode'], 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }

        return redirect()->route('admin.onprem')->with('success', 'Client saved (#'.$id.').');
    }

    /** Record a payment (manual: NEFT/cheque/UPI/cash; gateway later). */
    public function payment(Request $request, int $id)
    {
        $this->guard($request);
        $v = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'mode' => ['required', 'in:neft,cheque,upi,gateway,cash'],
            'reference' => ['nullable', 'string', 'max:190'],
            'paid_on' => ['nullable', 'date'],
        ]);
        abort_unless(DB::table('onprem_clients')->where('id', $id)->exists(), 404);
        DB::table('onprem_payments')->insert([
            'client_id' => $id, 'amount' => $v['amount'], 'mode' => $v['mode'],
            'reference' => $v['reference'] ?? null, 'paid_on' => $v['paid_on'] ?? now()->toDateString(),
            'entered_by' => $request->user()->name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('onprem_clients')->where('id', $id)->update([
            'paid_total' => (float) DB::table('onprem_payments')->where('client_id', $id)->sum('amount'),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.onprem')->with('success', 'Payment recorded.');
    }

    /** The Q2 tick — allow key generation on partial payment (recorded). */
    public function partialToggle(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        DB::table('onprem_clients')->where('id', $id)->update([
            'activate_on_partial' => $c->activate_on_partial ? 0 : 1,
            'notes' => trim(($c->notes ?? '')."\n".now()->format('d M Y').': Activate-on-partial '.($c->activate_on_partial ? 'OFF' : 'ON').' by '.$request->user()->name),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.onprem')->with('success', 'Partial-payment activation '.($c->activate_on_partial ? 'disabled' : 'enabled').'.');
    }

    /** Generate the licence key (THE gate) + email it to the client. */
    public function issueKey(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        if (DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->exists()) {
            return redirect()->route('admin.onprem')->with('success', 'This client already has a live licence — revoke it first if you really need a new key.');
        }
        self::ensureSaleCols();
        $fullyPaid = self::fullyPaid($c);
        if (! $fullyPaid && ! $c->activate_on_partial) {
            return redirect()->route('admin.onprem')->with('success', 'Key NOT generated: payment is partial (₹'.number_format((float) $c->paid_total).' of ₹'.number_format(self::totals($c)['total']).' incl. GST). Tick "Activate on partial payment" if you approve.');
        }

        $amcExpiry = self::resolveExpiry($c);   // rev140 — Super-Admin-chosen validity
        $key = LicenseService::issue($id, $c->edition, $amcExpiry, $c->expiry_mode ?? 'renew');

        // Email the key + activation steps (fail-soft; key stays visible in panel).
        try {
            if ($c->email) {
                \App\Services\MailService::queue([
                    'tenant_id' => null,
                    'to' => $c->email,
                    'subject' => 'Your SmartPRS-'.strtoupper($c->edition).' licence key',
                    'heading' => 'Welcome to SmartPRS, '.($c->contact_name ?: $c->company).'!',
                    'intro' => 'Your perpetual licence is ready. Keep this key safe — it activates your installation.',
                    'lines' => [
                        'Licence key: '.$key,
                        'Edition: SmartPRS-'.strtoupper($c->edition),
                        'AMC (updates & support) valid till: '.$amcExpiry,
                        'Activation: open SmartPRS on your server, sign in as admin, and enter this key on the activation screen.',
                        'Help: ejaz@ametecsindia.com · WhatsApp 9000098877',
                    ],
                    'kind' => 'licence_key',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return redirect()->route('admin.onprem', ['reveal' => $id])->with('success', 'Licence key generated'.($c->email ? ' and emailed to '.$c->email : '').'. It is shown below — note it in the installation record.');
    }

    /**
     * POST /admin/onprem/{id}/offline-key — rev146. Build a SELF-CONTAINED,
     * signed offline License Code from the client's edition + validity + expiry
     * mode. No server round-trip and no DB licence row: the client verifies it
     * locally. Regenerate any time (e.g. after changing the validity) to extend.
     */
    public function offlineKey(Request $request, int $id)
    {
        $this->guard($request);
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        $expiry = self::resolveExpiry($c);
        // rev147 — optional device lock: MAC / serial / UUID / GUID, any
        // separator. Left blank = the key works on any machine.
        $hwRaw = (string) $request->input('hardware', '');
        $hwLocks = preg_split('/[\s,;]+/', trim($hwRaw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // rev167 — optional EMAIL lock: defaults to the client's registered email
        // so the install activates only where SMARTPRS_LICENCE_EMAIL matches.
        // Clear the field to issue an email-agnostic code.
        $emailLock = trim((string) $request->input('email', $c->email ?? ''));
        $key = LicenseService::makeOfflineKey($c->edition, $expiry, $c->expiry_mode ?? 'renew', $c->company, $hwLocks, $emailLock);
        $lockBits = [];
        if ($emailLock !== '') { $lockBits[] = 'email '.$emailLock; }
        if ($hwLocks) { $lockBits[] = 'device '.implode(', ', $hwLocks); }
        $lockNote = $lockBits ? ' Locked to '.implode(' + ', $lockBits).'.' : '';
        // rev147 — record it so it can be REVOKED later (hybrid revocation).
        LicenseService::recordOffline($id, $c->edition, $expiry, $c->expiry_mode ?? 'renew', $key);

        try {
            if ($c->email) {
                \App\Services\MailService::queue([
                    'tenant_id' => null,
                    'to' => $c->email,
                    'subject' => 'Your SmartPRS-'.strtoupper($c->edition).' License Code',
                    'heading' => 'SmartPRS activation code for '.($c->contact_name ?: $c->company),
                    'intro' => 'Enter this License Code on the SmartPRS login screen to activate your installation. It works offline — no internet needed.',
                    'lines' => [
                        'License Code: '.$key,
                        'Edition: SmartPRS-'.strtoupper($c->edition),
                        'Valid till: '.$expiry,
                        'Help: ejaz@ametecsindia.com · WhatsApp 9000098877',
                    ],
                    'kind' => 'licence_key',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return redirect()->route('admin.onprem')
            ->with('offline_key', $key)
            ->with('offline_key_id', $id)
            ->with('success', 'Offline License Code generated for '.$c->company.' (valid till '.$expiry.')'.$lockNote.($c->email ? ' and emailed to '.$c->email : '').'.');
    }

    /**
     * POST /admin/onprem/{id}/lic-file — AS-DL. Build and DOWNLOAD an RSA-signed,
     * node-locked offline .lic for the client, bound to the machine fingerprint
     * shown on that install's Activation screen. The product verifies it locally
     * with the embedded public key (App\Services\LicenseFile); the private key
     * never leaves this /super server. Recorded so it can be revoked later.
     */
    public function licFile(Request $request, int $id)
    {
        $this->guard($request);
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);

        $signer = new \App\Services\LicenseSigner();
        if (! $signer->available()) {
            return redirect()->route('admin.onprem')->with('success', 'Cannot generate .lic: the signing key is missing on this server. Run  php artisan smartprs:make-license-keys  once, then try again.');
        }

        $fingerprint = trim((string) $request->input('fingerprint', ''));
        $seats = (int) $request->input('seats', 0);
        $expiry = self::resolveExpiry($c);

        // Reuse the client's live licence key if present, else mint a fresh one so
        // the .lic carries a stable identifier for records + revocation.
        $live = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        $key = ($live ? LicenseService::reveal($live) : null) ?: LicenseService::generateKey();

        $token = $signer->sign([
            'key'          => $key,
            'company'      => $c->company,
            'edition'      => $c->edition,
            'device_limit' => $seats > 0 ? $seats : null,
            'kind'         => 'perpetual',
            'deployment'   => 'onprem',
            'expires_at'   => $expiry,
            'grace_days'   => 7,
            'features'     => [],
            'fingerprint'  => $fingerprint,
        ]);

        // Record it (revocable + visible in the panel). The token itself carries
        // the fingerprint + seat cap, so no schema change is needed here.
        LicenseService::recordOffline($id, $c->edition, $expiry, $c->expiry_mode ?? 'renew', $token);

        // Email a copy too (fail-soft) so the client has it even if the download
        // is misplaced. The .lic is safe to email — it is useless on any other PC.
        try {
            if ($c->email) {
                \App\Services\MailService::queue([
                    'tenant_id' => null,
                    'to' => $c->email,
                    'subject' => 'Your SmartPRS-'.strtoupper($c->edition).' licence file (.lic)',
                    'heading' => 'SmartPRS licence file for '.($c->contact_name ?: $c->company),
                    'intro' => 'Save the attached code as license.lic (or paste it) on the SmartPRS Activation screen. It works fully offline and is locked to your server.',
                    'lines' => [
                        'Licence key: '.$key,
                        'Edition: SmartPRS-'.strtoupper($c->edition),
                        'Seats: '.($seats > 0 ? $seats : 'unlimited'),
                        'Valid till: '.$expiry,
                        'Licence code:',
                        $token,
                        'Help: ejaz@ametecsindia.com · WhatsApp 9000098877',
                    ],
                    'kind' => 'licence_key',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return response($token."\n", 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$signer->filename($key).'"',
        ]);
    }

    /**
     * GET /admin/onprem/{id}/lic-file — re-download the LAST generated .lic
     * exactly as issued (Ejaz, 6 Aug 2026: a generated licence must be STORED
     * and retrievable, not a one-shot download). No new storage was needed:
     * licFile() has always saved the full token encrypted in the licences row
     * (key_enc, via LicenseService::recordOffline) — this simply decrypts and
     * streams it back, so it also works on a /super with no signing key.
     */
    public function licDownload(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        $live = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        $token = $live ? LicenseService::reveal($live) : null;
        if (! $token || ! ClientUpdateController::looksLikeLicenceFile($token)) {
            return redirect()->route('admin.onprem')->with('success', 'No stored .lic for '.$c->company.' yet — use "Generate .lic file" once; every generated file is stored here and can be re-downloaded afterwards.');
        }
        $key = 'license';
        try {
            $p = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
            if (is_array($p) && ! empty($p['key'])) {
                $key = (string) $p['key'];
            }
        } catch (\Throwable $e) {
        }

        return response($token."\n", 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.(new \App\Services\LicenseSigner())->filename($key).'"',
        ]);
    }

    // ---------- rev 107b: invoice + online payment link (SRS FR-11 steps 2-3) ----------

    /** POST /admin/onprem/{id}/invoice — assign number + email PDF + pay link. */
    public function invoice(Request $request, int $id)
    {
        $this->guard($request);
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        if ($c->price <= 0) {
            return redirect()->route('admin.onprem')->with('success', 'Set the licence price first — the invoice needs an amount.');
        }
        $upd = [];
        if (! $c->invoice_no) {
            // rev 187 (Ejaz): PRS-<FY>-<MM>-<count> — one consecutive series
            // through the financial year, SHARED with the SaaS invoices.
            $upd['invoice_no'] = BillingController::nextInvoiceNumber();
        }
        if (! $c->invoice_token) {
            $upd['invoice_token'] = Str::random(40);
        }
        if ($upd) {
            $upd['updated_at'] = now();
            DB::table('onprem_clients')->where('id', $id)->update($upd);
            $c = DB::table('onprem_clients')->where('id', $id)->first();
        }
        $t = self::totals($c);
        $payUrl = url('/licence/'.$c->invoice_token);
        try {
            if ($c->email) {
                \App\Services\MailService::queue([
                    'tenant_id' => null,
                    'to' => $c->email,
                    'subject' => 'SmartPRS licence invoice '.$c->invoice_no.' — '.$c->company,
                    'heading' => 'Your SmartPRS-'.strtoupper($c->edition).' licence invoice',
                    'intro' => 'Thank you for choosing SmartPRS. Your tax invoice is attached; you can pay securely online with the button below.',
                    'lines' => [
                        'Invoice: '.$c->invoice_no,
                        'Licence: SmartPRS-'.strtoupper($c->edition).' (perpetual)'.($c->employee_band ? ' · '.$c->employee_band : ''),
                        'Amount: ₹'.number_format($t['price'], 2).' + GST ₹'.number_format($t['tax'], 2).' = ₹'.number_format($t['total'], 2),
                        'Balance due: ₹'.number_format($t['balance'], 2),
                        'On full payment your licence key is generated and emailed automatically.',
                    ],
                    'cta_label' => 'Pay securely online',
                    'cta_url' => $payUrl,
                    'attach_b64' => base64_encode($this->buildInvoicePdf($c)->output()),
                    'attach_name' => $c->invoice_no.'.pdf',
                    'attach_mime' => 'application/pdf',
                    'kind' => 'licence_invoice',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return redirect()->route('admin.onprem')->with('success', 'Invoice '.($upd['invoice_no'] ?? $c->invoice_no).' emailed'.($c->email ? ' to '.$c->email : '').'. Pay link: '.$payUrl);
    }

    private function buildInvoicePdf(object $c)
    {
        $t = self::totals($c);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('onprem-invoice-pdf', ['c' => $c, 't' => $t])
            ->setPaper('a4');
    }

    /** GET /licence/{token}/pdf — public invoice PDF (token-secured). */
    public function invoicePdf(string $token)
    {
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
        abort_unless($c && $c->invoice_no, 404);

        return $this->buildInvoicePdf($c)->stream($c->invoice_no.'.pdf');
    }

    /** GET /licence/{token} — public pay page (Razorpay, balance due). */
    public function payShow(string $token)
    {
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
        abort_unless($c, 404);

        return view('licence-pay', ['c' => $c, 't' => self::totals($c), 'token' => $token]);
    }

    /** POST /licence/{token}/order — Razorpay order for the balance. */
    public function payOrder(Request $request, string $token)
    {
        try {
            self::ensureSaleCols();
            $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found.'], 404);
            }
            $t = self::totals($c);
            if ($t['balance'] <= 0) {
                return response()->json(['ok' => false, 'error' => 'This invoice is already fully paid — thank you!'], 422);
            }
            $creds = BillingController::razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Online payment is not configured yet. Please pay by NEFT and share the reference with Ametecs.'], 422);
            }
            $paise = (int) round($t['balance'] * 100);
            $resp = Http::withBasicAuth($creds['key'], $creds['secret'])->asForm()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $paise, 'currency' => 'INR', 'receipt' => 'LIC-'.$c->id,
                    'notes' => ['invoice' => $c->invoice_no, 'company' => $c->company],
                ]);
            if (! $resp->successful()) {
                return response()->json(['ok' => false, 'error' => 'Could not start the payment: '.$resp->body()], 422);
            }
            $orderId = $resp->json()['id'] ?? null;
            DB::table('onprem_clients')->where('id', $c->id)->update(['gateway_order_id' => $orderId, 'updated_at' => now()]);

            return response()->json(['ok' => true, 'orderId' => $orderId, 'keyId' => $creds['key'], 'amountPaise' => $paise]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /licence/{token}/complete — verify, record, auto-issue key when full. */
    public function payComplete(Request $request, string $token)
    {
        try {
            self::ensureSaleCols();
            $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found.'], 404);
            }
            $v = $request->validate([
                'razorpay_order_id' => ['required', 'string'],
                'razorpay_payment_id' => ['required', 'string'],
                'razorpay_signature' => ['required', 'string'],
            ]);
            if (! $c->gateway_order_id || $c->gateway_order_id !== $v['razorpay_order_id']) {
                return response()->json(['ok' => false, 'error' => 'Payment/order mismatch.'], 422);
            }
            $creds = BillingController::razorpayCreds();
            $expected = hash_hmac('sha256', $v['razorpay_order_id'].'|'.$v['razorpay_payment_id'], $creds['secret'] ?? '');
            if (! $creds || ! hash_equals($expected, $v['razorpay_signature'])) {
                return response()->json(['ok' => false, 'error' => 'Payment signature verification failed.'], 422);
            }
            // Idempotent on the payment id.
            if (! DB::table('onprem_payments')->where('client_id', $c->id)->where('reference', $v['razorpay_payment_id'])->exists()) {
                $t = self::totals($c);
                DB::table('onprem_payments')->insert([
                    'client_id' => $c->id, 'amount' => $t['balance'], 'mode' => 'gateway',
                    'reference' => $v['razorpay_payment_id'], 'paid_on' => now()->toDateString(),
                    'entered_by' => 'Razorpay', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('onprem_clients')->where('id', $c->id)->update([
                    'paid_total' => (float) DB::table('onprem_payments')->where('client_id', $c->id)->sum('amount'),
                    'updated_at' => now(),
                ]);
                $c = DB::table('onprem_clients')->where('id', $c->id)->first();
            }
            // Full payment → the key generates and emails itself (FR-11 step 4).
            $keyMsg = '';
            if (self::fullyPaid($c) && ! DB::table('licences')->where('client_id', $c->id)->whereIn('status', ['pending', 'active'])->exists()) {
                $key = LicenseService::issue($c->id, $c->edition, self::resolveExpiry($c), $c->expiry_mode ?? 'renew');
                $keyMsg = ' Your licence key has been emailed to '.($c->email ?: 'your registered address').'.';
                try {
                    if ($c->email) {
                        \App\Services\MailService::queue([
                            'tenant_id' => null,
                            'to' => $c->email,
                            'subject' => 'Payment received — your SmartPRS-'.strtoupper($c->edition).' licence key',
                            'heading' => 'Payment received with thanks, '.($c->contact_name ?: $c->company).'!',
                            'intro' => 'Your perpetual licence is ready. Keep this key safe — it activates your installation.',
                            'lines' => [
                                'Licence key: '.$key,
                                'Edition: SmartPRS-'.strtoupper($c->edition),
                                'AMC (updates & support) valid till: '.now()->addYear()->toDateString(),
                                'Activation: open SmartPRS on your server, sign in as admin, and enter this key.',
                                'Help: ejaz@ametecsindia.com · WhatsApp 9000098877',
                            ],
                            'kind' => 'licence_key',
                        ]);
                    }
                } catch (\Throwable $e) {
                }
            }

            return response()->json(['ok' => true, 'message' => 'Payment received — thank you!'.$keyMsg]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Extend the licence validity. rev140 — the Super Admin may set an exact
     * new expiry date, or a number of months; otherwise it honours the
     * client's configured term, falling back to +1 year. Term-based renewals
     * extend from the current expiry (or today, whichever is later).
     */
    public function renewAmc(Request $request, int $id)
    {
        $this->guard($request);
        $lic = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        abort_unless($lic, 404, 'No live licence for this client.');
        $today = now()->toDateString();
        $until = trim((string) $request->input('renew_until', ''));
        $months = (int) $request->input('renew_months', 0);
        if ($until !== '' && substr($until, 0, 10) >= $today) {
            $new = substr($until, 0, 10);
        } else {
            $c = DB::table('onprem_clients')->where('id', $id)->first();
            $m = $months > 0 ? $months : (int) ($c->licence_term_months ?? 0);
            $base = max((string) $lic->amc_expires_on, $today);
            $new = $m > 0
                ? \Carbon\Carbon::parse($base)->addMonths($m)->toDateString()
                : \Carbon\Carbon::parse($base)->addYear()->toDateString();
        }
        DB::table('licences')->where('id', $lic->id)->update(['amc_expires_on' => $new, 'updated_at' => now()]);
        LicenseService::event($lic->id, 'amc_renewed', 'Licence extended to '.$new.' by '.$request->user()->name);

        return redirect()->route('admin.onprem')->with('success', 'Licence renewed till '.$new.'.');
    }

    /** Release the server binding so the client can re-activate after a move (Q5). */
    public function deactivate(Request $request, int $id)
    {
        $this->guard($request);
        $lic = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        abort_unless($lic, 404);
        DB::table('licences')->where('id', $lic->id)->update([
            'fingerprint' => null, 'server_name' => null,
            'reactivations_used' => (int) $lic->reactivations_used + 1,
            'updated_at' => now(),
        ]);
        LicenseService::event($lic->id, 'deactivated', 'Server binding released by '.$request->user()->name.' (move #'.((int) $lic->reactivations_used + 1).')');

        return redirect()->route('admin.onprem')->with('success', 'Server binding released — the client can activate on the new server now.');
    }

    /** Revoke (fraud/non-payment). Per Q4: blocks activation + updates, never locks the running app. */
    public function revoke(Request $request, int $id)
    {
        $this->guard($request);
        $lic = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        abort_unless($lic, 404);
        DB::table('licences')->where('id', $lic->id)->update(['status' => 'revoked', 'updated_at' => now()]);
        LicenseService::event($lic->id, 'revoked', 'Revoked by '.$request->user()->name);

        return redirect()->route('admin.onprem')->with('success', 'Licence revoked — activation and updates are blocked for it.');
    }

    /**
     * rev168 — DELETE a client and all its local licence/payment records. This
     * removes the sales/licence bookkeeping row on THIS panel; it does not reach
     * the client's installed server. Any offline code already issued keeps
     * working until it expires unless you REVOKE it first (revoke, then delete).
     */
    public function destroy(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        try {
            $licIds = DB::table('licences')->where('client_id', $id)->pluck('id')->all();
            if ($licIds) {
                DB::table('licence_events')->whereIn('licence_id', $licIds)->delete();
            }
            DB::table('licences')->where('client_id', $id)->delete();
            DB::table('onprem_payments')->where('client_id', $id)->delete();
            DB::table('onprem_clients')->where('id', $id)->delete();
        } catch (\Throwable $e) {
            return redirect()->route('admin.onprem')->with('success', 'Could not delete this client: '.$e->getMessage());
        }

        return redirect()->route('admin.onprem')->with('success', 'Client "'.$c->company.'" and its licence records were deleted.');
    }
}
