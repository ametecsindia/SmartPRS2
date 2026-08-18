<?php

namespace App\Http\Controllers;

use App\Services\ApiKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → API Keys, and Settings → Pending Punches.
 *
 * API Keys issues the credential Smart Biometric Bridge presents on
 * /api/v1/*. The secret is displayed ONCE, immediately after creation, and is
 * never recoverable afterwards — only its sha256 is stored. A key that is lost
 * is revoked and re-issued, not looked up.
 *
 * Pending Punches is the other half of the promise the ingest path makes: a
 * punch whose device PIN matches no employee is held, not discarded, and this
 * screen is where an admin sees what is waiting and maps it. Mapping releases
 * the held punches into attendance_logs (PunchIngestService::replayPending).
 */
class ApiKeyController extends Controller
{
    private const ROLES = ['admin', 'hr_manager'];

    private const SCOPES = ['ingest', 'read'];

    // ---- API keys --------------------------------------------------------

    /** GET /app/api-keys */
    public function index(Request $request)
    {
        $this->authorizeScreen($request);

        // The screen ships before its table does: the code can be deployed and
        // the migration run later, in a maintenance window. Say so plainly
        // instead of throwing on a missing table.
        if (! Schema::hasTable('api_keys')) {
            return view('settings.api-keys', [
                'keys' => collect(),
                'scopes' => self::SCOPES,
                'notReady' => true,
                'conn' => self::connectionDetails(),
            ]);
        }

        $keys = DB::table('api_keys')
            ->when($this->tid($request), fn ($q, $tid) => $q->where('tenant_id', $tid), fn ($q) => $q->whereNull('tenant_id'))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'prefix' => $r->prefix,
                'scopes' => implode(', ', self::decodeScopes($r->scopes)) ?: '—',
                'last_used_at' => $r->last_used_at ? substr((string) $r->last_used_at, 0, 16) : 'never',
                'expires_at' => $r->expires_at ? substr((string) $r->expires_at, 0, 10) : 'never',
                'expired' => $r->expires_at !== null && now()->greaterThan($r->expires_at),
                'active' => (bool) $r->active,
            ]);

        return view('settings.api-keys', [
            'keys' => $keys,
            'scopes' => self::SCOPES,
            'notReady' => false,
            'conn' => self::connectionDetails(),
        ]);
    }

    /**
     * Everything an installer has to type into Smart Biometric Bridge.
     *
     * Derived from the request host, so it is correct on every install — SaaS,
     * on-prem, custom domain — without anyone maintaining a setting. If this
     * shows http:// rather than https://, fix APP_URL before handing the
     * address to a site: an API key must not travel in clear text.
     */
    private static function connectionDetails(): array
    {
        return [
            'base' => url('/api/v1'),
            'ping' => url('/api/v1/ping'),
            'punches' => url('/api/v1/attendance/punches'),
            'header' => 'X-Api-Key: <your key>',
            'headerAlt' => 'Authorization: Bearer <your key>',
            'timezone' => (string) config('app.timezone'),
            'version' => (string) config('smartprs.version', ''),
            'secure' => str_starts_with(strtolower(url('/')), 'https://'),
        ];
    }

    /** POST /app/api-keys */
    public function store(Request $request)
    {
        $this->authorizeScreen($request);

        if ($notReady = $this->notReady()) {
            return $notReady;
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return back()->with('key_error', 'Give the key a name so you can tell your sites apart, e.g. "SBB — Chennai Plant".');
        }

        $scopes = array_values(array_intersect(
            self::SCOPES,
            array_map('strval', (array) $request->input('scopes', []))
        ));
        if (! $scopes) {
            return back()->with('key_error', 'Pick at least one scope. Smart Biometric Bridge needs "ingest".');
        }

        $expiresAt = null;
        $rawExpiry = trim((string) $request->input('expires_at', ''));
        if ($rawExpiry !== '') {
            try {
                $expiresAt = Carbon::createFromFormat('Y-m-d', $rawExpiry, config('app.timezone'))->endOfDay();
            } catch (\Throwable $e) {
                return back()->with('key_error', 'Expiry date must look like 2027-03-31, or be left blank for no expiry.');
            }
            if ($expiresAt->isPast()) {
                return back()->with('key_error', 'That expiry date has already passed.');
            }
        }

        $minted = ApiKeys::mint();

        DB::table('api_keys')->insert([
            'tenant_id' => $this->tid($request),
            'company_id' => $this->cid($request),
            'name' => mb_substr($name, 0, 200),
            'prefix' => $minted['prefix'],
            'key_hash' => $minted['hash'],
            'scopes' => json_encode($scopes),
            'expires_at' => $expiresAt,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('api_key.created', [
            'prefix' => $minted['prefix'],
            'tenant' => $this->tid($request),
            'scopes' => $scopes,
            'by' => $request->user()?->email,
        ]);

        // Shown ONCE. Flashed, not stored.
        return back()
            ->with('new_key_secret', $minted['secret'])
            ->with('new_key_name', $name);
    }

    /** POST /app/api-keys/{id}/revoke */
    public function revoke(Request $request, int $id)
    {
        $this->authorizeScreen($request);

        if ($notReady = $this->notReady()) {
            return $notReady;
        }

        $tid = $this->tid($request);
        $n = DB::table('api_keys')
            ->where('id', $id)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid), fn ($q) => $q->whereNull('tenant_id'))
            ->update(['active' => false, 'updated_at' => now()]);

        if ($n) {
            Log::info('api_key.revoked', ['id' => $id, 'tenant' => $tid, 'by' => $request->user()?->email]);
        }

        return back()->with('key_notice', $n
            ? 'Key revoked. Any bridge still using it will start getting 401 immediately.'
            : 'That key was not found.');
    }

    // ---- pending punches -------------------------------------------------

    /** GET /app/pending-punches */
    public function pending(Request $request)
    {
        $this->authorizeScreen($request);

        $tid = $this->tid($request);
        $rows = collect();
        $employees = collect();

        if (Schema::hasTable('attendance_pending')) {
            // 18 Aug 2026 hotfix — group by device_code (the value the mapping
            // works in) AND device_sn, so two devices that both use PIN 5 are
            // listed separately and can be mapped to different people.
            $codeCol = Schema::hasColumn('attendance_pending', 'device_code')
                ? 'device_code'
                : 'device_user_id';

            $rows = DB::table('attendance_pending')
                ->selectRaw($codeCol.' as device_code, device_user_id, device_sn, COUNT(*) as punches, MIN(punch_at) as first_seen, MAX(punch_at) as last_seen')
                ->whereNull('resolved_at')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid), fn ($q) => $q->whereNull('tenant_id'))
                ->groupBy($codeCol, 'device_user_id', 'device_sn')
                ->orderByDesc('punches')
                ->limit(500)
                ->get();
        }

        // Devices that auto-registered with no tenant. Until one is claimed its
        // punches can only ever match null-tenant employees (Fix 1), so on a
        // multi-tenant server they will all sit in the quarantine above.
        $unassigned = collect();
        if ($tid) {
            try {
                $unassigned = DB::table('biometric_configs')
                    ->whereNull('tenant_id')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get(['id', 'serial_number', 'label', 'provider', 'last_sync_at', 'last_status']);
            } catch (\Throwable $e) {
            }
        }

        $companies = collect();
        try {
            $companies = DB::table('companies')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name']);
        } catch (\Throwable $e) {
        }

        try {
            $employees = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['emp_code', 'name']);
        } catch (\Throwable $e) {
        }

        return view('settings.pending-punches', [
            'rows' => $rows,
            'employees' => $employees,
            'unassigned' => $unassigned,
            'companies' => $companies,
            'totalHeld' => $rows->sum('punches'),
        ]);
    }

    /**
     * POST /app/pending-punches/assign-device
     *
     * 18 Aug 2026 hotfix. Thin wrapper so the panel can claim an
     * auto-registered device; the rule lives in BiometricConfigController.
     */
    public function assignDevice(Request $request, BiometricConfigController $bio)
    {
        $this->authorizeScreen($request);

        $id = (int) $request->input('device_row_id', 0);
        if ($id <= 0) {
            return back()->with('key_error', 'Pick a device to assign.');
        }

        $data = (array) ($bio->assign($request, $id)->getData(true) ?? []);

        return ! empty($data['ok'])
            ? back()->with('key_notice', $data['message'] ?? 'Device assigned.')
            : back()->with('key_error', $data['error'] ?? 'Could not assign that device.');
    }

    /**
     * POST /app/pending-punches/map
     *
     * Delegates to BiometricConfigController::mapEmployee so the "one device ID
     * belongs to one employee" rule, the backfill and the quarantine replay all
     * stay in exactly one place.
     */
    public function mapPending(Request $request, BiometricConfigController $bio)
    {
        $this->authorizeScreen($request);

        $response = $bio->mapEmployee($request);
        $data = (array) ($response->getData(true) ?? []);

        if (! empty($data['ok'])) {
            return back()->with('key_notice', $data['message'] ?? 'Mapped.');
        }

        return back()->with('key_error', ($data['error'] ?? 'Could not map that device ID.')
            .(! empty($data['needForce']) ? ' Tick "move it" and submit again to reassign.' : ''));
    }

    // ---- internals -------------------------------------------------------

    /** A redirect back with an explanation when the table has not been created yet. */
    private function notReady()
    {
        if (Schema::hasTable('api_keys')) {
            return null;
        }

        return back()->with('key_error', 'API keys are not set up on this server yet. Run "php artisan migrate" to create the table, then reload this page.');
    }

    private function authorizeScreen(Request $request): void
    {
        $u = $request->user();
        try {
            if ($u && ($u->hasRole('super_admin') || $u->hasAnyRole(self::ROLES))) {
                return;
            }
        } catch (\Throwable $e) {
            return;   // same fail-open posture as ApprovalService::denyUnlessRole
        }

        abort(403, 'You do not have permission to manage API keys.');
    }

    private function tid(Request $request): ?int
    {
        $t = $request->user()?->tenant_id;

        return $t ? (int) $t : null;
    }

    private function cid(Request $request): ?int
    {
        $c = $request->user()?->company_id ?? null;

        return $c ? (int) $c : null;
    }

    /** @return list<string> */
    private static function decodeScopes($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }
}
