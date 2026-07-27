<?php

namespace App\Services;

use App\Http\Controllers\ConfigController;
use App\Jobs\SendNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * SmartPRS communication engine (email).
 *
 * One entry point -- MailService::queue($msg) -- used everywhere a notification
 * should go out (approvals, salary disbursement, payslips, acknowledgements,
 * compliance alerts). It:
 *
 *   1. Records every message in a self-creating `mail_log` table (audit + retry
 *      visibility) with status queued -> sent / failed / skipped.
 *   2. Dispatches a queued job (SendNotification) so the user's click is never
 *      blocked by a slow SMTP server. If QUEUE_CONNECTION=sync (no worker), the
 *      job runs inline -- still works, just synchronously.
 *   3. At send time resolves the RIGHT SMTP server for the company via
 *      ConfigController::mailConfigFor (per-company, tenant-default fallback),
 *      applies it at runtime, renders a branded blade, and sends.
 *
 * Everything fails soft: a missing SMTP config or a send error is logged, never
 * thrown up into the request that triggered it.
 *
 * $msg shape (all optional unless noted):
 *   tenant_id   int|null   - for SMTP + branding resolution
 *   company_id  int|null   - which company's mail server / brand
 *   to          string     - REQUIRED recipient email
 *   to_name     string     - recipient display name
 *   subject     string     - REQUIRED subject line
 *   heading     string     - big heading inside the email
 *   intro       string     - lead paragraph
 *   lines       array      - ['Label' => 'Value', ...] rendered as a detail table
 *   body        string     - extra paragraph(s) under the table
 *   cta_label   string     - button text (optional)
 *   cta_url     string     - button link (optional)
 *   kind        string     - tag for the log (e.g. 'salary.disbursed')
 */
class MailService
{
    /** Create the audit table once (project convention: schema self-heals). */
    public static function ensureTable(): void
    {
        if (Schema::hasTable('mail_log')) {
            return;
        }
        Schema::create('mail_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('kind')->nullable()->index();
            $t->string('recipient')->nullable();
            $t->string('subject')->nullable();
            $t->string('status')->default('queued')->index();   // queued|sent|failed|skipped
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Public entry point. Logs the message as 'queued' and dispatches the job.
     * Returns the mail_log id (or null if it could not even be recorded).
     */
    public static function queue(array $msg): ?int
    {
        try {
            self::ensureTable();
            if (empty($msg['to']) || empty($msg['subject'])) {
                return null;   // nothing to send
            }
            // rev 97: the PUBLIC demo workspace must never send real email —
            // log it as skipped so the demo still "looks" like it worked.
            if (! empty($msg['tenant_id']) && \App\Http\Controllers\DemoAccessController::isDemoTenant($msg['tenant_id'])) {
                DB::table('mail_log')->insert([
                    'tenant_id' => $msg['tenant_id'], 'company_id' => $msg['company_id'] ?? null,
                    'kind' => $msg['kind'] ?? null, 'recipient' => $msg['to'], 'subject' => $msg['subject'],
                    'status' => 'skipped', 'error' => 'Demo workspace — outgoing mail muted',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return null;
            }
            $id = DB::table('mail_log')->insertGetId([
                'tenant_id' => $msg['tenant_id'] ?? null,
                'company_id' => $msg['company_id'] ?? null,
                'kind' => $msg['kind'] ?? null,
                'recipient' => $msg['to'],
                'subject' => $msg['subject'],
                'status' => 'queued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $msg['log_id'] = $id;
            // rev 170: CRITICAL mails (login credentials, password reset,
            // quotations) are sent INLINE — 'sync' => true — so they go out
            // even when no queue worker is running (the silent failure that
            // locked a paying client out of their new workspace). Everything
            // else stays on the queue as before.
            if (! empty($msg['sync'])) {
                try {
                    self::deliver($msg);
                } catch (\Throwable $e) {
                    // deliver() already marked the mail_log row failed
                    Log::warning('MailService sync send failed ('.($msg['kind'] ?? '?').'): '.$e->getMessage());
                }

                return $id;
            }
            SendNotification::dispatch($msg);

            return $id;
        } catch (\Throwable $e) {
            Log::warning('MailService::queue failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Actually deliver one message (called from the queued job). Resolves the
     * company SMTP, applies it, renders the branded template and sends.
     */
    public static function deliver(array $msg): void
    {
        $logId = $msg['log_id'] ?? null;
        try {
            self::ensureTable();
            $tenantId = $msg['tenant_id'] ?? null;
            $companyId = $msg['company_id'] ?? null;
            $m = ConfigController::mailConfigFor($tenantId, $companyId);

            // PLATFORM fallback: a tenant with no SMTP of its own (e.g. a brand-new
            // self-serve signup) falls back to the platform mailbox the super admin
            // configures under "Platform Email (SMTP)" — so invoices, payment
            // confirmations and login-credential emails always have a sender.
            if (empty($m['host'])) {
                $m = ConfigController::mailConfigFor(null, '0');
            }

            if (empty($m['host']) || empty($m['from_address'])) {
                self::mark($logId, 'skipped', 'No SMTP configured — set the tenant/company SMTP, or the platform default (super admin → Platform Email).');

                return;
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $m['host'],
                'mail.mailers.smtp.port' => (int) $m['port'],
                'mail.mailers.smtp.username' => $m['username'] ?: null,
                'mail.mailers.smtp.password' => $m['password'] ?: null,
                'mail.mailers.smtp.encryption' => $m['encryption'] === 'none' ? null : $m['encryption'],
                'mail.from.address' => $m['from_address'],
                'mail.from.name' => $m['from_name'] ?: 'SmartPRS',
            ]);

            $brand = [];
            try {
                $brand = ConfigController::brandFor($tenantId, $companyId);
            } catch (\Throwable $e) {
                $brand = [];
            }

            $data = [
                'brand' => $brand,
                'platform' => ! empty($msg['platform']),   // rev 170: full Ametecs identity footer
                'heading' => $msg['heading'] ?? ($msg['subject'] ?? ''),
                'toName' => $msg['to_name'] ?? '',
                'intro' => $msg['intro'] ?? '',
                'lines' => $msg['lines'] ?? [],
                'bodyText' => $msg['body'] ?? '',
                'ctaLabel' => $msg['cta_label'] ?? '',
                'ctaUrl' => $msg['cta_url'] ?? '',
            ];

            Mail::send('emails.generic', $data, function ($mail) use ($msg) {
                $mail->to($msg['to'], $msg['to_name'] ?? null)->subject($msg['subject']);
                // rev 77b: optional PDF attachment (base64 in the queued payload —
                // used by transfer orders; keep attachments small).
                if (! empty($msg['attach_b64']) && ! empty($msg['attach_name'])) {
                    $mail->attachData(base64_decode($msg['attach_b64']), $msg['attach_name'], [
                        'mime' => $msg['attach_mime'] ?? 'application/pdf',
                    ]);
                }
            });

            self::mark($logId, 'sent', null);
        } catch (\Throwable $e) {
            self::mark($logId, 'failed', $e->getMessage());
            throw $e;   // let the queue retry per the job's tries/backoff
        }
    }

    private static function mark(?int $logId, string $status, ?string $error): void
    {
        if (! $logId) {
            return;
        }
        try {
            DB::table('mail_log')->where('id', $logId)->update([
                'status' => $status,
                'error' => $error ? mb_substr($error, 0, 1000) : null,
                'sent_at' => $status === 'sent' ? now() : null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // logging the log must never throw
        }
    }
}
