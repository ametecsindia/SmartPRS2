<?php

namespace App\Http\Controllers;

use App\Services\ETimeOfficeService;
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

        $data = [
            'tenant_id' => $tid,
            'label' => trim((string) $request->input('label', '')) ?: null,          // F3
            'branch' => trim((string) $request->input('branch', '')) ?: null,        // F3
            'provider' => trim((string) $request->input('provider', 'etimeoffice')) ?: 'etimeoffice',
            'enabled' => $request->boolean('enabled'),
            'base_url' => trim((string) $request->input('base_url', '')) ?: 'https://api.etimeoffice.com/api',
            'endpoint' => trim((string) $request->input('endpoint', '')) ?: 'DownloadPunchDataMCID',
            'corp_id' => trim((string) $request->input('corp_id', '')) ?: null,
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

        return [
            'provider' => $request->input('provider', $r->provider ?? 'etimeoffice'),
            'enabled' => $request->boolean('enabled'),
            'base_url' => trim((string) $request->input('base_url', $r->base_url ?? '')) ?: 'https://api.etimeoffice.com/api',
            'endpoint' => trim((string) $request->input('endpoint', $r->endpoint ?? '')) ?: 'DownloadPunchDataMCID',
            'corp_id' => trim((string) $request->input('corp_id', $r->corp_id ?? '')),
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
        if (! ETimeOfficeService::configured($cfg)) {
            return response()->json(['ok' => false, 'error' => 'Enter Corporate ID, Username and Password first.'], 422);
        }
        $to = now();
        $from = (clone $to)->subDay();
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
        if (! ETimeOfficeService::configured($cfg)) {
            return response()->json(['ok' => false, 'error' => 'Save the connection details first.'], 422);
        }
        $days = max(1, min(31, (int) $request->input('days', 1)));
        $to = now();
        $from = (clone $to)->subDays($days);
        $res = ETimeOfficeService::fetch($cfg, $from, $to);
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
        }
        $punches = ETimeOfficeService::parse($res['json'], $cfg);
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
}
