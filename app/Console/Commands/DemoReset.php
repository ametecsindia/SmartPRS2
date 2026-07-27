<?php

namespace App\Console\Commands;

use Database\Seeders\DummyDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SHARED DEMO TENANT for sales (rev 76, Ejaz 4 Jun 2026).
 *
 * Creates (or wipes + re-creates) ONE permanent demo workspace full of rich
 * sample data — employees, org structure, branding — that you show every
 * prospective client. Works on a LIVE platform: it does NOT create the fake
 * client tenants, does NOT touch any real tenant, and creates NO super admin.
 *
 *   php artisan demo:reset
 *   php artisan demo:reset --password=MyDemoPass1
 *
 * Demo logins (tenant "SmartPRS Demo Workspace", subdomain `demo`):
 *   demo-admin@smartprs.in  (Admin)        — password below
 *   demo-hr@smartprs.in     (HR Manager)
 *   demo-field@smartprs.in  (Field Agent)
 * Default password: smartprs-demo (override with --password=…).
 *
 * The demo tenant gets a far-future subscription so the expiry lock-out never
 * interrupts a sales demo. REAL tenants (manual or paid signup) always start
 * EMPTY — this command is the only thing that makes demo data on a server.
 * Re-run any time to reset the demo to pristine after a client meeting.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset {--password=smartprs-demo : Password for the demo logins} {--if-due : rev185 — reset only when a passkey window has ended and nobody with a live passkey is inside}';

    protected $description = 'Create or reset the shared sales-demo tenant (safe on a live platform)';

    public function handle(): int
    {
        // rev185 — passkey-aware gate: with --if-due, wipe ONLY when some visitor's
        // window has ended (their data must be erased) and no live passkey session
        // could still be mid-demo. Without the flag the reset always runs (deploy
        // script + daily backstop).
        if ($this->option('if-due') && ! $this->resetDue()) {
            $this->info('demo:reset --if-due: nothing due — skipped.');

            return self::SUCCESS;
        }
        $now = now();
        $password = (string) $this->option('password');

        // 1) The demo tenant (subdomain `demo`) — reused if it exists.
        $tenantId = DB::table('tenants')->where('subdomain', 'demo')->value('id');
        $planId = DB::table('plans')->where('name', 'Growth')->value('id')
            ?: DB::table('plans')->orderBy('id')->value('id');
        if (! $tenantId) {
            $tenantId = DB::table('tenants')->insertGetId([
                'uuid' => (string) Str::uuid(), 'name' => 'SmartPRS Demo Workspace',
                'plan_id' => $planId, 'status' => 'active',
                'seats_used' => 36, 'seats_licensed' => 75, 'mrr' => 2500,
                'deployment' => 'saas', 'owner_email' => 'demo-admin@smartprs.in',
                'subdomain' => 'demo', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->info('Created demo tenant #'.$tenantId);
        } else {
            DB::table('tenants')->where('id', $tenantId)->update([
                'name' => 'SmartPRS Demo Workspace', 'status' => 'active', 'updated_at' => $now,
            ]);
            $this->info('Reusing demo tenant #'.$tenantId.' (will be wiped + reseeded)');
        }

        // 2) Far-future subscription (3 companies, 75 employees) so the demo
        //    never hits the expiry lock-out or the seat/company limits mid-pitch.
        try {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'companies')) {
                \Illuminate\Support\Facades\Schema::table('subscriptions', fn ($t) => $t->integer('companies')->default(1));
            }
        } catch (\Throwable $e) {
        }
        // 12 months (Ejaz, 5 Jun 2026 — was 10 years, which made the pro-rata
        // upgrade quote look absurd in demos). The deploy script re-runs this
        // command every time, so the demo period keeps rolling forward and the
        // demo can still never expire mid-demonstration.
        $end = $now->copy()->addMonths(12)->toDateString();
        $subRow = [
            'tenant_id' => $tenantId, 'plan_id' => $planId, 'seats' => 75, 'companies' => 3,
            'cycle' => 'annual', 'amount' => 0, 'status' => 'active',
            'current_period_end' => $end, 'next_renewal' => $end, 'updated_at' => $now,
        ];
        $subRow = \App\Http\Controllers\ApprovalService::safeRow('subscriptions', $subRow);
        $existing = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
        if ($existing) {
            DB::table('subscriptions')->where('id', $existing->id)->update($subRow);
        } else {
            $subRow['created_at'] = $now;
            DB::table('subscriptions')->insert($subRow);
        }

        // 3) Demo logins (NO super admin — demo users belong to the demo tenant).
        foreach ([
            ['Demo Admin', 'demo-admin@smartprs.in', 'admin'],
            ['Demo HR Manager', 'demo-hr@smartprs.in', 'hr_manager'],
            ['Demo Field Agent', 'demo-field@smartprs.in', 'field_agent'],
        ] as [$name, $email, $role]) {
            $uid = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])->value('id');
            if ($uid) {
                DB::table('users')->where('id', $uid)->update([
                    'name' => $name, 'tenant_id' => $tenantId, 'status' => 'active',
                    'password' => Hash::make($password), 'updated_at' => $now,
                ]);
            } else {
                $uid = DB::table('users')->insertGetId([
                    'tenant_id' => $tenantId, 'name' => $name, 'email' => strtolower($email),
                    'password' => Hash::make($password), 'status' => 'active',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            try {
                \App\Models\User::find($uid)?->syncRoles([$role]);
            } catch (\Throwable $e) {
            }
        }

        // 4) Rich sample data: org + ~36 employees + branding (WIPES the demo
        //    tenant's people first — that is the "reset"). Fake client tenants
        //    are SKIPPED so a live SaaS panel stays clean.
        DummyDataSeeder::$skipClientTenants = true;
        (new DummyDataSeeder)->setCommand($this)->run();

        // 5) Industry starter content (training/FAQs/KB/letters) for realism.
        try {
            SeedIndustryContent::seedForTenant($tenantId);
        } catch (\Throwable $e) {
        }

        // 6) Clear any persisted demo app_state so screens rebuild fresh.
        try {
            DB::table('settings')->where('tenant_id', $tenantId)->where('key', 'app_state')->delete();
        } catch (\Throwable $e) {
        }

        // rev185 — stamp the requests whose visitor-entered data this reset erased.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('demo_requests')) {
                DB::table('demo_requests')->whereIn('status', ['expired', 'revoked'])
                    ->whereNull('wiped_at')->update(['wiped_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
        }

        $this->info('Demo workspace ready. Logins (password: '.$password.'):');
        $this->line('  demo-admin@smartprs.in  (Admin)');
        $this->line('  demo-hr@smartprs.in     (HR Manager)');
        $this->line('  demo-field@smartprs.in  (Field Agent)');

        return self::SUCCESS;
    }

    /** rev185 — is a --if-due reset warranted right now? */
    private function resetDue(): bool
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('demo_requests')) {
                return false;
            }
            // Flip overdue passes first, then: skip while anyone holds a live
            // passkey they have USED (mid-demo); run when an ended/revoked pass
            // that was actually used still awaits its wipe.
            DB::table('demo_requests')->where('status', 'active')
                ->where('expires_at', '<', now())->update(['status' => 'expired', 'updated_at' => now()]);
            $someoneInside = DB::table('demo_requests')->where('status', 'active')
                ->where('expires_at', '>', now())->whereNotNull('last_login_at')->exists();
            if ($someoneInside) {
                return false;
            }

            return DB::table('demo_requests')->whereIn('status', ['expired', 'revoked'])
                ->whereNull('wiped_at')->whereNotNull('last_login_at')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
