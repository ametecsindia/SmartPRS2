<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * eTimeOffice (api.etimeoffice.com) cloud-attendance connector.
 *
 * Config is a plain array (provider, base_url, endpoint, corp_id, username,
 * password, empcode, emp_prefix). It comes from the per-tenant biometric_configs
 * row (frontend "Biometric Device Setup" screen) or, as a fallback, from .env.
 *
 * Auth: HTTP Basic where the username is "CorpID:User:Password:true" and the
 * password is the password. Punches are pulled for a window via DownloadPunchData*
 * and written into attendance_logs, matched to employees by emp_code AFTER the
 * emp_prefix is applied (device "12345" + prefix "A" -> "A12345").
 */
class ETimeOfficeService
{
    /** Config from .env (config/smartprs.php). */
    public static function envConfig(): array
    {
        $c = (array) config('smartprs.etimeoffice');

        return [
            'provider' => 'etimeoffice',
            'enabled' => (bool) ($c['enabled'] ?? false),
            'base_url' => $c['base_url'] ?? 'https://api.etimeoffice.com/api',
            'endpoint' => $c['endpoint'] ?? 'DownloadPunchDataMCID',
            'corp_id' => $c['corp_id'] ?? null,
            'username' => $c['username'] ?? null,
            'password' => $c['password'] ?? null,
            'empcode' => $c['empcode'] ?? 'ALL',
            'emp_prefix' => $c['emp_prefix'] ?? '',
            'tenant_id' => null,
        ];
    }

    /** Decode a biometric_configs DB row into a config array (password decrypted). */
    public static function rowToCfg(object $r): array
    {
        $pwd = null;
        if (! empty($r->password)) {
            try {
                $pwd = Crypt::decryptString($r->password);
            } catch (\Throwable $e) {
                $pwd = $r->password; // tolerate a plain value
            }
        }

        $provider = $r->provider ?? 'etimeoffice';

        return [
            'id' => $r->id ?? null,
            'provider' => $provider,
            'enabled' => (bool) ($r->enabled ?? false),
            'last_sync_at' => $r->last_sync_at ?? null,
            'sync_interval_min' => $r->sync_interval_min ?? null,
            // eTimeTrackLite keeps the raw WebAPI URL; eTimeOffice falls back to the cloud default.
            'base_url' => $r->base_url ?: ($provider === 'etimetracklite' ? '' : 'https://api.etimeoffice.com/api'),
            'endpoint' => $r->endpoint ?: 'DownloadPunchDataMCID',
            'corp_id' => $r->corp_id,
            'serial_number' => $r->serial_number ?? null,   // etimetracklite — device serial
            'api_config' => $r->api_config ?? null,          // generic — full request/mapping recipe (JSON)
            'username' => $r->username,
            'password' => $pwd,
            'empcode' => $r->empcode ?: 'ALL',
            'emp_prefix' => $r->emp_prefix ?? '',
            'in_machine_id' => $r->in_machine_id ?? '',    // rev173e
            'out_machine_id' => $r->out_machine_id ?? '',  // rev173e
            'tenant_id' => $r->tenant_id ?? null,
        ];
    }

    /** Resolve config for one tenant: DB row first, else .env. */
    public static function configForTenant(?int $tid): ?array
    {
        if (Schema::hasTable('biometric_configs')) {
            $q = DB::table('biometric_configs');
            $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');
            $row = $q->orderByDesc('id')->first();
            if ($row) {
                return self::rowToCfg($row);
            }
        }
        $env = self::envConfig();

        return self::configured($env) ? $env : null;
    }

    /** Every enabled config to sync (all tenant rows, else .env). */
    public static function allConfigs(): array
    {
        $out = [];
        if (Schema::hasTable('biometric_configs')) {
            foreach (DB::table('biometric_configs')->where('enabled', true)->get() as $r) {
                $cfg = self::rowToCfg($r);
                if (self::configuredAny($cfg)) {
                    $out[] = $cfg;
                }
            }
        }
        if (! $out) {
            $env = self::envConfig();
            if (($env['enabled'] ?? false) && self::configured($env)) {
                $out[] = $env;
            }
        }

        return $out;
    }

    public static function configured(array $cfg): bool
    {
        return ! empty($cfg['corp_id']) && ! empty($cfg['username']) && ! empty($cfg['password']);
    }

    /** Provider-aware "is this connection usable?" — routes eTimeTrackLite to its own check. */
    public static function configuredAny(array $cfg): bool
    {
        $p = $cfg['provider'] ?? '';
        if ($p === 'etimetracklite') {
            return ETimeTrackLiteService::configured($cfg);
        }
        if ($p === 'generic') {
            return GenericApiService::configured($cfg);
        }

        return self::configured($cfg);
    }

