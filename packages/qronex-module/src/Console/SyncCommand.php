<?php

namespace Ametecs\Qronex\Console;

use Ametecs\Qronex\Models\Setting;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\EmployeeSync;
use Ametecs\Qronex\Services\HostSync;
use Illuminate\Console\Command;

/** Hourly: mirror host employees for every enabled tenant and retry punches the host hasn't accepted yet. */
class SyncCommand extends Command
{
    protected $signature = 'qronex:sync {--tenant= : Only this tenant id}';

    protected $description = 'QR Attendance: sync employees from the host app and retry pending punch writes';

    public function handle(): int
    {
        $tenants = Setting::withoutGlobalScopes()->where('enabled', true)->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))->pluck('tenant_id');
        foreach ($tenants as $tid) {
            if (! Qronex::enabled($tid)) {
                continue;
            }
            $n = EmployeeSync::run($tid);
            [$ok, $bad] = HostSync::retry($tid);
            $this->line("tenant $tid: +{$n['added']} employees, {$n['updated']} updated, {$n['deactivated']} deactivated; punches synced $ok, failed $bad");
        }

        return self::SUCCESS;
    }
}
