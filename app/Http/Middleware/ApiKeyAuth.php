<?php

namespace App\Http\Middleware;

use App\Services\ApiKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SBB — API key authentication for the stateless JSON ingest path.
 *
 * Usage:
 *   ->middleware('api-key')            any valid key
 *   ->middleware('api-key:ingest')     a valid key that also holds the scope
 *
 * The identity of the caller comes from the KEY and nothing else. tenant_id and
 * company_id are lifted off the key row into $request->attributes; the request
 * body is never consulted for them. This is the whole point of the endpoint:
 * the /iclock push path takes its tenant from an auto-registered device row that
 * is NULL on first contact, which makes ETimeOfficeService.php:321 skip the
 * tenant filter and lets one customer's EMP001 match another customer's EMP001.
 * Nothing here can reproduce that.
 */
class ApiKeyAuth
{
    /** Do not write last_used_at more than once per key per minute. */
    private const TOUCH_SECONDS = 60;

    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $presented = self::presentedKey($request);
        if ($presented === '') {
            return self::deny(401, 'API key missing. Send it as "X-Api-Key: <key>" or "Authorization: Bearer <key>".');
        }

        if (! Schema::hasTable('api_keys')) {
            return self::deny(401, 'API keys are not configured on this server.');
        }

        $prefix = ApiKeys::prefixOf($presented);
        if ($prefix === null) {
            return self::deny(401, 'API key is not valid.');
        }

        $row = self::locate($prefix, $presented);
        if (! $row) {
            return self::deny(401, 'API key is not valid.');
        }
        if (! $row->active) {
            return self::deny(401, 'API key has been revoked.');
        }
        if ($row->expires_at !== null && now()->greaterThan($row->expires_at)) {
            return self::deny(401, 'API key expired on '.substr((string) $row->expires_at, 0, 16).'.');
        }

        $scopes = self::scopes($row);
        if ($scope !== null && ! in_array($scope, $scopes, true)) {
            return self::deny(403, 'API key does not have the "'.$scope.'" scope.', 'API_KEY_403');
        }

        // A key with no tenant cannot be written safely: both attendance_logs
        // unique indexes include tenant_id, and NULL never collides in a unique
        // index — so a NULL-tenant key would silently lose its de-duplication
        // guarantee and re-insert every retried punch. Refuse instead.
        if ($scope === 'ingest' && $row->tenant_id === null) {
            return self::deny(401, 'API key is not bound to a tenant and cannot be used to ingest punches. Re-create the key from Settings → API Keys while signed in to the workspace it belongs to.');
        }

        $request->attributes->set('api_key_id', (int) $row->id);
        $request->attributes->set('api_key_prefix', (string) $row->prefix);
        $request->attributes->set('api_key_name', (string) $row->name);
        $request->attributes->set('tenant_id', $row->tenant_id !== null ? (int) $row->tenant_id : null);
        $request->attributes->set('company_id', $row->company_id !== null ? (int) $row->company_id : null);
        $request->attributes->set('scopes', $scopes);

        self::touch($row);

        return $next($request);
    }

    /** X-Api-Key, else an Authorization: Bearer header. */
    private static function presentedKey(Request $request): string
    {
        $header = trim((string) $request->header('X-Api-Key', ''));
        if ($header !== '') {
            return $header;
        }
        $auth = trim((string) $request->header('Authorization', ''));
        if ($auth !== '' && preg_match('~^Bearer\s+(.+)$~i', $auth, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Find the key row by prefix, then confirm the full secret in constant time.
     * The prefix is only a lookup handle — it is NOT a credential, so a match on
     * it proves nothing until hash_equals agrees.
     */
    private static function locate(string $prefix, string $presented): ?object
    {
        $hash = ApiKeys::hash($presented);

        foreach (DB::table('api_keys')->where('prefix', $prefix)->orderBy('id')->get() as $row) {
            if (hash_equals((string) $row->key_hash, $hash)) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function scopes(object $row): array
    {
        $raw = $row->scopes ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($s) => is_string($s) ? trim($s) : null,
            $raw
        )));
    }

    /** last_used_at, throttled to at most once per minute per key. */
    private static function touch(object $row): void
    {
        try {
            $last = $row->last_used_at ?? null;
            if ($last !== null && now()->diffInSeconds($last, true) < self::TOUCH_SECONDS) {
                return;
            }
            DB::table('api_keys')->where('id', $row->id)->update(['last_used_at' => now()]);
        } catch (\Throwable $e) {
            // Telemetry only — never fail a valid request because of it.
        }
    }

    private static function deny(int $status, string $message, ?string $code = null)
    {
        return response()->json([
            'error' => [
                'code' => $code ?: 'API_KEY_'.$status,
                'message' => $message,
            ],
        ], $status);
    }
}
