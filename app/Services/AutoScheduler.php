<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * 2026-09-28 (Ejaz) — THE SCHEDULER RUNS BY ITSELF.
 *
 * "I do not want to run it manually - it should already be available within
 * the installation itself by default."
 *
 * Everything time-based in SmartPRS (probation alerts, absence alerts,
 * biometric auto-sync, greetings, backups, compliance scan…) lives in
 * routes/console.php and only runs if something calls `artisan schedule:run`
 * every minute. On a client server nobody had set that up, so none of it ran.
 *
 * Three layers, so no install depends on a human:
 *   1. The installers call `php artisan smartprs:scheduler-install`, which
 *      registers an OS task (Windows Task Scheduler / Linux crontab) that runs
 *      `php artisan smartprs:tick` every minute.
 *   2. Installs made before this (upgraded by self-update, installer never
 *      re-run) register that same task themselves: while no OS task is
 *      ticking, the web app tries once a day (AutoSchedulerMiddleware).
 *   3. Until an OS task ticks, web traffic drives the tick: at most once a
 *      minute a request spawns `smartprs:tick` in the background (the request
 *      is not slowed). smartprs:tick also catches up the hourly notifiers, so
 *      one visit in the hour is enough.
 *
 * Opt out with SMARTPRS_AUTO_SCHEDULER=false in .env (e.g. a dev machine).
 */
class AutoScheduler
{
    /** Written every minute by a scheduler run that an OS task (not the web) started. */
    public static function heartbeatFile(): string
    {
        return storage_path('framework/schedule-heartbeat');
    }

    public static function enabled(): bool
    {
        return filter_var(env('SMARTPRS_AUTO_SCHEDULER', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /** True when an OS task / cron has ticked in the last 3 minutes. */
    public static function osTicking(): bool
    {
        $f = self::heartbeatFile();

        return is_file($f) && (time() - (int) @filemtime($f)) < 180;
    }

    /**
     * Called after every web response. Cheap: one stat() when an OS task is
     * ticking; otherwise a non-blocking file lock and, at most once a minute,
     * one detached process spawn.
     */
    public static function maybeTick(): void
    {
        if (! self::enabled() || self::osTicking()) {
            return;
        }
        $stamp = storage_path('framework/auto-schedule.stamp');
        $fh = @fopen($stamp, 'c+');
        if (! $fh) {
            return;
        }
        try {
            if (! flock($fh, LOCK_EX | LOCK_NB)) {
                return;   // another request is handling it
            }
            $last = json_decode((string) stream_get_contents($fh), true) ?: [];
            $now = time();
            if ($now - (int) ($last['tick'] ?? 0) < 55) {
                return;
            }
            $last['tick'] = $now;
            // Layer 2 — once a day, try to hand the job to the OS for good.
            $tryInstall = $now - (int) ($last['install_try'] ?? 0) > 86400;
            if ($tryInstall) {
                $last['install_try'] = $now;
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($last));
            fflush($fh);
            flock($fh, LOCK_UN);

            $php = (new UpdateClient)->phpBinary();
            if (! $php || ! (new UpdateClient)->canSpawn()) {
                return;
            }
            self::spawnTick($php);
            if ($tryInstall) {
                $r = self::installOsTask($php);
                Log::info('smartprs.scheduler.self-install', $r);
            }
        } catch (\Throwable $e) {
            // never break a request
        } finally {
            @fclose($fh);
        }
    }

    /** Launch `artisan smartprs:tick` detached, flagged as web-driven. */
    public static function spawnTick(string $php): void
    {
        $artisan = base_path('artisan');
        if (self::windows()) {
            $cmd = 'cmd /C "set SMARTPRS_TICK_SOURCE=web&& start "" /B '.escapeshellarg($php).' '.escapeshellarg($artisan)
                .' smartprs:tick > NUL 2>&1"';   // errors still reach laravel.log
            $h = @popen($cmd, 'r');
            if ($h !== false) {
                pclose($h);
            }
        } else {
            $cmd = 'SMARTPRS_TICK_SOURCE=web '.escapeshellarg($php).' '.escapeshellarg($artisan)
                .' smartprs:tick > /dev/null 2>&1 &';
            $h = @proc_open($cmd, [], $pipes);
            if (is_resource($h)) {
                proc_close($h);
            }
        }
    }

    /**
     * Register the every-minute OS task. Idempotent (re-creating replaces it).
     *
     * @return array{ok:bool,message:string}
     */
    public static function installOsTask(?string $php = null): array
    {
        $php = $php ?: (new UpdateClient)->phpBinary();
        if (! $php) {
            return ['ok' => false, 'message' => 'PHP command-line program not found; set SMARTPRS_PHP_BINARY in .env.'];
        }
        $base = base_path();
        $artisan = $base.DIRECTORY_SEPARATOR.'artisan';

        if (self::windows()) {
            // A VBS wrapper runs php hidden — a per-minute task otherwise flashes a
            // console window on the desktop of whoever is logged in.
            $vbs = storage_path('app/smartprs-tick.vbs');
            @file_put_contents($vbs, 'CreateObject("WScript.Shell").Run """'.$php.'"" ""'.$artisan.'"" smartprs:tick", 0, False'."\r\n");
            $name = 'SmartPRS Scheduler '.substr(md5(strtolower($base)), 0, 6);
            // SYSTEM keeps running with nobody logged in (needs admin — the
            // installer is run as admin). Otherwise fall back to this user.
            foreach (['/RU SYSTEM ', ''] as $ru) {
                $out = [];
                $rc = 1;
                // Quoted by hand: Windows escapeshellarg() turns inner quotes into
                // spaces, which breaks a path like "…ClientPackage (1)\storage".
                @exec('schtasks /Create /F /SC MINUTE /MO 1 '.$ru.'/TN "'.$name.'" /TR "wscript.exe \\"'.$vbs.'\\"" 2>&1', $out, $rc);
                if ($rc === 0) {
                    return ['ok' => true, 'message' => 'Windows task "'.$name.'" runs every minute'.($ru ? ' as SYSTEM.' : ' as the current user.')];
                }
            }

            return ['ok' => false, 'message' => 'schtasks refused: '.trim(implode(' ', $out ?? []))];
        }

        // Linux / macOS — this user's crontab.
        $line = '* * * * * cd '.escapeshellarg($base).' && '.escapeshellarg($php).' artisan smartprs:tick >> /dev/null 2>&1';
        $cur = [];
        @exec('crontab -l 2>/dev/null', $cur);
        $keep = array_values(array_filter($cur, fn ($l) => ! str_contains($l, 'smartprs:tick') || ! str_contains($l, $base)));
        $keep[] = $line;
        $tmp = tempnam(sys_get_temp_dir(), 'sprs');
        @file_put_contents($tmp, implode("\n", $keep)."\n");
        $out = [];
        $rc = 1;
        @exec('crontab '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        @unlink($tmp);

        return $rc === 0
            ? ['ok' => true, 'message' => 'crontab entry added: runs every minute.']
            : ['ok' => false, 'message' => 'crontab refused: '.trim(implode(' ', $out))];
    }

    private static function windows(): bool
    {
        return str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');
    }
}
