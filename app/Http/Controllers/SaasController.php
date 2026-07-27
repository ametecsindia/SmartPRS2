<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SaaS Platform (Super Admin only).
 *
 * Real tenant provisioning + plans. A tenant is the customer account; inside it
 * live one or more companies, users and employees. This controller lets the
 * platform super-admin:
 *   - list tenants (with plan, seats, status),
 *   - create a tenant END-TO-END: tenants row + its first company + a first
 *     ADMIN user who receives the set-password invite email (reusing the rev-24
 *     mail engine + rev-26 user_password_resets token),
 *   - activate / suspend a tenant,
 *   - manage Plans (name, price, seat cap, included module groups).
 *
 * Module groups on a plan (plans.features JSON) are the basis for plan→module
 * gating; this controller stores + exposes them. All endpoints are super-admin
 * guarded and fail soft (JSON {error}).
 */
class SaasController extends Controller
{
    /** Module groups a plan can include (key → label). Mirrors the app's nav sections. */
    public const MODULE_GROUPS = [
        'hiring' => 'Hiring & Onboarding',
        'people' => 'Employees / People',
        'attendance' => 'Time & Attendance',
        'leave' => 'Leave',
        'payroll' => 'Payroll',
        'compensation' => 'Compensation & Claims',
        'statutory' => 'Statutory & Compliance',
        'performance' => 'Performance & Rewards',
        'learning' => 'Learning & Knowledge',
        'letters' => 'HR Letters',
        'field' => 'Field Force',
        'communication' => 'Communication',
        'reports' => 'Reports & Analytics',
        'administration' => 'Administration',
    ];

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    // ============================================================ tenants ===

    public function tenants(Request $request)
    {
        try {
            $this->guard($request);
            $this->ensureTenantCols();
            $hasDomain = Schema::hasColumn('tenants', 'custom_domain');
            $planNames = DB::table('plans')->pluck('name', 'id');
            $userCounts = DB::table('users')->select('tenant_id', DB::raw('count(*) as c'))
                ->whereNotNull('tenant_id')->groupBy('tenant_id')->pluck('c', 'tenant_id');
            $empCounts = DB::table('employees')->whereNull('deleted_at')
                ->select('tenant_id', DB::raw('count(*) as c'))->groupBy('tenant_id')->pluck('c', 'tenant_id');

            $rows = DB::table('tenants')->whereNull('deleted_at')->orderBy('name')->get()->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'plan' => $t->plan_id ? ($planNames[$t->plan_id] ?? '—') : '—',
                'planId' => $t->plan_id ? (int) $t->plan_id : null,
                'status' => $t->status,
                'seatsLicensed' => (int) $t->seats_licensed,
                'users' => (int) ($userCounts[$t->id] ?? 0),
                'employees' => (int) ($empCounts[$t->id] ?? 0),
                'ownerEmail' => $t->owner_email,
                'deployment' => $t->deployment,
                'subdomain' => $t->subdomain ?? '',
                'customDomain' => $hasDomain ? ($t->custom_domain ?? '') : '',
                'gstin' => $t->gstin ?? '',
                'state' => $t->state ?? '',
                'docStorageEmpMb' => isset($t->doc_storage_emp_mb) && $t->doc_storage_emp_mb !== null ? (int) $t->doc_storage_emp_mb : null,
                'created' => $t->created_at ? \Illuminate\Support\Carbon::parse($t->created_at)->format('d M Y') : '',
            ])->values();

