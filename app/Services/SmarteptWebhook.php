<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SmartEPT webhook receiver — the format, the signature, and the translation.
 *
 * SmartEPT (app/Services/OutboundPusher.php over there) is the sender. It POSTs
 * a JSON body and signs it:
 *
 *     Content-Type:          application/json
 *     X-SmartEPT-Event:      attendance.punch | attendance.daily
 *     X-SmartEPT-Signature:  hash_hmac('sha256', <raw body>, <shared secret>)   hex
 *
 * attendance.punch — one live IN/OUT as it happens:
 *     event, company_id, device_id, employee_code, biometric_employee_id,
 *     punch_type (IN|OUT), punched_at (ATOM, WITH offset), verification_mode,
 *     source, sent_at
 *
 * attendance.daily — one company-day summary:
 *     event, company_id, date, generated_at, count,
 *     records[]: employee_code, employee_name, work_date, status,
 *                first_in (ISO8601|null), last_out (ISO8601|null),
 *                worked_seconds, break_seconds, source
 *
 * Two things about that format need care, and both are handled here rather
 * than by loosening PunchIngestService:
 *
 *   TIME  SmartEPT sends ATOM — "2026-08-26T09:41:00+05:30". PunchIngestService
 *         REJECTS any string carrying an offset, on purpose, because SBB devices
 *         send naive wall clock and a stray offset there means a bug, not a
 *         timezone. SmartEPT's offset is real and authoritative, so we convert
 *         it INTO app-timezone wall clock here and hand the ingest path exactly
 *         what it expects. Converting is the whole job: an unconverted UTC punch
 *         lands 5h30m early, silently, in payroll.
 *
 *   ID    SmartEPT sends no external_id, and it is fire-and-forget with no
 *         delivery guarantee, so the same punch can arrive twice. We derive a
 *         DETERMINISTIC external_id from the punch's own identity, which turns a
 *         re-push into an insertOrIgnore no-op instead of a duplicate row.
 *
 * The tenant NEVER comes from the payload. `company_id` in the body is
 * SmartEPT's own integer, from a different database, unauthenticated. It is
 * used only as salt in the external_id. The real tenant comes from the endpoint
 * row the signature verified against.
 */
class SmarteptWebhook
{
    /**
     * Written into attendance_logs.source.
     *
     * Deliberately ONE value for both events: attlog_natural_unique is
     * (tenant_id, emp_code, punch_at, source), so a first_in in the nightly
     * summary collides with the live punch it describes and is ignored. Split
     * the sources and every relayed punch would be stored twice.
     */
    public const SOURCE = 'smartept';

    public const EVENT_PUNCH = 'attendance.punch';

    public const EVENT_DAILY = 'attendance.daily';

    public const EVENTS = [self::EVENT_PUNCH, self::EVENT_DAILY];

    public const SIGNATURE_HEADER = 'X-SmartEPT-Signature';

    public const EVENT_HEADER = 'X-SmartEPT-Event';

    /** A daily push for a large company is big; a megabyte of it is not. */
    public const MAX_BODY_BYTES = 2097152;

    /** Serial the device_sn is reported under, so the mapping card can group it. */
    public const DEVICE_FALLBACK = 'SMARTEPT';

    // ---- credential ------------------------------------------------------

    /**
     * A new endpoint credential.
     *
     * @return array{slug:string,secret:string}
     */
    public static function mint(): array
    {
        return [
            'slug' => self::freeSlug(),
            // Product-scoped so an operator can see at a glance that a SmartEPT
            // secret is not a SmartPRS API key (sk_prs_...).
            'secret' => 'whk_ept_'.Str::random(48),
        ];
    }

    public static function encryptSecret(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    /** Null when the stored value cannot be decrypted (APP_KEY changed). */
    public static function decryptSecret(?string $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException $e) {
            return null;
        }
    }

    /**
     * Constant-time signature check over the RAW body.
     *
     * The raw body, never a re-encode of the parsed array: json_encode here
     * would reorder nothing but would re-escape slashes and unicode differently
     * from the sender, and every signature would fail.
     */
    public static function signatureMatches(string $rawBody, string $secret, string $presented): bool
    {
        $presented = trim($presented);
        if ($presented === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $presented);
    }

    // ---- translation -----------------------------------------------------

    /**
     * Turn one SmartEPT payload into punches PunchIngestService understands.
     *
     * @return array{punches:list<array<string,string|null>>,skipped:int,note:string|null}
     */
    public static function punchesFor(string $event, array $payload): array
    {
        if ($event === self::EVENT_PUNCH) {
            return self::fromPunch($payload);
        }
        if ($event === self::EVENT_DAILY) {
            return self::fromDaily($payload);
        }

        return ['punches' => [], 'skipped' => 0, 'note' => 'Unsupported event.'];
    }

