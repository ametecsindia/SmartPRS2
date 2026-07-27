<?php

namespace App\Console\Commands;

use App\Http\Controllers\BillingController;
use Illuminate\Console\Command;

/**
 * Daily SaaS auto-renewal. Finds active subscriptions whose next_renewal date
 * has arrived, generates the next GST invoice, advances the billing period, and
 * emails the invoice to the tenant's billing contact.
 *
 * Scheduled in routes/console.php (daily). Can also be run on demand:
 *   php artisan billing:renew
 *
 * Idempotent per day in practice: once an invoice is raised the subscription's
 * next_renewal moves forward a full cycle, so a second run the same day is a
 * no-op for that tenant.
 */
class RenewSubscriptions extends Command
{
    protected $signature = 'billing:renew';

    protected $description = 'Raise + email renewal invoices for subscriptions due for renewal';

    public function handle(): int
    {
        $summary = BillingController::runRenewals(fn ($line) => $this->line('  '.$line));
        $this->info(sprintf(
            'Renewals: %d due, %d invoices raised, %d emailed, %d error(s).',
            $summary['due'], $summary['invoices'], $summary['emailed'], $summary['errors']
        ));

        return self::SUCCESS;
    }
}
