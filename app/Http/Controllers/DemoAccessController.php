<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev 97 — PUBLIC LIVE DEMO. rev185 (Ejaz, 16 Jul 2026) — the whole demo family
 * (/demo, /app1, /app2, /app3, /teamdemo) is now PASSKEY-GATED:
 *
 *   1. Visitor submits Name + Phone + Email ("Submit request").
 *   2. A passkey (6-digit PIN) is generated AUTOMATICALLY and sent to their
 *      email + WhatsApp; every request also lands in the Super Admin panel's
 *      "Demo Requests" register (resend / revoke / validity setting there).
 *   3. The visitor enters the passkey in the box below the form and is signed
 *      in to the shared demo workspace.
 *   4. The passkey is valid for N hours (Super Admin setting, default 2). When
 *      the window ends the session is logged out (boot-JS timer + server check)
 *      and the entry page asks for a fresh request.
 *   5. Whatever the visitor entered is ERASED: `demo:reset --if-due` (every 15
 *      minutes) wipes + reseeds the workspace once the window has ended and
 *      nobody with a live passkey is inside. A daily 03:30 full reset backstops.
 *
 * The old instant-OTP flow and the team PIN (config smartprs.team_pin) are gone.
 * Outgoing email + WhatsApp from the demo tenant itself stay MUTED (isDemoTenant).
 */
class DemoAccessController extends Controller
{
    /** Cached per-request: is this tenant the public demo workspace? */
    private static $demoTid = false;   // false = not looked up yet; null = no demo tenant

    public static function demoTenantId(): ?int
    {
        if (self::$demoTid === false) {
            try {
                self::$demoTid = Schema::hasTable('tenants')
                    ? (DB::table('tenants')->where('subdomain', 'demo')->whereNull('deleted_at')->value('id') ?: null)
                    : null;
            } catch (\Throwable $e) {
                self::$demoTid = null;
            }
        }

        return self::$demoTid;
    }

    public static function isDemoTenant($tenantId): bool
    {
        if (! $tenantId) {
            return false;
        }
        $demo = self::demoTenantId();

        return $demo !== null && (int) $tenantId === (int) $demo;
    }

    /* ----------------------------------------------------------------------
     |  rev185 — the passkey gate
     * -------------------------------------------------------------------- */

