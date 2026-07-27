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

        // config-first so `php artisan config:cache` can never blank the flag
        // (env() returns null once the config is cached).
        $e = strtolower(trim((string) (config('smartprs.edition') ?? env('SMARTPRS_EDITION', 'saas'))));

        return in_array($e, ['saas', 'l1', 'l2', 'l3'], true) ? $e : 'saas';
    }

    public static function isOnPrem(): bool
    {
        return self::current() !== 'saas';
    }

    /** Feature level: saas behaves as full (3). */
    public static function level(): int
    {
        return ['saas' => 3, 'l1' => 1, 'l2' => 2, 'l3' => 3][self::current()];
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
        switch (self::current()) {
            case 'l1':
                return array_values(array_unique(array_merge(self::SAAS_ONLY, self::DNA, self::L2_MODULES)));
            case 'l2':
                return array_values(array_unique(array_merge(self::SAAS_ONLY, self::DNA)));
            case 'l3':
                return self::SAAS_ONLY;
            default:
                return [];
        }
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
        switch (self::current()) {
            case 'l1':
                return array_merge(self::BLOCK_SAAS, self::BLOCK_DNA, self::BLOCK_L2);
            case 'l2':
                return array_merge(self::BLOCK_SAAS, self::BLOCK_DNA);
            case 'l3':
                return self::BLOCK_SAAS;
            default:
                return [];
        }
    }

    /** Friendly label for the 403 message / About screens. */
    public static function label(): string
    {
        return ['saas' => 'SmartPRS SaaS', 'l1' => 'SmartPRS-L1 (Core HR)', 'l2' => 'SmartPRS-L2 (Advanced)', 'l3' => 'SmartPRS-L3 (Collections DNA)'][self::current()];
    }
}
