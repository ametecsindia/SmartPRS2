<?php

namespace App\Http\Controllers;

use App\Services\ETimeOfficeService;
use App\Services\ETimeTrackLiteService;
use App\Services\GenericApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev157 — frontend "Biometric Device Setup" screen backend.
 * Stores the cloud-attendance API connection (eTimeOffice) per tenant in
 * biometric_configs and exposes load / save / test / sync to the SPA. Admin/HR
 * only. The password is never returned to the browser (only a hasPassword flag).
 *
 * F3 (multiple devices) — a tenant may now hold MANY device rows, each mapped to
 * a Branch (from the Branches master) plus an optional free-text location label
 * (e.g. "Main Gate"). list() returns them all; save() upserts by id; delete()
 * removes one; test()/sync() act on a specific device (by id). The hourly
 * scheduler already syncs every enabled row (ETimeOfficeService::allConfigs()).
 */
class BiometricConfigController extends Controller
{
    private static function tid(Request $request): ?int
    {
        return $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
    }

    /** Keep api_config only when it is valid JSON; else store null. */
    private static function cleanJson($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = json_decode($v, true);

        return is_array($d) ? json_encode($d) : null;
    }

    private static function ensureTable(): void
    {
        if (! Schema::hasTable('biometric_configs')) {
            Schema::create('biometric_configs', function ($t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('provider', 40)->default('etimeoffice');
                $t->boolean('enabled')->default(false);
                $t->string('base_url')->nullable();
                $t->string('endpoint')->nullable();
                $t->string('corp_id')->nullable();
                $t->string('username')->nullable();
                $t->text('password')->nullable();
                $t->string('empcode')->default('ALL');
                $t->string('emp_prefix', 20)->nullable();
                $t->timestamp('last_sync_at')->nullable();
                $t->string('last_status')->nullable();
                $t->integer('last_count')->default(0);
                $t->timestamps();
            });
        }
        // Additive columns for existing installs (In/Out machine IDs + F3 label/branch).
        $add = [
            'in_machine_id' => fn ($t) => $t->string('in_machine_id', 40)->nullable(),
            'out_machine_id' => fn ($t) => $t->string('out_machine_id', 40)->nullable(),
            'label' => fn ($t) => $t->string('label', 120)->nullable(),          // F3 — device / location label
            'branch' => fn ($t) => $t->string('branch', 120)->nullable(),        // F3 — mapped Branch (name)
            'serial_number' => fn ($t) => $t->string('serial_number', 80)->nullable(),   // eTimeTrackLite / push — device serial
            'api_config' => fn ($t) => $t->text('api_config')->nullable(),                // generic — request/mapping recipe (JSON)
            'sync_interval_min' => fn ($t) => $t->integer('sync_interval_min')->nullable(), // owner-chosen sync frequency (min)
        ];
        foreach ($add as $c => $fn) {
            if (! Schema::hasColumn('biometric_configs', $c)) {
                try {
                    Schema::table('biometric_configs', function ($t) use ($fn) {
                        $fn($t);
                    });
                } catch (\Throwable $e) {
                    // best-effort; save() surfaces a real failure
                }
            }
        }
    }

    /** A single config row by id, tenant-scoped. */
    private function rowById(Request $request, ?int $id)
    {
        if (! $id) {
            return null;
        }
        self::ensureTable();
        $tid = self::tid($request);
        $q = DB::table('biometric_configs')->where('id', $id);
        $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');

        return $q->first();
    }

    /** The latest config row for this tenant (back-compat for single-device callers). */
    private function row(Request $request)
    {
        self::ensureTable();
        $tid = self::tid($request);
        $q = DB::table('biometric_configs');
        $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');

        return $q->orderByDesc('id')->first();
    }

    private function present($r): array
    {
        return [
            'id' => $r?->id,
            'label' => $r?->label ?? '',
            'branch' => $r?->branch ?? '',
            'provider' => $r?->provider ?? '',
            'enabled' => (bool) ($r?->enabled ?? false),
            'base_url' => $r?->base_url ?? '',
            'endpoint' => $r?->endpoint ?? '',
            'corp_id' => $r?->corp_id ?? '',
            'serial_number' => $r?->serial_number ?? '',
            'api_config' => $r?->api_config ?? '',
            'sync_interval_min' => $r?->sync_interval_min ?? '',
            'username' => $r?->username ?? '',
            'empcode' => $r?->empcode ?? '',
            'emp_prefix' => $r?->emp_prefix ?? '',
            'in_machine_id' => $r?->in_machine_id ?? '',
            'out_machine_id' => $r?->out_machine_id ?? '',
            'hasPassword' => ! empty($r?->password),
            'lastSyncAt' => $r?->last_sync_at ?? null,
            'lastStatus' => $r?->last_status ?? null,
        ];
    }

