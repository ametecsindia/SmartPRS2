<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds a demo tenant + company + role-based users + sample employees against
 * the TDD schema. Uses schema-driven inserts so it stays decoupled from models.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Idempotent: reuse the demo tenant if it already exists (so re-running
        // `db:seed` on every deploy never creates duplicate tenants/companies).
        $tenantId = DB::table('tenants')->where('subdomain', 'demo')->value('id');

        if (! $tenantId) {
            // Published Growth plan (normally created by StandardPlanSeeder, which
            // runs first; fallback mirrors its Jun-2026 pricing: ₹2,500/mo incl.
            // 75 employees, ₹50/extra employee).
            $planId = DB::table('plans')->where('name', 'Growth')->value('id')
                ?? DB::table('plans')->insertGetId([
                    'name' => 'Growth', 'base_price' => 2500, 'per_user_price' => 50,
                    'billing_cycle' => 'quarterly', 'features' => json_encode(['payroll', 'attendance', 'compliance']),
                    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);

            $tenantId = DB::table('tenants')->insertGetId([
                'uuid' => (string) Str::uuid(), 'name' => 'Demo Collections Group', 'plan_id' => $planId,
                'status' => 'active', 'seats_used' => 6, 'seats_licensed' => 50, 'mrr' => 2500,
                'deployment' => 'saas', 'owner_email' => 'owner@democollections.in', 'subdomain' => 'demo',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Ensure at least one company exists for the tenant (DummyDataSeeder tops
        // this up to the full group of 3 and renames them).
        $hasCompany = DB::table('companies')->where('tenant_id', $tenantId)->whereNull('deleted_at')->exists();
        if (! $hasCompany) {
            DB::table('companies')->insert([
                'tenant_id' => $tenantId, 'name' => 'Apex Collections Pvt. Ltd.', 'type' => 'collections',
                'gstin' => '27ABCDE1234F1Z5', 'pan' => 'ABCDE1234F', 'phone' => '+91 40 1234 5678',
                'email' => 'hr@democollections.in', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Role-based users (password: password). Idempotent via firstOrCreate.
        $this->makeUser('Super Admin', 'superadmin@smartprs.local', 'super_admin', null);
        $this->makeUser('Company Admin', 'admin@smartprs.local', 'admin', $tenantId);
        $this->makeUser('HR Manager', 'hr@smartprs.local', 'hr_manager', $tenantId);
        $this->makeUser('Field Agent', 'field@smartprs.local', 'field_agent', $tenantId);
        $this->makeUser('Employee', 'employee@smartprs.local', 'employee', $tenantId);
        // Employees are seeded by DummyDataSeeder (which runs next).
    }

    private function makeUser(string $name, string $email, string $role, ?int $tenantId): void
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'tenant_id' => $tenantId, 'status' => 'active']
        );
        $user->syncRoles([$role]);
    }
}