            return response()->json([
                'rows' => $rows,
                'plans' => DB::table('plans')->where('status', 'active')->orderBy('name')
                    ->get(['id', 'name', 'seat_max'])->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'seatMax' => $p->seat_max])->values(),
                'canManage' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Provision a new tenant end-to-end: tenant + first company + admin user
     * (invited by email to set their password).
     */
    public function provisionTenant(Request $request)
    {
        try {
            $this->guard($request);
            $v = $request->validate([
                'name' => ['required', 'string', 'max:150'],          // tenant / group name
                'company_name' => ['nullable', 'string', 'max:150'],  // first company (defaults to tenant name)
                'admin_name' => ['required', 'string', 'max:120'],
                'admin_email' => ['required', 'email', 'max:191'],
                'plan_id' => ['nullable', 'integer'],
                'seats_licensed' => ['nullable', 'integer', 'min:0'],
                'deployment' => ['nullable', 'in:saas,onprem'],
            ]);

            $res = self::provisionTenantRecord($v);
            if (! $res['ok']) {
                return response()->json(['ok' => false, 'error' => $res['error']], 422);
            }

            return response()->json(['ok' => true, 'tenant_id' => $res['tenant_id'], 'message' => 'Tenant created — admin invite emailed to '.$res['email']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Create a tenant + first company + first ADMIN user (set-password invite
     * emailed) + the Collections & Recovery starter content. Shared by the
     * super-admin "New Tenant" screen above and the PUBLIC self-serve signup
     * (SignupController) — so it carries NO auth guard; callers validate.
     */
    public static function provisionTenantRecord(array $v): array
    {
        try {
            $email = strtolower(trim($v['admin_email']));
            if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return ['ok' => false, 'error' => 'That admin email is already in use by another account.'];
            }

            $now = now();
            // 1) Tenant (rev 90: gstin/state captured at signup drive the
            //    CGST+SGST vs IGST split on every invoice for this tenant).
            self::ensureGstCols();
            $slug = self::resolveSubdomain($v['subdomain'] ?? null, $v['name']);
            $tenantId = DB::table('tenants')->insertGetId(ApprovalService::safeRow('tenants', [
                'uuid' => (string) Str::uuid(),
                'name' => $v['name'],
                'plan_id' => $v['plan_id'] ?? null,
                'status' => 'active',
                'seats_used' => 0,
                'seats_licensed' => $v['seats_licensed'] ?? 0,
                'mrr' => 0,
                'deployment' => $v['deployment'] ?? 'saas',
                'owner_email' => $email,
                'subdomain' => $slug,
                'gstin' => strtoupper(trim((string) ($v['gstin'] ?? ''))) ?: null,
                'state' => trim((string) ($v['state'] ?? '')) ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            // 2) First company under the tenant — this is the MASTER company
            //    (rev 77: additional companies created later are subsidiaries).
            try {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('companies', 'is_master')) {
                    \Illuminate\Support\Facades\Schema::table('companies', fn ($t) => $t->boolean('is_master')->default(false));
                }
            } catch (\Throwable $e) {
            }
            $companyName = ($v['company_name'] ?? null) ?: $v['name'];
            DB::table('companies')->insert(ApprovalService::safeRow('companies', [
                'tenant_id' => $tenantId,
                'name' => $companyName,
                'is_master' => 1,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            // 3) First ADMIN user. Default: unusable password + set-password invite.
            // With 'email_credentials' => true (the paid self-serve signup), a strong
            // TEMPORARY password is generated and included in the welcome email so
            // the customer can sign in immediately (the set-password link is still
            // included so they can change it right away).
            $tempPassword = ! empty($v['email_credentials']) ? Str::password(12, true, true, false) : null;
            $userId = DB::table('users')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $v['admin_name'],
                'email' => $email,
                'password' => bcrypt($tempPassword ?: Str::random(40)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                \App\Models\User::find($userId)?->syncRoles(['admin']);
            } catch (\Throwable $e) {
                // role pivot best-effort
            }
            // rev 170: guarantee a password path that does NOT depend on email.
            // The new admin is forced to CREATE their own password on first
            // entry into /app (AppController redirects to /app/first-password
            // while this flag is set). Fixes the lock-out when the credentials
            // email silently fails (no platform SMTP / worker down).
            try {
                AuthController::ensureFirstPasswordCol();
                DB::table('users')->where('id', $userId)->update(['must_set_password' => 1]);
            } catch (\Throwable $e) {
                // best-effort — without the column, behaviour is simply as before
            }
            // rev 108: the welcome email teaches them their branded sign-in page.
            self::sendAdminInvite($userId, $v['admin_name'], $email, $tenantId, $v['name'], $tempPassword,
                array_merge($v['extra_lines'] ?? [], ['Your branded sign-in page: '.url('/c/'.$slug).' — bookmark it!']));

            // Auto-install the Collections & Recovery starter content (Training
            // Programs, Training Content, FAQs + global Knowledge Base / Code of
            // Conduct) for the new tenant. Best-effort; never blocks provisioning.
            try {
                \App\Console\Commands\SeedIndustryContent::seedForTenant($tenantId);
            } catch (\Throwable $e) {
                // content seeding is non-critical
            }

            return ['ok' => true, 'tenant_id' => $tenantId, 'email' => $email, 'temp_password' => $tempPassword];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Activate / suspend a tenant. Suspended = its users are blocked from login. */
    public function tenantStatus(Request $request, int $id)
    {
        try {
            $this->guard($request);
            $v = $request->validate(['status' => ['required', 'in:active,suspended']]);
            $tenant = DB::table('tenants')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $tenant) {
                return response()->json(['ok' => false, 'error' => 'Tenant not found.'], 404);
            }
            DB::table('tenants')->where('id', $id)->update(['status' => $v['status'], 'updated_at' => now()]);
            // Cascade to the tenant's user logins so suspension actually blocks access.
            DB::table('users')->where('tenant_id', $id)
                ->update(['status' => $v['status'] === 'active' ? 'active' : 'disabled', 'updated_at' => now()]);

            return response()->json(['ok' => true, 'status' => $v['status']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Self-creating custom_domain column on tenants (for vanity login URLs). */
    private function ensureTenantCols(): void
    {
        try {
            if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'custom_domain')) {
                Schema::table('tenants', function (\Illuminate\Database\Schema\Blueprint $t) {
                    $t->string('custom_domain')->nullable();
                });
            }
        } catch (\Throwable $e) {
            // non-fatal
        }
        try {
            // rev172 — PER-EMPLOYEE document-storage allowance (MB). Null =
            // platform default (DocumentController::DEFAULT_EMP_QUOTA_MB, 100 MB).
            if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'doc_storage_emp_mb')) {
                Schema::table('tenants', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->unsignedInteger('doc_storage_emp_mb')->nullable());
            }
        } catch (\Throwable $e) {
            // non-fatal
        }
        self::ensureGstCols();
    }

    /** rev 90: buyer GST profile on the tenant (GSTIN + state → CGST/SGST vs IGST). */
    public static function ensureGstCols(): void
    {
        try {
            if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'gstin')) {
                Schema::table('tenants', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->string('gstin', 20)->nullable());
            }
            if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'state')) {
                Schema::table('tenants', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->string('state', 60)->nullable());
            }
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * Edit a tenant: name, owner email, plan, seats, subdomain and custom domain.
     * Keeps the owner_email in sync; custom_domain enables a vanity login URL
     * (DNS/host must point at this server — see handoff).
     */
    public function updateTenant(Request $request, int $id)
    {
        try {
            $this->guard($request);
            $this->ensureTenantCols();
            $tenant = DB::table('tenants')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $tenant) {
                return response()->json(['ok' => false, 'error' => 'Tenant not found.'], 404);
            }
            $v = $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'owner_email' => ['nullable', 'email', 'max:191'],
                'plan_id' => ['nullable', 'integer'],
                'seats_licensed' => ['nullable', 'integer', 'min:0'],
                'doc_storage_emp_mb' => ['nullable', 'integer', 'min:1', 'max:10240'],
                'subdomain' => ['nullable', 'string', 'max:30'],
                'custom_domain' => ['nullable', 'string', 'max:191'],
                'gstin' => ['nullable', 'string', 'max:20'],
                'state' => ['nullable', 'string', 'max:60'],
            ]);

            // Normalise + uniqueness for subdomain / custom domain (ignore self).
            // rev 108 (Ejaz): slugs are lowercase letters/numbers, 3–10 chars.
            $sub = $v['subdomain'] !== null && $v['subdomain'] !== '' ? strtolower(preg_replace('/[^a-z0-9]/i', '', $v['subdomain'])) : null;
            if ($sub) {
                if (strlen($sub) < 3 || strlen($sub) > self::SLUG_MAX) {
                    return response()->json(['ok' => false, 'error' => 'The web address must be 3 to '.self::SLUG_MAX.' letters/numbers (no spaces or symbols).'], 422);
                }
                $clash = DB::table('tenants')->where('subdomain', $sub)->where('id', '!=', $id)->exists();
                if ($clash) {
                    return response()->json(['ok' => false, 'error' => 'That web address is already in use by another client.'], 422);
                }
            }
            $domain = $v['custom_domain'] ?? null;
            if ($domain) {
                $domain = strtolower(trim(preg_replace('#^https?://#', '', $domain)));
                $domain = rtrim($domain, '/');
                $clash = DB::table('tenants')->where('custom_domain', $domain)->where('id', '!=', $id)->exists();
                if ($clash) {
                    return response()->json(['ok' => false, 'error' => 'That custom domain is already assigned to another tenant.'], 422);
                }
            }

            $upd = [
                'name' => $v['name'],
                'owner_email' => $v['owner_email'] ?: $tenant->owner_email,
                'updated_at' => now(),
            ];
            if (array_key_exists('plan_id', $v)) {
                $upd['plan_id'] = $v['plan_id'] ?: null;
            }
            if (array_key_exists('seats_licensed', $v) && $v['seats_licensed'] !== null) {
                $upd['seats_licensed'] = $v['seats_licensed'];
            }
            if ($sub !== null) {
                $upd['subdomain'] = $sub;
            }
            // rev172 — per-EMPLOYEE document-storage allowance (MB); null/blank = platform default (100 MB).
            if (array_key_exists('doc_storage_emp_mb', $v)) {
                $upd['doc_storage_emp_mb'] = $v['doc_storage_emp_mb'] !== null && $v['doc_storage_emp_mb'] !== '' ? (int) $v['doc_storage_emp_mb'] : null;
            }
            $upd['custom_domain'] = $domain;   // null clears it
            // rev 90: buyer GST profile (drives CGST+SGST vs IGST on invoices).
            $upd['gstin'] = strtoupper(trim((string) ($v['gstin'] ?? ''))) ?: null;
            $upd['state'] = trim((string) ($v['state'] ?? '')) ?: null;

            DB::table('tenants')->where('id', $id)->update(ApprovalService::safeRow('tenants', $upd));

            return response()->json(['ok' => true, 'message' => 'Tenant updated']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Change a tenant's plan + seat cap. */
    public function tenantPlan(Request $request, int $id)
    {
        try {
            $this->guard($request);
            $v = $request->validate([
                'plan_id' => ['nullable', 'integer'],
                'seats_licensed' => ['nullable', 'integer', 'min:0'],
            ]);
            $upd = ['updated_at' => now()];
            if (array_key_exists('plan_id', $v)) {
                $upd['plan_id'] = $v['plan_id'] ?: null;
            }
            if (array_key_exists('seats_licensed', $v) && $v['seats_licensed'] !== null) {
                $upd['seats_licensed'] = $v['seats_licensed'];
            }
            DB::table('tenants')->where('id', $id)->update($upd);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ============================================================== plans ===

    public function plans(Request $request)
    {
        try {
            $this->guard($request);
            $rows = DB::table('plans')->orderBy('base_price')->get()->map(function ($p) {
                $feat = json_decode($p->features ?? '[]', true) ?: [];

                return [
                    'id' => (int) $p->id,
                    'name' => $p->name,
                    'basePrice' => (float) $p->base_price,
                    'perUserPrice' => (float) $p->per_user_price,
                    'billingCycle' => $p->billing_cycle,
                    'seatMax' => $p->seat_max,
                    'modules' => array_values($feat),
                    'status' => $p->status,
                ];
            })->values();

            return response()->json([
                'rows' => $rows,
                'moduleGroups' => self::MODULE_GROUPS,
                'canManage' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    public function savePlan(Request $request)
    {
        try {
            $this->guard($request);
            $v = $request->validate([
                'id' => ['nullable', 'integer'],
                'name' => ['required', 'string', 'max:120'],
                'base_price' => ['nullable', 'numeric', 'min:0'],
                'per_user_price' => ['nullable', 'numeric', 'min:0'],
                'billing_cycle' => ['nullable', 'in:quarterly,halfyear,annual'],
                'seat_max' => ['nullable', 'integer', 'min:0'],
                'modules' => ['array'],
                'modules.*' => ['string'],
                'status' => ['nullable', 'in:active,inactive'],
            ]);
            // Keep only valid module-group keys.
            $modules = array_values(array_intersect($v['modules'] ?? [], array_keys(self::MODULE_GROUPS)));

            $row = [
                'name' => $v['name'],
                'base_price' => $v['base_price'] ?? 0,
                'per_user_price' => $v['per_user_price'] ?? 0,
                'billing_cycle' => $v['billing_cycle'] ?? 'quarterly',
                'seat_max' => $v['seat_max'] ?? null,
                'features' => json_encode($modules),
                'status' => $v['status'] ?? 'active',
                'updated_at' => now(),
            ];
            if (! empty($v['id'])) {
                DB::table('plans')->where('id', $v['id'])->update($row);
                $id = $v['id'];
            } else {
                $row['created_at'] = now();
                $id = DB::table('plans')->insertGetId($row);
            }

            return response()->json(['ok' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deletePlan(Request $request)
    {
        try {
            $this->guard($request);
            $id = (int) $request->input('id');
            if (! $id) {
                return response()->json(['ok' => false, 'error' => 'Plan id is required.'], 422);
            }
            // Referential safety: never orphan a tenant or subscription that points at this plan.
            $inUse = 0;
            if (\Illuminate\Support\Facades\Schema::hasTable('tenants') && \Illuminate\Support\Facades\Schema::hasColumn('tenants', 'plan_id')) {
                $inUse += DB::table('tenants')->where('plan_id', $id)->count();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('subscriptions') && \Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'plan_id')) {
                $inUse += DB::table('subscriptions')->where('plan_id', $id)->count();
            }
            if ($inUse > 0) {
                return response()->json(['ok' => false, 'error' => 'This plan is still assigned to ' . $inUse . ' tenant/subscription(s). Reassign or cancel those first, or set the plan inactive instead of deleting.'], 422);
            }
            DB::table('plans')->where('id', $id)->delete();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ============================================================ helpers ===

    /**
     * rev 108 (Ejaz): slugs are SHORT — 10 characters maximum. The client may
     * request one at signup; otherwise we derive it from the company name.
     */
    public const SLUG_MAX = 10;

    private static function uniqueSubdomain(string $name): string
    {
        $base = substr(Str::slug($name, ''), 0, self::SLUG_MAX) ?: ('t'.Str::lower(Str::random(6)));
        $slug = $base;
        $i = 1;
        while (DB::table('tenants')->where('subdomain', $slug)->exists()) {
            $i++;
            $slug = substr($base, 0, self::SLUG_MAX - strlen((string) $i)).$i;
        }

        return $slug;
    }

    /**
     * Resolve a CLIENT-REQUESTED slug: lowercase letters/numbers only, 3–10
     * characters, unique — else fall back to the auto slug from the name.
     */
    public static function resolveSubdomain(?string $requested, string $name): string
    {
        $req = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $requested));
        if (strlen($req) >= 3 && strlen($req) <= self::SLUG_MAX
            && ! DB::table('tenants')->where('subdomain', $req)->exists()) {
            return $req;
        }

        return self::uniqueSubdomain($name);
    }

    /** Issue a set-password token and email the new tenant admin a welcome invite. */
    private static function sendAdminInvite(int $userId, string $name, string $email, int $tenantId, string $tenantName, ?string $tempPassword = null, array $extraLines = []): void
    {
        try {
            // Create the token table if missing (mirrors AuthController's schema).
            if (! Schema::hasTable('user_password_resets')) {
                Schema::create('user_password_resets', function (\Illuminate\Database\Schema\Blueprint $t) {
                    $t->id();
                    $t->string('email')->index();
                    $t->string('token', 64)->index();
                    $t->timestamp('expires_at')->nullable();
                    $t->timestamp('used_at')->nullable();
                    $t->timestamps();
                });
            }
            $token = Str::random(48);
            DB::table('user_password_resets')->insert([
                'email' => $email,
                'token' => hash('sha256', $token),
                'expires_at' => now()->addHours(72),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $link = url('/reset-password/'.$token.'?email='.urlencode($email));
            // rev 171 (Ejaz): the sign-in URL is the BRANDED company page
            // /c/{slug} — never the generic /login — and the login email is
            // the registration email itself.
            $slug = DB::table('tenants')->where('id', $tenantId)->value('subdomain');
            $lines = ['Organisation' => $tenantName, 'Login email (your registration email)' => $email, 'Role' => 'Admin'];
            if ($tempPassword) {
                $lines['Temporary password'] = $tempPassword;
                $lines['Sign in at'] = $slug ? url('/c/'.$slug) : url('/login');
            }
            // Plan / payment summary etc. supplied by the caller (self-serve signup).
            foreach ($extraLines as $k => $val) {
                $lines[$k] = $val;
            }
            MailService::queue([
                'tenant_id' => $tenantId,
                'to' => $email,
                'to_name' => $name,
                'subject' => 'Welcome to SmartPRS — your admin account for '.$tenantName,
                'heading' => 'Welcome to SmartPRS by Ametecs',
                'intro' => $tempPassword
                    ? 'Thank you for choosing SmartPRS, a product of Ametecs India Private Limited. Your workspace for "'.$tenantName.'" is ready! Sign in at your company page below using your registration email and the temporary password — on your first entry the app will ask you to create your own password.'
                    : 'Thank you for choosing SmartPRS, a product of Ametecs India Private Limited. An administrator account has been created for you for "'.$tenantName.'". Set your password to get started — this link is valid for 72 hours.',
                'lines' => $lines,
                'body' => 'As the admin you can add your companies, employees, users and run payroll.'
                    .($tempPassword ? ' For security, change the temporary password after your first sign-in (Account settings → Change password).' : ' After setting your password, sign in at the SmartPRS login page.'),
                'cta_label' => $tempPassword ? 'Change your password' : 'Set your password',
                'cta_url' => $link,
                'kind' => 'tenant.admin_invite',
                'sync' => true,       // rev 170: credentials must not wait on a queue worker
                'platform' => true,   // rev 170: full Ametecs identity + contact footer
            ]);
        } catch (\Throwable $e) {
            // invite is best-effort; the tenant + admin still exist and a reset can be re-sent
        }
    }
}
