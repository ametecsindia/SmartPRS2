<?php

namespace App\Console\Commands;

use App\Services\TransferService;
use Illuminate\Console\Command;

/**
 * Daily sweep for FUTURE-DATED employee transfers (rev 77): every APPROVED
 * transfer whose effective date has arrived is applied automatically —
 * employee moves to the destination company/branch, the register row flips
 * to 'applied' with the from→to snapshot, and the employee is emailed.
 *
 * Scheduled daily (routes/console.php). Manual run:
 *   php artisan transfers:apply
 */
class ApplyTransfers extends Command
{
    protected $signature = 'transfers:apply';

    protected $description = 'Apply approved employee transfers whose effective date has arrived';

    public function handle(): int
    {
        $n = TransferService::applyDue(fn ($line) => $this->line('  '.$line));
        $this->info('Transfers applied: '.$n);

        return self::SUCCESS;
    }
}
