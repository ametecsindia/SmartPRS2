<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * rev 107 — THE UPDATE SERVER (SRS FR-5, SaaS platform only).
 *
 * Public, HTTPS, throttled endpoints every on-prem installation calls:
 *   POST /update/activate            one-time activation handshake
 *   POST /update/heartbeat           light revalidation (never blocks clients)
 *   POST /update/check               "is there a GRANTED update for me?"
 *   GET  /update/download/{version}  the package zip (licence + grant checked)
 *
 * Never reveals whether a guessed key exists — wrong keys get the same
 * neutral message everywhere.
 */
class UpdateServerController extends Controller
{
    /** Download tokens live in the cache for an hour — long enough to resume, short enough that a leaked URL dies on its own. */
    private const TOKEN_PREFIX = 'sprs_update_dl:';

    private const TOKEN_TTL_MINUTES = 60;

    private const NEUTRAL = 'Licence not recognised. Please check the key, or contact Ametecs (ejaz@ametecsindia.com · WhatsApp 9000098877).';

    /** Resolve + basic-validate the licence from the request key. */
    private function licence(Request $request): array
    {
        $key = (string) $request->input('key', $request->query('key', ''));
        if (strlen(LicenseService::normalize($key)) < 16) {
            return [null, null];
        }
        $lic = LicenseService::findByKey($key);

        return [$lic, $key];
    }

