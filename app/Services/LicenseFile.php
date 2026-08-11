<?php

namespace App\Services;

/**
 * Offline, node-locked licence file for SmartPRS on-prem (Distribution & Licensing / AS-DL).
 *
 * A `license.lic` is an RSA-signed token: base64url(payload).base64url(signature).
 * The /super Central signs the payload with a PRIVATE key it never ships; this class
 * verifies it with the PUBLIC key embedded below. Verification is fully OFFLINE — no
 * network. The token is bound to a machine fingerprint, so copying the file to another
 * PC fails.
 *
 * This class only PROVES a token is genuine and for this machine. Folding a verified
 * token into SmartPRS's local licence record (the settings 'licence' cert used by
 * ClientUpdateController::licenceStatus()/licenceValid()) is done by the controller,
 * so every existing downstream check — the login gate, expiry/offline grace, the
 * Licence screen — keeps working unchanged.
 *
 * Payload shape (produced by /super LicenseSigner):
 *   { v, key, company, plan|edition, device_limit, kind, deployment,
 *     expires_at (YYYY-MM-DD), grace_days, features[], fingerprint, issued_at }
 */
class LicenseFile
{
    /**
     * SmartPRS Central licence PUBLIC key. Its matching PRIVATE key lives ONLY on the
     * /super server (storage/app/keys/license_private.pem) and is never shipped.
     *
     * To rotate keys: run `php artisan smartprs:make-license-keys --force` on /super and
     * paste the printed public block here, then rebuild the l1/l2/l3 edition installs.
     * Rotating invalidates every .lic already issued.
     */
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqlK8Q6CYHa4+TIH9YDGw
5w89+Aa7h7EtQvxNSEFtRmep7Z2IX2nURYpaqYSEyZd/tPlhwikdF7iM8tgfbG9P
FQLNMnm0jQ8yOEpNE9ROyepYnQdACJaCJxGIFqLv5gH/IeAj261WoOFUhzW5E7cn
3/ViYHXXj0JliOo2swQL7l1B86KzN1/a9Bc03OzVFRn5TLAcd9MfcuIPKHWnCFVf
kzAIuEXkY0Ruaes/qDhsFDGx5tTDhv8fQrPj+p7RFVKTHzZvSGIcrAMbpGI4diGw
tILAddBEJL+uKflXNLwRyGUS7B8Eb+vVuS3BfmQSNoJo/G7WTC3xdCzqvKoRlsj9
AwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /** Where the licence file lives (defaults to license.lic in the app root). */
    public function path(): string
    {
        $p = (string) config('smartprs.license_file');
        return $p !== '' ? $p : base_path('license.lic');
    }

    /** True when a licence file is present on disk. */
    public function exists(): bool
    {
        return is_readable($this->path());
    }

    /** Stable, hard-to-move machine fingerprint (SMBIOS/OS UUID, hashed). */
    public function machineFingerprint(): string
    {
        // Cache the fingerprint so it is computed ONCE, not by spawning a Windows
        // command (wmic/powershell) on every request. Spawning per-request is slow
        // and can intermittently fail — which would make a valid licence look
        // "wrong machine" and bounce the admin to the login screen. A per-request
        // static cache plus a persisted file (storage/app/.machine_fp) keep it fast
        // and STABLE for the life of the install.
        static $fp = null;
        if ($fp !== null) {
            return $fp;
        }
        $cacheFile = storage_path('app/.machine_fp');
        if (is_readable($cacheFile)) {
            $c = trim((string) @file_get_contents($cacheFile));
            if (strlen($c) === 40 && ctype_xdigit($c)) {
                return $fp = $c;
            }
        }
        $fp = substr(hash('sha256', 'SMARTPRS|' . $this->rawMachineId()), 0, 40);
        @file_put_contents($cacheFile, $fp);

        return $fp;
    }

    private function rawMachineId(): string
    {
        try {
            if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
                $out = (string) @shell_exec('wmic csproduct get uuid 2>NUL');
                if (preg_match('/[0-9A-Fa-f]{8}-?[0-9A-Fa-f]{4}-?[0-9A-Fa-f]{4}-?[0-9A-Fa-f]{4}-?[0-9A-Fa-f]{12}/', $out, $m)) {
                    return strtoupper($m[0]);
                }
                $out = (string) @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID" 2>NUL');
                if (trim($out) !== '') {
                    return strtoupper(trim($out));
                }
            } elseif (PHP_OS_FAMILY === 'Linux') {
                foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
                    if (is_readable($f)) {
                        return trim((string) file_get_contents($f));
                    }
                }
            } elseif (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
                $out = (string) @shell_exec("ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null | awk -F'\"' '/IOPlatformUUID/{print \$4}'");
                if (trim($out) !== '') {
                    return trim($out);
                }
            }
        } catch (\Throwable $e) {
            // fall through to the weak fallback
        }

        // Fallback (never empty). Weaker than a hardware UUID but keeps the app usable.
        return php_uname('n') . '|' . php_uname('s') . '|' . php_uname('m');
    }

    /**
     * Verify a licence TOKEN string (base64url(payload).base64url(sig)).
     * Returns ['ok'=>bool,'reason'=>?string,'payload'=>?array].
     */
    public function verifyToken(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'reason' => 'no_file'];
        }

        $parts = explode('.', $raw);
        if (count($parts) !== 2) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        [$b64, $sig64] = $parts;
        $payloadJson = $this->b64urlDecode($b64);
        $sig = $this->b64urlDecode($sig64);
        if ($payloadJson === false || $sig === false) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $pub = openssl_pkey_get_public(self::PUBLIC_KEY);
        if ($pub === false) {
            return ['ok' => false, 'reason' => 'bad_public_key'];
        }

        // Signature is over the exact base64url payload string.
        if (openssl_verify($b64, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'reason' => 'invalid_signature'];
        }

        $p = json_decode($payloadJson, true);
        if (! is_array($p) || empty($p['key'])) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        // Node-lock: the token MUST be for THIS machine. 11 Aug 2026 (flowchart
        // integrity) — a .lic with NO fingerprint is a FLOATING licence that would
        // run on any PC, breaking "one licence <-> one machine". Refuse it. Genuine
        // fresh installs activate ONLINE (the server binds the hardware fingerprint)
        // and never reach this offline check, so they are unaffected; only an
        // air-gapped .lic must carry its target machine's fingerprint.
        $fp = (string) ($p['fingerprint'] ?? '');
        if ($fp === '') {
            return ['ok' => false, 'reason' => 'not_locked', 'payload' => $p];
        }
        if (! hash_equals($fp, $this->machineFingerprint())) {
            return ['ok' => false, 'reason' => 'wrong_machine', 'payload' => $p];
        }

        return ['ok' => true, 'reason' => null, 'payload' => $p];
    }

    /** Verify the licence file currently on disk. Same return shape as verifyToken(). */
    public function verify(): array
    {
        if (! $this->exists()) {
            return ['ok' => false, 'reason' => 'no_file'];
        }

        return $this->verifyToken((string) file_get_contents($this->path()));
    }

    /** Persist a licence token to the licence path (used on import from the Licence screen). */
    public function write(string $token): void
    {
        @file_put_contents($this->path(), trim($token) . "\n");
    }

    private function b64urlDecode(string $s)
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
