<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles/permissions are always needed.
        $this->call(RolePermissionSeeder::class);

        // The published Starter/Growth/Professional plans (idempotent; demo + prod).
        $this->call(StandardPlanSeeder::class);

        // Demo data (fake tenant/companies/employees + shared 'password' logins) is
        // OPT-IN and HARD-BLOCKED in production. Enable on dev with SMARTPRS_DEMO_DATA=true.
        $demo = ! app()->environment('production')
            && filter_var(env('SMARTPRS_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN);

        if ($demo) {
            $this->call([
                DemoCompanySeeder::class,
                DummyDataSeeder::class,   // demo employees across all group companies (idempotent)
            ]);
        } else {
            // Real deployment: just create the platform super admin from env creds.
            $this->call(SuperAdminSeeder::class);
        }
    }
}
