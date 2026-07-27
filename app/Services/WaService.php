<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp sending via Interakt (https://app.interakt.ai) — the first live
 * provider adapter (un-parked by Ejaz, Jun 2026, for signup welcome messages).
 *
 * Config: the first ACTIVE `wa_settings` row whose provider mentions
 * "interakt" (api_key = the Interakt API key from Settings → Developer
 * Settings; api_url optional override), else env INTERAKT_API_KEY.
 *
 * Only TEMPLATE messages are supported — WhatsApp requires business-initiated
 * messages to use a template APPROVED in the Interakt dashboard, with the
 * same variable count as bodyValues. Every attempt is logged to `wa_log`
 * (mirrors mail_log). Fail-soft: never throws into callers.
 */
class WaService
{
    private static function ensureLog(): void
    {
        if (! Schema::hasTable('wa_log')) {
            Schema::create('wa_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('mobile', 20)->nullable();
                $t->string('template')->nullable();
                $t->text('body_values')->nullable();
                $t->string('kind')->nullable();
                $t->string('status', 20)->default('failed'); // sent | failed
                $t->text('error')->nullable();
                $t->timestamps();
            });
        }
    }

    /** Resolve the Interakt credentials (wa_settings row → env fallback). */
    public static function config(): ?array
    {
        try {
            if (Schema::hasTable('wa_settings')) {
                $row = DB::table('wa_settings')
                    ->whereRaw("LOWER(COALESCE(provider,'')) LIKE '%interakt%'")
                    ->whereRaw("LOWER(COALESCE(status,'active')) NOT IN ('inactive','disabled')")
                    // rev 91c: the PLATFORM row (super admin, tenant NULL/0) wins —
                    // a tenant's own row must never hijack platform sends.
                    ->orderByRaw('CASE WHEN tenant_id IS NULL OR tenant_id = 0 THEN 0 ELSE 1 END')
                    ->orderBy('id')->first();
                if ($row && $row->api_key) {
                    return ['key' => $row->api_key, 'url' => $row->api_url ?: 'https://api.interakt.ai/v1/public/message/'];
                }
            }
        } catch (\Throwable $e) {
            // fall through to env
        }
        if (env('INTERAKT_API_KEY')) {
            return ['key' => env('INTERAKT_API_KEY'), 'url' => env('INTERAKT_API_URL') ?: 'https://api.interakt.ai/v1/public/message/'];
        }

        return null;
    }

    /**
     * rev 92: resolve the template NAME for a purpose (welcome|payment|renewal|lead)
     * from the wa_templates registry — the tenant's own APPROVED row wins, then the
     * platform's APPROVED row, then env/default names. Renaming a template in the
     * WhatsApp Templates module is all it takes for the flows to use it.
     */
    public static function templateNameFor(string $purpose, ?int $tenantId = null): string
    {
        $defaults = [
            'welcome' => env('INTERAKT_TEMPLATE_WELCOME') ?: 'smartprs_welcome',
            'payment' => env('INTERAKT_TEMPLATE_PAYMENT') ?: 'smartprs_payment',
            'renewal' => env('INTERAKT_TEMPLATE_RENEWAL') ?: 'smartprs_renewal',
            'lead' => env('INTERAKT_TEMPLATE_LEAD') ?: 'smartprs_lead',
            'otp' => env('INTERAKT_TEMPLATE_OTP') ?: 'smartprs_otp',   // rev 98: demo entry verification
        ];
        try {
            if (Schema::hasTable('wa_templates')) {
                $q = DB::table('wa_templates')->where('purpose', $purpose)->where('status', 'approved');
                if ($tenantId) {
                    $q->where(function ($w) use ($tenantId) {
                        $w->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    })->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END');
                } else {
                    $q->whereNull('tenant_id');
                }
                $name = $q->orderBy('id')->value('name');
                if ($name) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            // fall through to defaults
        }

        return $defaults[$purpose] ?? $purpose;
    }

    /**
     * Send an APPROVED template message.
     *
     * $opts: tenant_id?, mobile (any format — last 10 digits, +91 assumed),
     * template? (default env INTERAKT_TEMPLATE_WELCOME or 'smartprs_welcome'),
     * lang? ('en'), bodyValues (array, must match the approved template), kind?.
     */
    public static function sendTemplate(array $opts): bool
    {
        $ok = false;
        $error = null;
        $digits = preg_replace('/\D+/', '', (string) ($opts['mobile'] ?? ''));
        $phone = substr($digits, -10);
        $template = $opts['template'] ?? (env('INTERAKT_TEMPLATE_WELCOME') ?: 'smartprs_welcome');

        // rev 97: the PUBLIC demo workspace must never send real WhatsApp.
        try {
            if (! empty($opts['tenant_id']) && \App\Http\Controllers\DemoAccessController::isDemoTenant($opts['tenant_id'])) {
                self::ensureLog();
                DB::table('wa_log')->insert([
                    'tenant_id' => $opts['tenant_id'], 'mobile' => $phone ?: null, 'template' => $template,
                    'body_values' => json_encode(array_values($opts['bodyValues'] ?? [])),
                    'kind' => $opts['kind'] ?? null, 'status' => 'skipped',
                    'error' => 'Demo workspace — outgoing WhatsApp muted',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return false;
            }
        } catch (\Throwable $e) {
            // never block on the demo check
        }

        try {
            $cfg = self::config();
            if (! $cfg) {
                throw new \RuntimeException('WhatsApp (Interakt) not configured — add an active row in Administration → WhatsApp API with provider "interakt" + the API key, or set INTERAKT_API_KEY in .env.');
            }
            if (strlen($phone) < 10) {
                throw new \RuntimeException('No valid mobile number to send to.');
            }
            $resp = Http::timeout(15)->withHeaders(['Authorization' => 'Basic '.$cfg['key']])
                ->post($cfg['url'], [
                    'countryCode' => '+91',
                    'phoneNumber' => $phone,
                    'type' => 'Template',
                    'template' => [
                        'name' => $template,
                        'languageCode' => $opts['lang'] ?? 'en',
                        'bodyValues' => array_values(array_map('strval', $opts['bodyValues'] ?? [])),
                    ],
                ]);
            $ok = $resp->successful();
            if (! $ok) {
                $error = 'HTTP '.$resp->status().': '.substr($resp->body(), 0, 400);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        try {
            self::ensureLog();
            DB::table('wa_log')->insert([
                'tenant_id' => $opts['tenant_id'] ?? null,
                'mobile' => $phone ?: null,
                'template' => $template,
                'body_values' => json_encode(array_values($opts['bodyValues'] ?? [])),
                'kind' => $opts['kind'] ?? null,
                'status' => $ok ? 'sent' : 'failed',
                'error' => $error,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // logging is best-effort
        }

        return $ok;
    }
}
