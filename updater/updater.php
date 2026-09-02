<?php
/**
 * SmartPRS ON-PREMISES UPDATER (Ejaz's self-update flow chart, 2 Sep 2026).
 *
 * Runs as its OWN process, outside Laravel, launched by
 * App\Services\UpdateClient::install(). It HAS to be standalone: the files it
 * replaces include the very application that would otherwise be running it,
 * and on Windows/IIS an open PHP file cannot be overwritten at all.
 *
 * The sequence is the flow chart, step for step:
 *    5. verify package (hash + shape)      9. run database migrations
 *    6. create backup                     10. post-update checks
 *    7. maintenance mode                  11. maintenance off, or ROLL BACK
 *    8. install files                     12. running on the new version
 *
 * Nothing is touched until every check that can fail has passed. From the
 * moment the backup exists, ANY failure rolls the tree back and brings the
 * app up on its previous version.
 *
 * Progress is written to storage/app/updates/state.json, which the browser
 * polls through public/update-status.php — that file boots nothing, which is
 * the only way to show progress while the app itself is answering 503.
 *
 * Usage: php updater/updater.php <path-to-job.json>
 */

set_time_limit(0);
ignore_user_abort(true);
error_reporting(E_ALL);

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || ! is_file($jobFile)) {
    fwrite(STDERR, "updater: job file not found\n");
    exit(1);
}

$job = json_decode((string) file_get_contents($jobFile), true);
if (! is_array($job) || empty($job['base']) || empty($job['package']) || empty($job['version'])) {
    fwrite(STDERR, "updater: malformed job file\n");
    exit(1);
}

$base = rtrim($job['base'], '\\/');
$package = $job['package'];
$version = $job['version'];
$sha = $job['sha256'] ?? null;
$php = $job['php'] ?? PHP_BINARY;
$stateFile = $job['state_file'] ?? ($base.'/storage/app/updates/state.json');
$workDir = dirname($stateFile);
$stamp = date('Ymd_Hi');
$backupDir = $base.'/storage/app/updates/backup_'.$stamp;