    /** GET /app/biometric-config/list — every device for this tenant (F3). */
    public function list(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        self::ensureTable();
        $tid = self::tid($request);
        $q = DB::table('biometric_configs');
        $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');
        $rows = $q->orderBy('id')->get();

        $branches = [];
        try {
            $bq = DB::table('branches');
            if ($tid && Schema::hasColumn('branches', 'tenant_id')) {
                $bq->where('tenant_id', $tid);
            }
            if (Schema::hasColumn('branches', 'deleted_at')) {
                $bq->whereNull('deleted_at');
            }
            $branches = $bq->orderBy('name')->pluck('name')->all();
        } catch (\Throwable $e) {
            // branches master optional
        }

        return response()->json([
            'ok' => true,
            'devices' => $rows->map(fn ($r) => $this->present($r))->values(),
            'branches' => $branches,
        ]);
    }

    /** GET /app/biometric-config — the latest single config (back-compat). */
    public function show(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $r = $this->row($request);
        $p = $this->present($r);

        return response()->json([
            'ok' => true,
            'config' => $p,
            'hasPassword' => $p['hasPassword'],
            'lastSyncAt' => $p['lastSyncAt'],
            'lastStatus' => $p['lastStatus'],
        ]);
    }

    /** POST /app/biometric-config — create OR update one device (by id). */
    public function save(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        self::ensureTable();
        $tid = self::tid($request);
        $id = $request->input('id') ? (int) $request->input('id') : null;
        $r = $id ? $this->rowById($request, $id) : null;

        $provider = trim((string) $request->input('provider', 'etimeoffice')) ?: 'etimeoffice';
        // eTimeTrackLite stores the raw WebAPI URL as typed; eTimeOffice keeps the cloud default.
        $baseUrl = trim((string) $request->input('base_url', ''));
        if ($baseUrl === '' && $provider !== 'etimetracklite') {
            $baseUrl = 'https://api.etimeoffice.com/api';
        }
        $data = [
            'tenant_id' => $tid,
            'label' => trim((string) $request->input('label', '')) ?: null,          // F3
            'branch' => trim((string) $request->input('branch', '')) ?: null,        // F3
            'provider' => $provider,
            'enabled' => $request->boolean('enabled'),
            'base_url' => $baseUrl ?: null,
            'endpoint' => trim((string) $request->input('endpoint', '')) ?: 'DownloadPunchDataMCID',
            'corp_id' => trim((string) $request->input('corp_id', '')) ?: null,
            'serial_number' => trim((string) $request->input('serial_number', '')) ?: null,   // eTimeTrackLite
            'api_config' => self::cleanJson($request->input('api_config')),                     // generic (JSON string)
            'sync_interval_min' => ($iv = trim((string) $request->input('sync_interval_min', ''))) !== '' ? max(0, (int) $iv) : null,
            'username' => trim((string) $request->input('username', '')) ?: null,
            'empcode' => trim((string) $request->input('empcode', '')) ?: 'ALL',
            'emp_prefix' => trim((string) $request->input('emp_prefix', '')) ?: null,
            'in_machine_id' => trim((string) $request->input('in_machine_id', '')) ?: null,
            'out_machine_id' => trim((string) $request->input('out_machine_id', '')) ?: null,
            'updated_at' => now(),
        ];
        $pwd = (string) $request->input('password', '');
        if ($pwd !== '') {
            $data['password'] = Crypt::encryptString($pwd);
        }

        if ($r) {
            DB::table('biometric_configs')->where('id', $r->id)->update($data);
            $savedId = $r->id;
        } else {
            $data['created_at'] = now();
            $savedId = DB::table('biometric_configs')->insertGetId($data);
        }

        return response()->json(['ok' => true, 'id' => $savedId]);
    }

