<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription lifecycle rules (rev 75) — the single place that answers:
 *
 *   1. What STATE is a tenant's subscription in?
 *      active → grace (7 days past expiry, app works with a warning)
 *      → locked (employees blocked; admin can sign in only to renew)
 *   2. How many employee SEATS is the tenant using vs. subscribed?
 *      Seats = ACTIVE ON-ROLL employees only (exited/inactive free their
 *      seat; off-roll commission agents never count).
 *   3. May the tenant add one more employee? (enforced at every door:
 *      add form, CSV import, recruitment hire)
 *
 * Used by: AppDataController (add/import), RecruitmentController (hire),
 * EnsureSubscriptionActive middleware (lock-out), AuthController (login
 * message), AppController (banner cfg), SubscriptionAlerts (reminders),
 * TenantBillingController / BillingController (renew + SaaS admin views).
 *
 * Fail-soft everywhere: if anything cannot be determined (no subscription
 * row, schema oddity), the answer is "allow" — a billing bug must never
 * brick a paying customer's HR system. Tenants WITHOUT any subscription
 * row (e.g. manually provisioned/legacy) are treated as ACTIVE.
 */
class SubscriptionService
{
    public const GRACE_DAYS = 7;

    /** Employee statuses that do NOT occupy a seat. */
    private const SEAT_FREE_STATUSES = ['exited', 'inactive', 'resigned', 'terminated', 'left', 'absconded'];

    /**
     * Subscription state for a tenant.
     * Returns ['state' => 'active'|'grace'|'locked'|'none', 'end' => ?Carbon,
     *          'graceEnd' => ?Carbon, 'daysLeft' => ?int] where daysLeft is
     * negative once past the period end.
     */
    public static function state(?int $tenantId): array
    {
        $none = ['state' => 'none', 'end' => null, 'graceEnd' => null, 'daysLeft' => null];
        if (! $tenantId) {
            return $none;   // platform super admin / legacy — never gated
        }
        try {
            $sub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
            if (! $sub || empty($sub->current_period_end)) {
                return $none;
            }
            if (in_array(($sub->status ?? 'active'), ['cancelled', 'suspended'], true)) {
                // Explicitly switched off (super admin) → locked immediately.
                $end = Carbon::parse($sub->current_period_end);

                return ['state' => 'locked', 'end' => $end, 'graceEnd' => $end, 'daysLeft' => null];
            }
            $end = Carbon::parse($sub->current_period_end)->endOfDay();
            $graceEnd = $end->copy()->addDays(self::GRACE_DAYS);
            $today = now();
            $daysLeft = (int) round($today->copy()->startOfDay()->diffInDays($end->copy()->startOfDay(), false));

            if ($today->lte($end)) {
                return ['state' => 'active', 'end' => $end, 'graceEnd' => $graceEnd, 'daysLeft' => $daysLeft];
            }
            if ($today->lte($graceEnd)) {
                return ['state' => 'grace', 'end' => $end, 'graceEnd' => $graceEnd, 'daysLeft' => $daysLeft];
            }

            return ['state' => 'locked', 'end' => $end, 'graceEnd' => $graceEnd, 'daysLeft' => $daysLeft];
        } catch (\Throwable $e) {
            return $none;   // fail-soft: never lock on an internal error
        }
    }

