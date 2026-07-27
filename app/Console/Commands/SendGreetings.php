<?php

namespace App\Console\Commands;

use App\Services\GreetingService;
use Illuminate\Console\Command;

/**
 * F8 — daily greetings sweep.
 *
 * Scheduled HOURLY (routes/console.php); for each run it greets only the tenants
 * whose configured send_hour matches the current hour, so every company fires at
 * its own local time without needing a separate schedule entry. Idempotent —
 * dedupe keys in NotificationService stop any double-send if the hour is retried.
 *
 *   php artisan greetings:send                 # today, honour each tenant's send_hour
 *   php artisan greetings:send --hour=9        # only tenants whose send_hour = 9
 *   php artisan greetings:send --date=2026-08-15 --all-hours --tenant=5
 */
class SendGreetings extends Command
{
    protected $signature = 'greetings:send
        {--date= : Day to greet (Y-m-d); defaults to today}
        {--hour= : Only tenants whose configured send_hour = this hour}
        {--all-hours : Ignore send_hour gating (run for every enabled tenant)}
        {--tenant= : Limit to one tenant id}';

    protected $description = 'Send birthday and work-anniversary greetings due today (F8).';

    public function handle(): int
    {
        $date = $this->option('date') ?: null;
        $tenant = $this->option('tenant') !== null && $this->option('tenant') !== ''
            ? (int) $this->option('tenant') : null;

        $hourGate = null;
        if (! $this->option('all-hours')) {
            $hourGate = $this->option('hour') !== null && $this->option('hour') !== ''
                ? (int) $this->option('hour')
                : (int) now()->format('G');   // current local hour
        }

        $res = GreetingService::runForToday($tenant, $date, $hourGate);
        $this->info("Greetings: sent {$res['sent']}, skipped {$res['skipped']}, across {$res['tenants']} tenant(s).");

        return self::SUCCESS;
    }
}
