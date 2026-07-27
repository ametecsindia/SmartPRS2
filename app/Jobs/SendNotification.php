<?php

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued delivery of one SmartPRS notification email.
 *
 * Dispatched by MailService::queue(). Kept deliberately thin: it just hands the
 * message array back to MailService::deliver(), which resolves the per-company
 * SMTP at run time and records the result in mail_log.
 *
 * Retries: 3 attempts with a short backoff. If QUEUE_CONNECTION=sync (no worker
 * running), Laravel runs this inline at dispatch time -- so notifications still
 * go out even before a queue worker is set up, just synchronously.
 */
class SendNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var array */
    public $msg;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(array $msg)
    {
        $this->msg = $msg;
        // Keep notifications off the default web path if a named queue is used.
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        MailService::deliver($this->msg);
    }
}
