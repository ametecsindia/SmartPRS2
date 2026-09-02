<?php

namespace App\Services;

use App\Http\Controllers\ClientUpdateController;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ON-PREM SELF-UPDATE CLIENT (Ejaz's flow chart, 2 Sep 2026) — steps 1-5.
 *
 * Talks to the SmartPRS update server over the SAME channel and identity as
 * licensing: the licence key stored by ClientUpdateController is the credential,
 * the machine fingerprint proves it is this server, and only update metadata
 * travels. config('smartprs.update_url') is the ONE place that knows where the
 * platform lives — never a second URL for updates.
 *
 * This class detects the version (1), checks the server (3), downloads the
 * package (4) and verifies it (5). It deliberately does NOT install: PHP cannot
 * reliably overwrite the very files it is executing, and on Windows/IIS it
 * cannot overwrite them at all while they are open. Steps 6-12 are handed to
 * the standalone updater in updater/updater.php, launched as its own process.
 *
 * Everything this feature writes lives under storage/app/updates — never in the
 * application tree, which the updater is about to replace.
 */
class UpdateClient
{
    /** Everything this feature writes lives here. */
    public const DIR = 'app/updates';

    // ---------------------------------------------------------------- 1. identity

    /**
     * Step 1 — the version this server believes it is running.
     *
     * version.json at the application root is the source of truth: it ships
     * inside the package, the updater rewrites it after a successful install,
     * and unlike a config value it cannot be lost to `artisan config:cache`.
     * config('smartprs.version') is only the fallback for an install made
     * before version.json existed.
     */
    public function currentVersion(): string
    {
        $data = $this->versionFile();

        return (string) ($data['version'] ?? config('smartprs.version', '0'));
    }

    /**
     * Which channel this server follows. Same file as the version, so opting a
     * test box into beta is one edited field and no .env change.
     */
    public function channel(): string
    {
        $data = $this->versionFile();

        return ($data['channel'] ?? '') === 'beta' ? 'beta' : 'stable';
    }

    private function versionFile(): array
    {
        $file = base_path('version.json');
        if (is_file($file)) {
            // Strip a UTF-8 BOM: version.json is written by the build script on
            // Windows, and a BOM makes json_decode() return null - which would
            // report this server as version 0 and re-offer every update forever.
            $raw = ltrim((string) file_get_contents($file), "\xEF\xBB\xBF");
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /** Stable, anonymous id for this installation — support uses it, nothing is gated on it. */
    public function installationId(): string
    {
        return substr(hash('sha256', 'install|'.config('app.key')), 0, 32);
    }

    /** The licence key this install activated with, or null. */
    public function licenceKey(): ?string
    {
        $st = ClientUpdateController::state();
        try {
            $key = ! empty($st['key_enc']) ? Crypt::decryptString($st['key_enc']) : '';
        } catch (\Throwable $e) {
            $key = '';
        }

        return $key !== '' ? $key : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('smartprs.update_url'), '/');
    }

    // ---------------------------------------------------------------- 3. the check

    /**
     * Step 3 — ask the platform whether a newer build is granted to this licence.
     * Never throws: the Updates screen has to render even with the network down.
     */
    public function check(): array
    {
        $key = $this->licenceKey();
        if (! $key) {
            return $this->fail('This server is not activated yet. Activate the licence first, then check again.');
        }

        $url = $this->baseUrl().'/check';

        try {
            $res = Http::timeout(25)->acceptJson()
                ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true]])
                ->post($url, [
                    'key' => $key,
                    'version' => $this->currentVersion(),
                    'current_version' => $this->currentVersion(),
                    'product' => 'smartprs',
                    'channel' => $this->channel(),
                    'fingerprint' => ClientUpdateController::fingerprint(),
                    'installation_id' => $this->installationId(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('smartprs update check failed: '.$e->getMessage());

            return $this->fail('Could not reach the SmartPRS update server. Check this server\'s internet connection and try again.');
        }

        $body = $res->json();
        if (! is_array($body)) {
            return $this->fail('The update server sent an unexpected reply (HTTP '.$res->status().').');
        }

        if (! $res->successful() || empty($body['ok'])) {
            $message = $body['message'] ?? $body['error'] ?? 'The update server refused the check.';
            // "Key not recognised" is usually the WRONG server, not a bad key — an
            // .env still pointing at a test platform. Name the host that answered so
            // the admin can see the difference without opening a support ticket.
            if (($body['reason'] ?? null) === 'unknown_key') {
                $message .= ' (asked '.parse_url($url, PHP_URL_HOST).')';
            }

            return $this->fail($message, $body['reason'] ?? null);
        }

        $state = $this->state();
        $state['checked_at'] = now()->toDateTimeString();
        $state['current_version'] = $this->currentVersion();
        $state['amc_expires_on'] = $body['amc_expires_on'] ?? ($state['amc_expires_on'] ?? null);

        // The server answers in the new flat shape; the old {update:{...}} shape is
        // still honoured so a platform that has not been updated yet keeps working.
        $offer = $body['update'] ?? null;
        $available = ($body['update_available'] ?? false) || is_array($offer);

        if (! $available) {
            $state['phase'] = 'idle';
            $state['available'] = null;
            $state['package'] = null;
            $state['message'] = $body['message'] ?? $body['reason'] ?? 'This server is on the latest version granted to you.';
            $this->writeState($state);

            return ['ok' => true, 'update_available' => false] + $state;
        }

        $version = (string) ($body['version'] ?? $offer['version'] ?? '');
        $state['phase'] = 'available';
        $state['available'] = [
            'version' => $version,
            'title' => $body['title'] ?? null,
            'notes' => $body['notes'] ?? ($offer['notes'] ?? null),
            'size_bytes' => (int) ($body['size_bytes'] ?? $offer['size'] ?? 0),
            'package_hash' => $body['package_hash'] ?? ($offer['checksum'] ?? null),
            // No token from an older platform — fall back to the legacy
            // ?key=<licence key> download so this client still updates.
            'download_url' => $body['download_url'] ?? ($this->baseUrl().'/download/'.rawurlencode($version)),
            'released_at' => $body['released_at'] ?? null,
        ];
        $state['message'] = 'Version '.$version.' is available.';
        $this->writeState($state);

        return ['ok' => true, 'update_available' => true] + $state;
    }

    // ------------------------------------------------------------- 4-5. download

    /** Steps 4 and 5 — fetch the package the server offered and prove it arrived intact. */
    public function download(): array
    {
        $state = $this->state();
        $available = $state['available'] ?? null;
        if (! $available || empty($available['download_url'])) {
            return $this->fail('Nothing to download — press "Check for updates" first.');
        }

        $dir = storage_path(self::DIR);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) {
            return $this->fail('Could not create '.$dir.'. Give the web server write access to storage/ and try again.');
        }
        if (! is_writable($dir)) {
            return $this->fail('storage/app/updates is not writable by the web server, so the package cannot be saved.');
        }

        $version = preg_replace('/[^0-9A-Za-z.\-]/', '', (string) $available['version']);
        $target = $dir.'/SmartPRS-'.$version.'-update.zip';

        $state['phase'] = 'downloading';
        $state['percent'] = 3;
        $state['message'] = 'Downloading version '.$available['version'].'…';
        $this->writeState($state);

        // A legacy download URL carries no token, so it still needs the key.
        $query = str_contains((string) $available['download_url'], '/package/')
            ? []
            : ['key' => (string) $this->licenceKey()];

        try {
            $res = Http::timeout(900)
                ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true], 'sink' => $target])
                ->get($available['download_url'], $query);
        } catch (\Throwable $e) {
            @unlink($target);

            return $this->fail('The download did not finish: '.$e->getMessage());
        }

        if (! $res->successful()) {
            @unlink($target);

            return $this->fail('The update server refused the download (HTTP '.$res->status()
                .'). The link may have expired — press "Check for updates" again.');
        }

        // The sink normally writes the file as it streams; some transports hand the
        // body back instead. Take it either way.
        if (! is_file($target) || filesize($target) === 0) {
            @file_put_contents($target, $res->body());
        }
        if (! is_file($target) || filesize($target) < 1000) {
            @unlink($target);

            return $this->fail('The package could not be saved to storage/app/updates.');
        }

        // Step 5. Integrity is not optional: a truncated zip that installs is far
        // worse than a download that failed loudly.
        if (! empty($available['package_hash'])) {
            $got = hash_file('sha256', $target);
            if (! hash_equals(strtolower((string) $available['package_hash']), strtolower((string) $got))) {
                @unlink($target);

                return $this->fail('The downloaded package failed its integrity check and was deleted. Please try again.');
            }
        }

        $state = $this->state();
        $state['phase'] = 'downloaded';
        $state['percent'] = 5;
        $state['package'] = $target;
        $state['message'] = 'Version '.$available['version'].' downloaded and verified. Ready to install.';
        $this->writeState($state);

        return ['ok' => true] + $state;
    }

    // ------------------------------------------------------------- 6-12. install

    /**
     * Hand the verified package to the standalone updater and return at once.
     * The browser then polls public/update-status.php while the updater works —
     * it cannot poll this application, which is about to answer 503.
     */
    public function install(): array
    {
        $state = $this->state();
        $package = $state['package'] ?? null;
        $version = $state['available']['version'] ?? null;

        if (! $package || ! is_file($package) || ! $version) {
            return $this->fail('No verified package is waiting. Check for the update and download it first.');
        }
        if (($state['phase'] ?? '') === 'installing') {
            return ['ok' => true] + $state;   // already running — never launch twice
        }

        $updater = base_path('updater/updater.php');
        if (! is_file($updater)) {
            return $this->fail('The updater component is missing (updater/updater.php). Please contact Ametecs (WhatsApp 9000098877).');
        }

        $php = $this->phpBinary();
        if (! $php) {
            return $this->fail('Could not locate the PHP command-line program on this server, so the update cannot install '
                .'itself. Set SMARTPRS_PHP_BINARY in .env to the full path of php.exe (or php) and try again.');
        }
        if (! $this->canSpawn()) {
            return $this->fail('This server blocks background processes (popen and proc_open are disabled in php.ini), so '
                .'the update cannot install itself. Ask your IT team to run: '.$php.' updater/updater.php');
        }

        $job = [
            'base' => base_path(),
            'package' => $package,
            'version' => $version,
            'sha256' => $state['available']['package_hash'] ?? null,
            'php' => $php,
            'state_file' => $this->stateFile(),
            'started_at' => now()->toDateTimeString(),
        ];
        $jobFile = storage_path(self::DIR.'/job.json');
        @file_put_contents($jobFile, json_encode($job, JSON_PRETTY_PRINT));

        $state['phase'] = 'installing';
        $state['percent'] = 5;
        $state['message'] = 'Starting the updater…';
        $state['log'] = ['Update started for version '.$version.'.'];
        $this->writeState($state);

        $this->spawn($php, $updater, $jobFile);

        return ['ok' => true] + $this->state();
    }

    // ---------------------------------------------------------------- state

    public function stateFile(): string
    {
        return storage_path(self::DIR.'/state.json');
    }

    public function state(): array
    {
        $file = $this->stateFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = is_array($data) ? $data : [];

        return $data + [
            'phase' => 'idle',
            'percent' => 0,
            'message' => '',
            'available' => null,
            'package' => null,
            'log' => [],
            'checked_at' => null,
            'amc_expires_on' => null,
            'current_version' => $this->currentVersion(),
        ];
    }

    public function writeState(array $state): void
    {
        $dir = storage_path(self::DIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $state['current_version'] = $state['current_version'] ?? $this->currentVersion();
        $state['updated_at'] = now()->toDateTimeString();
        @file_put_contents($this->stateFile(), json_encode($state, JSON_PRETTY_PRINT));
    }

    /** Forget a half-finished attempt so the screen can start clean. */
    public function reset(): void
    {
        $st = $this->state();
        $this->writeState([
            'phase' => 'idle', 'percent' => 0, 'message' => '',
            'available' => null, 'package' => null, 'log' => [],
            'checked_at' => $st['checked_at'] ?? null,
            'amc_expires_on' => $st['amc_expires_on'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function fail(string $message, ?string $reason = null): array
    {
        return ['ok' => false, 'error' => $message, 'message' => $message, 'reason' => $reason] + $this->state();
    }

    /**
     * The PHP CLI binary. Under a web SAPI, PHP_BINARY points at php-fpm or
     * php-cgi, which cannot run a script — so it is trusted only when its
     * basename really is php/php.exe.
     */
    public function phpBinary(): ?string
    {
        $candidates = [];
        if ($env = env('SMARTPRS_PHP_BINARY')) {
            $candidates[] = $env;
        }
        $windows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');
        $exe = $windows ? 'php.exe' : 'php';
        if (defined('PHP_BINDIR')) {
            $candidates[] = rtrim(PHP_BINDIR, '\\/').DIRECTORY_SEPARATOR.$exe;
        }
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $name = strtolower(basename(PHP_BINARY));
            if ($name === 'php' || $name === 'php.exe') {
                $candidates[] = PHP_BINARY;
            }
            $candidates[] = rtrim(dirname(PHP_BINARY), '\\/').DIRECTORY_SEPARATOR.$exe;
        }

        foreach ($candidates as $c) {
            if ($c && is_file($c)) {
                return $c;
            }
        }

        return null;
    }

    public function canSpawn(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('popen', $disabled, true) || ! in_array('proc_open', $disabled, true);
    }

    /** Launch the updater detached, so it survives this request ending. */
    private function spawn(string $php, string $updater, string $jobFile): void
    {
        $log = storage_path(self::DIR.'/updater.log');
        $windows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');

        $cmd = $windows
            ? 'start /B "" '.escapeshellarg($php).' '.escapeshellarg($updater).' '.escapeshellarg($jobFile)
                .' > '.escapeshellarg($log).' 2>&1'
            : escapeshellarg($php).' '.escapeshellarg($updater).' '.escapeshellarg($jobFile)
                .' > '.escapeshellarg($log).' 2>&1 &';

        try {
            if ($windows) {
                $handle = popen('cmd /C '.$cmd, 'r');
                if ($handle !== false) {
                    pclose($handle);
                }
            } else {
                $handle = proc_open($cmd, [], $pipes);
                if (is_resource($handle)) {
                    proc_close($handle);
                }
            }
        } catch (\Throwable $e) {
            Log::error('smartprs updater spawn failed: '.$e->getMessage());
            $state = $this->state();
            $state['phase'] = 'failed';
            $state['message'] = 'The updater could not be started: '.$e->getMessage();
            $this->writeState($state);
        }
    }
}
