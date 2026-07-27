<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete fabricated demo attendance (rows written by the old/dev demo
 * fabricator, tagged source='demo'). Real punches (source 'biometric',
 * 'manual', 'web', etc.) are NOT touched.
 *
 *   php artisan attendance:purge-demo            # all tenants
 *   php artisan attendance:purge-demo --tenant=5 # one tenant
 *
 * Note: rows fabricated before rev 51 were mis-tagged source='biometric' and
 * are indistinguishable from real device punches — for a contaminated dev DB,
 * a fresh migrate:fresh is the clean reset.
 */
class PurgeDemoAttendance extends Command
{
    protected $signature = 'attendance:purge-demo {--tenant= : Limit to one tenant id}';

    protected $description = "Delete fabricated demo attendance rows (source='demo')";

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        $q = DB::table('attendance_logs')->where('source', 'demo');
        if ($tenant !== null && $tenant !== '') {
            $q->where('tenant_id', (int) $tenant);
        }
        $deleted = $q->delete();
        $this->info("Purged {$deleted} demo attendance row(s).");

        return self::SUCCESS;
    }
}
