<?php

namespace App\Services;

use App\Jobs\NotifyLateArrivals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SBB (Smart Biometric Bridge) — the authenticated JSON ingest path.
 *
 * This is the SECOND way punches reach SmartPRS. The first, /iclock (see
 * PushController), stays exactly as it is: real ZKTeco hardware speaks it and
 * cannot send a credential. This path is for the on-premise SBB Windows service,
 * which collects from any vendor's device and forwards over HTTPS with a key.
 *
 * Four behaviours here are deliberately unlike the push path, because each of
 * them is a live defect on that path:
 *
 *   TENANT      The tenant always comes from the API key and is always passed to
 *               the matcher. The push path passes null, which makes
 *               ETimeOfficeService.php:321 drop the tenant filter, so EMP001
 *               matches an arbitrary customer's EMP001.
 *   TIME        punch_at is naive local wall clock, parsed in the app timezone,
 *               and a string carrying an offset is REJECTED. Carbon::parse would
 *               keep the sender's offset and the later ->format() would render
 *               and then discard it, storing a UTC punch 5h30m early on an
 *               Indian install, silently, into payroll.
 *   NOT LOST    A punch whose PIN maps to nobody goes to attendance_pending and
 *               is reported to the sender, instead of being dropped.
 *   TOLD        Every punch gets a per-punch verdict. An at-least-once sender
 *               that is told only "200" cannot know what actually landed.
 *
 * A second authenticated sender now shares this path: the SmartEPT webhook
 * receiver (App\Services\SmarteptWebhook). It passes its own $source so its
 * punches stay distinguishable in attendance_logs, and it converts SmartEPT's
 * ISO-8601 times to naive local BEFORE calling in. The TIME rule above is not
 * relaxed for it and must not be — the conversion belongs to the adapter that
 * knows the offset is trustworthy, not to this service.
 */
class PunchIngestService
{
    /** Default source. A caller on another path passes its own to ingest(). */
    public const SOURCE = 'sbb';

    /** Cap on one batch, mirrored by the controller's validation. */
    public const MAX_BATCH = 1000;