    /** Self-create the demo_requests register (no migration needed). */
    private static function ensureGate(): void
    {
        if (Schema::hasTable('demo_requests')) {
            return;
        }
        Schema::create('demo_requests', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 20)->index();
            $t->string('email', 160);
            $t->string('company', 160)->nullable();
            $t->string('entry', 10)->default('demo');       // demo | 1 | 2 | 3 | full
            $t->string('pin', 12)->index();                 // readable by the admin for manual sharing / resend
            $t->string('status', 12)->default('active');    // active | expired | revoked
            $t->dateTime('expires_at')->index();
            $t->dateTime('last_login_at')->nullable();
            $t->dateTime('wiped_at')->nullable();           // stamped by demo:reset once this visitor's data was erased
            $t->timestamps();
        });
    }

    /** Passkey validity in hours — Super Admin setting (app_settings), default 2, clamped 1–72. */
    public static function pinHours(): int
    {
        try {
            if (Schema::hasTable('app_settings')) {
                $row = DB::table('app_settings')->where('tenant_id', 0)->where('ckey', 'demo_gate')->value('value');
                $h = (int) ((json_decode((string) $row, true) ?: [])['hours'] ?? 0);
                if ($h >= 1) {
                    return min($h, 72);
                }
            }
        } catch (\Throwable $e) {
        }

        return 2;
    }

    private static function putPinHours(int $hours): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function ($t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('ckey')->index();
                $t->longText('value')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'ckey']);
            });
        }
        DB::table('app_settings')->updateOrInsert(
            ['tenant_id' => 0, 'ckey' => 'demo_gate'],
            ['value' => json_encode(['hours' => max(1, min($hours, 72))]), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Flip overdue active rows to expired (idempotent, called before any lookup). */
    private static function expireOverdue(): void
    {
        try {
            DB::table('demo_requests')->where('status', 'active')
                ->where('expires_at', '<', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }
    }

    /** The entry page a given demo entry belongs to (for redirects + re-entry). */
    public static function backUrlFor(string $entry): string
    {
        return ['demo' => '/demo', '1' => '/app1', '2' => '/app2', '3' => '/app3', 'full' => '/teamdemo'][$entry] ?? '/demo';
    }

    /**
     * rev185 — called by AppController::show() on every /app page load: when the
     * signed-in session is a passkey demo whose window ended (or was revoked),
     * log it out and send it back to its entry page. Returns the redirect URL
     * or null when the session may continue.
     */
    public static function expiredRedirect(Request $request): ?string
    {
        $p = $request->session()->get('demo_pass');
        if (! $p || ! is_array($p)) {
            return null;
        }
        $dead = now()->timestamp > (int) ($p['exp'] ?? 0);
        if (! $dead) {
            try {
                self::ensureGate();
                $status = DB::table('demo_requests')->where('id', (int) ($p['id'] ?? 0))->value('status');
                $dead = $status !== null && $status !== 'active';   // revoked from the admin panel
            } catch (\Throwable $e) {
            }
        }
        if (! $dead) {
            return null;
        }
        try {
            DB::table('demo_requests')->where('id', (int) ($p['id'] ?? 0))
                ->where('status', 'active')->update(['status' => 'expired', 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }
        $back = (string) ($p['back'] ?? '/demo');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $back.'?expired=1';
    }

    /** GET /demo — the public entry page (request + passkey form). */
    public function show()
    {
        return view('demo-entry', [
            'ready' => $this->demoUser() !== null,
            'hours' => self::pinHours(),
        ]);
    }

    /**
     * rev 104/105 — EDITION DEMONSTRATIONS (/app1 /app2 /app3 /teamdemo): the
     * same shared workspace viewed through that edition's licence. rev185: the
     * team PIN is gone — these pages carry the same request → passkey flow.
     */
    public const EDITION_DEMOS = [
        '1' => ['l1', 'SmartPRS-L1', 'Core HR', 'The complete, compliant HR & payroll system — people, GPS + selfie attendance, leave, full statutory payroll (PF · ESI · PT · TDS), notices and reports.'],
        '2' => ['l2', 'SmartPRS-L2', 'Advanced', 'Everything in L1 plus the nine advanced modules — Recruitment & ATS, HR Letters, Compensation & Claims, Multi-Company, Performance, Learning, WhatsApp Suite, Analytics, Communication Plus.'],
        '3' => ['l3', 'SmartPRS-L3', 'Collections DNA', 'The full platform — everything in L2 plus Live Salary, the Incentive & Earnings Engine, Field Force & Compliance and the Volume Hiring Machine.'],
        'full' => [null, 'SmartPRS', 'Complete Platform', 'The entire platform with nothing held back — all sixteen modules, every screen, settings included. The personal demonstration experience for your prospect, driven by the Ametecs team.'],
    ];

    /** GET /app1 | /app2 | /app3 | /teamdemo — branded entry page. */
    public function editionShow(string $n)
    {
        $d = self::EDITION_DEMOS[$n] ?? null;
        abort_unless($d, 404);

        return view('edition-demo-entry', [
            'n' => $n, 'edition' => $d[0], 'title' => $d[1], 'subtitle' => $d[2],
            'blurb' => $d[3], 'ready' => $this->demoUser() !== null,
            'hours' => self::pinHours(),
            'action' => $n === 'full' ? url('/teamdemo/start') : url('/app'.$n.'/start'),
            'requestAction' => url('/demo/request'),
        ]);
    }

    /**
     * POST /demo/request — rev185. Name + phone + email in, passkey out:
     * generated automatically, stored in the Demo Requests register, sent to
     * the visitor's email + WhatsApp. An unexpired request for the same phone
     * and entry page is RESENT instead of duplicated.
     */
    public function requestPin(Request $request)
    {
        try {
            if (trim((string) $request->input('website', '')) !== '') {
                return response()->json(['ok' => true, 'message' => 'Passkey sent.']);
            }
            $v = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'mobile' => ['required', 'string', 'max:20'],
                'email' => ['required', 'email', 'max:160'],
                'company' => ['nullable', 'string', 'max:160'],
                'entry' => ['required', 'in:demo,1,2,3,full'],
            ]);
            $digits = substr(preg_replace('/\D+/', '', $v['mobile']), -10);
            if (strlen($digits) < 10) {
                return response()->json(['ok' => false, 'error' => 'Please enter a valid 10-digit mobile number.'], 422);
            }

            self::ensureGate();
            self::expireOverdue();
            $hours = self::pinHours();

            // Reuse a still-valid request for the same phone + entry (resend, no duplicate).
            $row = DB::table('demo_requests')->where('phone', $digits)->where('entry', $v['entry'])
                ->where('status', 'active')->where('expires_at', '>', now())
                ->orderByDesc('id')->first();
            if (! $row) {
                do {
                    $pin = (string) random_int(100000, 999999);
                } while (DB::table('demo_requests')->where('pin', $pin)->where('status', 'active')->exists());
                $id = DB::table('demo_requests')->insertGetId([
                    'name' => $v['name'], 'phone' => $digits, 'email' => $v['email'],
                    'company' => $v['company'] ?? null, 'entry' => $v['entry'], 'pin' => $pin,
                    'status' => 'active', 'expires_at' => now()->addHours($hours),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('demo_requests')->where('id', $id)->first();

                // Every demo request is a sales lead (fail-soft).
                try {
                    LeadController::recordLead([
                        'name' => $v['name'], 'mobile' => $digits,
                        'company' => $v['company'] ?? null, 'email' => $v['email'],
                        'challenges' => 'Requested a live-demo passkey ('.self::backUrlFor($v['entry']).')',
                    ], 'live_demo');
                } catch (\Throwable $e) {
                }
            }

            $channels = $this->sendPin($row);
            $resp = ['ok' => true, 'channels' => $channels,
                'message' => $channels
                    ? 'Your passkey is on its way to your '.implode(' and ', $channels).'. Enter it below — it is valid for '.$hours.' hour'.($hours === 1 ? '' : 's').'.'
                    : 'We could not reach you by email/WhatsApp right now — please retry in a minute, or write to sales@ametecsindia.com.'];
            // rev185b (Ejaz): the passkey is NEVER returned to the browser — it
            // travels only by email/WhatsApp (admins can read it in Demo Requests).

            return response()->json($resp);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Send the passkey to the visitor's email + WhatsApp. Fail-soft per channel. */
    private function sendPin($row): array
    {
        $channels = [];
        $hours = self::pinHours();
        try {
            $id = \App\Services\MailService::queue([
                'tenant_id' => null, 'kind' => 'demo.pin',
                'to' => $row->email,
                'subject' => $row->pin.' is your SmartPRS demo passkey',
                'heading' => 'Your SmartPRS live-demo passkey',
                'intro' => 'Enter this passkey on the demo page to start. It is valid for '.$hours.' hour'.($hours === 1 ? '' : 's').' — after that the demo signs out and the workspace resets.',
                'lines' => ['Passkey' => $row->pin, 'Demo page' => url(self::backUrlFor($row->entry))],
            ]);
            if ($id) {
                $channels[] = 'email';
            }
        } catch (\Throwable $e) {
        }
        try {
            if (\App\Services\WaService::sendTemplate([
                'mobile' => $row->phone,
                'template' => \App\Services\WaService::templateNameFor('otp'),
                'kind' => 'demo.pin',
                'bodyValues' => [$row->pin],
            ])) {
                $channels[] = 'WhatsApp';
            }
        } catch (\Throwable $e) {
        }

        return $channels;
    }

    /** POST /demo/start — passkey login for the public demo. */
    public function start(Request $request)
    {
        return $this->enter($request, 'demo');
    }

    /** POST /app{n}/start | /teamdemo/start — passkey login for the edition demos. */
    public function editionStart(Request $request, string $n)
    {
        abort_unless(isset(self::EDITION_DEMOS[$n]), 404);

        return $this->enter($request, $n);
    }

    /** Shared passkey login. 'demo' answers JSON (fetch UI); editions redirect (classic form). */
    private function enter(Request $request, string $entry)
    {
        $json = $entry === 'demo';
        $back = self::backUrlFor($entry);
        $fail = function (string $msg) use ($json, $back) {
            return $json
                ? response()->json(['ok' => false, 'error' => $msg], 422)
                : redirect($back)->with('demo_err', $msg);
        };

        try {
            $pin = trim((string) $request->input('pin', ''));
            if ($pin === '') {
                return $fail('Enter the passkey we sent to your email / WhatsApp.');
            }
            self::ensureGate();
            self::expireOverdue();
            $row = DB::table('demo_requests')->where('pin', $pin)->where('entry', $entry)
                ->where('status', 'active')->where('expires_at', '>', now())
                ->orderByDesc('id')->first();
            if (! $row) {
                return $fail('That passkey is not valid for this page (or its time window has ended). Submit a new request to get a fresh one.');
            }
            $u = $this->demoUser();
            if (! $u) {
                return $fail('The demo is being refreshed right now — please try again in a couple of minutes.');
            }

            Auth::loginUsingId($u->id);
            $request->session()->regenerate();
            $d = self::EDITION_DEMOS[$entry] ?? null;
            if ($d && $d[0]) {
                $request->session()->put('edition_demo', $d[0]);   // l1 | l2 | l3 licence view
            } else {
                $request->session()->forget('edition_demo');
            }
            if ($entry === 'demo') {
                $request->session()->forget('demo_team');          // public demo stays restricted
            } else {
                $request->session()->put('demo_team', 1);          // team-driven pages stay unrestricted
            }
            $request->session()->put('demo_pass', [
                'id' => (int) $row->id,
                'exp' => Carbon::parse($row->expires_at)->timestamp,
                'back' => $back,
            ]);
            if (! $row->last_login_at) {
                DB::table('demo_requests')->where('id', $row->id)->update(['last_login_at' => now(), 'updated_at' => now()]);
            }

            $tour = $json ? true : $request->boolean('tour');

            return $json
                ? response()->json(['ok' => true, 'redirect' => url('/app?tour=1')])
                : redirect($tour ? '/app?tour=1' : '/app');
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }
    }

    /** The demo workspace's admin login (created by demo:reset). */
    private function demoUser()
    {
        $tid = self::demoTenantId();
        if (! $tid) {
            return null;
        }

        return DB::table('users')->where('tenant_id', $tid)
            ->whereRaw('LOWER(email) = ?', ['demo-admin@smartprs.in'])
            ->where('status', 'active')->first()
            ?: DB::table('users')->where('tenant_id', $tid)->where('status', 'active')->orderBy('id')->first();
    }

    /* ----------------------------------------------------------------------
     |  rev185 — Super Admin panel: the Demo Requests register
     * -------------------------------------------------------------------- */

    private function gateSuperAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->hasRole('super_admin'), 403);
    }

    /** GET /app/saas/demo-requests — register + validity setting. */
    public function saasList(Request $request)
    {
        $this->gateSuperAdmin($request);
        try {
            self::ensureGate();
            self::expireOverdue();
            $rows = DB::table('demo_requests')->orderByDesc('id')->limit(200)->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'email' => $r->email,
                    'company' => $r->company, 'entry' => self::backUrlFor($r->entry), 'pin' => $r->pin,
                    'status' => $r->status, 'expires' => (string) $r->expires_at,
                    'lastLogin' => $r->last_login_at ? (string) $r->last_login_at : '',
                    'wiped' => $r->wiped_at ? (string) $r->wiped_at : '',
                    'requested' => (string) $r->created_at,
                ])->values();

            return response()->json(['ok' => true, 'rows' => $rows, 'hours' => self::pinHours()]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/saas/demo-requests/hours — save the validity setting. */
    public function saasHours(Request $request)
    {
        $this->gateSuperAdmin($request);
        $v = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:72']]);
        self::putPinHours((int) $v['hours']);

        return response()->json(['ok' => true, 'hours' => self::pinHours()]);
    }

    /** POST /app/saas/demo-requests/{id}/resend — resend a still-valid passkey. */
    public function saasResend(Request $request, int $id)
    {
        $this->gateSuperAdmin($request);
        self::ensureGate();
        self::expireOverdue();
        $row = DB::table('demo_requests')->where('id', $id)->first();
        if (! $row || $row->status !== 'active') {
            return response()->json(['ok' => false, 'error' => 'That request is no longer active — the visitor should submit a fresh request.'], 422);
        }
        $channels = $this->sendPin($row);

        return response()->json(['ok' => true, 'message' => $channels ? 'Passkey resent by '.implode(' and ', $channels).'.' : 'Sending failed on both channels — check SMTP / WhatsApp settings.']);
    }

    /** POST /app/saas/demo-requests/{id}/revoke — kill a passkey now. */
    public function saasRevoke(Request $request, int $id)
    {
        $this->gateSuperAdmin($request);
        self::ensureGate();
        DB::table('demo_requests')->where('id', $id)->update(['status' => 'revoked', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'message' => 'Passkey revoked — the visitor is signed out on their next page load, and the workspace resets on the next sweep.']);
    }
}
