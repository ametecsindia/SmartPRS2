<?php

namespace App\Services;

use App\Support\SchemaHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * P0 Foundation — one notification entry point for every enhancement feature.
 *
 * Before this, SmartPRS could send EMAIL (via {@see MailService}, logged in
 * mail_log) but had NO in-app inbox: the sidebar "Notifications" bell/screen
 * existed with no data source behind it. Features F6 (probation reminders),
 * F8 (birthday / anniversary greetings) and F10 (previous-day absence) all need
 * to notify people reliably, in-app and by email, and be idempotent.
 *
 * NotificationService::send() writes ONE `notification_log` row (the in-app
 * inbox item + a delivery record) and, when asked, also queues an email through
 * the existing MailService. Channels at launch are EMAIL + IN-APP only
 * (decision #5 — WhatsApp is parked; the WhatsApp path can be added here later
 * without touching any caller).
 *
 * Everything fails soft: a notification failure must never break the business
 * action that raised it (a probation confirmation, an approval, a payroll run).
 *
 * $n shape (all optional unless noted):
 *   tenant_id    int      - REQUIRED for scoping
 *   company_id   int|null
 *   user_id      int|null - the app user who sees it in their bell (in-app)
 *   employee_id  int|null - the employee the event concerns
 *   kind         string   - REQUIRED tag, e.g. 'probation.due','greeting.birthday'
 *   title        string   - REQUIRED short headline shown in the inbox
 *   body         string   - longer text
 *   url          string   - deep link (SPA screen id or path) opened on click
 *   in_app       bool     - default true; set false for email-only
 *   email        bool     - default false; set true to also send an email
 *   email_to     string   - recipient email (auto-resolved from user/employee if omitted)
 *   email_subject string  - defaults to title
 *   email_lines  array    - ['Label'=>'Value',...] detail table in the email
 *   email_cta_label string
 *   dedupe_key   string   - when set, send() is a no-op if a row with the same
 *                           (kind, dedupe_key) already exists (idempotency)
 *   meta         array    - free-form JSON payload stored with the row
 */
