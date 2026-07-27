<?php

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * Daily error-visibility digest. Scans today's Laravel log for ERROR/CRITICAL/
 * ALERT/EMERGENCY entries and emails a short summary (counts + the most recent
 * messages) to the ops address (SMARTPRS_OPS_EMAIL) via the existing MailService
 * -> queue -> mail_log pipeline. If there are no errors, nothing is sent.
 *
 * Scheduled daily in routes/console.php. Run on demand:
 *   php artisan errors:digest
 */
class ErrorDigest extends Command
{
    protected $signature = 'errors:digest {--days=1 : How many days of log lines to scan}';

    protected $description = 'Email a digest of recent application errors to ops';

    public function handle(): int
    {
        $to = env('SMARTPRS_OPS_EMAIL');
        if (! $to) {
            $this->warn('SMARTPRS_OPS_EMAIL not set — skipping error digest.');

            return self::SUCCESS;
        }

        $logFile = storage_path('logs/laravel.log');
        if (! is_file($logFile)) {
            $this->info('No log file found — nothing to report.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = now()->subDays($i)->format('Y-m-d');
        }

        // Read only the tail of the log to stay cheap on a busy server.
        $lines = $this->tail($logFile, 4000);

        $levels = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
        $hits = [];
        foreach ($lines as $line) {
            $isToday = false;
            foreach ($dates as $d) {
                if (str_contains($line, '['.$d)) {
                    $isToday = true;
                    break;
                }
            }
            if (! $isToday) {
                continue;
            }
            foreach ($levels as $lvl) {
                if (str_contains($line, '.'.$lvl.':') || str_contains($line, $lvl.':')) {
                    $hits[] = trim($line);
                    break;
                }
            }
        }

        if (! $hits) {
            $this->info('No errors in the scanned window — no email sent.');

            return self::SUCCESS;
        }

        $total = count($hits);
        $recent = array_slice($hits, -10);
        $lineBlock = [];
        foreach ($recent as $i => $h) {
            $lineBlock['#'.($i + 1)] = mb_substr($h, 0, 240);
        }

        MailService::queue([
            'tenant_id' => null,
            'company_id' => null,
            'to' => $to,
            'to_name' => 'SmartPRS Ops',
            'subject' => '[SmartPRS] '.$total.' application error(s) in the last '.$days.' day(s)',
            'heading' => 'Daily error digest',
            'intro' => 'The application log recorded '.$total.' error-level entr'.($total === 1 ? 'y' : 'ies').
                ' in the last '.$days.' day(s). The most recent are below; see storage/logs on the server for full stack traces.',
            'lines' => $lineBlock,
            'kind' => 'ops.error_digest',
        ]);

        $this->info('Error digest queued — '.$total.' error(s), emailed to '.$to.'.');

        return self::SUCCESS;
    }

    /** Return roughly the last $maxLines lines of a file without loading it all. */
    private function tail(string $file, int $maxLines): array
    {
        $size = filesize($file);
        if ($size === 0) {
            return [];
        }
        // Cap the read window at ~2 MB from the end.
        $window = min($size, 2 * 1024 * 1024);
        $fh = fopen($file, 'rb');
        fseek($fh, -$window, SEEK_END);
        $data = fread($fh, $window);
        fclose($fh);
        $lines = preg_split('/\r\n|\n|\r/', (string) $data) ?: [];

        return array_slice($lines, -$maxLines);
    }
}
