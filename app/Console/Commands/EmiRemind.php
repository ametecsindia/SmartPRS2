<?php

namespace App\Console\Commands;

use App\Services\EmiReminderService;
use Illuminate\Console\Command;

/**
 * 30 Sep 2026 (Ejaz) — Loan EMI / Salary Advance deduction reminder, 3 days
 * before each company's pay date. Scheduled daily (routes/console.php);
 * idempotent per employee per payroll month.
 *
 *   php artisan emi:remind
 *   php artisan emi:remind --date=2026-10-28 --dry-run   (see who WOULD get it)
 */
class EmiRemind extends Command
{
    protected $signature = 'emi:remind
        {--tenant= : Limit to one tenant id}
        {--date= : Evaluate as of this date (Y-m-d); defaults to today}
        {--dry-run : List the reminders without sending}';

    protected $description = 'Email employees 3 days before pay date about the Loan EMI / Salary Advance to be deducted.';

    public function handle(): int
    {
        $t = $this->option('tenant');
        $r = EmiReminderService::run($t !== null && $t !== '' ? (int) $t : null, $this->option('date') ?: null, (bool) $this->option('dry-run'));
        foreach ($r['lines'] as $l) {
            $this->line($l);
        }
        $this->info(($this->option('dry-run') ? 'Dry run — would send ' : 'EMI reminders sent: ').($this->option('dry-run') ? count($r['lines']) : $r['sent'])." (employees checked: {$r['checked']}).");

        return self::SUCCESS;
    }
}
