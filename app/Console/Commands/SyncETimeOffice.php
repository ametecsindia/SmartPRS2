<?php

namespace App\Console\Commands;

use App\Services\ETimeOfficeService;
use App\Services\ETimeTrackLiteService;
use App\Services\GenericApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pull biometric punches into attendance_logs for every enabled connection (the
 * per-tenant rows from the "Biometric Device Setup" screen, or the .env fallback).
 * Handles BOTH providers per row: eTimeOffice (cloud API) and eTimeTrackLite
 * (eSSL on-prem SOAP WebAPI). Runs hourly via routes/console.php.
 *
 *   php artisan attendance:sync-etimeoffice --raw     (show raw response, no writes)
 *   php artisan attendance:sync-etimeoffice --days=1  (import last day, all configs)
 */
class SyncETimeOffice extends Command
{
    protected $signature = 'attendance:sync-etimeoffice
        {--from= : Start d/m/Y_H:i (default now - days)}
        {--to= : End d/m/Y_H:i (default now)}
        {--days=1 : Window in days when from/to omitted}
        {--raw : Print the raw API response + parse preview, write nothing}
        {--force : Ignore each device sync-interval and pull every enabled device now}
        {--dry : Fetch + parse + report, do not write}';

    protected $description = 'Sync biometric attendance from eTimeOffice into attendance_logs';

    public function handle(): int
    {
        $configs = ETimeOfficeService::allConfigs();
        if (! $configs) {
            $this->error('No enabled eTimeOffice connection. Set it up under Time & Attendance → Biometric Device Setup, or in .env.');

            return self::FAILURE;
        }

        $to = $this->option('to') ? self::parseOpt($this->option('to')) : now();
        $from = $this->option('from') ? self::parseOpt($this->option('from')) : (clone $to)->subDays((int) $this->option('days'));
        if (! $from || ! $to) {
            $this->error('Could not parse --from/--to. Use d/m/Y_H:i, e.g. 20/06/2026_09:00');

            return self::FAILURE;
        }

        // Owner-chosen frequency: the scheduler ticks often (every 5 min) and each
        // device is pulled only when its own sync_interval_min has elapsed. Manual
        // runs (--force / --from / --to / --raw / --dry) ignore the gate.
        $gate = ! ($this->option('force') || $this->option('from') || $this->option('to') || $this->option('raw') || $this->option('dry'));

        $any = false;
        foreach ($configs as $cfg) {
            $provider = $cfg['provider'] ?? 'etimeoffice';
            $isEtl = $provider === 'etimetracklite';
            $isGen = $provider === 'generic';
            $who = $isEtl ? ($cfg['serial_number'] ?? '?') : ($isGen ? 'device' : ($cfg['corp_id'] ?? '?'));
            $label = $who.($cfg['tenant_id'] ? ' (tenant '.$cfg['tenant_id'].')' : '').' ['.$provider.']';

            if ($gate && ! empty($cfg['last_sync_at'])) {
                $iv = (int) ($cfg['sync_interval_min'] ?? 0);
                if ($iv <= 0) {
                    $iv = 60;
                }
                try {
                    if (Carbon::parse($cfg['last_sync_at'])->diffInMinutes(now()) < $iv) {
                        continue;   // not due yet
                    }
                } catch (\Throwable $e) {
                }
            }
            $this->info('['.$label.'] '.$from->format('d/m/Y H:i').' → '.$to->format('d/m/Y H:i').' ...');

            if ($isEtl) {
                $res = ETimeTrackLiteService::fetch($cfg, $from, $to);
            } elseif ($isGen) {
                $res = GenericApiService::fetch($cfg, $from, $to);
            } else {
                $res = ETimeOfficeService::fetch($cfg, $from, $to);
            }
            if (! $res['ok']) {
                $this->error('  Fetch failed: '.($res['error'] ?? 'unknown'));

                continue;
            }

            $rawBody = ($isEtl || $isGen) ? (string) ($res['raw'] ?? '') : (string) ($res['body'] ?? '');
            if ($isEtl) {
                $punches = ETimeTrackLiteService::parse($rawBody, $cfg);
            } elseif ($isGen) {
                $punches = GenericApiService::parse($rawBody, $cfg);
            } else {
                $punches = ETimeOfficeService::parse($res['json'], $cfg);
            }

            if ($this->option('raw')) {
                $this->line('  ----- RAW (first 4000 chars) -----');
                $this->line(substr($rawBody, 0, 4000));
                $this->line('  ----- PARSED '.count($punches).' punch row(s); first 5: -----');
                foreach (array_slice($punches, 0, 5) as $p) {
                    $this->line('  '.$p['emp_code'].'  '.$p['punch_at']->format('Y-m-d H:i:s').'  '.$p['direction'].(($p['machine'] ?? '') !== '' ? '  MC:'.$p['machine'] : ''));
                }
                $any = true;

                continue;
            }
            if ($this->option('dry')) {
                $this->info('  Parsed '.count($punches).' punch(es) (dry-run, not written).');
                $any = true;

                continue;
            }
            if ($isEtl) {
                $cfg['source'] = 'etimetracklite';
            } elseif ($isGen) {
                $cfg['source'] = 'generic';
            }
            $r = ETimeOfficeService::import($punches, $cfg);
            $this->info('  Imported '.$r['imported'].' punch(es) for '.$r['matched'].' matched row(s).');
            if (! empty($r['unmatched'])) {
                $this->warn('  '.count($r['unmatched']).' unmatched code(s): '.implode(', ', array_slice(array_keys($r['unmatched']), 0, 15)));
            }
            // Stamp last_sync_at so the per-device interval gate can measure "due".
            if (! empty($cfg['id'])) {
                try {
                    \Illuminate\Support\Facades\DB::table('biometric_configs')->where('id', $cfg['id'])
                        ->update(['last_sync_at' => now(), 'last_count' => $r['imported'], 'updated_at' => now()]);
                } catch (\Throwable $e) {
                }
            }
            $any = true;
        }

        return $any ? self::SUCCESS : self::FAILURE;
    }

    private static function parseOpt(string $v): ?Carbon
    {
        $v = trim($v);
        foreach (['d/m/Y_H:i', 'd/m/Y H:i', 'd-m-Y H:i', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
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
}
