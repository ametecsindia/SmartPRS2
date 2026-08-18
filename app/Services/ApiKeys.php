<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SBB — minting and parsing of public API keys.
 *
 * One tiny class so the middleware and the admin screen can never drift on the
 * three rules that matter:
 *
 *   1. A key looks like  sk_prs_<4>_<40>   e.g. sk_prs_ab12_9Xk...
 *      Everything before the LAST underscore is the prefix; the prefix is what
 *      we store, index and show in the UI. The random tail is alphanumeric
 *      (Str::random never emits '_'), so the split is unambiguous.
 *   2. Only sha256 of the WHOLE secret is stored. The plaintext is returned to
 *      the caller of mint() exactly once and then forgotten.
 *   3. Comparison is hash_equals, never ==.
 */
class ApiKeys
{
    /** Product-scoped so a SmartEPT key is visibly not a SmartPRS key. */
    public const PREFIX_BASE = 'sk_prs';

    /**
     * Mint a new secret.
     *
     * @return array{prefix:string,secret:string,hash:string}
     */
    public static function mint(): array
    {
        $prefix = self::freePrefix();
        $secret = $prefix.'_'.Str::random(40);

        return [
            'prefix' => $prefix,
            'secret' => $secret,
            'hash' => self::hash($secret),
        ];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * The stored prefix for a presented key: everything before the last '_'.
     * Returns null when the value cannot be a key at all.
     */
    public static function prefixOf(string $secret): ?string
    {
        $secret = trim($secret);
        $pos = strrpos($secret, '_');
        if ($pos === false || $pos === 0) {
            return null;
        }
        $prefix = substr($secret, 0, $pos);

        return $prefix !== '' && strlen($prefix) <= 12 ? $prefix : null;
    }

    /** A prefix not already taken (36^4 space, so collisions are possible). */
    private static function freePrefix(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = self::PREFIX_BASE.'_'.Str::lower(Str::random(4));
            if (! DB::table('api_keys')->where('prefix', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Astronomically unlikely; lookup tolerates duplicate prefixes anyway.
        return self::PREFIX_BASE.'_'.Str::lower(Str::random(4));
    }
}
