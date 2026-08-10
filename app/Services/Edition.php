<?php

namespace App\Services;

/**
 * rev 103 — EDITIONS (Ejaz): one codebase, four faces.
 *
 *   SMARTPRS_EDITION=saas  (default) full app + SaaS Platform module
 *   SMARTPRS_EDITION=l1    on-prem CORE        (per SmartPRS-Module-Licensing-Levels.md)
 *   SMARTPRS_EDITION=l2    on-prem ADVANCED    (core + the nine L2 modules)
 *   SMARTPRS_EDITION=l3    on-prem DNA         (everything except the SaaS Platform)
 *
 * Gating = the proven demo pattern: hidden in the menu (boot JS reads
 * hiddenNavIds via cfg) AND blocked at the server (EditionGuard middleware
 * uses blockedPatterns). Data is never deleted — an upgrade is just a new
 * licence/.env value, and everything reappears.
 */
class Edition
{
    public static function current(): string
    {
        // rev 104: LIVE EDITION DEMONSTRATIONS (/app1 /app2 /app3 on the SaaS
        // server). A session may carry an edition override, honoured ONLY for
        // users of the shared demo workspace — so the sales team can show
        // L1/L2/L3 on smartprs.com itself, while real tenants are never
        // affected. Fail-soft everywhere (console/queue contexts have no session).
        try {
            $o = session('edition_demo');
            if (in_array($o, ['l1', 'l2', 'l3'], true)) {
                $u = auth()->user();
                if ($u && \App\Http\Controllers\DemoAccessController::isDemoTenant($u->tenant_id)) {
                    return $o;
                }
            }
        } catch (\Throwable $e) {
        }

        static $base = null;
        if ($base !== null) {
            return $base;
        }
        // 8 Aug 2026 (Ejaz — single product): editions are unified (see hiddenNavIds
        // / blockedPatterns below), so this value only needs to tell SaaS apart from
        // an on-prem client. Read the build's OWN .env DIRECTLY:
        // `config('smartprs.edition')` is frozen at `php artisan config:cache` time,
        // so a client build whose cache was ever made under a different edition (e.g.
        // cloned from another folder) would report the wrong one forever — no .env
        // edit fixing it until config:clear. Reading .env is the reliable source.
        $e = self::envEdition() ?? strtolower(trim((string) (config('smartprs.edition') ?? env('SMARTPRS_EDITION', 'saas'))));

        return $base = (in_array($e, ['saas', 'l1', 'l2', 'l3'], true) ? $e : 'saas');
    }

