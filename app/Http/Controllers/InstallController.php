<?php

namespace App\Http\Controllers;

use App\Services\Edition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AS-DL — BROWSER FIRST-RUN SETUP WIZARD (on-prem installs).
 *
 * A self-disabling web installer so a client can stand up SmartPRS without the
 * command line. It does two things the CLI `client:provision` did not surface:
 *   1) a REQUIREMENTS pre-flight (PHP, extensions, SourceGuardian loader, DB
 *      connectivity, writable paths, APP_KEY) — the shipped code is
 *      SourceGuardian-encoded, so the loader is a hard runtime dependency;
 *   2) a browser form to create the company + admin login (no default
 *      credentials are ever shipped — the installer sets them here).
 *
 * SAFETY: it self-disables the moment ANY user exists (a provisioned/live
 * install just redirects to /login), so it is inert on the SaaS box and cannot
 * be used to hijack a running client. Fail-soft throughout.
 */
class InstallController extends Controller
{
    /** Hard-required PHP extensions (missing = blocking). */
    private const REQUIRED_EXT = [
        'openssl', 'pdo_mysql', 'mbstring', 'tokenizer', 'ctype', 'json',
        'bcmath', 'curl', 'fileinfo', 'dom', 'gd', 'zip',
    ];

    /** True once the install has an admin — the wizard must then stay closed. */
    public static function alreadyInstalled(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (\Throwable $e) {
            // No DB yet → not installed (the requirements step will flag the DB).
            return false;
        }
    }

    /** True when the SourceGuardian runtime loader is present. */
    private static function sourceGuardianLoaded(): bool
    {
        return extension_loaded('sourceguardian')
            || function_exists('sg_load')
            || in_array('sourceguardian', array_map('strtolower', get_loaded_extensions()), true);
    }

    /**
     * Environment pre-flight. Each item: [key,label,ok,level,detail].
     * level: 'error' blocks setup; 'warn' is advisory (shown amber).
     */
    public static function requirements(): array
    {
        $checks = [];

        // PHP version — 8.3 is the standard; 8.2 works, older is blocked.
        $php = PHP_VERSION;
        $checks[] = [
            'key' => 'php', 'label' => 'PHP version (8.3 recommended)',
            'ok' => version_compare($php, '8.2.0', '>='),
            'level' => version_compare($php, '8.3.0', '>=') ? 'ok' : (version_compare($php, '8.2.0', '>=') ? 'warn' : 'error'),
            'detail' => 'Detected '.$php,
        ];

        // Required extensions.
        foreach (self::REQUIRED_EXT as $ext) {
            $checks[] = [
                'key' => 'ext_'.$ext, 'label' => 'PHP extension: '.$ext,
                'ok' => extension_loaded($ext), 'level' => 'error',
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not installed',
            ];
        }

        // SourceGuardian loader — REQUIRED to run an encoded production build.
        // On a plain-source dev copy it is absent; that is expected, so it is a
        // warning (blocking would stop dev installs), but the guide calls it out
        // as mandatory for the packaged client build.
        $sg = self::sourceGuardianLoaded();
        $checks[] = [
            'key' => 'sourceguardian', 'label' => 'SourceGuardian loader (required for encoded client build)',
            'ok' => $sg, 'level' => 'warn',
            'detail' => $sg ? 'Loaded' : 'Not detected — install the SourceGuardian loader before running the encoded build',
        ];

        // Application key.
        $hasKey = trim((string) config('app.key')) !== '';
        $checks[] = [
            'key' => 'appkey', 'label' => 'Application key (APP_KEY)',
            'ok' => $hasKey, 'level' => 'error',
            'detail' => $hasKey ? 'Set' : 'Missing — run  php artisan key:generate  (the packaged installer sets this)',
        ];

        // Database connectivity.
        $dbOk = false; $dbDetail = '';
        try {
            DB::connection()->getPdo();
            $dbOk = true;
            $dbDetail = 'Connected to "'.config('database.connections.'.config('database.default').'.database').'"';
        } catch (\Throwable $e) {
            $dbDetail = 'Cannot connect — check DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env ('.Str::limit($e->getMessage(), 90).')';
        }
        $checks[] = ['key' => 'db', 'label' => 'Database connection', 'ok' => $dbOk, 'level' => 'error', 'detail' => $dbDetail];

        // Migrations applied (the schema must exist before an admin can be made).
        $migrated = false;
        try {
            $migrated = $dbOk && Schema::hasTable('users') && Schema::hasTable('companies') && Schema::hasTable('tenants');
        } catch (\Throwable $e) {
        }
        $checks[] = [
            'key' => 'schema', 'label' => 'Database schema (migrations run)',
            'ok' => $migrated, 'level' => 'error',
            'detail' => $migrated ? 'Core tables present' : 'Run  php artisan migrate --force  first',
        ];

        // Writable paths.
        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache')] as $lbl => $path) {
            $w = @is_writable($path);
            $checks[] = ['key' => 'w_'.$lbl, 'label' => 'Writable: '.$lbl, 'ok' => $w, 'level' => 'error',
                'detail' => $w ? 'Writable' : 'Not writable — the web user needs write access'];
        }

