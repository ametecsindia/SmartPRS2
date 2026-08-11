<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment model
    |--------------------------------------------------------------------------
    | 'saas'   => multi-tenant hosted (company scoping enforced globally)
    | 'onprem' => single-tenant installed perpetual licence
    */
    'deployment' => env('SMARTPRS_DEPLOYMENT', 'saas'),

    /*
    |--------------------------------------------------------------------------
    | Edition (rev 103 — module licensing levels)
    |--------------------------------------------------------------------------
    | 'saas' => hosted, full app + SaaS Platform module
    | 'l1'   => on-prem Core HR   | 'l2' => + Advanced | 'l3' => + DNA modules
    | Read via App\Services\Edition (config-first, so config:cache is safe).
    */
    'edition' => env('SMARTPRS_EDITION', 'saas'),

    /*
    |--------------------------------------------------------------------------
    | Team demo PIN (rev 105)
    |--------------------------------------------------------------------------
    | Unlocks the UNRESTRICTED personal demos at /teamdemo, /app1, /app2,
    | /app3 (no demo write-guard, no hidden screens). Known to the Ametecs
    | sales team only; the public /demo stays OTP-gated and restricted.
    */
    'team_pin' => env('SMARTPRS_TEAM_PIN', 'ametecs'),

    /*
    |--------------------------------------------------------------------------
    | Version & update channel (rev 107 — Update & Licensing system)
    |--------------------------------------------------------------------------
    | 'version'    bumped on every release (BUILD-RELEASE.bat reads it).
    | 'update_url' the platform update server every on-prem client calls;
    |              baked-in default per the SRS, overridable for testing.
    | 'licence_enforce' lets a dev/demo install skip the activation gate.
    */
    'version' => '2026.6.1',
    'update_url' => env('SMARTPRS_UPDATE_URL', 'https://smartprs.com/update'),
    'licence_enforce' => env('SMARTPRS_LICENCE_ENFORCE', true),

    // 10 Aug 2026 (Ejaz, per activation flowchart) — OFFLINE .lic activation is
    // DISABLED by default: initial activation must be ONLINE so the server captures
    // the machine fingerprint. When false, a .lic is routed through the online
    // server flow (its embedded key is used); with no internet the activation stops.
    // Set SMARTPRS_OFFLINE_LIC=true only for genuinely air-gapped installs.
    'offline_lic' => env('SMARTPRS_OFFLINE_LIC', false),

    /*
    | Shared secret for OFFLINE self-contained License Codes (rev146). The
    | Super Admin signs a key with it; the client verifies the signature
    | locally — no server needed. It MUST be identical on the Super Admin and
    | on every client install.
    |
    | rev166 SECURITY: no working default is shipped. The signing/verifying
    | secret has no usable fallback in production — see LicenseService::offlineSecret(),
    | which refuses to sign or verify a License Code unless SMARTPRS_LICENCE_SECRET
    | is set, so codes can never be forged with a default lifted from the source.
    | The check is at point-of-use (NOT config load) so setup, migrations and any
    | non-licensing operation still run when the secret is absent.
    | Generate a strong random value (e.g. `php artisan key:generate --show`) and
    | set the SAME value on the licensing server and in each client's .env.
    */
    'licence_secret' => env('SMARTPRS_LICENCE_SECRET'),

    // rev167 — per-install account email a License Code is matched against.
    // Set per client at build/install (blank = no email lock). Compared to the
    // 'a' claim inside offline License Codes — see LicenseService offline keys.
    'licence_email' => env('SMARTPRS_LICENCE_EMAIL'),

    // AS-DL — where the RSA-signed offline .lic file lives (blank = license.lic in
    // the app root). Verified locally by App\Services\LicenseFile; the matching
    // private key stays on the /super server only.
    'license_file' => env('SMARTPRS_LICENSE_FILE'),

    'default_company_code' => env('SMARTPRS_DEFAULT_COMPANY_CODE', 'DEMO'),

    /*
    |--------------------------------------------------------------------------
    | Roles (locked — see CONTEXT-HANDOFF.md)
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'super_admin' => 'Super Admin',   // SaaS owner — cross-tenant
        'admin'       => 'Admin',          // company owner/admin
        'hr_manager'  => 'HR Manager',
        'field_agent' => 'Field Agent',
        'employee'    => 'Employee',
    ],

    /*
    |--------------------------------------------------------------------------
    | ZKTeco biometric devices
    |--------------------------------------------------------------------------
    */
    'zkteco' => [
        'default_port' => (int) env('ZKTECO_DEFAULT_PORT', 4370),
        'sync_enabled' => (bool) env('ZKTECO_SYNC_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | eTimeOffice cloud attendance (api.etimeoffice.com)
    |--------------------------------------------------------------------------
    | HTTP Basic auth where the *username* is "CorpID:User:Password:true" and the
    | *password* is the password. Punches are pulled by emp_code and written into
    | attendance_logs. Credentials live in .env (never shipped to clients).
    */
    'etimeoffice' => [
        'enabled'  => (bool) env('ETIMEOFFICE_ENABLED', false),
        'base_url' => env('ETIMEOFFICE_BASE_URL', 'https://api.etimeoffice.com/api'),
        'endpoint' => env('ETIMEOFFICE_ENDPOINT', 'DownloadPunchDataMCID'),
        'corp_id'  => env('ETIMEOFFICE_CORP_ID'),
        'username' => env('ETIMEOFFICE_USERNAME'),
        'password' => env('ETIMEOFFICE_PASSWORD'),
        'empcode'  => env('ETIMEOFFICE_EMPCODE', 'ALL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    | All monetary values use decimal columns + integer-safe math. Never float.
    */
    'currency' => 'INR',
    'money_scale' => 2,
];
