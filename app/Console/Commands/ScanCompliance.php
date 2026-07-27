<?php

namespace App\Console\Commands;

use App\Http\Controllers\ComplianceController;
use Illuminate\Console\Command;

/**
 * Daily field-force compliance scan. Finds DRA/PCC certifications that are
 * expired or expiring within the alert window and queues a digest email to each
 * tenant's HR/Admin users (via MailService -> the queue).
 *
 * Scheduled in routes/console.php (daily). Can also be run on demand:
 *   php artisan compliance:scan
 */
class ScanCompliance extends Command
{
    protected $signature = 'compliance:scan {--tenant= : Limit to one tenant id}';

    protected $description = 'Scan DRA/PCC expiry and email compliance digests to HR/Admin';

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        $tenantId = $tenant !== null && $tenant !== '' ? (int) $tenant : null;

        $queued = ComplianceController::notify($tenantId);
        $this->info('Compliance scan complete — '.$queued.' digest email(s) queued.');

        return self::SUCCESS;
    }
}