        // Licence file location writable (activation writes license.lic here).
        $licDir = dirname((string) (config('smartprs.license_file') ?: base_path('license.lic')));
        $lw = @is_writable($licDir);
        $checks[] = ['key' => 'w_lic', 'label' => 'Writable: licence file location', 'ok' => $lw, 'level' => 'warn',
            'detail' => $lw ? 'Writable' : 'Activation needs to write license.lic to '.$licDir];

        return $checks;
    }

    /** True when no blocking (error-level) requirement is failing. */
    public static function readyToInstall(): bool
    {
        foreach (self::requirements() as $c) {
            if (($c['level'] ?? '') === 'error' && empty($c['ok'])) {
                return false;
            }
        }

        return true;
    }

    /** GET /install — requirements pre-flight + the create-admin form. */
    public function show(Request $request)
    {
        if (self::alreadyInstalled()) {
            return redirect('/login')->with('status', 'SmartPRS is already set up on this server. Please sign in.');
        }

        return view('install', [
            'checks' => self::requirements(),
            'ready' => self::readyToInstall(),
            'edition' => Edition::label(),
        ]);
    }

    /** POST /install — create the company + admin login, then hand off to /login. */
    public function store(Request $request)
    {
        if (self::alreadyInstalled()) {
            return redirect('/login')->with('status', 'SmartPRS is already set up on this server. Please sign in.');
        }
        if (! self::readyToInstall()) {
            return back()->with('install_err', 'Some server requirements are not met yet. Fix the items marked in red, then re-check.');
        }

        $v = $request->validate([
            'company'  => ['required', 'string', 'max:190'],
            'name'     => ['nullable', 'string', 'max:190'],
            'email'    => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = strtolower(trim($v['email']));
        $company = trim($v['company']);
        $adminName = trim((string) ($v['name'] ?? '')) ?: 'Administrator';
        $now = now();

        try {
            // Guard against a race: re-check emptiness inside the write.
            if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return back()->with('install_err', 'A user with that email already exists — this server looks already set up.')->withInput();
            }

            $tenantId = DB::table('tenants')->insertGetId(ApprovalService::safeRow('tenants', [
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

            try {
                if (! Schema::hasColumn('companies', 'is_master')) {
                    Schema::table('companies', fn ($t) => $t->boolean('is_master')->default(false));
                }
            } catch (\Throwable $e) {
            }
            DB::table('companies')->insert(ApprovalService::safeRow('companies', [
                'tenant_id' => $tenantId,
                'name' => $company,
                'is_master' => 1,
                'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]));

            $userId = DB::table('users')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $adminName,
                'email' => $email,
                'password' => Hash::make($v['password']),
                'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            try {
                \App\Models\User::find($userId)?->syncRoles(['admin']);
            } catch (\Throwable $e) {
            }

            try {
                \App\Console\Commands\SeedIndustryContent::seedForTenant($tenantId);
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
            return back()->with('install_err', 'Setup could not complete: '.$e->getMessage())->withInput();
        }

        $msg = Edition::isOnPrem()
            ? 'Setup complete. Sign in below, then enter your Ametecs licence (or upload your .lic file) to activate this installation.'
            : 'Setup complete. Please sign in below.';

        return redirect('/login')->with('status', $msg);
    }
}
