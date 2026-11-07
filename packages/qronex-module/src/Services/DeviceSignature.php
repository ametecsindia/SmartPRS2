<?php

namespace Ametecs\Qronex\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that a request comes from the approved phone (same scheme as ROVEXY RequestSignature):
 * canonical = METHOD \n PATH+QUERY \n UNIX_TS \n NONCE \n hex(sha256(body)),
 * ECDSA P-256 / SHA-256 (DER, base64) — what Android Keystore "SHA256withECDSA" produces.
 * A stolen bearer token is useless without the phone's non-exportable private key.
 */
class DeviceSignature
{
    public const MAX_SKEW = 300;

    /** base64 DER SubjectPublicKeyInfo (EC P-256 only) → PEM, or null if invalid. */
    public static function pemFromBase64Der(?string $b64): ?string
    {
        $der = base64_decode(strtr(trim((string) $b64), '-_', '+/'), true);
        if ($der === false || strlen($der) < 80 || strlen($der) > 200) {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $d = $key ? openssl_pkey_get_details($key) : null;

        return ($d['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($d['ec']['curve_name'] ?? '') === 'prime256v1' ? $pem : null;
    }

    public static function canonical(string $method, string $uri, string $ts, string $nonce, string $body): string
    {
        return strtoupper($method)."\n".$uri."\n".$ts."\n".$nonce."\n".hash('sha256', $body);
    }

    /** null = valid; otherwise an error code: signature_missing | signature_stale | signature_invalid | signature_replayed */
    public static function check(Request $r, string $pem): ?string
    {
        $ts = (string) $r->header('X-Timestamp');
        $nonce = (string) $r->header('X-Nonce');
        $sig = (string) $r->header('X-Signature');
        if ($ts === '' || $nonce === '' || $sig === '') {
            return 'signature_missing';
        }
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > self::MAX_SKEW) {
            return 'signature_stale';
        }
        $raw = base64_decode(strtr($sig, '-_', '+/'), true);
        if (! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) || ! $raw
            || openssl_verify(self::canonical($r->getMethod(), $r->getRequestUri(), $ts, $nonce, $r->getContent()), $raw, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return 'signature_invalid';
        }
        // replay check only after a valid signature, so nobody can burn nonces
        return Cache::add('qx_nonce:'.hash('sha256', $pem).':'.$nonce, 1, self::MAX_SKEW * 2) ? null : 'signature_replayed';
    }
}
