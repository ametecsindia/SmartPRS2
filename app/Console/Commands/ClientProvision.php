<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * rev 104 — ON-PREMISE CLIENT PROVISIONING (SmartPRS-L1/L2/L3 installs).
 *
 * Run ONCE on the client's server after `migrate` + `db:seed`. Creates the
 * client's workspace CLEAN — their company, their admin login — no demo data.
 *
 *   php artisan client:provision --company="ABC Recoveries Pvt Ltd"
 *       --email=admin@abcrecoveries.in --password=TheirStrongPassword
 *       [--name="Mr. Sharma"]
 *
 * Mirrors SaasController::provisionTenantRecord but offline-safe: no invite
 * email (client servers often have no SMTP yet), password set directly.
 * Idempotent: refuses to run twice against the same admin email.
 */
class ClientProvision extends Command
{
    protected $signature = 'client:provision
        {--company= : The client company name}
        {--email=   : The admin login email}
        {--password= : The admin login password}
        {--name=    : Admin display name (default: Administrator)}';

    protected $description = 'Provision a clean on-premise client workspace (company + admin login, no demo data)';

    public function handle(): int
    {
        $company = trim((string) $this->option('company'));
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');
        $adminName = trim((string) $this->option('name')) ?: 'Administrator';

        if ($company === '' || $email === '' || $password === '') {
            $this->error('Usage: php artisan client:provision --company="..." --email=... --password=...');

            return self::FAILURE;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That admin email does not look valid: '.$email);

            return self::FAILURE;
        }
        if (strlen($password) < 8) {
            $this->error('Please use a password of at least 8 characters.');

            return self::FAILURE;
        }
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('A user with that email already exists — this server looks already provisioned.');

            return self::FAILURE;
        }

        $now = now();

        // 1) The client's tenant (single-tenant on-prem; subdomain unused but unique).
        $tenantId = DB::table('tenants')->insertGetId(\App\Http\Controllers\ApprovalService::safeRow('tenants', [
            'uuid' => (string) Str::uuid(),
            'name' => $company,
            'plan_id' => DB::table('plans')->orderBy('id')->value('id'),
            'status' => 'active',
            'seats_used' => 0,
            'seats_licensed' => 100000,
            'mrr' => 0,
            'deployment' => 'onprem',
            'owner_email' => $email,
            'subdomain' => Str::slug(Str::limit($company, 40, '')) ?: 'client',
            'created_at' => $now, 'updated_at' => $now,
        ]));

        // 2) The MASTER company.
        try {
            if (! Schema::hasColumn('companies', 'is_master')) {
                Schema::table('companies', fn ($t) => $t->boolean('is_master')->default(false));
            }
        } catch (\Throwable $e) {
        }
        DB::table('companies')->insert(\App\Http\Controllers\ApprovalService::safeRow('companies', [
            'tenant_id' => $tenantId,
            'name' => $company,
            'is_master' => 1,
            'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]));

        // 3) The admin login — password set directly (no invite email needed).
        $userId = DB::table('users')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $adminName,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            \App\Models\User::find($userId)?->syncRoles(['admin']);
        } catch (\Throwable $e) {
            $this->warn('Could not attach the admin role — run db:seed first, then re-check the user.');
        }

        // 4) Collections & Recovery starter content (training/FAQs/KB/letters)
        //    — same professional starter pack every SaaS tenant gets.
        try {
            SeedIndustryContent::seedForTenant($tenantId);
        } catch (\Throwable $e) {
        }

        $edition = \App\Services\Edition::label();
        $this->info('Provisioned: '.$company.'  ('.$edition.')');
        $this->line('  Admin login : '.$email);
        $this->line('  Workspace   : /app  (sign in at /login)');

        return self::SUCCESS;
    }
}
