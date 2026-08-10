<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * On-prem workspace unlock (8 Aug 2026).
 *
 * The full Administration menu (Users, Roles, Settings, Company Branding,
 * Email Senders, WhatsApp / SMS, Exit & FnF) is hidden for the PUBLIC DEMO
 * workspace only: DemoAccessController::isDemoTenant() is true when a tenant's
 * `subdomain` = 'demo', and AppController then applies the "LIVE DEMO" lockdown
 * (hide list) + banner. This is by design for the shared sales demo — but an
 * on-prem client install must NOT be a demo tenant, or the client's admin loses
 * those menus.
 *
 * This seeder makes the local L3 workspace a REAL (non-demo) workspace so the
 * full product is visible, and guarantees a full-access admin login exists.
 * It is idempotent and safe to re-run.
 *
 * It does NOT touch the Licence / Activation / Installation system in any way —
 * it only renames the demo tenant's subdomain and ensures an admin user + roles.
 *
 * Run once:   php artisan db:seed --class=OnPremWorkspaceSeeder
 */
class OnPremWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('tenants')) {
            $this->msg('No tenants table yet — run migrations first.');
            return;
        }

        // 1) Un-demo the workspace. The ONLY thing that marks a tenant as the
        //    restricted demo is subdomain = 'demo'. Rename it so isDemoTenant()
        //    returns false and the full Administration menu renders.
        $demoTenants = DB::table('tenants')->where('subdomain', 'demo')->whereNull('deleted_at')->pluck('id');
        $renamed = 0;
        foreach ($demoTenants as $tid) {
            // pick a unique, clearly non-demo subdomain
            $sub = 'workspace';
            $i = 1;
            while (DB::table('tenants')->where('subdomain', $sub)->where('id', '!=', $tid)->exists()) {
                $sub = 'workspace'.(++$i);
            }
            $upd = ['subdomain' => $sub, 'updated_at' => now()];
            if (Schema::hasColumn('tenants', 'deployment')) {
                $upd['deployment'] = 'onprem';   // on-prem install, not saas demo
            }
            DB::table('tenants')->where('id', $tid)->update($upd);
            $renamed++;
        }

        // 2) Make sure the roles exist (so the admin can be granted the admin role).
        try {
            if (Schema::hasTable('roles') && ! DB::table('roles')->where('name', 'admin')->exists()) {
                $this->call(RolePermissionSeeder::class);
            }
        } catch (\Throwable $e) {
            // non-fatal — role assignment below is guarded
        }

        // 3) Ensure a full-access admin login exists on the (now real) workspace.
        $tenantId = DB::table('tenants')->whereNull('deleted_at')->orderBy('id')->value('id');
        $email = 'admin@smartprs.local';
        $created = false;
        if ($tenantId) {
            $exists = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists();
            if (! $exists) {
                DB::table('users')->insert([
                    'name' => 'Administrator',
                    'email' => $email,
                    'password' => Hash::make('password'),
                    'tenant_id' => $tenantId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created = true;
            }
            // Grant the admin role if the Spatie roles are present.
            try {
                if (class_exists(\App\Models\User::class) && class_exists(\Spatie\Permission\Models\Role::class)
                    && \Spatie\Permission\Models\Role::where('name', 'admin')->exists()) {
                    $u = \App\Models\User::whereRaw('LOWER(email) = ?', [$email])->first();
                    if ($u && method_exists($u, 'syncRoles')) {
                        $u->syncRoles(['admin']);
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        $this->msg(($renamed ? "Un-demoed $renamed workspace tenant(s) — the full Administration menu will now show. " : "No demo tenant found (already a real workspace). ")
            . ($created ? "Created admin login $email (password: password)." : "Admin login $email is present."));
        $this->msg('Reminder: run  php artisan optimize:clear  and hard-refresh (Ctrl+F5). Licence/activation/installation were NOT changed.');
    }

    private function msg(string $s): void
    {
        if ($this->command) {
            $this->command->info($s);
        }
    }
}
