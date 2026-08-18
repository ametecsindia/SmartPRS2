<?php

namespace App\Jobs;

use App\Services\LateArrivalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Late-arrival evaluation + notification for punches that were just ingested.
 *
 * ETimeOfficeService::import() calls LateArrivalService::notifyTouched() inline,
 * which ends at Mail::raw (LateArrivalService.php:289). On a slow or unreachable
 * SMTP host that blocks the HTTP response until the sender times out — and an
 * at-least-once sender that times out RETRIES, which is where the duplicate
 * punches come from. The SBB path therefore never touches Mail on the request.
 *
 * With QUEUE_CONNECTION=sync (no worker yet) Laravel runs this inline, so
 * notifications still go out on a fresh install; run a worker to get the async
 * behaviour this job exists for.
 *
 * @param array<string,array<string,bool>> $touched  emp_code => [ 'Y-m-d' => true ]
 */
class NotifyLateArrivals implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public ?int $tenantId,
        public array $touched,
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        try {
            LateArrivalService::notifyTouched($this->tenantId, $this->touched);
        } catch (\Throwable $e) {
            // Notification is a courtesy; a punch is already stored either way.
            Log::warning('sbb.late_arrival.failed: '.$e->getMessage());
        }
    }
}