    private static function authPair(array $cfg): array
    {
        $user = $cfg['corp_id'].':'.$cfg['username'].':'.$cfg['password'].':true';

        return [$user, (string) $cfg['password']];
    }

    /**
     * Fetch raw punch data for [from, to].
     * @return array{ok:bool,status:int,body:string,json:?array,error:?string}
     */
    public static function fetch(array $cfg, Carbon $from, Carbon $to): array
    {
        $base = rtrim((string) ($cfg['base_url'] ?: 'https://api.etimeoffice.com/api'), '/');
        $path = trim((string) ($cfg['endpoint'] ?: 'DownloadPunchDataMCID'), '/');
        $url = $base.'/'.$path;
        [$bu, $bp] = self::authPair($cfg);

        try {
            $resp = Http::withBasicAuth($bu, $bp)->timeout(60)->get($url, [
                'Empcode' => $cfg['empcode'] ?: 'ALL',
                'FromDate' => $from->format('d/m/Y_H:i'),
                'ToDate' => $to->format('d/m/Y_H:i'),
            ]);
            $json = null;
            try {
                $json = $resp->json();
            } catch (\Throwable $e) {
            }

            return [
                'ok' => $resp->successful(),
                'status' => $resp->status(),
                'body' => $resp->body(),
                'json' => is_array($json) ? $json : null,
                'error' => $resp->successful() ? null : ('HTTP '.$resp->status()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => $e->getMessage()];
        }
    }

    private static function isList(array $a): bool
    {
        return array_keys($a) === range(0, count($a) - 1);
    }

    private static function pick(array $row, array $keys): ?string
    {
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (array_key_exists($lk, $lower) && trim((string) $lower[$lk]) !== '') {
                return (string) $lower[$lk];
            }
        }

        return null;
    }

    private static function parseDate(string $v): ?Carbon
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y_H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
                if ($d !== false) {
                    return $d;
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function normDir(?string $v): string
    {
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return 'in';
        }
        if (str_contains($s, 'out') || $s === 'o' || $s === '0') {
            return 'out';
        }

        return 'in';
    }

    /**
     * Normalise a raw response into punch rows (emp_code is the RAW device code,
     * prefix is applied later in import()).
     *
     * rev173e — In/Out Machine IDs: many sites use SEPARATE devices for entry and
     * exit while the feed's INOUT flag is blank/wrong. When the config sets
     * in_machine_id / out_machine_id, a punch whose machine number matches one of
     * them gets that direction — overriding the feed's flag. Punches from any
     * other machine (or when neither ID is configured) keep the old behaviour.
     *
     * @return list<array{emp_code:string,name:?string,punch_at:Carbon,direction:string,machine:string}>
     */
    public static function parse(?array $json, array $cfg = []): array
    {
        $out = [];
        if (! is_array($json)) {
            return $out;
        }
        $data = $json['PunchData'] ?? $json['punchData'] ?? $json['Data'] ?? (self::isList($json) ? $json : []);
        if (! is_array($data)) {
            return $out;
        }
        $inMc = trim((string) ($cfg['in_machine_id'] ?? ''));
        $outMc = trim((string) ($cfg['out_machine_id'] ?? ''));
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (self::isList($item)) {
                $emp = $item[0] ?? null;
                $name = $item[1] ?? null;
                $dt = $item[2] ?? null;
                $dir = $item[3] ?? null;
                $mc = $item[4] ?? null;
            } else {
                $emp = self::pick($item, ['Empcode', 'EmpCode', 'EmployeeCode', 'empcode', 'EMPCODE', 'Code']);
                $name = self::pick($item, ['Name', 'EmpName', 'EmployeeName', 'empname']);
                $dt = self::pick($item, ['DateTime', 'PunchDate', 'Punchdate', 'PunchDateTime', 'DownloadDate', 'punchdatetime', 'Datetime']);
                $dir = self::pick($item, ['INOUT', 'InOut', 'INOUTStatus', 'Status', 'Direction', 'inout']);
                $mc = self::pick($item, ['MachineNo', 'MachineNumber', 'Machine', 'MCID', 'McId', 'mcid', 'machineno', 'MCNo', 'MC_No', 'DeviceId', 'DeviceID', 'deviceid']);
            }
            if (! $emp || ! $dt) {
                continue;
            }
            $when = self::parseDate((string) $dt);
            if (! $when) {
                continue;
            }
            $mcs = trim((string) ($mc ?? ''));
            // Machine-based direction beats the feed's INOUT flag when configured.
            if ($mcs !== '' && $inMc !== '' && strcasecmp($mcs, $inMc) === 0) {
                $direction = 'in';
            } elseif ($mcs !== '' && $outMc !== '' && strcasecmp($mcs, $outMc) === 0) {
                $direction = 'out';
            } else {
                $direction = self::normDir($dir !== null ? (string) $dir : null);
            }
            $out[] = [
                'emp_code' => trim((string) $emp),
                'name' => $name !== null ? trim((string) $name) : null,
                'punch_at' => $when,
                'direction' => $direction,
                'machine' => $mcs,
            ];
        }

