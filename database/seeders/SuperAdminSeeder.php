<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Production-safe platform super admin. Runs INSTEAD of the demo seeders on a
 * real deployment (see DatabaseSeeder). Creates exactly one super_admin from
 * env credentials:
 *
 *   SMARTPRS_ADMIN_EMAIL=you@yourdomain.com
 *   SMARTPRS_ADMIN_PASSWORD=...          # optional; a strong one is generated + printed if omitted
 *
 * Idempotent: never overwrites an existing user's password. No demo tenants,
 * companies, employees, or shared 'password' logins are created.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SMARTPRS_ADMIN_EMAIL');
        if (! $email) {
            $this->command?->warn('SuperAdminSeeder: set SMARTPRS_ADMIN_EMAIL in .env to create the platform super admin. Skipped.');

            return;
        }
        $email = strtolower(trim($email));

        if (User::where('email', $email)->exists()) {
            $this->command?->info("SuperAdminSeeder: super admin {$email} already exists — left unchanged.");

            return;
        }

        $plain = env('SMARTPRS_ADMIN_PASSWORD') ?: Str::password(16);
        $user = User::create([
            'name' => env('SMARTPRS_ADMIN_NAME', 'Platform Admin'),
            'email' => $email,
            'password' => Hash::make($plain),
            'tenant_id' => null,
            'status' => 'active',
        ]);
        $user->syncRoles(['super_admin']);

        $this->command?->info("SuperAdminSeeder: created super admin {$email}.");
        if (! env('SMARTPRS_ADMIN_PASSWORD')) {
            $this->command?->warn("Generated password (change it after first login): {$plain}");
        }
    }
}
