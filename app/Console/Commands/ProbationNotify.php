<?php

namespace App\Console\Commands;

use App\Services\ProbationService;
use Illuminate\Console\Command;

/**
 * F6 — daily probation-completion reminder sweep.
 *
 * Scheduled daily (routes/console.php). Sends milestone reminders (default
 * 30/15/7/0 days before probation end, plus an overdue nudge) to HR and the
 * reporting manager, via the P0 NotificationService. Idempotent — each
 * (employee, milestone, end-date) fires once.
 *
 *   php artisan probation:notify
 *   php artisan probation:notify --tenant=5 --date=2026-08-01
 */
class ProbationNotify extends Command
{
    protected $signature = 'probation:notify
        {--tenant= : Limit to one tenant id}
        {--date= : Evaluate as of this date (Y-m-d); defaults to today}';

    protected $description = 'Notify HR and managers about upcoming/overdue probation completions (F6).';

    public function handle(): int
    {
        $tenant = $this->option('tenant') !== null && $this->option('tenant') !== ''
            ? (int) $this->option('tenant') : null;
        $date = $this->option('date') ?: null;

        $sent = ProbationService::runReminders($tenant, $date);
        $this->info("Probation reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
