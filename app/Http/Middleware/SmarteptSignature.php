<?php

namespace App\Http\Middleware;

use App\Services\SmarteptWebhook;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SmartEPT webhook — HMAC authentication for the inbound push.
 *
 * Usage:  ->middleware('smartept-webhook')   on a route carrying {slug}
 *
 * SmartEPT's pusher sends no API key, so ApiKeyAuth cannot be used. The slug in
 * the URL selects the endpoint row; the row's shared secret must reproduce the
 * X-SmartEPT-Signature over the raw request body. Only then does the request
 * reach the controller, and only then with a tenant attached.
 *
 * As in ApiKeyAuth, the caller's identity comes from the credential and from
 * nothing else. The body's `company_id` belongs to SmartEPT's database, is not
 * authenticated, and is never allowed to pick a tenant here — that is exactly
 * the /iclock defect (ETimeOfficeService.php:321) this path must not reproduce.
 *
 * Every failure answers the same 401 with the same wording. A receiver that
 * distinguished "no such endpoint" from "bad signature" would let anyone with
 * the URL enumerate live endpoints.
 */
class SmarteptSignature
{
    public function handle(Request $request, Closure $next)
    {
        $slug = trim((string) $request->route('slug'));

        if (! Schema::hasTable('smartept_webhook_endpoints')) {
            return self::deny('SmartEPT webhooks are not configured on this server.', 503, 'WEBHOOK_503');
        }

        $body = (string) $request->getContent();
        if (strlen($body) > SmarteptWebhook::MAX_BODY_BYTES) {
            return self::deny('Payload too large.', 413, 'WEBHOOK_413');
        }

        $row = $slug === '' ? null : DB::table('smartept_webhook_endpoints')->where('slug', $slug)->first();
        $secret = $row ? SmarteptWebhook::decryptSecret($row->secret ?? null) : null;
        $presented = (string) $request->header(SmarteptWebhook::SIGNATURE_HEADER, '');

        // Verify before looking at `active`, so a revoked endpoint and a wrong
        // secret take the same path and the same time.
        $verified = $row !== null
            && $secret !== null
            && SmarteptWebhook::signatureMatches($body, $secret, $presented);

        if (! $verified) {
            Log::warning('smartept.webhook.denied', [
                'slug' => $slug !== '' ? substr($slug, 0, 8).'…' : '(none)',
                'known' => $row !== null,
                'signed' => $presented !== '',
                'decryptable' => $row !== null ? ($secret !== null) : null,
                'ip' => $request->ip(),
            ]);

            return self::deny('Webhook endpoint or signature is not valid.');
        }

        if (! $row->active) {
            return self::deny('This webhook endpoint has been revoked.');
        }

        if ($row->tenant_id === null) {
            // Same reasoning as ApiKeyAuth: both attendance_logs unique indexes
            // include tenant_id, and NULL never collides in a unique index, so a
            // tenant-less endpoint would silently lose de-duplication and
            // re-insert every re-pushed punch.
            return self::deny('This webhook endpoint is not bound to a tenant. Re-create it from Time & Attendance → API Keys while signed in to the workspace it belongs to.');
        }

        $request->attributes->set('smartept_endpoint_id', (int) $row->id);
        $request->attributes->set('smartept_endpoint_name', (string) $row->name);
        $request->attributes->set('smartept_events', self::events($row));
        $request->attributes->set('tenant_id', (int) $row->tenant_id);
        $request->attributes->set('company_id', $row->company_id !== null ? (int) $row->company_id : null);

        return $next($request);
    }

    /** @return list<string> */
    private static function events(object $row): array
    {
        $raw = $row->events ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || $raw === []) {
            return SmarteptWebhook::EVENTS;   // subscribed to everything by default
        }

        return array_values(array_filter($raw, 'is_string'));
    }

    private static function deny(string $message, int $status = 401, ?string $code = null)
    {
        return response()->json([
            'error' => [
                'code' => $code ?: 'WEBHOOK_'.$status,
                'message' => $message,
            ],
        ], $status);
    }
}