        return $out;
    }

    /**
     * Look up an employee for a device code: by emp_code first (with the
     * configured prefix applied), then by the Biometric Mapping field
     * (employees.device_user_id — set in the Directory profile / mapping card).
     * Tries the prefixed code, then the raw device code, for both columns.
     */
    public static function matchEmployee(string $full, string $raw, ?int $tid = null)
    {
        $codes = array_values(array_unique(array_filter([strtolower($full), strtolower($raw)], fn ($c) => $c !== '')));
        foreach (['emp_code', 'device_user_id'] as $col) {
            if ($col === 'device_user_id' && ! Schema::hasColumn('employees', 'device_user_id')) {
                continue;
            }
            foreach ($codes as $c) {
                $q = DB::table('employees')
                    ->whereRaw('LOWER('.$col.') = ?', [$c])
                    ->whereNull('deleted_at');
                if (Schema::hasColumn('employees', 'tenant_id')) {
                    // 18 Aug 2026 HOTFIX — SYMMETRIC tenant filter.
                    // Previously: if ($tid && ...) { $q->where('tenant_id', $tid); }
                    // With $tid null the filter never ran, so the lookup matched
                    // emp_code across EVERY tenant on the server. $tid is null
                    // routinely, not rarely: PushController::rowForSn() auto-creates
                    // a biometric_configs row for any unknown serial with
                    // tenant_id = null, and cfgForSn() passes that null straight
                    // through — so the FIRST punch from a newly installed bridge
                    // was matched against every customer's employee table. Two
                    // customers both holding EMP001 could file a punch against the
                    // wrong company's payroll.
                    // A null tenant must now match ONLY null-tenant employees —
                    // never "any tenant". Fail-closed. Same idiom already used in
                    // this file by configForTenant() and recordUnmapped().
                    $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');
                }
                $emp = $q->first(['id', 'emp_code', 'name', 'tenant_id', 'company_id']);
                if ($emp) {
                    return $emp;
                }
            }
        }

        return null;
    }

    /**
     * Biometric Mapping — remember device codes whose punches matched NOBODY, so
     * the "Employee Mapping" card in Biometric Device Setup can list them for
     * one-click linking (SmartEPT-style unmapped-ID picker). Fail-soft.
     */
    public static function recordUnmapped(?int $tid, array $unmatched, string $source): void
    {
        try {
            if (! $unmatched) {
                return;
            }
            if (! Schema::hasTable('biometric_unmapped')) {
                Schema::create('biometric_unmapped', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->string('device_code', 80);
                    $t->string('source', 40)->nullable();
                    $t->unsignedInteger('punches')->default(0);
                    $t->timestamp('last_seen')->nullable();
                    $t->timestamps();
                    $t->unique(['tenant_id', 'device_code'], 'bio_unmapped_unique');
                });
            }
            foreach ($unmatched as $code => $n) {
                $row = DB::table('biometric_unmapped')
                    ->where('device_code', (string) $code)
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid), fn ($q) => $q->whereNull('tenant_id'))
                    ->first();
                if ($row) {
                    DB::table('biometric_unmapped')->where('id', $row->id)->update([
                        'punches' => (int) $row->punches + (int) $n,
                        'source' => $source,
                        'last_seen' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('biometric_unmapped')->insert([
                        'tenant_id' => $tid, 'device_code' => (string) $code, 'source' => $source,
                        'punches' => (int) $n, 'last_seen' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // mapping helper only — must never break an import
        }
    }

    /**
     * 18 Aug 2026 HOTFIX — hold a punch whose device code maps to nobody.
     *
     * device_code is the code the mapping card works in (prefix applied), i.e.
     * the same value recordUnmapped() writes to biometric_unmapped.device_code,
     * so the admin's Map action lines up with what is held here.
     * device_user_id keeps the RAW code the device sent, for diagnosis.
     *
     * updateOrInsert rather than insertOrIgnore on purpose: the unique index
     * cannot protect a null tenant (NULL never collides), and the three
     * self-hosted customers all run with tenant_id null. Fail-soft throughout —
     * quarantining a punch must never break an import.
     */
    private static function quarantine(array $p, array $cfg, string $full): void
    {
        try {
            if (! Schema::hasTable('attendance_pending')) {
                return;
            }

            // PushController::importAttlog puts the device serial in 'machine'.
            $sn = trim((string) ($p['machine'] ?? $cfg['serial_number'] ?? ''));

            $match = [
                'tenant_id' => $cfg['tenant_id'] ?? null,
                'device_code' => $full,
                'punch_at' => $p['punch_at']->format('Y-m-d H:i:s'),
                'source' => (string) ($cfg['source'] ?? ($cfg['provider'] ?? 'device')),
            ];

            DB::table('attendance_pending')->updateOrInsert($match, [
                'company_id' => $cfg['company_id'] ?? null,
                'device_sn' => $sn !== '' ? $sn : null,
                'device_user_id' => (string) ($p['emp_code'] ?? ''),
                'direction' => (string) ($p['direction'] ?? 'in'),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 18 Aug 2026 HOTFIX — replay held punches once the code is mapped.
     *
     * Called from BiometricConfigController::mapEmployee. Scoped by tenant AND,
     * when the caller knows it, device_sn: two devices can both have PIN 5 and
     * they are different people.
     *
     * Writes with the SAME $match / updateOrInsert shape the import loop uses,
     * which is what makes a re-import or a second mapping idempotent WITHOUT
     * relying on a unique index on attendance_logs (that index is deliberately
     * not being added in this hotfix).
     *
     * @param  object  $emp  employee row: emp_code, name, tenant_id, company_id
     * @return int rows promoted
     */
    public static function promotePending(?int $tid, string $deviceCode, $emp, ?string $deviceSn = null): int
    {
        $deviceCode = trim($deviceCode);
        if ($deviceCode === '' || ! $emp) {
            return 0;
        }

        $promoted = 0;
        $touched = [];

        try {
            if (! Schema::hasTable('attendance_pending') || ! Schema::hasTable('attendance_logs')) {
                return 0;
            }

            $lc = strtolower($deviceCode);

            $q = DB::table('attendance_pending')
                ->whereNull('resolved_at')
                // Matches punches held by either ingest path: the /iclock import
                // records device_code (prefixed), the SBB path records the raw PIN.
                ->where(function ($w) use ($lc) {
                    $w->whereRaw('LOWER(device_code) = ?', [$lc])
                        ->orWhereRaw('LOWER(device_user_id) = ?', [$lc]);
                });

            // Symmetric, exactly as in matchEmployee.
            $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');

            if ($deviceSn !== null && trim($deviceSn) !== '') {
                $q->whereRaw('LOWER(device_sn) = ?', [strtolower(trim($deviceSn))]);
            }

            foreach ($q->orderBy('id')->get() as $row) {
                $when = Carbon::parse($row->punch_at);

                $match = [
                    'emp_code' => $emp->emp_code,
                    'punch_at' => $when->format('Y-m-d H:i:s'),
                    'source' => $row->source ?: 'device',
                ];
                if (! empty($emp->tenant_id)) {
                    $match['tenant_id'] = $emp->tenant_id;
                }

                // Provenance carried over from the held row: which device, which
                // PIN on it, the sender's own id, how the person verified. These
                // were lost when mapEmployee moved off PunchIngestService::
                // replayPending (18 Aug 2026) — a released punch landed with a
                // NULL external_id and no trace of where it came from.
                //
                // FILLED IN only, never overwritten. A row that already carries an
                // external_id got it from a real ingest, and writing a second one
                // onto it collides with attlog_tenant_external_unique, throws, and
                // aborts the rest of the replay half-done.
                $existing = DB::table('attendance_logs')->where($match)->first();

                $provenance = [];
                foreach (['external_id', 'device_sn', 'device_user_id', 'verify_mode'] as $col) {
                    $held = $row->$col ?? null;
                    if ($held === null || $held === '') {
                        continue;
                    }
                    if ($existing === null || ($existing->$col ?? null) === null) {
                        $provenance[$col] = $held;
                    }
                }

                DB::table('attendance_logs')->updateOrInsert($match, $provenance + [
                    'direction' => $row->direction ?: 'in',
                    'emp_name' => $emp->name ?? null,
                    'log_date' => $when->toDateString(),
                    'tenant_id' => $emp->tenant_id ?? null,
                    'company_id' => $emp->company_id ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);

                // Stamped whether the row was inserted or matched an existing
                // punch: either way it is in attendance_logs and must not replay.
                DB::table('attendance_pending')->where('id', $row->id)->update([
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);

                $promoted++;
                if (($row->direction ?: 'in') === 'in') {
                    $touched[$emp->emp_code][$when->toDateString()] = true;
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return $promoted;
        }

        if ($promoted > 0) {
            Log::info('attendance.pending.promoted', [
                'tenant' => $tid,
                'device_code' => $deviceCode,
                'device_sn' => $deviceSn,
                'emp_code' => $emp->emp_code,
                'promoted' => $promoted,
            ]);
        }

        return $promoted;
    }

    /**
     * Write parsed punches into attendance_logs, matching employees by
     * (emp_prefix . device_code) and, failing that, by the Biometric Mapping
     * field (employees.device_user_id). Returns counts + unmatched device codes.
     * @return array{imported:int,matched:int,unmatched:array<string,int>}
     */
    public static function import(array $punches, array $cfg): array
    {
        $prefix = trim((string) ($cfg['emp_prefix'] ?? ''));
        $imported = 0;
        $matched = 0;
        $unmatched = [];
        if (! Schema::hasTable('attendance_logs') || ! Schema::hasTable('employees')) {
            return ['imported' => 0, 'matched' => 0, 'unmatched' => $unmatched];
        }
        $cache = [];
        $touched = [];   // F4 — emp_code => [date => true] for late-arrival notification
        foreach ($punches as $p) {
            $full = $prefix.$p['emp_code'];
            if (! array_key_exists($full, $cache)) {
                $cache[$full] = self::matchEmployee($full, (string) $p['emp_code'], $cfg['tenant_id'] ?? null);
            }
            $emp = $cache[$full];
            if (! $emp) {
                $unmatched[$full] = ($unmatched[$full] ?? 0) + 1;

                // 18 Aug 2026 HOTFIX — QUARANTINE instead of discard.
                // This used to be a bare `continue`: the punch was destroyed and
                // only a counter survived via recordUnmapped(), while the endpoint
                // still answered 200. That fires hardest at go-live, when nobody
                // is mapped yet, and SBB does not keep a copy either — it forwards
                // the raw PIN and trusts SmartPRS to map it. The punch is now held
                // and replayed the moment an admin maps the code.
                self::quarantine($p, $cfg, $full);

                continue;
            }
            $matched++;
            // rev172 — tenant_id in the MATCH keys (when present): emp codes repeat
            // across tenants; prevents cross-tenant overwrite of an identical punch.
            // rev173g — DIRECTION REMOVED from the match keys: it used to be part of
            // the identity, so after fixing the In/Out Machine IDs a re-sync
            // re-inserted every punch with the corrected direction NEXT TO the old
            // wrong-direction row → duplicate punches → attendance/payroll wrong.
            // A punch's identity is (tenant, emp, moment, source); direction is a
            // PROPERTY that a re-sync may correct in place.
            $match = [
                'emp_code' => $emp->emp_code,
                'punch_at' => $p['punch_at']->format('Y-m-d H:i:s'),
                // Channel label — 'etimeoffice' (cloud) by default, 'etimetracklite'
                // (local WebAPI) when that provider imports. Keeps the two feeds
                // from overwriting each other and lets a re-sync correct in place.
                'source' => $cfg['source'] ?? 'etimeoffice',
            ];
            if (! empty($emp->tenant_id)) {
                $match['tenant_id'] = $emp->tenant_id;
            }
            DB::table('attendance_logs')->updateOrInsert(
                $match,
                [
                    'direction' => $p['direction'],
                    'emp_name' => $emp->name ?? $p['name'],
                    'log_date' => $p['punch_at']->toDateString(),
                    'tenant_id' => $emp->tenant_id ?? null,
                    'company_id' => $emp->company_id ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $imported++;
            // F4 — remember only IN punches for the late-arrival check.
            if (($p['direction'] ?? 'in') === 'in') {
                $touched[$emp->emp_code][$p['punch_at']->toDateString()] = true;
            }
        }

        // F4 — immediate late-arrival notification for the punches just imported.
        // Fail-soft inside the service; never affects the import result.
        LateArrivalService::notifyTouched($cfg['tenant_id'] ?? null, $touched);

        // Biometric Mapping — surface unknown device codes to the mapping card.
        self::recordUnmapped($cfg['tenant_id'] ?? null, $unmatched, (string) ($cfg['source'] ?? ($cfg['provider'] ?? 'device')));

        return ['imported' => $imported, 'matched' => $matched, 'unmatched' => $unmatched];
    }
}
