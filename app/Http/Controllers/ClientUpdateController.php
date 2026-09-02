<?php

namespace App\Http\Controllers;

use App\Services\Edition;
use App\Services\LicenseFile;
use App\Services\LicenseService;
use App\Services\UpdateClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * rev 107 — ON-PREM CLIENT SIDE: activation + Administration → Updates.
 *
 * Local licence state lives in the settings table (tenant_id 0, key
 * 'licence'): {key_enc, cert, activated_at, last_check, last_ok}.
 * The update server URL is config('smartprs.update_url') — baked default
 * https://smartprs.com/update (SRS FR-5.1).
 *
 * Apply (FR-9.2): download → sha256 verify → backup code folders →
 * extract (never .env / storage / vendor) → migrate → clear caches.
 * Any failure → restore the backup taken in the same run.
 */
class ClientUpdateController extends Controller
{
    // ---------- local licence state ----------

    public static function state(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }
            $raw = DB::table('settings')->where('tenant_id', 0)->where('key', 'licence')->value('value');

            return $raw ? (json_decode($raw, true) ?: []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function saveState(array $st): void
    {
        try {
            $row = ['tenant_id' => 0, 'key' => 'licence'];
            $vals = ['value' => json_encode($st), 'updated_at' => now()];
            if (DB::table('settings')->where($row)->exists()) {
                DB::table('settings')->where($row)->update($vals);
            } else {
                DB::table('settings')->insert($row + $vals + ['created_at' => now()]);
            }
        } catch (\Throwable $e) {
        }
    }

    public static function activated(): bool
    {
        $st = self::state();

        return ! empty($st['cert']) && ! empty($st['activated_at']);
    }

    /**
     * rev139 — expiry-aware licence state for the LOGIN gate.
     * Returns ['state' => 'none'|'expired'|'ok', 'expires_on' => ?string,
     * 'company' => ?string]. Uses the LOCALLY stored certificate (offline
     * grace): once activated the install keeps running offline until the
     * stored amc_expires_on passes — the online check only happens when a
     * code is entered or via the periodic heartbeat.
     */
    public static function licenceStatus(): array
    {
        $st = self::state();
        $cert = $st['cert'] ?? [];
        $company = $st['company'] ?? null;
        // Offline keys can only be renewed by entering a new code (no server to
        // pull a renewal from), so they always use the 'renew' field on expiry.
        $mode = (($cert['expiry_mode'] ?? 'renew') === 'notify' && empty($cert['offline'])) ? 'notify' : 'renew';
        if (empty($cert) || empty($st['activated_at'])) {
            return ['state' => 'none', 'expires_on' => null, 'company' => $company, 'mode' => $mode];
        }
        // RSA .lic (source=file) is node-locked: re-verify the on-disk file on
        // every check, so copying license.lic to another machine — or deleting
        // it — blocks access even though a cert is stored locally. Fail-soft: a
        // transient openssl error falls through to the stored-cert check below.
        if (($cert['source'] ?? null) === 'file') {
            try {
                $vf = (new LicenseFile())->verify();
                if (empty($vf['ok'])) {
                    return ['state' => 'none', 'expires_on' => null, 'company' => $company, 'mode' => $mode];
                }
                if (! empty($vf['payload']['expires_at'])) {
                    $cert['amc_expires_on'] = $vf['payload']['expires_at'];
                }
            } catch (\Throwable $e) {
                // fail-soft — trust the stored cert
            }
        }
        $exp = $cert['amc_expires_on'] ?? null;
        if ($exp && $exp < now()->toDateString()) {
            return ['state' => 'expired', 'expires_on' => $exp, 'company' => $company, 'mode' => $mode];
        }

        return ['state' => 'ok', 'expires_on' => $exp, 'company' => $company, 'mode' => $mode];
    }

    /**
     * rev141 — silently re-validate the STORED key online (no user input).
     * Used by the login flow in 'notify' mode: if the Super Admin has renewed
     * the licence server-side, this pulls the fresh certificate and the
     * install unblocks itself. Offline or still-expired → returns ok=false.
     */
    public static function recheckStored(): array
    {
        $st = self::state();
        try {
            $key = ! empty($st['key_enc']) ? Crypt::decryptString($st['key_enc']) : '';
        } catch (\Throwable $e) {
            $key = '';
        }
        if ($key === '') {
            return ['ok' => false, 'error' => 'No stored licence to re-check.'];
        }

        return self::activateKey($key);
    }

    /** rev147 — best-effort server status for a key: active|revoked|suspended, or null when unreachable. */
    private static function serverLicenceStatus(string $key): ?string
    {
        try {
            // Send THIS machine's fingerprint so Central can catch a second/cloned
            // server using the same key (anti-fraud, ported from SmartEPT).
            $resp = Http::timeout(6)->post(config('smartprs.update_url').'/heartbeat', [
                'key' => $key,
                'fingerprint' => self::fingerprint(),
                'server_name' => gethostname() ?: 'unknown',
            ]);
            $j = $resp->json();
            if (! is_array($j)) {
                return null;
            }
            if (! empty($j['ok'])) {
                return strtolower((string) ($j['status'] ?? 'active')) ?: 'active';
            }
            $reason = strtolower((string) ($j['reason'] ?? ''));
            if ($reason === 'server_mismatch') {
                return 'server_mismatch';
            }
            if (str_starts_with($reason, 'licence_')) {
                return substr($reason, 8);   // revoked | suspended | expired
            }

            return null;
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * rev147 — HYBRID revocation. Called at login: when the client has internet
     * it checks the server (throttled to once a day) and, if the licence is
     * revoked/suspended, wipes the local certificate so access is blocked and
     * the revoked key is remembered. Offline → no-op (runs on the stored cert
     * until expiry). On-prem only; fail-soft.
     */
    public static function revocationSweep(): void
    {
        try {
            if (! Edition::isOnPrem()) {
                return;
            }
            $st = self::state();
            if (empty($st['cert']) || empty($st['key_enc'])) {
                return;
            }
            $last = $st['last_revcheck'] ?? null;
            if ($last && \Carbon\Carbon::parse($last)->gt(now()->subDay())) {
                return;   // already checked recently
            }
            try {
                $key = Crypt::decryptString($st['key_enc']);
            } catch (\Throwable $e) {
                return;
            }
            if ($key === '') {
                return;
            }
            $status = self::serverLicenceStatus($key);
            if ($status === null) {
                return;   // unreachable → offline grace, leave the cert in place
            }
            $st['last_revcheck'] = now()->toDateTimeString();
            $st['last_ok'] = now()->toDateTimeString();
            if (in_array($status, ['revoked', 'suspended', 'server_mismatch'], true)) {
                // revoked/suspended → remember the key as blocked; server_mismatch →
                // block THIS install (not the bound server) but do NOT blacklist the
                // key, so the genuine server keeps working / Shift-machine re-binds.
                if ($status !== 'server_mismatch') {
                    $rev = $st['revoked_hashes'] ?? [];
                    $rev[] = LicenseService::hashKey($key);
                    $st['revoked_hashes'] = array_values(array_unique($rev));
                }
                $st['cert'] = [];        // wipe → licenceValid() is now false (blocked)
                $st['revoked'] = true;
                $st['block_reason'] = $status;
            }
            self::saveState($st);
        } catch (\Throwable $e) {
        }
    }

    /**
     * True when access should be allowed. ALWAYS true off the on-prem
     * editions (or when enforcement is off) so SaaS / Super Admin login is
     * never touched. On an enforced on-prem install it is true only while a
     * stored, unexpired certificate exists.
     */
    public static function licenceValid(): bool
    {
        // Node-locked .lic activation applies ONLY to a TRUE on-prem install
        // (SMARTPRS_DEPLOYMENT=onprem). A SaaS / CLOUD-HOSTED instance — even when
        // it runs an L1/L2/L3 EDITION purely for feature-gating — is governed by
        // hosting + subscription, NEVER by a .lic, so it must never show the
        // activation gate. (Fix 6 Aug 2026: previously this keyed off edition
        // alone, so a cloud client on an L-edition wrongly demanded a .lic.)
        $deployment = strtolower((string) (config('smartprs.deployment') ?? env('SMARTPRS_DEPLOYMENT', 'saas')));
        if ($deployment !== 'onprem'
            || ! Edition::isOnPrem()
            || ! filter_var(config('smartprs.licence_enforce', true), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return self::licenceStatus()['state'] === 'ok';
    }

    /**
     * rev139 — ONLINE validate a License Code against the licence server and,
     * on success, store the signed certificate locally. Shared by the
     * Activation screen and the login-form LC field. Returns
     * ['ok' => bool, 'error' => ?string, 'company' => ?string].
     */
    public static function activateKey(string $key): array
    {
        $key = trim($key);

        // RSA-signed .lic token (base64url.base64url).
        if (self::looksLikeLicenceFile($key)) {
            // 10 Aug 2026 (activation flowchart) — INTERNET IS MANDATORY for initial
            // activation so the server captures the machine fingerprint. Local/offline
            // .lic verification is only used when explicitly enabled (air-gapped).
            if (filter_var(config('smartprs.offline_lic', false), FILTER_VALIDATE_BOOLEAN)) {
                return self::activateLicenceFile($key);
            }
            // Offline disabled: pull the embedded licence key out of the .lic and
            // activate ONLINE (below), so Central checks + captures THIS machine's
            // fingerprint. With no internet the online call fails and activation stops.
            $embedded = self::keyFromLicenceFile($key);
            if ($embedded === '') {
                return ['ok' => false, 'error' => 'This PC must be connected to the Internet to activate. Offline licence files are disabled — connect to the Internet and try again.'];
            }
            $key = $embedded;
        }

        // AS-DL cutover: the legacy SPRSX1 HMAC offline path is retired — offline
        // activation is RSA .lic only (handled above). Remaining input is treated
        // as an ONLINE server-validated key.
        if (strlen(LicenseService::normalize($key)) < 16) {
            return ['ok' => false, 'error' => 'That does not look like a SmartPRS License Code — please check and try again.'];
        }
        try {
            $resp = Http::timeout(20)->post(config('smartprs.update_url').'/activate', [
                'key' => $key,
                'fingerprint' => self::fingerprint(),
                'server_name' => gethostname() ?: 'unknown',
                'edition' => Edition::current(),
            ]);
            $j = $resp->json();
            if (! is_array($j) || empty($j['ok'])) {
                return ['ok' => false, 'error' => is_array($j) && ! empty($j['error']) ? $j['error'] : 'Could not reach the licence server — check the internet connection and try again.'];
            }
            // Subscription/block model: never accept an already-expired code.
            $exp = $j['cert']['amc_expires_on'] ?? ($j['amc_expires_on'] ?? null);
            if ($exp && $exp < now()->toDateString()) {
                return ['ok' => false, 'error' => 'That License Code expired on '.$exp.'. Please obtain a current code from Ametecs (WhatsApp 9000098877).'];
            }
            self::saveState([
                'key_enc' => Crypt::encryptString(LicenseService::normalize($key)),
                'cert' => $j['cert'] ?? [],
                'company' => $j['company'] ?? '',
                'activated_at' => now()->toDateTimeString(),
                'last_ok' => now()->toDateTimeString(),
            ]);

            return ['ok' => true, 'error' => null, 'company' => $j['company'] ?? ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the licence server — check the internet connection and try again.'];
        }
    }

    /**
     * rev146 — validate an OFFLINE self-contained License Code locally (no
     * server) and store a self-signed certificate. The key carries its own
     * edition/expiry/mode and a signature the client verifies with the shared
     * licence secret. Returns ['ok','error','company'].
     */
    public static function activateOfflineKey(string $key): array
    {
        $data = LicenseService::verifyOfflineKey($key);
        if (! $data) {
            return ['ok' => false, 'error' => 'That License Code is invalid or has been tampered with. Please check it and try again.'];
        }
        // rev147 — hybrid revocation: a key revoked while this machine was
        // online is remembered locally, so re-entering it stays blocked.
        $h = LicenseService::hashKey($key);
        $st0 = self::state();
        if (in_array($h, $st0['revoked_hashes'] ?? [], true)) {
            return ['ok' => false, 'error' => 'This License Code has been revoked. Please contact Ametecs (WhatsApp 9000098877).'];
        }
        $ed = strtolower((string) ($data['e'] ?? ''));
        if ($ed !== '' && $ed !== Edition::current()) {
            return ['ok' => false, 'error' => 'This License Code is for SmartPRS-'.strtoupper($ed).', but this installation is SmartPRS-'.strtoupper(Edition::current()).'.'];
        }
        $exp = $data['x'] ?? null;
        if ($exp && $exp < now()->toDateString()) {
            return ['ok' => false, 'error' => 'That License Code expired on '.$exp.'. Please obtain a current code from Ametecs (WhatsApp 9000098877).'];
        }
        // rev147 — optional HARDWARE LOCK: when the key carries device IDs, it
        // activates ONLY on a machine that presents all of them.
        $locks = $data['h'] ?? [];
        if (is_array($locks) && $locks) {
            $machine = self::machineHardwareIds();
            foreach ($locks as $lock) {
                $ln = LicenseService::normalizeHwId((string) $lock);
                if ($ln !== '' && ! in_array($ln, $machine, true)) {
                    return ['ok' => false, 'error' => 'This License Code is locked to a different device and cannot be used on this computer. Please contact Ametecs (WhatsApp 9000098877).'];
                }
            }
        }
        // rev167 — optional EMAIL LOCK: when the key carries an account email it
        // activates ONLY where this install's configured licence email
        // (SMARTPRS_LICENCE_EMAIL) matches. Absent claim = works on any email.
        $lockEmail = LicenseService::normalizeEmail((string) ($data['a'] ?? ''));
        if ($lockEmail !== '') {
            $deviceEmail = LicenseService::normalizeEmail((string) config('smartprs.licence_email'));
            if ($deviceEmail === '' || ! hash_equals($lockEmail, $deviceEmail)) {
                return ['ok' => false, 'error' => 'This License Code is issued for a different account email than the one configured on this installation. Please contact Ametecs (WhatsApp 9000098877).'];
            }
        }
        // rev147 — hybrid: if the server is reachable AND says this key is
        // revoked/suspended, block now and remember it; offline → trust the key.
        if (in_array(self::serverLicenceStatus($key), ['revoked', 'suspended'], true)) {
            $rev = $st0['revoked_hashes'] ?? [];
            $rev[] = $h;
            self::saveState(array_merge($st0, ['revoked_hashes' => array_values(array_unique($rev))]));

            return ['ok' => false, 'error' => 'This License Code has been revoked. Please contact Ametecs (WhatsApp 9000098877).'];
        }
        self::saveState([
            'key_enc' => Crypt::encryptString($key),
            'cert' => [
                'edition' => $ed ?: Edition::current(),
                'amc_expires_on' => $exp,
                'expiry_mode' => ($data['m'] ?? 'renew') === 'notify' ? 'notify' : 'renew',
                'fingerprint' => self::fingerprint(),
                'hw' => $locks ?: null,
                'email' => $lockEmail ?: null,
                'issued' => $data['i'] ?? now()->toDateString(),
                'offline' => true,
            ],
            'company' => (string) ($data['c'] ?? ''),
            'activated_at' => now()->toDateTimeString(),
            'last_ok' => now()->toDateTimeString(),
            'revoked_hashes' => $st0['revoked_hashes'] ?? [],
        ]);

        return ['ok' => true, 'error' => null, 'company' => (string) ($data['c'] ?? '')];
    }

    /** True when a string looks like an RSA .lic token: two base64url parts, not SPRSX1/SPRS-. */
    public static function looksLikeLicenceFile(string $key): bool
    {
        $t = trim($key);
        if ($t === '' || LicenseService::isOfflineKey($t) || stripos($t, 'SPRS-') === 0) {
            return false;
        }
        $parts = explode('.', $t);

        return count($parts) === 2
            && strlen($parts[0]) >= 40
            && preg_match('/^[A-Za-z0-9_-]+$/', $parts[0]) === 1
            && preg_match('/^[A-Za-z0-9_-]+$/', $parts[1]) === 1;
    }

    /** Pull the embedded licence key out of a .lic token's base64url payload, so an
     *  offline-disabled install can activate it ONLINE. The server re-validates the
     *  key, so we only need to decode (not signature-verify) the payload here. */
    private static function keyFromLicenceFile(string $token): string
    {
        try {
            $b64 = explode('.', trim($token))[0] ?? '';
            $b64 = strtr($b64, '-_', '+/');
            $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
            $p = json_decode((string) base64_decode($b64, true), true);

            return is_array($p) ? trim((string) ($p['key'] ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * AS-DL — activate an RSA-signed offline .lic token. Verified locally against
     * the public key embedded in LicenseFile; node-locked to this machine's
     * fingerprint. On success the token is written to license.lic and folded into
     * the same local licence cert every downstream check already uses.
     */
    public static function activateLicenceFile(string $token): array
    {
        $lf = new LicenseFile();
        $v = $lf->verifyToken($token);
        if (empty($v['ok'])) {
            $map = [
                'wrong_machine' => 'This licence file is locked to a different computer and cannot be used on this server. Ask Ametecs to re-issue it for this machine (WhatsApp 9000098877).',
                'not_locked' => 'This licence file is not tied to a computer, so it cannot be used (one licence works on one machine only). Send Ametecs this server\'s fingerprint from the Activation screen and we will re-issue it (WhatsApp 9000098877).',
                'invalid_signature' => 'This licence file is invalid or has been tampered with. Please use the exact .lic file Ametecs sent you.',
                'malformed' => 'This does not look like a SmartPRS licence file. Please paste the whole code or upload the .lic file Ametecs sent you.',
                'bad_public_key' => 'This SmartPRS build cannot read its licence key. Please contact Ametecs (WhatsApp 9000098877).',
                'no_file' => 'No licence file was provided.',
            ];

            return ['ok' => false, 'error' => $map[$v['reason'] ?? 'malformed'] ?? $map['malformed']];
        }
        $p = $v['payload'];

        // Edition lock: the file must match this installation's edition.
        $ed = strtolower((string) ($p['edition'] ?? $p['plan'] ?? ''));
        if ($ed !== '' && $ed !== Edition::current()) {
            return ['ok' => false, 'error' => 'This licence file is for SmartPRS-'.strtoupper($ed).', but this installation is SmartPRS-'.strtoupper(Edition::current()).'.'];
        }

        // Never accept an already-expired file.
        $exp = $p['expires_at'] ?? null;
        if ($exp && $exp < now()->toDateString()) {
            return ['ok' => false, 'error' => 'This licence expired on '.$exp.'. Please obtain a current licence file from Ametecs (WhatsApp 9000098877).'];
        }

        // Locally-remembered revocation (a key revoked while online stays blocked).
        $st0 = self::state();
        $h = LicenseService::hashKey((string) ($p['key'] ?? $token));
        if (in_array($h, $st0['revoked_hashes'] ?? [], true)) {
            return ['ok' => false, 'error' => 'This licence has been revoked. Please contact Ametecs (WhatsApp 9000098877).'];
        }

        // Seat cap at activation (Ejaz: strict): refuse a .lic that already covers
        // fewer seats than the install currently has active on-roll. Growth past
        // the cap AFTER activation is blocked at the Add-Employee door (via
        // SubscriptionService::seatUsage), not here — this install keeps running.
        $limit = (int) ($p['device_limit'] ?? 0);
        if ($limit > 0) {
            $active = self::activeEmployeeCount();
            if ($active > $limit) {
                return ['ok' => false, 'error' => 'This licence covers '.$limit.' employees, but this installation currently has '.$active.' active on the payroll. Obtain a licence for your team size, or move surplus employees to Inactive/Old-data, then activate (WhatsApp 9000098877).'];
            }
        }

        // Persist the file (node-lock re-checks it on every request) and fold the
        // verified entitlements into the local cert used by licenceStatus().
        $lf->write($token);
        self::saveState([
            'key_enc' => Crypt::encryptString((string) ($p['key'] ?? '')),
            'cert' => [
                'edition' => $ed ?: Edition::current(),
                'amc_expires_on' => $exp,
                'expiry_mode' => 'renew',   // a .lic renews by importing a fresh file
                'fingerprint' => $lf->machineFingerprint(),
                'device_limit' => $limit ?: null,
                'features' => $p['features'] ?? [],
                'kind' => $p['kind'] ?? null,
                'deployment' => $p['deployment'] ?? null,
                'source' => 'file',
                'offline' => true,
            ],
            'company' => (string) ($p['company'] ?? ''),
            'activated_at' => now()->toDateTimeString(),
            'last_ok' => now()->toDateTimeString(),
            'revoked_hashes' => $st0['revoked_hashes'] ?? [],
        ]);

        return ['ok' => true, 'error' => null, 'company' => (string) ($p['company'] ?? '')];
    }

    /** Seat ceiling from the active .lic (device_limit); null = unlimited/none. */
    public static function seatLimit(): ?int
    {
        $cert = self::state()['cert'] ?? [];
        $lim = (int) ($cert['device_limit'] ?? 0);

        return $lim > 0 ? $lim : null;
    }

    /** Active on-roll employees on this install (mirrors SubscriptionService seat rules). */
    public static function activeEmployeeCount(): int
    {
        try {
            if (! Schema::hasTable('employees')) {
                return 0;
            }
            $free = ['exited', 'inactive', 'resigned', 'terminated', 'left', 'absconded'];
            $q = DB::table('employees');
            if (Schema::hasColumn('employees', 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            if (Schema::hasColumn('employees', 'archived_at')) {
                $q->whereNull('archived_at');
            }
            if (Schema::hasColumn('employees', 'status')) {
                $q->where(fn ($w) => $w->whereNull('status')->orWhereNotIn(DB::raw('LOWER(status)'), $free));
            }

            return (int) $q->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Stable installation fingerprint (server identity, not hardware-fragile). */
    public static function fingerprint(): string
    {
        // 11 Aug 2026 (integrity / activation flowchart) — bind to the HARDWARE
        // machine id (SMBIOS / OS UUID), NOT hostname+path. The old
        // hash(hostname|edition|base_path) collided across PCs that shared a
        // hostname or the same install folder, so the update server could not tell
        // two machines apart and a licence bound on one PC validated on another.
        // This is the SAME hardware fingerprint shown on the /activate screen, so
        // what the admin sees is exactly what the server binds: one licence, one PC.
        try {
            $fp = (new \App\Services\LicenseFile())->machineFingerprint();
            if (is_string($fp) && $fp !== '') {
                return $fp;
            }
        } catch (\Throwable $e) {
            // fall through to the legacy value so activation never hard-fails
        }

        return hash('sha256', (gethostname() ?: 'host').'|'.Edition::current().'|'.base_path());
    }

    /**
     * rev147 — best-effort read of this machine's hardware identifiers
     * (MAC addresses, machine UUID/GUID, BIOS serial), normalised for offline
     * hardware-lock matching. Windows-first with a Linux fallback; fail-soft.
     */
    public static function machineHardwareIds(): array
    {
        $ids = [];
        try {
            if (stripos(PHP_OS, 'WIN') === 0) {
                $mac = @shell_exec('getmac /fo csv /nh 2>NUL');
                foreach (preg_split('/\r?\n/', (string) $mac) as $line) {
                    if (preg_match('/([0-9A-Fa-f]{2}[-:]){5}[0-9A-Fa-f]{2}/', $line, $m)) {
                        $ids[] = LicenseService::normalizeHwId($m[0]);
                    }
                }
                $uuid = @shell_exec('wmic csproduct get uuid 2>NUL');
                if (! trim((string) $uuid)) {
                    $uuid = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID" 2>NUL');
                }
                $serial = @shell_exec('wmic bios get serialnumber 2>NUL');
                if (! trim((string) $serial)) {
                    $serial = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_BIOS).SerialNumber" 2>NUL');
                }
                foreach ([$uuid, $serial] as $blob) {
                    foreach (preg_split('/\r?\n/', (string) $blob) as $line) {
                        $line = trim($line);
                        if ($line === '' || stripos($line, 'uuid') === 0 || stripos($line, 'serial') === 0) {
                            continue;
                        }
                        $n = LicenseService::normalizeHwId($line);
                        if (strlen($n) >= 5) {
                            $ids[] = $n;
                        }
                    }
                }
            } else {
                foreach (glob('/sys/class/net/*/address') ?: [] as $f) {
                    $mac = trim((string) @file_get_contents($f));
                    if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
                        $ids[] = LicenseService::normalizeHwId($mac);
                    }
                }
                foreach (['/sys/class/dmi/id/product_uuid', '/sys/class/dmi/id/product_serial', '/etc/machine-id'] as $f) {
                    if (@is_readable($f)) {
                        $v = trim((string) @file_get_contents($f));
                        if ($v !== '') {
                            $ids[] = LicenseService::normalizeHwId($v);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function guard(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->hasRole('admin') || $u->hasRole('super_admin')), 403, 'Admin only.');
    }

    // ---------- activation ----------

    /** GET /app/activate — the branded activation screen. */
    public function activateShow(Request $request)
    {
        $this->guard($request);

        return view('licence-activate', [
            'edition' => Edition::label(),
            'activated' => self::activated(),
            'state' => self::state(),
            'deviceEmail' => (string) config('smartprs.licence_email'),
            'deviceIds' => self::machineHardwareIds(),
            'fingerprint' => (new LicenseFile())->machineFingerprint(),
            'seatUsed' => self::activeEmployeeCount(),
            'seatLimit' => self::seatLimit(),
        ]);
    }

    /** POST /app/activate {key} or an uploaded .lic file */
    public function activatePost(Request $request)
    {
        $this->guard($request);
        $token = trim((string) $request->input('key', ''));
        // Allow uploading the .lic file instead of pasting its contents.
        if ($token === '' && $request->hasFile('licence_file')) {
            try {
                $token = trim((string) file_get_contents($request->file('licence_file')->getRealPath()));
            } catch (\Throwable $e) {
                $token = '';
            }
        }
        $res = self::activateKey($token);
        if (empty($res['ok'])) {
            return back()->with('lic_err', $res['error'] ?? 'Could not activate — please try again.');
        }

        return redirect('/app')->with('lic_ok', 'SmartPRS is activated. Welcome aboard!');
    }

    /**
     * GET /activate — PRE-LOGIN activation (AS-DL). On-prem only. Shows the
     * machine fingerprint + accepts the .lic by paste OR file upload before the
     * admin signs in, so a fresh node-locked install can be activated without
     * first needing an authenticated session.
     */
    public function publicActivateShow(Request $request)
    {
        abort_unless(\App\Services\Edition::isOnPrem(), 404);
        if (self::activated()) {
            return redirect('/login')->with('status', 'This installation is already activated. Please sign in.');
        }

        return view('licence-activate', [
            'edition' => Edition::label(),
            'activated' => self::activated(),
            'state' => self::state(),
            'deviceEmail' => (string) config('smartprs.licence_email'),
            'deviceIds' => self::machineHardwareIds(),
            'fingerprint' => (new LicenseFile())->machineFingerprint(),
            'seatUsed' => self::activeEmployeeCount(),
            'seatLimit' => self::seatLimit(),
            'formAction' => url('/activate'),
        ]);
    }

    /** POST /activate — activate from a pasted code or uploaded .lic, pre-login (on-prem only). */
    public function publicActivatePost(Request $request)
    {
        abort_unless(\App\Services\Edition::isOnPrem(), 404);
        $token = trim((string) $request->input('key', ''));
        if ($token === '' && $request->hasFile('licence_file')) {
            try {
                $token = trim((string) file_get_contents($request->file('licence_file')->getRealPath()));
            } catch (\Throwable $e) {
                $token = '';
            }
        }
        $res = self::activateKey($token);
        if (empty($res['ok'])) {
            return back()->with('lic_err', $res['error'] ?? 'Could not activate — please try again.');
        }

        return redirect('/login')->with('status', 'SmartPRS is activated. Please sign in to continue.');
    }

    // ---------- Administration → Updates (self-update flow chart, 2 Sep 2026) ----------
    //
    // Four deliberate steps, each its own call, so the screen can show honest
    // progress and a failure can never be mistaken for a success:
    //
    //     status  → what this server runs and what it last found
    //     check   → chart step 3: ask the platform
    //     download→ chart steps 4-5: fetch the package and verify its hash
    //     install → chart steps 6-12: hand off to the STANDALONE updater
    //
    // Nothing here ever writes over the application tree. PHP cannot reliably
    // overwrite the files it is executing — on Windows/IIS it cannot do it at
    // all while they are open — so the install is performed by
    // updater/updater.php running as its own process, outside Laravel.

    private function key(): ?string
    {
        $st = self::state();
        try {
            return ! empty($st['key_enc']) ? Crypt::decryptString($st['key_enc']) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function updates(): UpdateClient
    {
        return app(UpdateClient::class);
    }

    /** GET /app/updates/status — everything the screen shows. */
    public function status(Request $request)
    {
        $this->guard($request);
        $st = self::state();
        $u = $this->updates();
        $live = $u->state();
        $hist = [];
        try {
            if (Schema::hasTable('client_updates')) {
                $hist = DB::table('client_updates')->orderByDesc('id')->limit(20)->get();
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok' => true,
            'version' => $u->currentVersion(),
            'channel' => $u->channel(),
            'edition' => Edition::label(),
            'activated' => self::activated(),
            'company' => $st['company'] ?? '',
            'amc_expires_on' => $st['cert']['amc_expires_on'] ?? ($live['amc_expires_on'] ?? null),
            'last_check' => $live['checked_at'] ?? ($st['last_check'] ?? null),
            // The live state file is authoritative for the offer; $st['pending'] is
            // only kept so an install made before this feature still renders.
            'pending' => $live['available'] ?? ($st['pending'] ?? null),
            'phase' => $live['phase'],
            'percent' => $live['percent'],
            'message' => $live['message'],
            'log' => array_slice((array) $live['log'], -20),
            // Told to the screen BEFORE the admin commits, so "install" never
            // fails on something we already knew at page load.
            'can_install' => $u->phpBinary() !== null && $u->canSpawn(),
            'history' => $hist,
        ]);
    }

    /** POST /app/updates/check — chart step 3: ask the platform. */
    public function check(Request $request)
    {
        $this->guard($request);
        $res = $this->updates()->check();

        // Keep the legacy local licence state in step with the check, so the login
        // gate and the AMC banner still read the same dates they always did.
        $st = self::state();
        $st['last_check'] = now()->toDateTimeString();
        if (! empty($res['ok'])) {
            $st['last_ok'] = now()->toDateTimeString();
            $st['pending'] = $res['available'] ?? null;
            if (! empty($res['amc_expires_on'])) {
                $st['cert']['amc_expires_on'] = $res['amc_expires_on'];
            }
        }
        self::saveState($st);

        $this->logLocal('check', ! empty($res['available'])
            ? ('offered '.$res['available']['version'])
            : (string) ($res['message'] ?? ''));

        // 'update' is repeated in the old shape so nothing that read it breaks.
        return response()->json($res + ['update' => $res['available'] ?? null]);
    }

    /** POST /app/updates/download — chart steps 4-5: download, then verify the hash. */
    public function download(Request $request)
    {
        $this->guard($request);
        @set_time_limit(900);
        $res = $this->updates()->download();
        $this->logLocal($res['ok'] ? 'download' : 'failed', (string) ($res['message'] ?? ''));

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    /**
     * POST /app/updates/install — chart steps 6-12, handed to the standalone updater.
     *
     * The updater puts SmartPRS into maintenance mode, so the screen cannot poll
     * this application for progress while it runs. It is given a one-time token
     * and polls public/update-status.php instead, which reads the same state file
     * without booting anything.
     */
    public function install(Request $request)
    {
        $this->guard($request);
        $u = $this->updates();

        $state = $u->state();
        $state['poll_token'] = \Illuminate\Support\Str::random(40);
        $u->writeState($state);

        $res = $u->install();
        if (! empty($res['ok'])) {
            $this->logLocal('applying', 'started '.($state['available']['version'] ?? ''));
        } else {
            $this->logLocal('failed', (string) ($res['message'] ?? ''));
        }

        return response()->json($res + [
            'poll_url' => url('/update-status.php?t='.$state['poll_token']),
        ], ! empty($res['ok']) ? 200 : 422);
    }

    /** POST /app/updates/reset — forget a half-finished attempt so the screen starts clean. */
    public function resetUpdate(Request $request)
    {
        $this->guard($request);
        $this->updates()->reset();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /app/updates/apply {version} — the ORIGINAL one-button route, kept so
     * an older browser tab (or an install that has not refreshed its boot JS)
     * still works. It no longer extracts anything itself: it is now download
     * followed by install, exactly what the two new buttons do.
     */
    public function apply(Request $request)
    {
        $this->guard($request);
        @set_time_limit(900);

        $dl = $this->updates()->download();
        if (empty($dl['ok'])) {
            return response()->json($dl, 422);
        }

        return $this->install($request);
    }

    private function logLocal(string $action, string $detail): void
    {
        try {
            LicenseService::ensureTables();
            DB::table('client_updates')->insert([
                'client_id' => null, 'licence_id' => null,
                'version' => config('smartprs.version'), 'action' => $action,
                'detail' => mb_substr($detail, 0, 2000),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