    /** POST /app/biometric-config/{id}/delete — remove one device (F3). */
    public function delete(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $r = $this->rowById($request, $id);
        if (! $r) {
            return response()->json(['ok' => false, 'error' => 'Device not found.'], 404);
        }
        DB::table('biometric_configs')->where('id', $r->id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Build an effective config from posted values + a specific saved row's password. */
    private function effectiveConfig(Request $request): array
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;
        $r = $id ? $this->rowById($request, $id) : $this->row($request);
        $savedPwd = null;
        if ($r && ! empty($r->password)) {
            try {
                $savedPwd = Crypt::decryptString($r->password);
            } catch (\Throwable $e) {
                $savedPwd = $r->password;
            }
        }
        $posted = (string) $request->input('password', '');

        $provider = $request->input('provider', $r->provider ?? 'etimeoffice');
        $baseUrl = trim((string) $request->input('base_url', $r->base_url ?? ''));
        if ($baseUrl === '' && $provider !== 'etimetracklite') {
            $baseUrl = 'https://api.etimeoffice.com/api';
        }

        return [
            'provider' => $provider,
            'enabled' => $request->boolean('enabled'),
            'base_url' => $baseUrl,
            'endpoint' => trim((string) $request->input('endpoint', $r->endpoint ?? '')) ?: 'DownloadPunchDataMCID',
            'corp_id' => trim((string) $request->input('corp_id', $r->corp_id ?? '')),
            'serial_number' => trim((string) $request->input('serial_number', $r->serial_number ?? '')),
            'api_config' => self::cleanJson($request->input('api_config', $r->api_config ?? '')) ?? '',
            'username' => trim((string) $request->input('username', $r->username ?? '')),
            'password' => $posted !== '' ? $posted : $savedPwd,
            'empcode' => trim((string) $request->input('empcode', $r->empcode ?? 'ALL')) ?: 'ALL',
            'emp_prefix' => trim((string) $request->input('emp_prefix', $r->emp_prefix ?? '')),
            'in_machine_id' => trim((string) $request->input('in_machine_id', $r->in_machine_id ?? '')),
            'out_machine_id' => trim((string) $request->input('out_machine_id', $r->out_machine_id ?? '')),
            'tenant_id' => self::tid($request),
        ];
    }

    /** POST /app/biometric-config/test — fetch + parse, write nothing. */
    public function test(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $cfg = $this->effectiveConfig($request);
        $to = now();
        $from = (clone $to)->subDay();

        // eSSL eTimeTrackLite local WebAPI (SOAP) — always surface the RAW device
        // response so the field mapping can be confirmed / tuned against a live box.
        if (($cfg['provider'] ?? '') === 'etimetracklite') {
            if (! ETimeTrackLiteService::configured($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Enter the WebAPI URL, Serial Number, Username and Password first.'], 422);
            }
            $res = ETimeTrackLiteService::fetch($cfg, $from, $to);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
            }
            $parsed = ETimeTrackLiteService::parse((string) $res['raw'], $cfg);
            $lines = [];
            foreach (array_slice($parsed, 0, 8) as $p) {
                $lines[] = $p['emp_code'].'  '.$p['punch_at']->format('Y-m-d H:i').'  '.$p['direction']
                    .(($p['machine'] ?? '') !== '' ? '  MC:'.$p['machine'] : '');
            }
            $preview = ($parsed ? implode("\n", $lines)."\n\n" : '')
                .'--- RAW device response (first 1500 chars) ---'."\n".substr((string) $res['raw'], 0, 1500);

            return response()->json(['ok' => true, 'parsed' => count($parsed), 'preview' => $preview]);
        }

        // Custom / Generic API — same raw-first preview so the mapping can be tuned.
        if (($cfg['provider'] ?? '') === 'generic') {
            if (! GenericApiService::configured($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Set at least the API URL and Response format first.'], 422);
            }
            $res = GenericApiService::fetch($cfg, $from, $to);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
            }
            $parsed = GenericApiService::parse((string) $res['raw'], $cfg);
            $lines = [];
            foreach (array_slice($parsed, 0, 8) as $p) {
                $lines[] = $p['emp_code'].'  '.$p['punch_at']->format('Y-m-d H:i').'  '.$p['direction']
                    .(($p['machine'] ?? '') !== '' ? '  MC:'.$p['machine'] : '');
            }
            $preview = ($parsed ? implode("\n", $lines)."\n\n" : '')
                .'--- RAW device response (first 1500 chars) ---'."\n".substr((string) $res['raw'], 0, 1500);

            return response()->json(['ok' => true, 'parsed' => count($parsed), 'preview' => $preview]);
        }

        if (! ETimeOfficeService::configured($cfg)) {
            return response()->json(['ok' => false, 'error' => 'Enter Corporate ID, Username and Password first.'], 422);
        }
        $res = ETimeOfficeService::fetch($cfg, $from, $to);
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
        }
        $parsed = ETimeOfficeService::parse($res['json'], $cfg);
        $lines = [];
        foreach (array_slice($parsed, 0, 8) as $p) {
            $lines[] = $p['emp_code'].'  '.$p['punch_at']->format('Y-m-d H:i').'  '.$p['direction']
                .(($p['machine'] ?? '') !== '' ? '  MC:'.$p['machine'] : '')
                .'  '.($p['name'] ?? '');
        }
        $preview = $parsed ? implode("\n", $lines) : substr($res['body'], 0, 1500);

        return response()->json(['ok' => true, 'parsed' => count($parsed), 'preview' => $preview]);
    }

