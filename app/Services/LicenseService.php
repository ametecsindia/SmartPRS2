<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev 107 — LICENSING CORE (SRS FR-6..FR-8, approved defaults).
 *
 * Platform side: key generation, lookup, activation, AMC checks, events.
 * Keys: SPRS-XXXX-XXXX-XXXX-XXXX (unambiguous charset). Stored BOTH as a
 * sha256 hash (fast, leak-safe lookup) and Crypt-encrypted (so the panel can
 * re-show a key to the installing engineer — same pattern as gateway secrets).
 *
 * Tables are self-created with Schema guards (house convention, no migrations).
 */
class LicenseService
{
    private const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function ensureTables(): void
    {
        try {
            if (! Schema::hasTable('onprem_clients')) {
                Schema::create('onprem_clients', function ($t) {
                    $t->id();
                    $t->string('company');
                    $t->string('contact_name')->nullable();
                    $t->string('email')->nullable();
                    $t->string('mobile', 30)->nullable();
                    $t->string('gstin', 20)->nullable();
                    $t->string('state', 60)->nullable();
                    $t->text('address')->nullable();
                    $t->string('edition', 8)->default('l1');           // l1|l2|l3
                    $t->string('employee_band', 30)->nullable();        // e.g. "up to 250"
                    $t->decimal('price', 12, 2)->default(0);            // one-time licence price
                    $t->decimal('amc_percent', 5, 2)->default(18);      // Q1 default 18%
                    $t->decimal('paid_total', 12, 2)->default(0);
                    $t->boolean('activate_on_partial')->default(false); // Q2: super-admin tick
                    $t->text('notes')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('onprem_payments')) {
                Schema::create('onprem_payments', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('client_id')->index();
                    $t->decimal('amount', 12, 2);
                    $t->string('mode', 30)->default('neft');            // neft|cheque|upi|gateway|cash
                    $t->string('reference')->nullable();
                    $t->date('paid_on')->nullable();
                    $t->string('entered_by')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('licences')) {
                Schema::create('licences', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('client_id')->index();
                    $t->string('edition', 8);
                    $t->string('key_hash', 64)->unique();
                    $t->text('key_enc');                                 // Crypt — panel can re-show
                    $t->string('key_last4', 4);
                    $t->string('status', 16)->default('pending');        // pending|active|suspended|revoked
                    $t->date('amc_expires_on')->nullable();
                    $t->string('expiry_mode', 12)->default('renew');     // renew|notify (on expiry)
                    $t->timestamp('activated_at')->nullable();
                    $t->string('fingerprint')->nullable();
                    $t->string('server_name')->nullable();
                    $t->unsignedInteger('reactivations_used')->default(0); // Q5: 1/year self-service
                    $t->timestamp('last_seen_at')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('licence_events')) {
                Schema::create('licence_events', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('licence_id')->index();
                    $t->string('type', 30);                              // generated|activated|heartbeat|deactivated|revoked|denied
                    $t->text('detail')->nullable();
                    $t->string('ip', 60)->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('licence_devices')) {
                Schema::create('licence_devices', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('licence_id')->index();
                    $t->string('device_uid', 190)->index();          // the install fingerprint = one server seat
                    $t->string('fingerprint')->nullable();
                    $t->string('hostname')->nullable();
                    $t->string('status', 16)->default('active');       // active|deactivated
                    $t->timestamp('activated_at')->nullable();
                    $t->timestamp('deactivated_at')->nullable();
                    $t->timestamp('last_seen_at')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('releases')) {
                Schema::create('releases', function ($t) {
                    $t->id();
                    $t->string('version', 30)->unique();
                    $t->text('notes')->nullable();                       // plain-language changelog
                    $t->string('file_path')->nullable();                 // storage path of the zip
                    $t->string('checksum', 64)->nullable();              // sha256
                    $t->unsignedBigInteger('size')->default(0);
                    $t->timestamp('published_at')->nullable();
                    $t->timestamp('applied_platform_at')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('release_grants')) {
                Schema::create('release_grants', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('release_id')->index();
                    $t->unsignedBigInteger('client_id')->index();
                    $t->string('granted_by')->nullable();
                    $t->timestamp('emailed_at')->nullable();
                    $t->timestamps();
                });
            }
            if (! Schema::hasTable('client_updates')) {
                Schema::create('client_updates', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('client_id')->nullable()->index();
                    $t->unsignedBigInteger('licence_id')->nullable()->index();
                    $t->string('version', 30)->nullable();
                    $t->string('action', 20);                            // check|download|applied|failed
                    $t->text('detail')->nullable();
                    $t->timestamps();
                });
            }
            // rev141 — expiry behaviour for installs whose licences table
            // predates this column (renew = client enters a new code at login;
            // notify = "LC Expired" notice only). Schema-guarded.
            if (Schema::hasTable('licences') && ! Schema::hasColumn('licences', 'expiry_mode')) {
                Schema::table('licences', function ($t) {
                    $t->string('expiry_mode', 12)->default('renew');
                });
            }
            // anti-fraud + device seats (ported from SmartEPT, 6-Aug-2026), schema-guarded.
            if (Schema::hasTable('licences') && ! Schema::hasColumn('licences', 'server_limit')) {
                Schema::table('licences', function ($t) {
                    $t->unsignedInteger('server_limit')->default(1);   // how many servers/devices this licence may run on
                });
            }
            if (Schema::hasTable('licences') && ! Schema::hasColumn('licences', 'last_mismatch_at')) {
                Schema::table('licences', function ($t) {
                    $t->timestamp('last_mismatch_at')->nullable();     // last time a DIFFERENT machine tried this key
                });
            }
        } catch (\Throwable $e) {
            // fail-soft: a broken DDL must never take the platform down
        }
    }

    /** New plaintext key, e.g. SPRS-7K2M-9XQ4-PT3W-8NJB. */
    public static function generateKey(): string
    {
        $block = function () {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
            }

            return $s;
        };

        return 'SPRS-'.$block().'-'.$block().'-'.$block().'-'.$block();
    }

    public static function normalize(string $key): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
    }

    // ===================================================== offline keys (rev146)
    // A self-contained, SIGNED License Code the client verifies locally — no
    // server round-trip. Format:  SPRSX1.<payload-b64url>.<sig-hex>
    // payload = {e:edition, x:expiry(YYYY-MM-DD), m:mode, c:company, i:issued,
    //            a:account-email (rev167), h:[hardware-ids] (rev147)}
    // sig     = first 40 hex of HMAC-SHA256(payload-b64url, licence_secret).
    // a/h are OPTIONAL locks the client enforces ONLY when present: a → must
    // equal SMARTPRS_LICENCE_EMAIL; every h → must be one of the device's
    // MAC/UUID/serial IDs. Absent claim = works on any email/device.

    public const OFFLINE_PREFIX = 'SPRSX1';

    private static function offlineSecret(): string
    {
        $secret = (string) config('smartprs.licence_secret');
        if ($secret !== '') {
            return $secret;
        }
        // rev166: no working default in production — refuse to sign/verify a
        // License Code with a guessable fallback. Kept at point-of-use so setup,
        // migrations and every non-licensing request still run without the secret
        // (LicenseGate is fail-soft and lets requests through if this throws).
        if (app()->environment('production')) {
            throw new \RuntimeException('SMARTPRS_LICENCE_SECRET is not set. Set a strong, secret value (identical on the licensing server and every client install) before issuing or verifying License Codes.');
        }
        return 'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION';
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }

    /** True if the string looks like an offline self-contained key. */
    public static function isOfflineKey(string $key): bool
    {
        return str_starts_with(trim($key), self::OFFLINE_PREFIX.'.');
    }

    /** Normalise a hardware identifier (MAC/serial/UUID/GUID) for comparison. */
    public static function normalizeHwId(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
    }

    /** Normalise an account email for licence-lock comparison (rev167). */
    public static function normalizeEmail(string $s): string
    {
        return strtolower(trim($s));
    }

    /**
     * Build a signed offline License Code for a client (Super Admin side).
     * $hwLocks (optional) — MAC / serial / UUID / GUID strings the licence is
     * locked to; when present, the client activates only on a matching device.
     */
    public static function makeOfflineKey(string $edition, ?string $expiresOn, string $mode = 'renew', string $company = '', array $hwLocks = [], string $email = ''): string
    {
        $payload = [
            'e' => strtolower($edition),
            'x' => $expiresOn,
            'm' => in_array($mode, ['renew', 'notify'], true) ? $mode : 'renew',
            'c' => mb_substr($company, 0, 80),
            'i' => now()->toDateString(),
        ];
        $hw = [];
        foreach ($hwLocks as $h) {
            $n = self::normalizeHwId((string) $h);
            if (strlen($n) >= 4) {
                $hw[] = $n;
            }
        }
        if ($hw) {
            $payload['h'] = array_values(array_unique($hw));
        }
        // rev167 — optional account-email lock (matched on the client against
        // SMARTPRS_LICENCE_EMAIL). Blank = the code is not email-locked.
        $em = self::normalizeEmail($email);
        if ($em !== '') {
            $payload['a'] = $em;
        }
        $b = self::b64u((string) json_encode($payload));
        $sig = substr(hash_hmac('sha256', $b, self::offlineSecret()), 0, 40);

        return self::OFFLINE_PREFIX.'.'.$b.'.'.$sig;
    }

    /** Verify an offline key locally (client side). Returns the payload or null. */
    public static function verifyOfflineKey(string $key): ?array
    {
        $parts = explode('.', trim($key));
        if (count($parts) !== 3 || $parts[0] !== self::OFFLINE_PREFIX) {
            return null;
        }
        [, $b, $sig] = $parts;
        $expect = substr(hash_hmac('sha256', $b, self::offlineSecret()), 0, 40);
        if (! hash_equals($expect, $sig)) {
            return null;
        }
        $data = json_decode(self::b64uDecode($b), true);

        return is_array($data) ? $data : null;
    }

    /**
     * rev147 — record an offline-issued key server-side so it is REVOCABLE and
     * visible in the On-Prem panel (offline keys are otherwise stateless).
     * Upserts the client's live licence row, so regenerating just updates it.
     */
    public static function recordOffline(int $clientId, string $edition, ?string $expiresOn, string $expiryMode, string $token): void
    {
        self::ensureTables();
        // $token may be a signed .lic OR already a plain SPRS key. Online activation
        // finds a licence by hashKey(EMBEDDED KEY), so index the row by the plain key
        // — never the whole token — or a .lic generated here can never activate online.
        // (key_enc keeps the original token so the .lic can be re-downloaded.)
        $plainKey = self::keyInsideToken($token);
        if ($plainKey === '') {
            $plainKey = $token;
        }
        try {
            $vals = [
                'edition' => $edition,
                'key_hash' => self::hashKey($plainKey),
                'key_enc' => Crypt::encryptString($token),
                'key_last4' => substr(self::normalize($plainKey), -4),
                'status' => 'active',
                'amc_expires_on' => $expiresOn,
                'expiry_mode' => in_array($expiryMode, ['renew', 'notify'], true) ? $expiryMode : 'renew',
                'server_name' => 'offline-key',
                'updated_at' => now(),
            ];
            $live = DB::table('licences')->where('client_id', $clientId)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
            if ($live) {
                DB::table('licences')->where('id', $live->id)->update($vals);
                self::event($live->id, 'generated', 'Offline key (re)issued for client #'.$clientId);
            } else {
                $id = DB::table('licences')->insertGetId($vals + ['client_id' => $clientId, 'created_at' => now()]);
                self::event($id, 'generated', 'Offline key issued for client #'.$clientId);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Pull the embedded SPRS key out of a signed .lic token; '' when not a token. */
    public static function keyInsideToken(string $token): string
    {
        if (! str_contains($token, '.')) {
            return '';
        }
        $b64 = explode('.', trim($token))[0] ?? '';
        $b64 = strtr($b64, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $p = json_decode((string) base64_decode($b64, true), true);

        return is_array($p) ? trim((string) ($p['key'] ?? '')) : '';
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', self::normalize($key));
    }

    /** Licence row by plaintext key (null if unknown). */
    public static function findByKey(string $key): ?object
    {
        self::ensureTables();
        try {
            return DB::table('licences')->where('key_hash', self::hashKey($key))->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Create a licence for a client; returns the PLAINTEXT key (shown once + emailed). */
    public static function issue(int $clientId, string $edition, ?string $amcExpiresOn, string $expiryMode = 'renew'): string
    {
        self::ensureTables();
        $key = self::generateKey();
        $id = DB::table('licences')->insertGetId([
            'client_id' => $clientId,
            'edition' => $edition,
            'key_hash' => self::hashKey($key),
            'key_enc' => Crypt::encryptString($key),
            'key_last4' => substr(self::normalize($key), -4),
            'status' => 'pending',
            'amc_expires_on' => $amcExpiresOn,
            'expiry_mode' => in_array($expiryMode, ['renew', 'notify'], true) ? $expiryMode : 'renew',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::event($id, 'generated', 'Key issued for client #'.$clientId.' ('.$edition.')');

        return $key;
    }

    /** Plaintext key back from the panel (engineer view). */
    public static function reveal(object $licence): ?string
    {
        try {
            return Crypt::decryptString($licence->key_enc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function amcActive(object $licence): bool
    {
        return $licence->amc_expires_on && $licence->amc_expires_on >= now()->toDateString();
    }

    public static function event($licenceId, string $type, string $detail = '', ?string $ip = null): void
    {
        try {
            DB::table('licence_events')->insert([
                'licence_id' => (int) $licenceId, 'type' => $type,
                'detail' => mb_substr($detail, 0, 2000), 'ip' => $ip,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Activation certificate the client stores locally. Signed with an HMAC
     * keyed by the licence key itself — both sides know it, nobody else does.
     */
    public static function certificate(object $licence, string $key, string $fingerprint): array
    {
        $payload = [
            'edition' => $licence->edition,
            'amc_expires_on' => $licence->amc_expires_on,
            'expiry_mode' => $licence->expiry_mode ?? 'renew',
            'fingerprint' => $fingerprint,
            'issued' => now()->toDateTimeString(),
        ];
        $payload['sig'] = hash_hmac('sha256', json_encode($payload), self::normalize($key));

        return $payload;
    }

    // ============================ anti-fraud + device seats (ported from SmartEPT, 6-Aug-2026)

    /** Server-seat cap for a licence (how many servers/devices may run it). Default 1. */
    public static function serverLimit(object $licence): int
    {
        $n = (int) ($licence->server_limit ?? 1);

        return $n > 0 ? $n : 1;
    }

    /** Active server seats claimed against a licence. */
    public static function activeDeviceCount(int $licenceId): int
    {
        try {
            return (int) DB::table('licence_devices')->where('licence_id', $licenceId)->where('status', 'active')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Register/refresh a server (device) seat for a licence. $deviceUid is the
     * install fingerprint. Enforces the server-seat cap. Returns [ok, reason].
     * Fail-soft: a device-table error never blocks the licence.
     */
    public static function recordDevice(int $licenceId, string $deviceUid, ?string $fingerprint = null, ?string $hostname = null): array
    {
        self::ensureTables();
        $deviceUid = mb_substr(trim($deviceUid), 0, 190);
        if ($deviceUid === '') {
            return ['ok' => false, 'reason' => 'no_device'];
        }
        try {
            $row = DB::table('licence_devices')->where('licence_id', $licenceId)->where('device_uid', $deviceUid)->first();
            if ($row) {
                DB::table('licence_devices')->where('id', $row->id)->update([
                    'status' => 'active',
                    'fingerprint' => $fingerprint ?: $row->fingerprint,
                    'hostname' => $hostname ?: $row->hostname,
                    'last_seen_at' => now(), 'deactivated_at' => null, 'updated_at' => now(),
                ]);

                return ['ok' => true, 'reason' => null];
            }
            $limit = (int) (DB::table('licences')->where('id', $licenceId)->value('server_limit') ?: 1);
            if (self::activeDeviceCount($licenceId) >= max(1, $limit)) {
                return ['ok' => false, 'reason' => 'device_limit_reached'];
            }
            DB::table('licence_devices')->insert([
                'licence_id' => $licenceId, 'device_uid' => $deviceUid, 'fingerprint' => $fingerprint,
                'hostname' => $hostname, 'status' => 'active', 'activated_at' => now(),
                'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['ok' => true, 'reason' => null];
        } catch (\Throwable $e) {
            return ['ok' => true, 'reason' => 'store_error'];
        }
    }

    /** Free a server seat (controlled move / offboard). */
    public static function deactivateDevice(int $licenceId, string $deviceUid): bool
    {
        try {
            return (bool) DB::table('licence_devices')
                ->where('licence_id', $licenceId)
                ->where('device_uid', mb_substr(trim($deviceUid), 0, 190))
                ->where('status', 'active')
                ->update(['status' => 'deactivated', 'deactivated_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Once-per-day 'verified' history entry (hourly phone-homes never flood it). */
    public static function verifyOnce(object $licence, ?string $fingerprint): void
    {
        try {
            $already = DB::table('licence_events')->where('licence_id', $licence->id)->where('type', 'verified')
                ->where('created_at', '>=', now()->startOfDay())->exists();
            if (! $already) {
                self::event($licence->id, 'verified', 'Verified by bound server '.($fingerprint ?: ($licence->fingerprint ?: '(no fingerprint)')));
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Anti-fraud: record a rejected validation (deduped once/day per licence+reason)
     * and, for a server mismatch, email sales. Fail-soft — never breaks a phone-home.
     */
    public static function logRejection(object $licence, string $reason, ?string $attempted, ?string $ip = null): void
    {
        try {
            $already = DB::table('licence_events')->where('licence_id', $licence->id)->where('type', 'rejected')
                ->where('detail', 'like', '%'.$reason.'%')->where('created_at', '>', now()->subDay())->exists();
            if ($already) {
                return;
            }
            self::event($licence->id, 'rejected', $reason.' — attempted='.($attempted ?: '(none)').' bound='.($licence->fingerprint ?: '(unbound)'), $ip);
            if ($reason === 'server_mismatch') {
                try {
                    DB::table('licences')->where('id', $licence->id)->update(['last_mismatch_at' => now(), 'updated_at' => now()]);
                } catch (\Throwable $e) {
                }
                self::alertSalesMismatch($licence, $attempted);
            }
        } catch (\Throwable $e) {
        }
    }

    private static function alertSalesMismatch(object $licence, ?string $attempted): void
    {
        try {
            $company = DB::table('onprem_clients')->where('id', $licence->client_id)->value('company') ?: ('client #'.$licence->client_id);
            $to = (string) (config('smartprs.sales_email') ?: env('SMARTPRS_SALES_EMAIL', 'ejaz@ametecsindia.com'));
            \App\Services\MailService::queue([
                'tenant_id' => null,
                'to' => $to,
                'subject' => 'ALERT: SmartPRS licence contacted by a DIFFERENT machine — '.$company.' ('.now()->toDateString().')',
                'heading' => 'Possible licence misuse — '.$company,
                'intro' => 'A machine OTHER than the bound server tried to validate this licence. This can mean an old/"damaged" PC still running after a machine shift, or a cloned installation.',
                'lines' => [
                    'Company: '.$company,
                    'Edition: SmartPRS-'.strtoupper((string) $licence->edition),
                    'Bound server: '.($licence->fingerprint ?: '(not bound)'),
                    'Attempted machine: '.($attempted ?: '—'),
                    'Last good check-in: '.($licence->last_seen_at ?: '—'),
                    'Action: /super -> On-Prem Clients -> this client -> History. If genuine (PC replaced), use "Shift machine".',
                ],
                'kind' => 'licence_alert',
            ]);
        } catch (\Throwable $e) {
        }
    }
}
