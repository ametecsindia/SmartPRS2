<?php

namespace App\Services;

/**
 * Signs offline node-locked .lic tokens for SmartPRS on-prem (Distribution &
 * Licensing / AS-DL). Used by the "Generate .lic" action in the On-Prem Clients
 * screen (/super) and by the smartprs:issue-license command.
 *
 * The PRIVATE key lives ONLY on this /super server
 * (storage/app/keys/license_private.pem) and never ships to a client. The product
 * verifies each token with the PUBLIC key embedded in App\Services\LicenseFile.
 *
 * Token = base64url(payload).base64url(signature),
 * signature = openssl_sign(base64url(payload), PRIVATE_KEY, SHA256).
 */
class LicenseSigner
{
    public function privateKeyPath(): string
    {
        return storage_path('app/keys/license_private.pem');
    }

    /** True when the signing key is present (so /super can offer .lic generation). */
    public function available(): bool
    {
        return is_readable($this->privateKeyPath());
    }

    /**
     * Build + sign a .lic token from licence fields. Accepts a plain array so it
     * fits SmartPRS's DB-table licence storage (no Eloquent Licence model).
     */
    public function sign(array $data): string
    {
        $priv = openssl_pkey_get_private((string) @file_get_contents($this->privateKeyPath()));
        if ($priv === false) {
            throw new \RuntimeException('Licence signing key missing/invalid at ' . $this->privateKeyPath()
                . ' — run  php artisan smartprs:make-license-keys  once on the /super server.');
        }

        $fp = trim((string) ($data['fingerprint'] ?? ''));
        $payload = [
            'v'            => 1,
            'key'          => (string) ($data['key'] ?? ''),
            'company'      => $data['company'] ?? null,
            'edition'      => $data['edition'] ?? null,
            'device_limit' => ! empty($data['device_limit']) ? (int) $data['device_limit'] : null,
            'kind'         => $data['kind'] ?? 'perpetual',
            'deployment'   => $data['deployment'] ?? 'onprem',
            'expires_at'   => $data['expires_at'] ?? null,
            'grace_days'   => (int) ($data['grace_days'] ?? 7),
            'features'     => $data['features'] ?? [],
            'fingerprint'  => $fp !== '' ? $fp : null,
            'issued_at'    => now()->toIso8601String(),
        ];

        $b64 = $this->b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        openssl_sign($b64, $sig, $priv, OPENSSL_ALGO_SHA256);

        return $b64 . '.' . $this->b64url($sig);
    }

    /** A safe .lic filename for a licence key. */
    public function filename(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '.lic';
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