    /** POST /app/biometric-config/sync — fetch + parse + import now (one device). */
    public function sync(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $cfg = $this->effectiveConfig($request);
        $days = max(1, min(31, (int) $request->input('days', 1)));
        $to = now();
        $from = (clone $to)->subDays($days);

        if (($cfg['provider'] ?? '') === 'etimetracklite') {
            if (! ETimeTrackLiteService::configured($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Save the WebAPI URL, Serial Number, Username and Password first.'], 422);
            }
            $res = ETimeTrackLiteService::fetch($cfg, $from, $to);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
            }
            $cfg['source'] = 'etimetracklite';
            $punches = ETimeTrackLiteService::parse((string) $res['raw'], $cfg);
        } elseif (($cfg['provider'] ?? '') === 'generic') {
            if (! GenericApiService::configured($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Set the API URL and Response format first.'], 422);
            }
            $res = GenericApiService::fetch($cfg, $from, $to);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
            }
            $cfg['source'] = 'generic';
            $punches = GenericApiService::parse((string) $res['raw'], $cfg);
        } else {
            if (! ETimeOfficeService::configured($cfg)) {
                return response()->json(['ok' => false, 'error' => 'Save the connection details first.'], 422);
            }
            $res = ETimeOfficeService::fetch($cfg, $from, $to);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
            }
            $punches = ETimeOfficeService::parse($res['json'], $cfg);
        }
        $r = ETimeOfficeService::import($punches, $cfg);

        $status = 'Imported '.$r['imported'].' punch(es) for '.$r['matched'].' row(s)';
        $id = $request->input('id') ? (int) $request->input('id') : null;
        $row = $id ? $this->rowById($request, $id) : $this->row($request);
        if ($row) {
            DB::table('biometric_configs')->where('id', $row->id)->update([
                'last_sync_at' => now(), 'last_status' => $status, 'last_count' => $r['imported'], 'updated_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'imported' => $r['imported'],
            'matched' => $r['matched'],
            'unmatched' => count($r['unmatched']),
            'unmatchedCodes' => array_slice(array_keys($r['unmatched']), 0, 15),
        ]);
    }

    // =====================================================================
    // Biometric Mapping (2026-08-05) — SmartEPT-style device-ID ↔ employee
    // linking, adapted to SmartPRS: the mapping lives ON the employee record
    // (employees.device_user_id — also editable in the Directory profile and
    // the employee CSV template's biometric_id column). These endpoints feed
    // the "Employee Mapping" card in Biometric Device Setup.
    // =====================================================================

