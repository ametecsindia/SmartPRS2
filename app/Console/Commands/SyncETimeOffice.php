<?php

namespace App\Console\Commands;

use App\Services\ETimeOfficeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pull biometric punches from eTimeOffice into attendance_logs for every enabled
 * connection (the per-tenant rows from the "Biometric Device Setup" screen, or
 * the .env fallback). Runs hourly via routes/console.php.
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

        $any = false;
        foreach ($configs as $cfg) {
            $label = ($cfg['corp_id'] ?? '?').($cfg['tenant_id'] ? ' (tenant '.$cfg['tenant_id'].')' : '');
            $this->info('['.$label.'] '.$from->format('d/m/Y H:i').' → '.$to->format('d/m/Y H:i').' ...');
            $res = ETimeOfficeService::fetch($cfg, $from, $to);
            if (! $res['ok']) {
                $this->error('  Fetch failed: '.($res['error'] ?? 'unknown'));

                continue;
            }
            if ($this->option('raw')) {
                $this->line('  ----- RAW (first 4000 chars) -----');
                $this->line(substr($res['body'], 0, 4000));
                $parsed = ETimeOfficeService::parse($res['json'], $cfg);
                $this->line('  ----- PARSED '.count($parsed).' punch row(s); first 5: -----');
                foreach (array_slice($parsed, 0, 5) as $p) {
                    $this->line('  '.$p['emp_code'].'  '.$p['punch_at']->format('Y-m-d H:i:s').'  '.$p['direction'].(($p['machine'] ?? '') !== '' ? '  MC:'.$p['machine'] : ''));
                }
                $any = true;

                continue;
            }
            $punches = ETimeOfficeService::parse($res['json'], $cfg);
            if ($this->option('dry')) {
                $this->info('  Parsed '.count($punches).' punch(es) (dry-run, not written).');
                $any = true;

                continue;
            }
            $r = ETimeOfficeService::import($punches, $cfg);
            $this->info('  Imported '.$r['imported'].' punch(es) for '.$r['matched'].' matched row(s).');
            if (! empty($r['unmatched'])) {
                $this->warn('  '.count($r['unmatched']).' unmatched code(s): '.implode(', ', array_slice(array_keys($r['unmatched']), 0, 15)));
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