class NotificationService
{
    /** Self-healing table create (project convention — see mail_log/activity_logs). */
    public static function ensureTable(): void
    {
        SchemaHelper::ensureTable('notification_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();      // in-app recipient
            $t->unsignedBigInteger('employee_id')->nullable()->index();  // subject of the event
            $t->string('kind')->nullable()->index();
            $t->string('title')->nullable();
            $t->text('body')->nullable();
            $t->string('url')->nullable();
            $t->string('channels', 40)->nullable();      // e.g. 'in_app,email'
            $t->string('email_status', 20)->nullable();  // queued|sent|failed|skipped (mirrors mail_log)
            $t->unsignedBigInteger('mail_log_id')->nullable();
            $t->string('dedupe_key')->nullable()->index();
            $t->text('meta')->nullable();                // JSON
            $t->timestamp('read_at')->nullable();        // in-app read state (null = unread)
            $t->timestamps();
        });
    }

    /**
     * Send one notification. Returns the notification_log id, or null if nothing
     * was written (missing required fields, or a dedupe hit).
     */
    public static function send(array $n): ?int
    {
        try {
            self::ensureTable();

            $kind = $n['kind'] ?? null;
            $title = $n['title'] ?? null;
            if (! $kind || ! $title) {
                return null;   // nothing meaningful to record
            }

            $inApp = $n['in_app'] ?? true;
            $wantEmail = ! empty($n['email']);
            $dedupe = $n['dedupe_key'] ?? null;

            // Idempotency: skip if we've already logged this exact event.
            if ($dedupe !== null && self::alreadySent($kind, $dedupe)) {
                return null;
            }

            $channels = [];
            if ($inApp) {
                $channels[] = 'in_app';
            }
            if ($wantEmail) {
                $channels[] = 'email';
            }

            $row = [
                'tenant_id' => $n['tenant_id'] ?? null,
                'company_id' => $n['company_id'] ?? null,
                'user_id' => $n['user_id'] ?? null,
                'employee_id' => $n['employee_id'] ?? null,
                'kind' => $kind,
                'title' => mb_substr((string) $title, 0, 250),
                'body' => $n['body'] ?? null,
                'url' => $n['url'] ?? null,
                'channels' => $channels ? implode(',', $channels) : null,
                'dedupe_key' => $dedupe,
                'meta' => isset($n['meta']) ? json_encode($n['meta']) : null,
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $id = DB::table('notification_log')->insertGetId($row);

            // Optional email through the existing engine (per-company SMTP,
            // branding, mail_log — all handled by MailService).
            if ($wantEmail) {
                self::sendEmail($id, $n);
            }

            return $id;
        } catch (\Throwable $e) {
            Log::warning('NotificationService::send failed ('.($n['kind'] ?? '?').'): '.$e->getMessage());

            return null;
        }
    }

    /**
     * Idempotent variant: send only if no row with this (kind, dedupe_key)
     * exists yet. Sugar over send() with dedupe_key set — used by F8 greetings
     * and F10 absence so the same day's message is never sent twice.
     */
    public static function sendOnce(string $dedupeKey, array $n): ?int
    {
        $n['dedupe_key'] = $dedupeKey;

        return self::send($n);
    }

    /** True when a notification with this kind + dedupe key already exists. */
    public static function alreadySent(string $kind, string $dedupeKey): bool
    {
        try {
            return DB::table('notification_log')
                ->where('kind', $kind)
                ->where('dedupe_key', $dedupeKey)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Resolve + queue the email for a notification, recording its status back. */
    private static function sendEmail(int $notifId, array $n): void
    {
        try {
            $to = $n['email_to'] ?? self::resolveEmail($n);
            if (! $to) {
                DB::table('notification_log')->where('id', $notifId)
                    ->update(['email_status' => 'skipped', 'updated_at' => now()]);

                return;
            }

            $mailId = MailService::queue([
                'tenant_id' => $n['tenant_id'] ?? null,
                'company_id' => $n['company_id'] ?? null,
                'to' => $to,
                'to_name' => $n['email_to_name'] ?? '',
                'subject' => $n['email_subject'] ?? $n['title'],
                'heading' => $n['title'],
                'intro' => $n['body'] ?? '',
                'lines' => $n['email_lines'] ?? [],
                'cta_label' => $n['email_cta_label'] ?? '',
                'cta_url' => $n['email_cta_url'] ?? ($n['url'] ?? ''),
                'kind' => $n['kind'],
            ]);

            DB::table('notification_log')->where('id', $notifId)->update([
                'email_status' => $mailId ? 'queued' : 'skipped',
                'mail_log_id' => $mailId,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            try {
                DB::table('notification_log')->where('id', $notifId)
                    ->update(['email_status' => 'failed', 'updated_at' => now()]);
            } catch (\Throwable $ignored) {
            }
            Log::warning('NotificationService email failed (#'.$notifId.'): '.$e->getMessage());
        }
    }

    /** Best-effort email lookup: explicit user, then app user, then employee. */
    private static function resolveEmail(array $n): ?string
    {
        try {
            if (! empty($n['user_id']) && Schema::hasTable('users')) {
                $e = DB::table('users')->where('id', $n['user_id'])->value('email');
                if ($e) {
                    return $e;
                }
            }
            if (! empty($n['employee_id']) && Schema::hasTable('employees')) {
                $e = DB::table('employees')->where('id', $n['employee_id'])->value('email');
                if ($e) {
                    return $e;
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return null;
    }

    // ---- Request submitted → tell everyone above the employee ----------------

    /**
     * 30 Sep 2026 (Ejaz) — when an employee submits a request (leave, attendance
     * correction, salary advance, loan, expense …) every person ABOVE them gets a
     * bell notification: the reporting-manager chain all the way up (plus the
     * resolved approver, which may be an escalated one), and every Company Admin
     * and HR Manager login of the tenant. The submitter never notifies themself.
     *
     * $url is the SPA screen id the bell opens (e.g. 'loans', 'leave-apply').
     * One notification_log row per recipient, deduped on kind + record + user, so
     * a retried request never double-notifies. Fails soft like send().
     * Returns the number of notifications written.
     */
    public static function notifyUpline($emp, string $kind, string $title, ?string $body, string $url, int $recordId, ?int $approverEmpId = null, ?int $submitterUserId = null): int
    {
        try {
            if (! $emp || ! Schema::hasTable('users')) {
                return 0;
            }
            $tid = $emp->tenant_id ?? null;

            // 1) Reporting chain upward — the app-wide hierarchy (7 Oct 2026), loop-safe.
            $chain = [];
            $up = \App\Services\Hierarchy::upline(isset($emp->id) ? (int) $emp->id : null, $tid ? (int) $tid : null);
            if ($up) {
                foreach (DB::table('employees')->whereIn('id', $up)->whereNull('deleted_at')->get() as $row) {
                    $chain[$row->id] = $row;
                }
            }
            if ($approverEmpId && ! isset($chain[$approverEmpId])) {
                $a = DB::table('employees')->where('id', $approverEmpId)->first();
                if ($a) {
                    $chain[$a->id] = $a;
                }
            }

            // 2) Their logins: users.employee_id when present, else matching email.
            $userIds = [];
            if ($chain) {
                $uq = DB::table('users')->when($tid, fn ($q) => $q->where('tenant_id', $tid));
                $emails = array_values(array_filter(array_map(fn ($e) => isset($e->email) ? strtolower(trim((string) $e->email)) : '', $chain)));
                $hasEmpCol = Schema::hasColumn('users', 'employee_id');
                $uq->where(function ($w) use ($chain, $emails, $hasEmpCol) {
                    if ($hasEmpCol) {
                        $w->whereIn('employee_id', array_keys($chain));
                    }
                    if ($emails) {
                        $w->orWhereIn(DB::raw('LOWER(email)'), $emails);
                    }
                    if (! $hasEmpCol && ! $emails) {
                        $w->whereRaw('1 = 0');
                    }
                });
                $userIds = $uq->pluck('id')->all();
            }

            // 3) Every Company Admin + HR Manager login of this tenant.
            $userIds = array_merge($userIds, self::roleUserIds($tid, ['admin', 'hr_manager']));

            $userIds = array_values(array_diff(array_unique(array_map('intval', $userIds)), [(int) $submitterUserId]));
            $n = 0;
            foreach ($userIds as $uid) {
                if (self::send([
                    'tenant_id' => $tid,
                    'company_id' => $emp->company_id ?? null,
                    'user_id' => $uid,
                    'employee_id' => $emp->id ?? null,
                    'kind' => $kind,
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                    'dedupe_key' => $kind.':'.$recordId.':'.$uid,
                    'meta' => ['record_id' => $recordId],
                ])) {
                    $n++;
                }
            }

            return $n;
        } catch (\Throwable $e) {
            Log::warning('NotificationService::notifyUpline failed ('.$kind.'): '.$e->getMessage());

            return 0;
        }
    }

    /**
     * 8 Oct 2026 (Ejaz) — tell the EMPLOYEE (their own logins) that their request
     * was decided. Never notifies the person who made the decision. Fail-soft.
     */
    public static function notifyEmployee($empId, string $kind, string $title, ?string $body, string $url, int $recordId, ?int $actorUserId = null): int
    {
        try {
            $emp = $empId ? DB::table('employees')->where('id', (int) $empId)->first() : null;
            if (! $emp || ! Schema::hasTable('users')) {
                return 0;
            }
            $tid = $emp->tenant_id ?? null;
            $uq = DB::table('users')->when($tid, fn ($q) => $q->where('tenant_id', $tid));
            $hasEmpCol = Schema::hasColumn('users', 'employee_id');
            $email = strtolower(trim((string) ($emp->email ?? '')));
            $uq->where(function ($w) use ($emp, $email, $hasEmpCol) {
                if ($hasEmpCol) {
                    $w->where('employee_id', (int) $emp->id);
                }
                if ($email !== '') {
                    $w->orWhere(DB::raw('LOWER(email)'), $email);
                }
                if (! $hasEmpCol && $email === '') {
                    $w->whereRaw('1 = 0');
                }
            });
            $n = 0;
            foreach (array_diff(array_map('intval', $uq->pluck('id')->all()), [(int) $actorUserId]) as $uid) {
                if (self::send(['tenant_id' => $tid, 'company_id' => $emp->company_id ?? null, 'user_id' => $uid, 'employee_id' => $emp->id,
                    'kind' => $kind, 'title' => $title, 'body' => $body, 'url' => $url,
                    'dedupe_key' => $kind.':'.$recordId.':'.$uid, 'meta' => ['record_id' => $recordId]])) {
                    $n++;
                }
            }

            return $n;
        } catch (\Throwable $e) {
            Log::warning('NotificationService::notifyEmployee failed ('.$kind.'): '.$e->getMessage());

            return 0;
        }
    }

    /** Login ids holding any of these roles in the tenant (e.g. ['admin','hr_manager']). */
    public static function roleUserIds(?int $tid, array $roles): array
    {
        try {
            if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
                return [];
            }
            $ids = DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
                ->whereIn('r.name', $roles)->where('mr.model_type', \App\Models\User::class)->pluck('mr.model_id')->all();

            return $ids ? DB::table('users')->whereIn('id', $ids)->when($tid, fn ($q) => $q->where('tenant_id', $tid))->pluck('id')->map(fn ($x) => (int) $x)->all() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Everyone BELOW an employee in the reporting chain (ids). Used so a manager
     * who opens a notification can actually see their reportee's request.
     */
    public static function downlineIds(?int $empId, ?int $tenantId): array
    {
        // 7 Oct 2026 — one hierarchy for the whole app (reads the reporting-manager
        // NAME the Directory writes, not only the rarely-filled _id column).
        return \App\Services\Hierarchy::downline($empId, $tenantId);
    }

    // ---- In-app inbox reads (used by NotificationController) ----------------

    /** Recent notifications for one app user (newest first). */
    public static function feedForUser(?int $userId, ?int $tenantId, int $limit = 30): array
    {
        try {
            self::ensureTable();
            if (! $userId) {
                return [];
            }

            return DB::table('notification_log')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'kind' => $r->kind,
                    'title' => $r->title,
                    'body' => $r->body,
                    'url' => $r->url,
                    'read' => $r->read_at !== null,
                    'at' => (string) $r->created_at,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Unread count for the bell badge. */
    public static function unreadCount(?int $userId, ?int $tenantId): int
    {
        try {
            self::ensureTable();
            if (! $userId) {
                return 0;
            }

            return (int) DB::table('notification_log')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Mark one notification read (only if it belongs to the user). */
    public static function markRead(int $id, ?int $userId): bool
    {
        try {
            return DB::table('notification_log')
                ->where('id', $id)
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->whereNull('read_at')
                ->update(['read_at' => now(), 'updated_at' => now()]) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Mark one notification UNREAD again (only if it belongs to the user). 2026-09-28. */
    public static function markUnread(int $id, ?int $userId): bool
    {
        try {
            return DB::table('notification_log')
                ->where('id', $id)
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->whereNotNull('read_at')
                ->update(['read_at' => null, 'updated_at' => now()]) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Mark every unread notification for a user read. Returns rows updated. */
    public static function markAllRead(?int $userId, ?int $tenantId): int
    {
        try {
            if (! $userId) {
                return 0;
            }

            return (int) DB::table('notification_log')
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->update(['read_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
