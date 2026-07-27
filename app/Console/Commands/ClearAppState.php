<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clear the prototype engine's persisted "app_state" blob (settings table,
 * key = app_state). On older dev installs this blob holds the demo dataset that
 * was synced from the front-end SEED, which is what made fake records reappear
 * on screens even after the SEED was emptied. Removing it forces a clean reseed
 * from the (now-empty) SEED, so every screen starts empty and fills only from
 * real backend data.
 *
 *   php artisan app:clear-state              # all tenants
 *   php artisan app:clear-state --tenant=0   # just the platform / super-admin state
 *
 * Safe: employees, payroll, attendance_logs, masters etc. live in their own
 * normalized tables and are NOT touched. Only the prototype state cache is cleared.
 */
class ClearAppState extends Command
{
    protected $signature = 'app:clear-state {--tenant= : Limit to one tenant_id}';

    protected $description = 'Clear the persisted prototype app_state (removes demo data cached in the settings table)';

    public function handle(): int
    {
        $q = DB::table('settings')->where('key', 'app_state');
        $tenant = $this->option('tenant');
        if ($tenant !== null && $tenant !== '') {
            $q->where('tenant_id', (int) $tenant);
        }
        $deleted = $q->delete();
        $this->info("Cleared {$deleted} app_state row(s). Screens will reseed empty and load live data.");

        return self::SUCCESS;
    }
}
