<?php

namespace App\Console\Commands;

use App\Services\MailService;
use App\Services\SubscriptionService;
use App\Services\WaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily subscription expiry alerts (rev 75) — replaces the old auto-invoice
 * renewal job now that clients renew themselves via My Subscription.
 *
 * Schedule (Ejaz, 4 Jun 2026): email + WhatsApp to the tenant's billing
 * contact at 15 / 7 / 3 / 1 days before expiry, on expiry day, then DAILY
 * during the 7-day grace period. After grace the tenant row is marked
 * 'suspended' (login lock-out is enforced live by middleware; this flag is
 * for the super admin's dashboards). Every attempt is logged to
 * `subscription_alerts` (visible in SaaS → Subscriptions).
 *
 * Idempotent per tenant + period end + alert kind + channel, so re-runs on
 * the same day never double-send. Renewing moves current_period_end forward,
 * which naturally re-arms the alerts for the new period.
 *
 *   php artisan billing:alerts          # manual run (scheduled daily 08:00)
 */
class SubscriptionAlerts extends Command
{
    protected $signature = 'billing:alerts';

    protected $description = 'Send subscription expiry reminders (email + WhatsApp) and sync expired tenants';

    private const BEFORE_DAYS = [15, 7, 3, 1, 0];

    public function handle(): int
    {
        SubscriptionService::ensureAlertTable();
        $sent = 0;
        $skipped = 0;
        $suspended = 0;

        $subs = DB::table('subscriptions')->whereNotNull('current_period_end')->get();
        foreach ($subs as $sub) {
            try {
                $tid = (int) $sub->tenant_id;
                $tenant = DB::table('tenants')->where('id', $tid)->whereNull('deleted_at')->first();
                if (! $tenant) {
                    continue;
                }
                $end = Carbon::parse($sub->current_period_end)->startOfDay();
                $days = (int) round(now()->startOfDay()->diffInDays($end, false));   // negative = past (int: Carbon 3 returns float)

                $kind = null;
                if (in_array($days, self::BEFORE_DAYS, true) && ($sub->status ?? 'active') === 'active') {
                    $kind = 'expiry-'.$days;
                } elseif ($days < 0 && $days >= -SubscriptionService::GRACE_DAYS) {
                    $kind = 'grace-'.abs($days);   // daily during grace
                } elseif ($days < -SubscriptionService::GRACE_DAYS) {
                    // Past grace: flag the tenant for the super admin views (the
                    // live lock-out is middleware; users are NOT disabled so the
                    // admin can still sign in and renew).
                    if (($tenant->status ?? 'active') !== 'suspended') {
                        DB::table('tenants')->where('id', $tid)->update(['status' => 'suspended', 'updated_at' => now()]);
                        SubscriptionService::logAlert($tid, 'auto-suspend', 'system', 'sent', 'Tenant marked suspended after grace ended', $end->toDateString());
                        $suspended++;
                    }
                    continue;
                }
                if (! $kind) {
                    continue;
                }

                $contact = SubscriptionService::billingContact($tid);
                $plan = $sub->plan_id ? DB::table('plans')->where('id', $sub->plan_id)->first() : null;
                $planName = $plan->name ?? 'your plan';
                $endTxt = $end->format('d M Y');
                $renewUrl = url('/app/my-subscription');
                $graceEndTxt = $end->copy()->addDays(SubscriptionService::GRACE_DAYS)->format('d M Y');

                if ($days > 0) {
                    $headline = 'Your SmartPRS subscription expires in '.$days.' day'.($days === 1 ? '' : 's');
                    $body = 'Your '.$planName.' subscription ('.$sub->seats.' employees) is active until '.$endTxt.'. Renew in a minute from Administration → My Subscription — pay online and your workspace continues without interruption.';
                } elseif ($days === 0) {
                    $headline = 'Your SmartPRS subscription expires TODAY';
                    $body = 'Your '.$planName.' subscription ends today ('.$endTxt.'). Your workspace stays available during a short grace period until '.$graceEndTxt.' — please renew now from Administration → My Subscription.';
                } else {
                    $headline = 'SmartPRS subscription EXPIRED — workspace suspends on '.$graceEndTxt;
                    $body = 'Your '.$planName.' subscription expired on '.$endTxt.'. Your team keeps access only until '.$graceEndTxt.'; after that employees are blocked and only the admin can sign in to renew. Please renew now from Administration → My Subscription.';
                }

                // ---- Email (logged in mail_log AND subscription_alerts) -------
                if ($contact['email'] && ! SubscriptionService::alertAlreadySent($tid, $kind, $end->toDateString(), 'email')) {
                    $id = MailService::queue([
                        'tenant_id' => $tid,
                        'to' => $contact['email'],
                        'to_name' => $contact['name'],
                        'subject' => $headline,
                        'heading' => $headline,
                        'intro' => $body,
                        'body' => 'GST tax invoice is generated and emailed automatically after payment. Need help? Write to sales@ametecsindia.com.',
                        'cta_label' => 'Renew now',
                        'cta_url' => $renewUrl,
                        'kind' => 'subscription.renewal',
                    ]);
                    SubscriptionService::logAlert($tid, $kind, 'email', $id ? 'sent' : 'failed', $contact['email'], $end->toDateString());
                    $id ? $sent++ : $skipped++;
                }

                // ---- WhatsApp (Interakt; fail-soft; needs approved template) --
                if ($contact['mobile'] && ! SubscriptionService::alertAlreadySent($tid, $kind, $end->toDateString(), 'whatsapp')) {
                    $ok = false;
                    try {
                        $ok = WaService::sendTemplate([
                            'tenant_id' => $tid,
                            'mobile' => $contact['mobile'],
                            'kind' => 'subscription.renewal',
                            'template' => WaService::templateNameFor('renewal'),
                            'bodyValues' => [
                                $contact['name'] ?: 'there',             // {{1}} company/name
                                $planName,                               // {{2}} plan
                                $endTxt,                                 // {{3}} expiry date
                                $days > 0 ? ('in '.$days.' day'.($days === 1 ? '' : 's')) : ($days === 0 ? 'today' : 'EXPIRED'),  // {{4}} when
                                $renewUrl,                               // {{5}} renew link
                            ],
                        ]);
                    } catch (\Throwable $e) {
                    }
                    SubscriptionService::logAlert($tid, $kind, 'whatsapp', $ok ? 'sent' : 'failed', $contact['mobile'], $end->toDateString());
                    $ok ? $sent++ : $skipped++;
                }

                $this->line(sprintf('  tenant #%d (%s): %s → %s', $tid, $tenant->name, $kind, $contact['email'] ?: 'no email'));
            } catch (\Throwable $e) {
                $this->warn('  tenant #'.($sub->tenant_id ?? '?').': '.$e->getMessage());
            }
        }

        $this->info(sprintf('Subscription alerts: %d sent, %d failed/skipped, %d tenant(s) auto-suspended.', $sent, $skipped, $suspended));

        return self::SUCCESS;
    }
}
