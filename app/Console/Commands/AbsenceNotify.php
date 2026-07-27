<?php

namespace App\Console\Commands;

use App\Services\AbsenceService;
use Illuminate\Console\Command;

/**
 * F10 — previous-day absence sweep.
 *
 * Scheduled HOURLY; each tenant fires only when the current hour matches its
 * configured send_hour (default 11:00), so imports have time to land. By
 * default it evaluates YESTERDAY. Idempotent (dedupe per employee/day).
 *
 *   php artisan absence:notify
 *   php artisan absence:notify --date=2026-07-25 --all-hours --tenant=5
 */
class AbsenceNotify extends Command
{
    protected $signature = 'absence:notify
        {--date= : Day to evaluate (Y-m-d); defaults to yesterday}
        {--hour= : Only tenants whose send_hour = this hour}
        {--all-hours : Ignore send_hour gating}
        {--tenant= : Limit to one tenant id}';

    protected $description = 'Notify employees who were absent (no attendance) the previous working day (F10).';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();
        $tenant = $this->option('tenant') !== null && $this->option('tenant') !== ''
            ? (int) $this->option('tenant') : null;

        $hourGate = null;
        if (! $this->option('all-hours')) {
            $hourGate = $this->option('hour') !== null && $this->option('hour') !== ''
                ? (int) $this->option('hour')
                : (int) now()->format('G');
        }

        $r = AbsenceService::runForDate($tenant, $date, $hourGate);
        $this->info("Absence sweep ({$date}): notified {$r['notified']} of {$r['evaluated']} evaluated, across {$r['tenants']} tenant(s).");

        return self::SUCCESS;
    }
}