    /** POST /update/activate {key, fingerprint, server_name, edition} */
    public function activate(Request $request)
    {
        [$lic, $key] = $this->licence($request);
        $fp = mb_substr(trim((string) $request->input('fingerprint', '')), 0, 190);
        $edition = strtolower(trim((string) $request->input('edition', '')));
        if (! $lic || ! $fp) {
            return response()->json(['ok' => false, 'error' => self::NEUTRAL], 422);
        }
        if (in_array($lic->status, ['revoked', 'suspended'], true)) {
            LicenseService::event($lic->id, 'denied', 'Activation attempt on '.$lic->status.' licence', $request->ip());

            return response()->json(['ok' => false, 'error' => 'This licence is not active. Please contact Ametecs (WhatsApp 9000098877).'], 422);
        }
        if ($edition && $edition !== $lic->edition) {
            LicenseService::event($lic->id, 'denied', 'Edition mismatch: installed '.$edition.', licensed '.$lic->edition, $request->ip());

            return response()->json(['ok' => false, 'error' => 'This key is for SmartPRS-'.strtoupper($lic->edition).' but this installation is SmartPRS-'.strtoupper($edition).'. Please install the matching edition or contact Ametecs.'], 422);
        }
        // Idempotent on the SAME machine; a different machine needs a panel
        // deactivation first (Q5: self-service moves are limited).
        // 10 Aug 2026 (flowchart step 4/5) — enforce "one .lic ↔ one machine
        // fingerprint" STRICTLY: block a different machine whenever this licence is
        // already bound, regardless of status. The old check only fired on
        // status==='active', so a licence bound while still 'pending' (e.g. via an
        // early heartbeat) could be hijacked by another PC. The heartbeat already
        // blocks on any mismatch — activate now matches it.
        if ($lic->fingerprint && ! hash_equals($lic->fingerprint, $fp)) {
            // Anti-fraud tripwire: a DIFFERENT machine tried to activate this key.
            // Logged to History + sales alerted (deduped once/day).
            LicenseService::logRejection($lic, 'server_mismatch', $fp, $request->ip());

            return response()->json(['ok' => false, 'error' => 'This licence is already activated on another machine. To move it, contact Ametecs and we will release it (takes a minute).'], 422);
        }
        $wasUnbound = ! $lic->fingerprint;

        DB::table('licences')->where('id', $lic->id)->update([
            'status' => 'active',
            'fingerprint' => $fp,
            'server_name' => mb_substr((string) $request->input('server_name', ''), 0, 190),
            'activated_at' => $lic->activated_at ?: now(),
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);
        LicenseService::event($lic->id, 'activated', 'Activated on '.$request->input('server_name', 'unknown server'), $request->ip());
        if ($wasUnbound) {
            LicenseService::event($lic->id, 'machine_bound', 'Bound to server '.$fp, $request->ip());
        }
        LicenseService::recordDevice($lic->id, $fp, $fp, (string) $request->input('server_name', ''));
        LicenseService::verifyOnce($lic, $fp);

        return response()->json([
            'ok' => true,
            'cert' => LicenseService::certificate($lic, $key, $fp),
            'company' => DB::table('onprem_clients')->where('id', $lic->client_id)->value('company'),
        ]);
    }

    /**
     * POST /update/heartbeat {key, fingerprint, server_name} — daily revalidation.
     * Anti-fraud (ported from SmartEPT): a DIFFERENT machine phoning home on this
     * key — even silently — is caught, logged and sales-alerted here; the client
     * honours the returned reason and blocks. Availability-first: a client that is
     * unreachable simply keeps running on its cached certificate.
     */
    public function heartbeat(Request $request)
    {
        [$lic] = $this->licence($request);
        if (! $lic) {
            return response()->json(['ok' => false, 'error' => self::NEUTRAL], 422);
        }
        $fp = mb_substr(trim((string) $request->input('fingerprint', '')), 0, 190);

        // Central kill-switch: a revoked/suspended licence tells the client to stop.
        if (in_array($lic->status, ['revoked', 'suspended'], true)) {
            LicenseService::logRejection($lic, 'licence_'.$lic->status, $fp, $request->ip());

            return response()->json(['ok' => false, 'reason' => 'licence_'.$lic->status, 'status' => $lic->status], 200);
        }

        // THE fraud tripwire: a machine other than the bound one is using this key.
        if ($fp && $lic->fingerprint && ! hash_equals($lic->fingerprint, $fp)) {
            LicenseService::logRejection($lic, 'server_mismatch', $fp, $request->ip());

            return response()->json(['ok' => false, 'reason' => 'server_mismatch', 'status' => $lic->status], 200);
        }

        // Bind-on-first, so an install that only ever heartbeats still gets bound + logged.
        $upd = ['last_seen_at' => now(), 'updated_at' => now()];
        $justBound = false;
        if ($fp && ! $lic->fingerprint) {
            $upd['fingerprint'] = $fp;
            $upd['server_name'] = mb_substr((string) $request->input('server_name', ''), 0, 190);
            $upd['activated_at'] = $lic->activated_at ?: now();
            $justBound = true;
        }
        DB::table('licences')->where('id', $lic->id)->update($upd);
        if ($justBound) {
            LicenseService::event($lic->id, 'machine_bound', 'Bound to server '.$fp, $request->ip());
        }
        if ($fp) {
            LicenseService::recordDevice($lic->id, $fp, $fp, (string) $request->input('server_name', ''));
        }
        LicenseService::verifyOnce($lic, $fp);

        return response()->json([
            'ok' => true,
            'status' => $lic->status,
            'amc_expires_on' => $lic->amc_expires_on,
            'amc_active' => LicenseService::amcActive($lic),
            'expiry_mode' => $lic->expiry_mode ?? 'renew',
        ]);
    }

    /** POST /update/device/activate {key, device_uid, hostname} — claim a server seat. */
    public function deviceActivate(Request $request)
    {
        [$lic] = $this->licence($request);
        if (! $lic || $lic->status !== 'active') {
            return response()->json(['ok' => false, 'error' => self::NEUTRAL], 422);
        }
        $uid = (string) $request->input('device_uid', '');
        $res = LicenseService::recordDevice($lic->id, $uid, $uid, (string) $request->input('hostname', ''));
        if (empty($res['ok'])) {
            LicenseService::event($lic->id, 'denied', 'Server seat refused: '.($res['reason'] ?? 'error'), $request->ip());
        }

        return response()->json($res + ['limit' => LicenseService::serverLimit($lic)]);
    }

    /** POST /update/device/deactivate {key, device_uid} — free a server seat. */
    public function deviceDeactivate(Request $request)
    {
        [$lic] = $this->licence($request);
        if (! $lic) {
            return response()->json(['ok' => false, 'error' => self::NEUTRAL], 422);
        }
        $ok = LicenseService::deactivateDevice($lic->id, (string) $request->input('device_uid', ''));

        return response()->json(['ok' => $ok]);
    }

    /**
     * POST /update/check — "is there a GRANTED update for me?"
     *
     * READ-ONLY on licence identity, deliberately (self-update flow chart,
     * 2 Sep 2026): a version check must never bind a machine fingerprint or
     * move activation state, or pressing a button on the client's Updates
     * screen would silently rewrite their licence. It reads the row, decides,
     * and leaves it as it found it. Machine cloning is caught where it belongs
     * — the daily heartbeat, which already logs and alerts sales.
     *
     * Every refusal returns a `reason` the client can branch on AND a `message`
     * the admin can act on. Never a bare 403.
     */
    public function check(Request $request)
    {
        [$lic] = $this->licence($request);
        $current = trim((string) $request->input('current_version', $request->input('version', '0')));
        $channel = $request->input('channel', 'stable') === 'beta' ? 'beta' : 'stable';

        if (! $lic) {
            // Naming the host that answered turns "key not recognised" from a
            // support ticket into a glance: it is almost always an .env still
            // pointing at a test platform, not a bad key.
            return response()->json([
                'ok' => false, 'update_available' => false, 'reason' => 'unknown_key',
                'message' => self::NEUTRAL, 'error' => self::NEUTRAL,
            ], 422);
        }
        if (in_array($lic->status, ['revoked', 'suspended'], true)) {
            $msg = 'Updates need an active licence. This licence is '.$lic->status.'. Please contact Ametecs (WhatsApp 9000098877).';

            return response()->json([
                'ok' => false, 'update_available' => false, 'reason' => 'licence_'.$lic->status,
                'message' => $msg, 'error' => $msg,
            ], 422);
        }
        if ($lic->status !== 'active') {
            return response()->json([
                'ok' => false, 'update_available' => false, 'reason' => 'licence_not_active',
                'message' => self::NEUTRAL, 'error' => self::NEUTRAL,
            ], 422);
        }

        DB::table('licences')->where('id', $lic->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
        $this->log($lic, 'check', 'on '.$current.' ('.$channel.')');

        $base = [
            'ok' => true,
            'amc_expires_on' => $lic->amc_expires_on,
            'expiry_mode' => $lic->expiry_mode ?? 'renew',
        ];

        if (! LicenseService::amcActive($lic)) {
            $msg = 'Your AMC ended on '.($lic->amc_expires_on ?: '—').'. Renew to receive updates — WhatsApp 9000098877.';

            return response()->json($base + [
                'update_available' => false, 'update' => null,
                'reason' => 'amc_expired', 'message' => $msg,
                'amc_active' => false,
            ]);
        }

        $rel = DB::table('releases')
            ->join('release_grants', 'release_grants.release_id', '=', 'releases.id')
            ->where('release_grants.client_id', $lic->client_id)
            ->whereNotNull('releases.published_at')
            ->orderByDesc('releases.id')
            ->select('releases.*')
            ->first();

        // Version comparison is NUMERIC, never string: "2026.6.10" sorts BELOW
        // "2026.6.9" as text, which would hide every tenth release.
        if (! $rel || version_compare($this->numeric($rel->version), $this->numeric($current), '<=')) {
            return response()->json($base + [
                'update_available' => false, 'update' => null,
                'reason' => $rel ? 'up_to_date' : 'not_granted',
                'message' => $rel
                    ? 'You are on the latest version granted to you.'
                    : 'No update has been released for your licence yet.',
                'amc_active' => true,
            ]);
        }

        // The package is fetched with a short-lived token, never with the licence
        // key in a query string — URLs land in access logs, proxy caches and
        // browser history, and a key in one is a credential you have published.
        $token = Str::random(48);
        Cache::put(self::TOKEN_PREFIX.$token, [
            'release_id' => $rel->id,
            'licence_id' => $lic->id,
            'client_id' => $lic->client_id,
        ], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        return response()->json($base + [
            'update_available' => true,
            'amc_active' => true,
            'product' => 'smartprs',
            'version' => $rel->version,
            'title' => 'SmartPRS '.$rel->version,
            'notes' => $rel->notes,
            'size_bytes' => (int) $rel->size,
            'package_hash' => $rel->checksum,
            'download_url' => url('/update/package/'.$token),
            'token_expires_in' => self::TOKEN_TTL_MINUTES * 60,
            'released_at' => $rel->published_at,
            // The pre-2 Sep 2026 shape, so a client that has not been updated
            // yet still sees the offer and can still fetch it by version.
            'update' => ['version' => $rel->version, 'notes' => $rel->notes, 'size' => (int) $rel->size, 'checksum' => $rel->checksum],
        ]);
    }

    /**
     * GET /update/package/{token} — the package, fetched without a licence key.
     *
     * The token stays valid for its whole window rather than being burned on the
     * first byte, so a dropped 200 MB download can be retried without another
     * round trip through check.
     */
    public function packageDownload(Request $request, string $token)
    {
        $entry = Cache::get(self::TOKEN_PREFIX.$token);
        if (! is_array($entry)) {
            return response()->json([
                'ok' => false, 'reason' => 'token_expired',
                'message' => 'This download link has expired. Press "Check for updates" again.',
            ], 410);
        }

        $rel = DB::table('releases')->where('id', $entry['release_id'])->whereNotNull('published_at')->first();
        if (! $rel || ! $rel->file_path || ! is_file(storage_path('app/'.$rel->file_path))) {
            return response()->json([
                'ok' => false, 'reason' => 'package_unavailable',
                'message' => 'That package is no longer available. Please contact Ametecs (WhatsApp 9000098877).',
            ], 404);
        }

        // Re-check entitlement at download time: a licence revoked in the hour
        // since the check must not still be able to pull the build.
        $lic = DB::table('licences')->where('id', $entry['licence_id'])->first();
        $granted = $lic && DB::table('release_grants')
            ->where('release_id', $rel->id)->where('client_id', $lic->client_id)->exists();
        if (! $lic || $lic->status !== 'active' || ! LicenseService::amcActive($lic) || ! $granted) {
            return response()->json([
                'ok' => false, 'reason' => 'not_entitled',
                'message' => 'This update is not available for your licence.',
            ], 403);
        }

        $this->log($lic, 'download', $rel->version);

        return response()->download(storage_path('app/'.$rel->file_path), 'SmartPRS-Update-'.$rel->version.'.zip', [
            'Content-Type' => 'application/zip',
            'X-SmartPRS-Version' => $rel->version,
            'X-SmartPRS-Sha256' => (string) $rel->checksum,
        ]);
    }

    /** GET /update/download/{version}?key=... */
    public function download(Request $request, string $version)
    {
        [$lic] = $this->licence($request);
        if (! $lic || $lic->status !== 'active' || ! LicenseService::amcActive($lic)) {
            return response(self::NEUTRAL, 403);
        }
        $rel = DB::table('releases')->where('version', $version)->whereNotNull('published_at')->first();
        $granted = $rel && DB::table('release_grants')->where('release_id', $rel->id)->where('client_id', $lic->client_id)->exists();
        if (! $rel || ! $granted || ! $rel->file_path || ! is_file(storage_path('app/'.$rel->file_path))) {
            return response('This update is not available for your licence.', 403);
        }
        $this->log($lic, 'download', $version);

        return response()->download(storage_path('app/'.$rel->file_path), 'SmartPRS-Update-'.$version.'.zip');
    }

    private function log(object $lic, string $action, string $detail = ''): void
    {
        try {
            DB::table('client_updates')->insert([
                'client_id' => $lic->client_id, 'licence_id' => $lic->id,
                'version' => null, 'action' => $action, 'detail' => $detail,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** "2026.6.12" → comparable "2026.6.12"; tolerates v-prefixes/spaces. */
    private function numeric(string $v): string
    {
        return preg_replace('/[^0-9.]/', '', $v) ?: '0';
    }
}