    /** GET /app/biometric-config/mappings — current links + unmapped device IDs. */
    public function mappings(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tid = self::tid($request);
        $mappings = [];
        $employees = [];
        try {
            $rows = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'emp_code', 'name', 'device_user_id']);
            foreach ($rows as $e) {
                $employees[] = ['code' => $e->emp_code, 'name' => $e->name];
                if (trim((string) ($e->device_user_id ?? '')) !== '') {
                    $mappings[] = ['code' => $e->emp_code, 'name' => $e->name, 'deviceId' => $e->device_user_id];
                }
            }
        } catch (\Throwable $e) {
        }

        // Unmapped device IDs seen in punches (recorded by every import path),
        // minus anything that has since been linked or now matches an emp_code.
        $unmapped = [];
        try {
            if (Schema::hasTable('biometric_unmapped')) {
                $known = [];
                foreach ($mappings as $m) {
                    $known[strtolower((string) $m['deviceId'])] = true;
                }
                foreach ($employees as $e) {
                    $known[strtolower((string) $e['code'])] = true;
                }
                $rows = DB::table('biometric_unmapped')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid), fn ($q) => $q->whereNull('tenant_id'))
                    ->orderByDesc('punches')->limit(500)->get();
                foreach ($rows as $u) {
                    if (isset($known[strtolower((string) $u->device_code)])) {
                        continue;
                    }
                    $unmapped[] = [
                        'deviceId' => $u->device_code,
                        'punches' => (int) $u->punches,
                        'lastSeen' => $u->last_seen ? substr((string) $u->last_seen, 0, 16) : '',
                        'source' => (string) ($u->source ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true, 'mappings' => $mappings, 'unmapped' => $unmapped, 'employees' => $employees]);
    }

    /** POST /app/biometric-config/map — link a device ID to an employee. */
    public function mapEmployee(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tid = self::tid($request);
        $deviceId = trim((string) $request->input('device_id', ''));
        $empCode = trim((string) $request->input('emp_code', ''));
        if ($deviceId === '' || $empCode === '') {
            return response()->json(['ok' => false, 'error' => 'Pick a biometric ID and an employee.'], 422);
        }

        $emp = DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereRaw('LOWER(emp_code) = ?', [strtolower($empCode)])
            ->whereNull('deleted_at')->first();
        if (! $emp) {
            return response()->json(['ok' => false, 'error' => 'Employee not found.'], 404);
        }

        // SmartEPT rule: one device ID belongs to ONE employee. If another
        // employee already holds this ID, ask for an explicit confirmation
        // (force) — then move it (the old employee loses the mapping).
        $holder = DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereRaw('LOWER(device_user_id) = ?', [strtolower($deviceId)])
            ->whereNull('deleted_at')->where('id', '!=', $emp->id)->first();
        if ($holder && ! $request->boolean('force')) {
            return response()->json([
                'ok' => false, 'needForce' => true,
                'error' => 'Biometric ID "'.$deviceId.'" is already mapped to '.$holder->name.' ('.$holder->emp_code.'). Save again to move it.',
            ], 409);
        }
        if ($holder) {
            DB::table('employees')->where('id', $holder->id)->update(['device_user_id' => null, 'updated_at' => now()]);
        }

        DB::table('employees')->where('id', $emp->id)->update(['device_user_id' => $deviceId, 'updated_at' => now()]);

        // Backfill: punches that were stored under the raw device code (e.g. from
        // a CSV upload) are re-keyed to the employee, so attendance/reports and
        // the dashboard count them from today backwards. New device syncs match
        // automatically via the mapping.
        $backfilled = 0;
        try {
            if (Schema::hasTable('attendance_logs') && strcasecmp($deviceId, (string) $emp->emp_code) !== 0) {
                $q = DB::table('attendance_logs')->whereRaw('LOWER(emp_code) = ?', [strtolower($deviceId)]);
                if ($tid && Schema::hasColumn('attendance_logs', 'tenant_id')) {
                    $q->where(fn ($w) => $w->where('tenant_id', $tid)->orWhereNull('tenant_id'));
                }
                $backfilled = $q->update([
                    'emp_code' => $emp->emp_code,
                    'emp_name' => $emp->name,
                    'tenant_id' => $emp->tenant_id ?? $tid,
                    'company_id' => $emp->company_id ?? null,
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
        }

        // The ID is mapped now — drop it from the unmapped list.
        try {
            if (Schema::hasTable('biometric_unmapped')) {
                DB::table('biometric_unmapped')
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid), fn ($q) => $q->whereNull('tenant_id'))
                    ->whereRaw('LOWER(device_code) = ?', [strtolower($deviceId)])->delete();
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok' => true,
            'message' => 'Mapped '.$deviceId.' → '.$emp->name.' ('.$emp->emp_code.').'
                .($backfilled ? ' '.$backfilled.' earlier punch(es) re-linked.' : ''),
        ]);
    }

    /** POST /app/biometric-config/unmap — remove an employee's device mapping. */
    public function unmapEmployee(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $tid = self::tid($request);
        $empCode = trim((string) $request->input('emp_code', ''));
        if ($empCode === '') {
            return response()->json(['ok' => false, 'error' => 'Employee code required.'], 422);
        }
        $n = DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereRaw('LOWER(emp_code) = ?', [strtolower($empCode)])
            ->whereNull('deleted_at')
            ->update(['device_user_id' => null, 'updated_at' => now()]);

        return response()->json(['ok' => $n > 0, 'message' => $n > 0 ? 'Mapping removed. Punch history is kept; new punches for that ID stay unmapped until re-linked.' : 'Employee not found.']);
    }
}