    /** @return array{punches:list<array<string,string|null>>,skipped:int,note:string|null} */
    private static function fromPunch(array $p): array
    {
        $companyKey = self::str($p['company_id'] ?? '');
        $employeeCode = self::str($p['employee_code'] ?? '');
        $biometricId = self::str($p['biometric_employee_id'] ?? '');
        $identity = $employeeCode !== '' ? $employeeCode : $biometricId;

        $direction = strtoupper(self::str($p['punch_type'] ?? ''));
        if (! in_array($direction, ['IN', 'OUT'], true)) {
            $direction = 'UNKNOWN';
        }

        $at = self::toNaiveLocal(self::str($p['punched_at'] ?? ''));

        // Let PunchIngestService pass its own verdict on the bad ones — it has
        // the reason codes and the per-punch reporting. We only stop when there
        // is literally nothing to build a punch out of.
        if ($identity === '' && $at === null) {
            return ['punches' => [], 'skipped' => 1, 'note' => 'Punch carried neither an employee nor a time.'];
        }

        return [
            'punches' => [self::punch(
                deviceSn: self::str($p['device_id'] ?? '') ?: self::DEVICE_FALLBACK,
                deviceUserId: $biometricId !== '' ? $biometricId : $identity,
                employeeCode: $employeeCode,
                direction: $direction,
                at: $at,
                verifyMode: self::str($p['verification_mode'] ?? '') ?: 'SYSTEM',
                companyKey: $companyKey,
                identity: $identity,
            )],
            'skipped' => 0,
            'note' => null,
        ];
    }

    /**
     * The daily summary as punches: each record's first_in and last_out.
     *
     * A record with neither (absent, holiday, leave) contributes nothing — it is
     * counted as skipped and reported, never invented as a punch.
     *
     * @return array{punches:list<array<string,string|null>>,skipped:int,note:string|null}
     */
    private static function fromDaily(array $payload): array
    {
        $companyKey = self::str($payload['company_id'] ?? '');
        $records = $payload['records'] ?? [];
        if (! is_array($records)) {
            return ['punches' => [], 'skipped' => 0, 'note' => 'Payload had no records array.'];
        }

        $punches = [];
        $skipped = 0;

        foreach ($records as $raw) {
            $r = is_array($raw) ? $raw : [];
            $employeeCode = self::str($r['employee_code'] ?? '');
            if ($employeeCode === '') {
                $skipped++;

                continue;
            }

            $before = count($punches);

            foreach ([['first_in', 'IN'], ['last_out', 'OUT']] as [$field, $direction]) {
                $at = self::toNaiveLocal(self::str($r[$field] ?? ''));
                if ($at === null) {
                    continue;
                }
                $punches[] = self::punch(
                    deviceSn: self::DEVICE_FALLBACK,
                    deviceUserId: $employeeCode,
                    employeeCode: $employeeCode,
                    direction: $direction,
                    at: $at,
                    verifyMode: 'SYSTEM',
                    companyKey: $companyKey,
                    identity: $employeeCode,
                );
            }

            if (count($punches) === $before) {
                $skipped++;   // present in the summary, but with no times to import
            }
        }

        return [
            'punches' => $punches,
            'skipped' => $skipped,
            'note' => $skipped > 0
                ? $skipped.' record(s) produced no punch — no employee code, or no in/out time.'
                : null,
        ];
    }

    /** One punch in PunchIngestService's shape. @return array<string,string|null> */
    private static function punch(
        string $deviceSn,
        string $deviceUserId,
        string $employeeCode,
        string $direction,
        ?string $at,
        string $verifyMode,
        string $companyKey,
        string $identity,
    ): array {
        return [
            // '' rather than null when the time could not be parsed: the field is
            // required, so PunchIngestService answers VALIDATION and the sender
            // is told which punch was wrong instead of it vanishing.
            'punch_at' => $at ?? '',
            'external_id' => self::externalId($companyKey, $identity, $direction, (string) $at),
            'device_sn' => mb_substr($deviceSn, 0, 64),
            'device_user_id' => mb_substr($deviceUserId, 0, 64),
            'employee_code' => $employeeCode !== '' ? mb_substr($employeeCode, 0, 64) : null,
            'direction' => $direction,
            'verify_mode' => mb_substr($verifyMode, 0, 24),
        ];
    }

    /**
     * A stable id for one punch, so a re-push is a duplicate and not a new row.
     *
     * Derived only from the punch's own identity — never from a timestamp the
     * sender stamps per delivery (sent_at, generated_at), which would change on
     * every retry and defeat the whole purpose.
     */
    public static function externalId(string $companyKey, string $identity, string $direction, string $at): string
    {
        return 'ept_'.hash('sha256', implode('|', [
            self::SOURCE, $companyKey, mb_strtolower($identity), strtoupper($direction), $at,
        ]));
    }

    /**
     * ISO-8601 with an offset -> naive local wall clock in the app timezone.
     *
     * This is the inverse of PunchIngestService::parseNaiveLocal's rule, and
     * deliberately so. There, an offset is a sender bug to be rejected. Here it
     * is the sender's contract, it is trustworthy, and dropping it instead of
     * converting is what stores an Indian punch five and a half hours early.
     *
     * Anything that is not clearly a date is refused rather than guessed:
     * DateTimeImmutable would happily read "now" or "+1 day".
     */
    public static function toNaiveLocal(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || ! preg_match('~^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}~', $v)) {
            return null;
        }

        try {
            // No offset in the string means the sender meant local time, and
            // PHP's default zone is already config('app.timezone').
            $d = new \DateTimeImmutable($v);

            return $d->setTimezone(new \DateTimeZone((string) config('app.timezone')))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---- internals -------------------------------------------------------

    private static function freeSlug(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = Str::lower(Str::random(16));
            if (! DB::table('smartept_webhook_endpoints')->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        return Str::lower(Str::random(24));
    }

    private static function str($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
