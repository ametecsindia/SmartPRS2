<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // Idempotent on the SAME server; a different server needs a panel
        // deactivation first (Q5: self-service moves are limited).
        if ($lic->status === 'active' && $lic->fingerprint && ! hash_equals($lic->fingerprint, $fp)) {
            // Anti-fraud tripwire: a DIFFERENT machine tried to activate this key.
            // Logged to History + sales alerted (deduped once/day).
            LicenseService::logRejection($lic, 'server_mismatch', $fp, $request->ip());

            return response()->json(['ok' => false, 'error' => 'This licence is already activated on another server. To move servers, contact Ametecs and we will release it (takes a minute).'], 422);
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

    /** POST /update/check {key, version} — latest GRANTED release beyond theirs. */
    public function check(Request $request)
    {
        [$lic] = $this->licence($request);
        if (! $lic || $lic->status !== 'active') {
            return response()->json(['ok' => false, 'error' => self::NEUTRAL], 422);
        }
        $current = trim((string) $request->input('version', '0'));
        DB::table('licences')->where('id', $lic->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
        $this->log($lic, 'check', 'on '.$current);

        if (! LicenseService::amcActive($lic)) {
            return response()->json([
                'ok' => true, 'update' => null,
                'reason' => 'Your AMC ended on '.($lic->amc_expires_on ?: '—').'. Renew to receive updates — WhatsApp 9000098877.',
                'amc_active' => false, 'amc_expires_on' => $lic->amc_expires_on,
                'expiry_mode' => $lic->expiry_mode ?? 'renew',
            ]);
        }

        $rel = DB::table('releases')
            ->join('release_grants', 'release_grants.release_id', '=', 'releases.id')
            ->where('release_grants.client_id', $lic->client_id)
            ->whereNotNull('releases.published_at')
            ->orderByDesc('releases.id')
            ->select('releases.*')
            ->first();

        if (! $rel || version_compare($this->numeric($rel->version), $this->numeric($current), '<=')) {
            return response()->json(['ok' => true, 'update' => null, 'reason' => 'You are on the latest version granted to you.', 'amc_active' => true, 'amc_expires_on' => $lic->amc_expires_on, 'expiry_mode' => $lic->expiry_mode ?? 'renew']);
        }

        return response()->json([
            'ok' => true, 'amc_active' => true, 'amc_expires_on' => $lic->amc_expires_on,
            'expiry_mode' => $lic->expiry_mode ?? 'renew',
            'update' => ['version' => $rel->version, 'notes' => $rel->notes, 'size' => (int) $rel->size, 'checksum' => $rel->checksum],
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