    /**
     * Ingest one batch.
     *
     * @param  array<int,mixed>  $punches  raw, unvalidated punch payloads
     * @param  string  $source  written to attendance_logs.source; part of
     *                          attlog_natural_unique, so two senders reporting
     *                          the same punch under different sources both store
     *                          it. Reuse one value per real-world sender.
     * @return array{batch:array<string,int>,results:list<array<string,string|null>>}
     */
    public static function ingest(array $punches, int $tenantId, ?int $companyId, string $keyPrefix, string $source = self::SOURCE): array
    {
        $results = [];
        $counts = ['received' => count($punches), 'accepted' => 0, 'duplicates' => 0, 'pending' => 0, 'rejected' => 0];

        $empCache = [];        // "full|raw" => employee row|false
        $prefixCache = [];     // device_sn  => emp_prefix
        $touched = [];         // emp_code   => [Y-m-d => true]   (late-arrival check)
        $unmapped = [];        // device code => count            (mapping card)
        $devices = [];         // device_sn  => true              (for the log line)

        DB::transaction(function () use (
            $punches, $tenantId, $companyId, $source,
            &$results, &$counts, &$empCache, &$prefixCache, &$touched, &$unmapped, &$devices
        ) {
            foreach ($punches as $raw) {
                $p = is_array($raw) ? $raw : [];
                $externalId = self::str($p['external_id'] ?? null);

                $bad = self::validate($p);
                if ($bad !== null) {
                    $results[] = ['external_id' => $externalId ?: null, 'status' => 'rejected', 'reason' => $bad];
                    $counts['rejected']++;

                    continue;
                }

                $deviceSn = self::str($p['device_sn']);
                $deviceUserId = self::str($p['device_user_id']);
                $devices[$deviceSn] = true;

                $when = self::parseNaiveLocal(self::str($p['punch_at']));
                if ($when === null) {
                    $results[] = ['external_id' => $externalId, 'status' => 'rejected', 'reason' => 'TIME_FORMAT'];
                    $counts['rejected']++;

                    continue;
                }

                $direction = strtolower(self::str($p['direction']));
                $verifyMode = self::str($p['verify_mode'] ?? null) ?: null;

                // --- resolve the employee, ALWAYS inside this tenant ----------
                $employeeCode = self::str($p['employee_code'] ?? null);
                $full = $employeeCode !== ''
                    ? $employeeCode
                    : self::prefixFor($deviceSn, $tenantId, $prefixCache).$deviceUserId;

                $cacheKey = $full.'|'.$deviceUserId;
                if (! array_key_exists($cacheKey, $empCache)) {
                    $empCache[$cacheKey] = ETimeOfficeService::matchEmployee($full, $deviceUserId, $tenantId) ?: false;
                }
                $emp = $empCache[$cacheKey];

                // --- unmapped: quarantine, never discard ----------------------
                if (! $emp) {
                    self::quarantine([
                        'tenant_id' => $tenantId,
                        'company_id' => $companyId,
                        'device_sn' => $deviceSn,
                        'device_user_id' => $deviceUserId,
                        'punch_at' => $when->format('Y-m-d H:i:s'),
                        'direction' => $direction,
                        'verify_mode' => $verifyMode,
                        'external_id' => $externalId,
                        'source' => $source,
                        'resolved_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $unmapped[$full] = ($unmapped[$full] ?? 0) + 1;
                    $results[] = ['external_id' => $externalId, 'status' => 'pending', 'reason' => 'EMPLOYEE_NOT_MAPPED'];
                    $counts['pending']++;

                    continue;
                }

                // --- matched: let the unique indexes decide ------------------
                // insertOrIgnore, never SELECT-then-INSERT: SBB is at-least-once
                // and two concurrent retries of the same batch WILL race.
                $inserted = DB::table('attendance_logs')->insertOrIgnore([
                    'tenant_id' => $emp->tenant_id,
                    'company_id' => $emp->company_id,
                    'emp_code' => $emp->emp_code,
                    'emp_name' => $emp->name,
                    'log_date' => $when->toDateString(),
                    'punch_at' => $when->format('Y-m-d H:i:s'),
                    'direction' => $direction,
                    'source' => $source,
                    'device_sn' => $deviceSn,
                    'device_user_id' => $deviceUserId,
                    'external_id' => $externalId,
                    'verify_mode' => $verifyMode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted > 0) {
                    $results[] = ['external_id' => $externalId, 'status' => 'accepted'];
                    $counts['accepted']++;
                    if ($direction === 'in') {
                        $touched[$emp->emp_code][$when->toDateString()] = true;
                    }
                } else {
                    $results[] = ['external_id' => $externalId, 'status' => 'duplicate'];
                    $counts['duplicates']++;
                }
            }
        });

        // --- after the batch is durable ----------------------------------
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'rejected') {
                Log::warning('sbb.punch.rejected', [
                    'key' => $keyPrefix,
                    'tenant' => $tenantId,
                    'external_id' => $r['external_id'],
                    'reason' => $r['reason'] ?? null,
                ]);
            }
        }

        Log::info('sbb.ingest.batch', [
            'key' => $keyPrefix,
            'tenant' => $tenantId,
            'source' => $source,
            'device_sn' => implode(',', array_slice(array_keys($devices), 0, 5)),
            'received' => $counts['received'],
            'accepted' => $counts['accepted'],
            'duplicates' => $counts['duplicates'],
            'pending' => $counts['pending'],
            'rejected' => $counts['rejected'],
        ]);

        // Surface unknown device codes to the existing Employee Mapping card.
        if ($unmapped) {
            ETimeOfficeService::recordUnmapped($tenantId, $unmapped, $source);
        }

        // Late-arrival mail is QUEUED. ETimeOfficeService::import sends it inline
        // (LateArrivalService.php:289), which blocks the response on SMTP, times
        // the sender out, and causes the retry that creates the duplicates.
        if ($touched) {
            try {
                NotifyLateArrivals::dispatch($tenantId, $touched);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['batch' => $counts, 'results' => $results];
    }

    /**
     * Promote every unresolved quarantined punch for a device ID into
     * attendance_logs. Called when an admin maps that ID to an employee.
     *
     * @return int rows promoted
     */
    public static function replayPending(?int $tenantId, string $deviceUserId, object $emp): int
    {
        if (! Schema::hasTable('attendance_pending') || ! Schema::hasTable('attendance_logs')) {
            return 0;
        }
        $deviceUserId = trim($deviceUserId);
        if ($deviceUserId === '') {
            return 0;
        }

        $promoted = 0;
        $touched = [];

        try {
            DB::table('attendance_pending')
                ->whereNull('resolved_at')
                ->whereRaw('LOWER(device_user_id) = ?', [strtolower($deviceUserId)])
                ->when(
                    $tenantId,
                    fn ($q) => $q->where('tenant_id', $tenantId),
                    fn ($q) => $q->whereNull('tenant_id')
                )
                ->chunkById(200, function ($rows) use ($emp, &$promoted, &$touched) {
                    foreach ($rows as $row) {
                        $when = Carbon::parse($row->punch_at);

                        DB::table('attendance_logs')->insertOrIgnore([
                            'tenant_id' => $emp->tenant_id,
                            'company_id' => $emp->company_id,
                            'emp_code' => $emp->emp_code,
                            'emp_name' => $emp->name,
                            'log_date' => $when->toDateString(),
                            'punch_at' => $when->format('Y-m-d H:i:s'),
                            'direction' => $row->direction ?: 'unknown',
                            'source' => $row->source ?: self::SOURCE,
                            'device_sn' => $row->device_sn,
                            'device_user_id' => $row->device_user_id,
                            'external_id' => $row->external_id,
                            'verify_mode' => $row->verify_mode,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // resolved_at is stamped whether the insert landed or was
                        // ignored as a duplicate: either way the punch is now in
                        // attendance_logs and must not be replayed again.
                        DB::table('attendance_pending')
                            ->where('id', $row->id)
                            ->update(['resolved_at' => now(), 'updated_at' => now()]);

                        $promoted++;
                        if (($row->direction ?: '') === 'in') {
                            $touched[$emp->emp_code][$when->toDateString()] = true;
                        }
                    }
                });
        } catch (\Throwable $e) {
            report($e);
        }

        if ($promoted > 0) {
            Log::info('sbb.pending.replayed', [
                'tenant' => $tenantId,
                'device_user_id' => $deviceUserId,
                'emp_code' => $emp->emp_code,
                'promoted' => $promoted,
            ]);
        }
        if ($touched) {
            try {
                NotifyLateArrivals::dispatch($tenantId, $touched);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $promoted;
    }

    /**
     * Parse a NAIVE LOCAL wall clock, "Y-m-d H:i:s", in the app timezone.
     *
     * Returns null — meaning reason TIME_FORMAT — for anything else, including
     * anything carrying a timezone offset or Z. We never guess a timezone: the
     * digits a device reports ARE the local time at that device, and a punch
     * that arrives as "2026-08-16 09:41:00+05:30" is a sender bug we want to see
     * in the response, not a punch to store five and a half hours early.
     */
    public static function parseNaiveLocal(string $value): ?Carbon
    {
        $v = trim($value);

        // Explicit offset or zone designator anywhere at the end -> reject.
        if (preg_match('~(?:[Zz]|[+\-]\d{2}:?\d{2}|\s[A-Za-z]{2,5})$~', $v)) {
            return null;
        }
        if (! preg_match('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $v)) {
            return null;
        }

        try {
            $d = Carbon::createFromFormat('Y-m-d H:i:s', $v, config('app.timezone'));
        } catch (\Throwable $e) {
            return null;
        }
        if (! $d instanceof Carbon) {
            return null;
        }

        // Round-trip guard: rejects rolled-over nonsense like 2026-02-30 10:00:00.
        return $d->format('Y-m-d H:i:s') === $v ? $d : null;
    }

    // ---- internals -------------------------------------------------------

    /** @return string|null a reason code, or null when the punch is well formed */
    private static function validate(array $p): ?string
    {
        $required = [
            'external_id' => 96,
            'device_sn' => 64,
            'device_user_id' => 64,
            'punch_at' => 32,
            'direction' => 16,
        ];
        foreach ($required as $field => $max) {
            $v = $p[$field] ?? null;
            if (! is_scalar($v) || trim((string) $v) === '' || strlen(trim((string) $v)) > $max) {
                return 'VALIDATION';
            }
        }

        $optional = ['employee_code' => 64, 'verify_mode' => 24, 'device_status_raw' => 8];
        foreach ($optional as $field => $max) {
            $v = $p[$field] ?? null;
            if ($v !== null && (! is_scalar($v) || strlen(trim((string) $v)) > $max)) {
                return 'VALIDATION';
            }
        }

        if (! in_array(strtoupper(trim((string) $p['direction'])), ['IN', 'OUT', 'UNKNOWN'], true)) {
            return 'VALIDATION';
        }

        return null;
    }

    /** Quarantine one punch. Duplicate external_id is a no-op by unique index. */
    private static function quarantine(array $row): void
    {
        DB::table('attendance_pending')->insertOrIgnore($row);
    }

    /**
     * The emp_prefix configured for this device serial, if the admin set one on
     * the Biometric Device Setup screen (device "1043" + prefix "EMP" -> "EMP1043").
     */
    private static function prefixFor(string $deviceSn, int $tenantId, array &$cache): string
    {
        if (array_key_exists($deviceSn, $cache)) {
            return $cache[$deviceSn];
        }

        $prefix = '';
        try {
            if ($deviceSn !== '' && Schema::hasTable('biometric_configs')) {
                $prefix = (string) (DB::table('biometric_configs')
                    ->where('serial_number', $deviceSn)
                    ->where('tenant_id', $tenantId)
                    ->orderByDesc('id')
                    ->value('emp_prefix') ?? '');
            }
        } catch (\Throwable $e) {
            $prefix = '';
        }

        return $cache[$deviceSn] = trim($prefix);
    }

    private static function str($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