/** Everything reports through here, so the console always knows where it is. */
function step(string $phase, int $percent, string $message): void
{
    global $stateFile;
    $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
    $state = is_array($state) ? $state : [];
    $state['phase'] = $phase;
    $state['percent'] = $percent;
    $state['message'] = $message;
    $log = isset($state['log']) && is_array($state['log']) ? $state['log'] : [];
    $log[] = date('H:i:s').'  '.$message;
    $state['log'] = array_slice($log, -60);
    $state['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    echo $message.PHP_EOL;
}

function setInstalledVersion(string $v): void
{
    global $stateFile;
    $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
    $state = is_array($state) ? $state : [];
    $state['current_version'] = $v;
    $state['available'] = null;
    $state['package'] = null;
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

function artisan(string $command, string $base, string $php): array
{
    $cmd = escapeshellarg($php).' '.escapeshellarg($base.'/artisan').' '.$command.' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);

    return [$code, trim(implode("\n", $out))];
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function copyTree(string $from, string $to): int
{
    $count = 0;
    if (is_file($from)) {
        @mkdir(dirname($to), 0775, true);
        if (@copy($from, $to)) {
            $count++;
        }

        return $count;
    }
    if (! is_dir($from)) {
        return 0;
    }
    @mkdir($to, 0775, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $count += copyTree($from.'/'.$entry, $to.'/'.$entry);
    }

    return $count;
}

/**
 * Step 11 (No branch) — restore the backup, then let the app back in.
 *
 * Each replaced entry is REMOVED before its backup is copied back, never
 * merged over. Copying alone would leave behind every file the new version
 * ADDED — including a stray new migration in database/migrations, which would
 * then run on the next update and damage the database this rollback exists to
 * protect.
 */
function rollback(string $backupDir, string $base, string $php, string $why): void
{
    global $topLevel;

    step('rolling_back', 90, 'Update failed: '.$why.' — restoring the previous version.');
    if (is_dir($backupDir)) {
        foreach ((array) $topLevel as $entry) {
            $live = $base.'/'.$entry;
            is_dir($live) ? rrmdir($live) : @unlink($live);
            if (file_exists($backupDir.'/'.$entry)) {
                copyTree($backupDir.'/'.$entry, $live);
            }
        }
        if (is_file($backupDir.'/version.json')) {
            @copy($backupDir.'/version.json', $base.'/version.json');
        }
        step('rolling_back', 95, 'Previous version restored from '.basename($backupDir).'.');
    } else {
        step('rolling_back', 95, 'No backup had been taken yet — nothing was changed.');
    }
    artisan('optimize:clear', $base, $php);
    artisan('up', $base, $php);
    @unlink($base.'/storage/framework/down');
    step('failed', 100, 'Update cancelled. This server is still running its previous version. Reason: '.$why);
    exit(1);
}

// ------------------------------------------------------------ 5. verify package
step('verifying', 10, 'Verifying the update package…');

if (! is_file($package)) {
    step('failed', 100, 'The update package is missing. Please download it again.');
    exit(1);
}
if ($sha && ! hash_equals(strtolower($sha), strtolower((string) hash_file('sha256', $package)))) {
    @unlink($package);
    step('failed', 100, 'The update package failed its integrity check and was deleted. Please download it again.');
    exit(1);
}
if (! class_exists('ZipArchive')) {
    step('failed', 100, 'PHP on this server has no zip extension, so the package cannot be opened. Enable ext-zip.');
    exit(1);
}

$extract = $workDir.'/extract_'.preg_replace('/[^0-9A-Za-z.\-]/', '', $version).'_'.$stamp;
rrmdir($extract);
@mkdir($extract, 0775, true);

$zip = new ZipArchive();
if ($zip->open($package) !== true || ! $zip->extractTo($extract)) {
    rrmdir($extract);
    step('failed', 100, 'The update package could not be opened. Please download it again.');
    exit(1);
}
$zip->close();

$payload = $extract;
$entries = fn (string $d) => array_values(array_diff(scandir($d) ?: [], ['.', '..']));

// The SmartPRS client package wraps everything in ONE folder
// (SmartPRS-OnPrem/...). Descend through those wrappers first. Without this the
// updater "installs" a new directory INTO the app root, replaces nothing, and
// still reports success — the worst possible outcome, because the version
// number moves and every later check then says "up to date".
while (count($list = $entries($payload)) === 1 && is_dir($payload.'/'.$list[0])) {
    $payload .= '/'.$list[0];
}

// A manifest-style package puts the application under app/ next to
// manifest.json; a plain zip of the application root is accepted too, so both
// build styles install.
if (is_file($payload.'/manifest.json') && is_dir($payload.'/app') && is_dir($payload.'/app/app')) {
    $payload .= '/app';
}

// Prove it IS a SmartPRS tree BEFORE anything is backed up, shut down or
// stamped. Refusing here costs the client nothing; a wrong payload that gets as
// far as "installed" leaves them believing they upgraded when they did not.
if (! is_dir($payload.'/app') || ! is_dir($payload.'/config')) {
    rrmdir($extract);
    step('failed', 100, 'This package is not a SmartPRS update — it has no app/ and config/ folders at its root. '
        .'Nothing was changed. (An installer/Setup zip is not an update package.)');
    exit(1);
}

$topLevel = $entries($payload);
if (! $topLevel) {
    rrmdir($extract);
    step('failed', 100, 'The update package is empty.');
    exit(1);
}

// Never let a package overwrite this installation's identity or its data.
$protected = ['.env', '.env.bak', 'storage', 'license.lic', '.git', 'node_modules'];
$topLevel = array_values(array_filter($topLevel, fn ($e) => ! in_array(strtolower($e), $protected, true)));
if (! $topLevel) {
    rrmdir($extract);
    step('failed', 100, 'The update package contains nothing that may be installed.');
    exit(1);
}

step('verifying', 15, 'Package verified. It replaces: '.implode(', ', $topLevel).'.');

// Read the channel NOW, before anything is installed. version.json is itself a
// top-level entry in the package, so by the time the files are copied this
// install's own channel has already been overwritten by the package's. Capture
// it here or a beta test box silently drops back to stable on its first update
// — and then never sees another beta build.
$liveVersionJson = is_file($base.'/version.json')
    ? json_decode(ltrim((string) file_get_contents($base.'/version.json'), "\xEF\xBB\xBF"), true) : null;
$keepChannel = (is_array($liveVersionJson) && ($liveVersionJson['channel'] ?? '') === 'beta') ? 'beta' : 'stable';

// ------------------------------------------------------------- 6. create backup
step('backup', 25, 'Backing up the current version…');

@mkdir($backupDir, 0775, true);
if (! is_dir($backupDir)) {
    rrmdir($extract);
    step('failed', 100, 'Could not create a backup folder under storage/app/updates. Nothing was changed.');
    exit(1);
}
foreach ($topLevel as $entry) {
    if (file_exists($base.'/'.$entry)) {
        copyTree($base.'/'.$entry, $backupDir.'/'.$entry);
    }
}
if (is_file($base.'/version.json')) {
    copyTree($base.'/version.json', $backupDir.'/version.json');
}
step('backup', 30, 'Backup written to storage/app/updates/'.basename($backupDir).'.');

// ---------------------------------------------------------- 7. maintenance mode
step('maintenance', 35, 'Putting SmartPRS into maintenance mode…');

[$code] = artisan('down --retry=60', $base, $php);
if ($code !== 0) {
    // artisan may already be half-replaced or unbootable — the flag file alone is
    // enough for Laravel to refuse requests.
    @mkdir($base.'/storage/framework', 0775, true);
    @file_put_contents($base.'/storage/framework/down', json_encode([
        'except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => null,
        'secret' => null, 'status' => 503, 'template' => null,
    ]));
}

// -------------------------------------------------------------- 8. install files
step('installing', 55, 'Installing version '.$version.'…');

$copied = 0;
foreach ($topLevel as $entry) {
    $copied += copyTree($payload.'/'.$entry, $base.'/'.$entry);
}
if ($copied === 0) {
    rrmdir($extract);
    rollback($backupDir, $base, $php, 'no files could be written (check folder permissions)');
}
step('installing', 60, $copied.' files installed.');

// The progress reader must survive an update that replaces public/.
if (! is_file($base.'/public/update-status.php') && is_file($base.'/updater/update-status.php')) {
    @copy($base.'/updater/update-status.php', $base.'/public/update-status.php');
}

// ---------------------------------------------------------- 9. database migration
step('migrating', 70, 'Updating the database…');

artisan('optimize:clear', $base, $php);
[$mCode, $mOut] = artisan('migrate --force', $base, $php);
if ($mCode !== 0) {
    rrmdir($extract);
    rollback($backupDir, $base, $php, 'the database update failed — '.substr($mOut, 0, 400));
}

// -------------------------------------------------------- 10. post-update checks
step('checking', 85, 'Checking that SmartPRS starts correctly…');

[$hCode, $hOut] = artisan('--version', $base, $php);
if ($hCode !== 0) {
    rrmdir($extract);
    rollback($backupDir, $base, $php, 'the application did not start after the update — '.substr($hOut, 0, 400));
}

// ------------------------------------------------------- 11. maintenance mode off
step('finishing', 95, 'Bringing SmartPRS back online…');

// Stamp the new version, keeping the channel captured before the install.
@file_put_contents($base.'/version.json', json_encode([
    'product' => 'smartprs',
    'version' => $version,
    'channel' => $keepChannel,
    'installed_at' => date('c'),
], JSON_PRETTY_PRINT));

artisan('optimize:clear', $base, $php);
artisan('up', $base, $php);
@unlink($base.'/storage/framework/down');

rrmdir($extract);
@unlink($package);

// ---------------------------------------------------------- 12. new version live
setInstalledVersion($version);
step('done', 100, 'SmartPRS is now running version '.$version.'. The previous version is kept in storage/app/updates/'
    .basename($backupDir).' and can be deleted once everything looks right.');

exit(0);
