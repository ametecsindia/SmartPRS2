<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant / per-company configuration store for two features:
 *   • SMTP / email settings — now PER COMPANY (each group company has its own
 *     mail server), with a tenant-wide default fallback when a company has none.
 *   • Company-wise branding (display name, brand colour, logo URL, tagline).
 *
 * Both live in a self-creating `app_settings` table (tenant_id, ckey, value
 * JSON) as maps keyed by company id. The special company key '0' holds the
 * tenant-wide default SMTP. Static resolvers (brandFor / mailConfigFor) are
 * reused by AppDataController + the PDF controllers so branding and the right
 * mail server apply wherever relevant.
 */
class ConfigController extends Controller
{
    private static function ensureTable(): void
    {
        if (Schema::hasTable('app_settings')) {
            return;
        }
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('ckey')->index();
            $t->longText('value')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'ckey']);
        });
    }

    private static function get(?int $tenantId, string $key): array
    {
        self::ensureTable();
        $row = DB::table('app_settings')->where('tenant_id', $tenantId ?? 0)->where('ckey', $key)->value('value');

        return $row ? (json_decode($row, true) ?: []) : [];
    }

    private static function put(?int $tenantId, string $key, array $value): void
    {
        self::ensureTable();
        DB::table('app_settings')->updateOrInsert(
            ['tenant_id' => $tenantId ?? 0, 'ckey' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin']);
    }

    // ================================================================ SMTP ===
    // Stored under ckey 'mail' as a map: { "<companyId>": {...}, "0": {tenant default} }

    public static function mailDefaults(): array
    {
        return [
            'host' => '', 'port' => 587, 'username' => '', 'password' => '',
            'encryption' => 'tls', 'from_address' => '', 'from_name' => 'SmartPRS',
        ];
    }

    /** Effective mail config for a company: its own settings, else tenant default. */
    public static function mailConfigFor(?int $tenantId, $companyId): array
    {
        $map = self::get($tenantId, 'mail');
        $own = $map[(string) $companyId] ?? [];
        if (! empty($own['host'])) {
            return array_merge(self::mailDefaults(), $own);
        }
        $default = $map['0'] ?? [];

        return array_merge(self::mailDefaults(), $default);
    }

    public function mailIndex(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $map = self::get($tenantId, 'mail');
        // Super admin (tenant NULL) manages only the PLATFORM mailbox (the '0'
        // default below) — the sender used for invoices, payment confirmations
        // and login-credential emails when a tenant has no SMTP of its own.
        $companies = $tenantId === null
            ? collect([])
            : DB::table('companies')->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);

        $mask = function (array $m) {
            $m = array_merge(self::mailDefaults(), $m);
            $m['hasPassword'] = ! empty($m['password']);
            $m['password'] = '';

            return $m;
        };

        $rows = $companies->map(fn ($c) => [
            'id' => (string) $c->id,
            'name' => $c->name,
            'mail' => $mask($map[(string) $c->id] ?? []),
        ])->values();

        return response()->json([
            'companies' => $rows,
            'default' => $mask($map['0'] ?? []),   // tenant-wide fallback
            'canManage' => $this->canManage($request),
        ]);
    }

    public function mailSave(Request $request)
    {
        abort_unless($this->canManage($request), 403);
        $tenantId = $request->user()->tenant_id;
        $v = $request->validate([
            'company_id' => ['required', 'string'],   // '0' = tenant default
            'host' => ['nullable', 'string', 'max:191'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:191'],
            'encryption' => ['nullable', 'in:tls,ssl,none'],
            'from_address' => ['nullable', 'email', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:191'],
        ]);
        $cid = (string) $v['company_id'];
        $map = self::get($tenantId, 'mail');
        $existing = array_merge(self::mailDefaults(), $map[$cid] ?? []);
        unset($v['company_id']);
        $merged = array_merge($existing, $v);
        if (empty($v['password'])) {        // keep stored password if field left blank
            $merged['password'] = $existing['password'];
        }
        $merged['port'] = (int) ($merged['port'] ?: 587);
        $map[$cid] = $merged;
        self::put($tenantId, 'mail', $map);

        return response()->json(['ok' => true]);
    }

    /** Recent outbound mail (audit) for the Mail Log screen. */
    public function mailLog(Request $request)
    {
        try {
            abort_unless($this->canManage($request), 403);
            \App\Services\MailService::ensureTable();
            $tenantId = $request->user()->tenant_id;
            $rows = DB::table('mail_log')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'kind', 'recipient', 'subject', 'status', 'error', 'sent_at', 'created_at']);
            $counts = ['sent' => 0, 'failed' => 0, 'queued' => 0, 'skipped' => 0];
            foreach ($rows as $r) {
                if (isset($counts[$r->status])) {
                    $counts[$r->status]++;
                }
            }

            return response()->json([
                'rows' => $rows->map(fn ($r) => [
                    'id' => $r->id,
                    'kind' => $r->kind,
                    'to' => $r->recipient,
                    'subject' => $r->subject,
                    'status' => $r->status,
                    'error' => $r->error,
                    'at' => \Illuminate\Support\Carbon::parse($r->sent_at ?? $r->created_at)->format('d M Y H:i'),
                ])->values(),
                'counts' => $counts,
                'canManage' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    public function mailTest(Request $request)
    {
        abort_unless($this->canManage($request), 403);
        $tenantId = $request->user()->tenant_id;
        $v = $request->validate([
            'to' => ['required', 'email'],
            'company_id' => ['required', 'string'],
        ]);
        $m = self::mailConfigFor($tenantId, $v['company_id']);
        if (empty($m['host']) || empty($m['from_address'])) {
            return response()->json(['ok' => false, 'error' => 'No SMTP for this company (and no tenant default). Set host + From address, Save, then test.'], 422);
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $m['host'],
            'mail.mailers.smtp.port' => (int) $m['port'],
            'mail.mailers.smtp.username' => $m['username'] ?: null,
            'mail.mailers.smtp.password' => $m['password'] ?: null,
            'mail.mailers.smtp.encryption' => $m['encryption'] === 'none' ? null : $m['encryption'],
            'mail.from.address' => $m['from_address'],
            'mail.from.name' => $m['from_name'] ?: 'SmartPRS',
        ]);

        try {
            Mail::raw('This is a test email from SmartPRS. Your SMTP settings are working.', function ($msg) use ($v) {
                $msg->to($v['to'])->subject('SmartPRS SMTP test');
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Send failed: '.$e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Test email sent to '.$v['to']]);
    }

    // ============================================================ branding ===
    // Stored under ckey 'branding' as a map: { "<companyId>": {display_name,color,logo,tagline} }

    /** Effective branding for one company (saved overrides → company row → defaults). */
    public static function brandFor(?int $tenantId, $companyId): array
    {
        $map = self::get($tenantId, 'branding');
        $c = DB::table('companies')->where('id', $companyId)->first();
        $saved = $map[(string) $companyId] ?? [];

        // rev 131: an UPLOADED logo is stored locally (logo_stored = relative
        // path under storage/app/public). For the app we serve it via a URL; for
        // PDFs we hand DomPDF the ABSOLUTE local path (logo_file) so it renders
        // reliably. A manually-typed external URL still works as a fallback.
        $stored = $saved['logo_stored'] ?? null;
        $logoFile = null;
        $logoUrl = $saved['logo'] ?? ($c->logo_path ?? '');
        if ($stored && is_file(storage_path('app/public/'.$stored))) {
            $logoFile = storage_path('app/public/'.$stored);
            $logoUrl = url('/app/branding/logo/'.$companyId);
        }

        return [
            'display_name' => $saved['display_name'] ?? ($c->name ?? 'SmartPRS'),
            'color' => $saved['color'] ?? ($c->color ?? '#f97316'),
            'logo' => $logoUrl,
            'logo_file' => $logoFile,          // local path for PDFs (null if none uploaded)
            'tagline' => $saved['tagline'] ?? '',
            'name' => $c->name ?? 'SmartPRS',
        ];
    }

    /** POST /app/branding/logo — upload a company logo file (image, <=2MB). */
    public function brandingLogoUpload(Request $request)
    {
        abort_unless($this->canManage($request), 403);
        $tenantId = $request->user()->tenant_id;
        $v = $request->validate([
            'company_id' => ['required'],
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:2048'],
        ]);
        $cid = (string) $v['company_id'];
        // company must belong to this tenant
        $exists = DB::table('companies')->where('id', $cid)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->exists();
        abort_unless($exists, 404);

        $ext = strtolower($request->file('logo')->getClientOriginalExtension() ?: 'png');
        $rel = 'branding/'.($tenantId ?? 0).'/'.$cid.'-'.time().'.'.$ext;
        $request->file('logo')->storeAs('public/branding/'.($tenantId ?? 0), basename($rel));

        $map = self::get($tenantId, 'branding');
        $map[$cid] = array_merge($map[$cid] ?? [], [
            'logo_stored' => $rel,
            'logo' => url('/app/branding/logo/'.$cid),
        ]);
        // keep display_name/color/tagline defaults if first save
        $map[$cid]['display_name'] = $map[$cid]['display_name'] ?? '';
        $map[$cid]['color'] = $map[$cid]['color'] ?? '#f97316';
        $map[$cid]['tagline'] = $map[$cid]['tagline'] ?? '';
        self::put($tenantId, 'branding', $map);

        DB::table('companies')->where('id', $cid)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->update(['logo_path' => $map[$cid]['logo'], 'updated_at' => now()]);

        return response()->json(['ok' => true, 'logo' => $map[$cid]['logo'].'?t='.time()]);
    }

    /**
     * POST /app/branding/app-logo — rev169, RESCOPED rev184 (Ejaz): a client
     * admin's upload now changes the app logo ONLY for their own account
     * (images/tenants/{tenant_id}/applogo.png). The default SmartPRS logo
     * (images/logo.png) is never replaced from the UI any more, so one client
     * can no longer rebrand every other client on the install.
     */
    public function appLogoUpload(Request $request)
    {
        abort_unless($this->canManage($request), 403);
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);
        $tid = $request->user()->tenant_id;
        if (! $tid) {
            return response()->json(['ok' => false, 'error' => 'The platform login has no client scope. Sign in as that client\'s admin to set their logo.'], 422);
        }
        @mkdir(public_path('images/tenants/'.$tid), 0775, true);
        $dest = public_path('images/tenants/'.$tid.'/applogo.png');
        $file = $request->file('logo');
        $done = false;
        // Normalise to a real PNG at images/logo.png so every asset('images/logo.png') updates.
        if (function_exists('imagecreatefromstring')) {
            try {
                $im = @imagecreatefromstring((string) @file_get_contents($file->getRealPath()));
                if ($im !== false) {
                    @imagesavealpha($im, true);
                    @imagepng($im, $dest);
                    @imagedestroy($im);
                    $done = is_file($dest);
                }
            } catch (\Throwable $e) {
            }
        }
        if (! $done) {
            $file->move(public_path('images/tenants/'.$tid), 'applogo.png');
        }
        @clearstatcache();

        return response()->json(['ok' => true, 'logo' => self::appLogoUrlFor($tid)]);
    }

    /**
     * rev184 — the app (sidebar) logo URL for a tenant: their own uploaded
     * per-client logo when present, else the default SmartPRS product logo.
     */
    public static function appLogoUrlFor($tenantId): string
    {
        if ($tenantId) {
            $p = public_path('images/tenants/'.$tenantId.'/applogo.png');
            if (is_file($p)) {
                return url('/images/tenants/'.$tenantId.'/applogo.png').'?t='.(int) @filemtime($p);
            }
        }
        return url('/images/logo.png');
    }

    /** GET /app/branding/logo/{companyId} — serve the uploaded company logo. */
    public function brandingLogoServe(Request $request, $companyId)
    {
        $tenantId = $request->user()->tenant_id;
        $map = self::get($tenantId, 'branding');
        $rel = $map[(string) $companyId]['logo_stored'] ?? null;
        $full = $rel ? storage_path('app/public/'.$rel) : null;
        abort_unless($full && is_file($full), 404);
        $mime = function_exists('mime_content_type') ? mime_content_type($full) : 'image/png';

        return response()->file($full, ['Content-Type' => $mime]);
    }

    /** Whole branding map (companyId → brand) for the frontend to switch live. */
    public static function brandingMap(?int $tenantId): array
    {
        $map = self::get($tenantId, 'branding');
        $companies = DB::table('companies')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')->get(['id', 'name', 'color', 'logo_path']);
        $out = [];
        foreach ($companies as $c) {
            $saved = $map[(string) $c->id] ?? [];
            $out[(string) $c->id] = [
                'display_name' => $saved['display_name'] ?? $c->name,
                'color' => $saved['color'] ?? ($c->color ?? '#f97316'),
                'logo' => $saved['logo'] ?? ($c->logo_path ?? ''),
                'tagline' => $saved['tagline'] ?? '',
            ];
        }

        return $out;
    }

    public function brandingIndex(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $map = self::get($tenantId, 'branding');
        $companies = DB::table('companies')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'logo_path', 'color']);
        $out = $companies->map(fn ($c) => [
            'id' => (string) $c->id,
            'name' => $c->name,
            'brand' => $map[(string) $c->id] ?? [
                'display_name' => $c->name,
                'color' => $c->color ?: '#f97316',
                'logo' => $c->logo_path ?: '',
                'tagline' => '',
            ],
        ])->values();

        return response()->json([
            'companies' => $out,
            'canManage' => $this->canManage($request),
        ]);
    }

    public function brandingSave(Request $request)
    {
        abort_unless($this->canManage($request), 403);
        $tenantId = $request->user()->tenant_id;
        $v = $request->validate([
            'company_id' => ['required'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'string', 'max:1000'],
            'tagline' => ['nullable', 'string', 'max:200'],
        ]);
        $cid = (string) $v['company_id'];
        $map = self::get($tenantId, 'branding');
        // rev 131: preserve an UPLOADED logo across a Save. A typed URL overrides
        // the upload; otherwise the stored file (and its served URL) is kept.
        $existing = $map[$cid] ?? [];
        $typedUrl = trim((string) ($v['logo'] ?? ''));
        $stored = $existing['logo_stored'] ?? null;
        if ($typedUrl !== '' && strpos($typedUrl, '/app/branding/logo/') === false) {
            $stored = null;   // the admin chose an external URL instead of the upload
        }
        $logoUrl = $stored ? url('/app/branding/logo/'.$cid) : $typedUrl;
        $map[$cid] = [
            'display_name' => $v['display_name'] ?? '',
            'color' => $v['color'] ?: '#f97316',
            'logo' => $logoUrl,
            'tagline' => $v['tagline'] ?? '',
        ];
        if ($stored) {
            $map[$cid]['logo_stored'] = $stored;
        }
        self::put($tenantId, 'branding', $map);

        // Reflect colour + logo onto the company record so PDFs/landing can use them.
        $companyQ = DB::table('companies')->where('id', $cid)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));
        $companyQ->update([
            'color' => $map[$cid]['color'],
            'logo_path' => $map[$cid]['logo'] ?: DB::raw('logo_path'),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'brand' => $map[$cid]]);
    }
}