    /** SMARTPRS_EDITION read straight from the build's .env file (bypasses the
     *  cached config). Null if the file/line is absent. Cached per request. */
    private static function envEdition(): ?string
    {
        static $done = false;
        static $val = null;
        if ($done) {
            return $val;
        }
        $done = true;
        try {
            $path = base_path('.env');
            if (is_file($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                    $ln = trim($ln);
                    if ($ln === '' || $ln[0] === '#' || stripos($ln, 'SMARTPRS_EDITION') !== 0) {
                        continue;
                    }
                    $eq = strpos($ln, '=');
                    if ($eq !== false) {
                        $v = strtolower(trim(substr($ln, $eq + 1), " \t\"'"));
                        if (in_array($v, ['saas', 'l1', 'l2', 'l3'], true)) {
                            $val = $v;
                        }
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return $val;
    }

    public static function isOnPrem(): bool
    {
        return self::current() !== 'saas';
    }

    /** Feature level. 8 Aug 2026 (Ejaz — single product): every edition is full. */
    public static function level(): int
    {
        return 3;
    }

    // ---- Module → nav-id sets (mirrors the licensing decision document) ----

    /** SaaS-platform-only surfaces (hidden on EVERY on-prem edition). */
    private const SAAS_ONLY = ['platform-dashboard', 'tenants', 'plans', 'subscriptions', 'invoices', 'payments', 'gateways', 'my-subscription'];

    /** Level-3 DNA nav ids (hidden on l1 + l2). rev 115: + incentive-schemes (module 3.1). */
    private const DNA = ['live-salary', 'pay-ledger', 'commissions', 'commission-calc', 'incentive-schemes', 'clawbacks', 'escalations', 'offroll-agents', 'agent-auth', 'compliance-alerts', 'roster', 'complaints'];

    /** Level-2 module nav ids (hidden on l1). */
    private const L2_MODULES = [
        'recruitment',                                                              // 2.1
        'letters-offer', 'letters-increment', 'letters-warning', 'letters-relieving', 'letters-templates',   // 2.2
        'expenses', 'advance', 'loans', 'bonus-enc', 'increments',                  // 2.3
        'performance', 'points-ledger', 'points-rules', 'points-scores', 'awards', 'tests', 'test-reports',  // 2.5
        'training-programs', 'training-records', 'training-content', 'faqs', 'code-of-conduct', 'kb',        // 2.6
        'wa-settings', 'wa-templates',                                              // 2.7
        'attrition', 'activity-logs',                                               // 2.8
        'send-message', 'sms-settings', 'sms-templates',                            // 2.9
    ];

    public static function hiddenNavIds(): array
    {
        // 8 Aug 2026 (Ejaz — FINAL: single product, all features, licensed by user
        // count). Every on-prem edition (l1/l2/l3) now shows ALL modules; only the
        // SaaS-platform surfaces (tenant billing + Super Admin) stay hidden on a
        // client install. The old per-edition DNA / L2_MODULES gating is retired
        // (constants kept for reference / easy revert).
        return self::current() === 'saas' ? [] : self::SAAS_ONLY;
    }

    // ---- Server-side endpoint blocking (regex on relative path) ------------

    /**
     * SaaS/platform + Super-Admin endpoints — blocked on every on-prem edition.
     * rev147: these are PLATFORM surfaces (incl. the Super Admin portal /super
     * and the whole /admin panel); on a client they are HIDDEN (404), never a
     * 403 upsell, so no Super Admin login/route/API is reachable or even hinted.
     */
    private const BLOCK_SAAS = [
        '#^app/saas#', '#^app/billing#', '#^app/my-subscription#',
        '#^admin($|/)#', '#^super($|/)#', '#^signup#', '#^quote#', '#^demo($|/)#', '#^lead$#',
    ];

    /** Platform/Super-Admin patterns that must be fully HIDDEN (404) on-prem. */
    public static function platformPatterns(): array
    {
        return self::BLOCK_SAAS;
    }

    /** DNA endpoints — blocked on l1 + l2 (ALL methods: data stays invisible). */
    private const BLOCK_DNA = [
        '#^app/requests/commissions#', '#^app/requests/salary-pay#', '#^app/requests/clawbacks#',
        '#^app/incentive#', '#^app/schemes#', '#^app/live-salary#', '#^app/offroll-agent#',
        '#^app/compliance-alerts#', '#^app/master/(escalations|agent-auth|roster|complaints)(/|$)#',
        '#^app/recruitment/(messages|campaigns|campaign|drives|drive|message)(/|$)#',   // 3.3 volume hiring
        '#^webhooks/interakt#',
    ];

    /** L2-module endpoints — blocked on l1 only. */
    private const BLOCK_L2 = [
        '#^app/recruitment#', '#^app/offer#',
        '#^app/master/letters-#', '#^app/letters#',
        '#^app/requests/(expenses|advance|loans|bonus-enc|increments)#', '#^app/increments#',
        '#^app/master/(performance|points-ledger|points-rules|tests|training-programs|training-records|training-content|faqs|awards)(/|$)#',
        '#^app/performance#', '#^app/points#',
        '#^app/tests#', '#^app/code-of-conduct#', '#^app/kb#',
        '#^app/wa-templates#', '#^app/master/(wa-settings|sms-settings|sms-templates)(/|$)#',
        '#^app/send-message#',
    ];

    public static function blockedPatterns(): array
    {
        // 8 Aug 2026 (Ejaz — single product): on-prem clients block only the SaaS
        // platform / Super-Admin endpoints; every product module is reachable.
        return self::current() === 'saas' ? [] : self::BLOCK_SAAS;
    }

    /** Friendly label. 8 Aug 2026 (Ejaz — single product): one name, no L1/L2/L3. */
    public static function label(): string
    {
        return 'SmartPRS';
    }
}
