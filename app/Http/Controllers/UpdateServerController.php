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
            LicenseService::event($lic->id, 'denied', 'Activation attempt from a second server', $request->ip());

            return response()->json(['ok' => false, 'error' => 'This licence is already activated on another server. To move servers, contact Ametecs and we will release it (takes a minute).'], 422);
        }

        DB::table('licences')->where('id', $lic->id)->update([
            'status' => 'active',
            'fingerprint' => $fp,
            'server_name' => mb_substr((string) $request->input('server_name', ''), 0, 190),
            'activated_at' => $lic->activated_at ?: now(),
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);
        LicenseService::event($lic->id, 'activated', 'Activated on '.$request->input('server_name', 'unknown server'), $request->ip());

        return response()->json([
            'ok' => true,
            'cert' => LicenseService::certificate($lic, $key, $fp),
            'company' => DB::table('onprem_clients')->where('id', $lic->client_id)->value('company'),
        ]);
    }

    /** POST /update/heartbeat {key} — light status; failures NEVER block usage. */
    public function heartbeat(Request $request)
    {
        [$lic] = $this->licence($request);
        if (! $lic) {
            return response()->json(['ok' => false, 'error' => self::NEUTRAL], 422);
        }
        DB::table('licences')->where('id', $lic->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);

        return response()->json([
            'ok' => true,
            'status' => $lic->status,
            'amc_expires_on' => $lic->amc_expires_on,
            'amc_active' => LicenseService::amcActive($lic),
            'expiry_mode' => $lic->expiry_mode ?? 'renew',
        ]);
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