    /** Seats in use (active on-roll employees) + the subscribed limit (0 = unlimited/unknown). */
    public static function seatUsage(?int $tenantId): array
    {
        $res = ['used' => 0, 'licensed' => 0];
        if (! $tenantId) {
            return $res;
        }
        try {
            $q = DB::table('employees')->where('tenant_id', $tenantId);
            if (Schema::hasColumn('employees', 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            if (Schema::hasColumn('employees', 'status')) {
                $q->where(function ($w) {
                    $w->whereNull('status')->orWhereNotIn(DB::raw('LOWER(status)'), self::SEAT_FREE_STATUSES);
                });
            }
            $res['used'] = (int) $q->count();

            $sub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
            $res['licensed'] = (int) ($sub->seats ?? 0);
            if (! $res['licensed']) {
                $res['licensed'] = (int) (DB::table('tenants')->where('id', $tenantId)->value('seats_licensed') ?? 0);
            }

            // AS-DL — on an ENFORCED on-prem install the .lic device_limit is the
            // seat ceiling, overriding any SaaS subscription seats. This makes
            // every existing door (add form, CSV import, recruitment hire) honour
            // the offline licence's seat cap with no extra wiring.
            if (Edition::isOnPrem()
                && filter_var(config('smartprs.licence_enforce', true), FILTER_VALIDATE_BOOLEAN)) {
                $lim = \App\Http\Controllers\ClientUpdateController::seatLimit();
                if ($lim !== null && $lim > 0) {
                    $res['licensed'] = $lim;
                }
            }
        } catch (\Throwable $e) {
            // fail-soft → unlimited
        }

        return $res;
    }

    /**
     * May this tenant add $count more employee(s)?
     * Returns ['ok' => bool, 'error' => ?string, 'used', 'licensed'].
     */
    public static function canAddEmployees(?int $tenantId, int $count = 1): array
    {
        $u = self::seatUsage($tenantId);
        $ok = ['ok' => true, 'error' => null, 'used' => $u['used'], 'licensed' => $u['licensed']];
        if (! $tenantId || $u['licensed'] <= 0) {
            return $ok;   // no limit on record → allow (legacy/manual tenants)
        }
        if ($u['used'] + $count <= $u['licensed']) {
            return $ok;
        }
        $ok['ok'] = false;
        if (Edition::isOnPrem()) {
            $ok['error'] = 'Licence seat limit reached: your SmartPRS licence covers '.$u['licensed']
                .' employees and you already have '.$u['used'].' active. To add more, obtain a licence for a '
                .'larger team from Ametecs (WhatsApp 9000098877); moving an employee to Inactive or Old-data frees a seat.';
        } else {
            $ok['error'] = 'Employee limit reached: your subscription covers '.$u['licensed']
                .' employees and you currently have '.$u['used'].' active. '
                .'Please upgrade in Administration → My Subscription (exited employees free their seat).';
        }

        return $ok;
    }

    /** Companies in use + the subscribed company count (default 1; 0 = no sub → 1). */
    public static function companyUsage(?int $tenantId): array
    {
        $res = ['used' => 0, 'licensed' => 1];
        if (! $tenantId) {
            return $res;
        }
        try {
            $q = DB::table('companies')->where('tenant_id', $tenantId);
            if (Schema::hasColumn('companies', 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            $res['used'] = (int) $q->count();
            $sub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
            $res['licensed'] = max(1, (int) ($sub->companies ?? 1));
            if (! $sub) {
                $res['licensed'] = 0;   // no subscription on record → unrestricted (legacy/manual)
            }
        } catch (\Throwable $e) {
            $res['licensed'] = 0;   // fail-soft → unrestricted
        }

        return $res;
    }

    /**
     * May this tenant create one more company? STRICT policy (Ejaz, 4 Jun 2026):
     * the subscribed company count is the hard ceiling from day one.
     * Returns ['ok' => bool, 'error' => ?string, 'used', 'licensed'].
     */
    public static function canAddCompany(?int $tenantId): array
    {
        $u = self::companyUsage($tenantId);
        $ok = ['ok' => true, 'error' => null, 'used' => $u['used'], 'licensed' => $u['licensed']];
        if (! $tenantId || $u['licensed'] <= 0) {
            return $ok;   // platform / legacy tenants without a subscription
        }
        if ($u['used'] + 1 <= $u['licensed']) {
            return $ok;
        }
        $ok['ok'] = false;
        $ok['error'] = 'Company limit reached: your subscription covers '.$u['licensed']
            .' compan'.($u['licensed'] === 1 ? 'y' : 'ies').' and you already have '.$u['used'].'. '
            .'Each additional company is ₹1,000/month — upgrade in Administration → My Subscription.';

        return $ok;
    }

    // ---- Alert log (visible to the super admin) -----------------------------

    public static function ensureAlertTable(): void
    {
        if (Schema::hasTable('subscription_alerts')) {
            return;
        }
        Schema::create('subscription_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('kind', 40);          // expiry-15 / expiry-7 / expiry-3 / expiry-1 / expiry-0 / grace
            $t->string('channel', 20);       // email | whatsapp
            $t->string('status', 20);        // sent | failed | skipped
            $t->string('detail', 500)->nullable();
            $t->date('period_end')->nullable();
            $t->timestamps();
        });
    }

    /** Record an alert attempt (idempotence is checked by the caller per kind+day). */
    public static function logAlert(int $tenantId, string $kind, string $channel, string $status, ?string $detail, ?string $periodEnd): void
    {
        try {
            self::ensureAlertTable();
            DB::table('subscription_alerts')->insert([
                'tenant_id' => $tenantId, 'kind' => $kind, 'channel' => $channel,
                'status' => $status, 'detail' => $detail ? substr($detail, 0, 500) : null,
                'period_end' => $periodEnd,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // logging must never break the alert run
        }
    }

    /** Was this alert kind already sent for this tenant + period end (per channel, status=sent)? */
    public static function alertAlreadySent(int $tenantId, string $kind, string $periodEnd, ?string $channel = null): bool
    {
        try {
            self::ensureAlertTable();

            return DB::table('subscription_alerts')
                ->where('tenant_id', $tenantId)->where('kind', $kind)
                ->where('period_end', $periodEnd)->where('status', 'sent')
                ->when($channel, fn ($q) => $q->where('channel', $channel))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Best contact for billing notices: tenant owner email + a mobile if known. */
    public static function billingContact(int $tenantId): array
    {
        $email = null;
        $mobile = null;
        $name = '';
        try {
            $t = DB::table('tenants')->where('id', $tenantId)->first();
            $email = $t->owner_email ?? null;
            $name = $t->name ?? '';
            if (Schema::hasTable('signups')) {
                $mobile = DB::table('signups')->where('tenant_id', $tenantId)
                    ->whereNotNull('mobile')->orderByDesc('id')->value('mobile');
            }
            if (! $email) {
                $email = DB::table('users')->where('tenant_id', $tenantId)->orderBy('id')->value('email');
            }
        } catch (\Throwable $e) {
        }

        return ['email' => $email, 'mobile' => $mobile, 'name' => $name];
    }
}
