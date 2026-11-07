# QRONEX attendance module

QRONEX's QR attendance as a reusable Laravel module, embedded in **SmartPRS 2** and **SmartEPT Admin**.
This folder (in the QRONEX repo) is the single source; each app carries a copy in `packages/qronex-module`.

## What it does

The same features and rules as qronex.app:

- offices, gates and rotating-QR entrance displays;
- phone activation codes and phone approval with the ROVEXY match code, plus office/gate binding when approving;
- one phone per employee, with more allowed only as an admin exception;
- scan scope (bound gate only, bound office, or any office);
- geofence, GPS accuracy, mock-location, QR-expiry and replay checks;
- remote attendance (WFH, field duty and so on);
- missed-punch corrections;
- the attendance ledger and CSV export.

Every verified punch is written into the host app's own attendance, so payroll, late marks and reports treat it like a biometric punch:

| Host | Writes to | Through |
|---|---|---|
| SmartPRS | `attendance_logs` (source `qronex`, external_id `qx:<ref>`) | `PunchIngestService::ingest` (late-arrival mails, dedupe) |
| SmartEPT | `biometric_logs` (external_id `qx:<ref>`, metadata `method=QR`) | `BiometricController` merge → gate → derive |

Writes are idempotent. A failed write is kept and retried by `php artisan qronex:sync`, which runs hourly and also re-syncs employees.

## Data, tenants, on/off

- **Tables:** all `qx_*`. There are no foreign keys into host tables, so host schemas are untouched.
- **Tenant:** the PRS2 tenant / EPT company.
- **Employees:** the host is the master. `qx_employees` mirrors it, keyed by the host employee id, so a code rename follows. Leavers are deactivated and their phones revoked.
- **Enable/Disable:** per tenant, under *QR Attendance → Module & policy*. It is off by default.
  - When off, the phone API returns 403 `module_disabled`, displays stop showing codes, and nothing is written to the host. Data is kept.
  - SmartEPT also requires the licence feature `qr_attendance`, set in Central → licence → *QR Attendance: Yes*.
- **Times:** stored in the host's `app.timezone`. Never call `->utc()` before saving or querying.

## Who can do what

The adapter maps host roles to four abilities: `view`, `approve`, `manage` and `configure`.

| Ability | SmartPRS roles | SmartEPT roles |
|---|---|---|
| configure (enable/disable, policy, extra phones) | super_admin, admin | SUPER_ADMIN, COMPANY_ADMIN |
| manage (offices, gates, binding, activation codes) | + hr_manager, hr | + HR_ADMIN, BRANCH_ADMIN |
| approve (phones, remote, corrections) | + manager | + MANAGER, TEAM_LEADER |
| view | + accountant | + AUDITOR, COMPLIANCE_OFFICER |

## Addresses

| What | URL |
|---|---|
| Admin screens | `/qronex` |
| Phone API (same contract as qronex.app `/api/v1`) | `/api/qronex/v1/*` |
| Entrance display | `/qronex/display/{gate}?key=…` |
| EPT console sign-in | `GET /api/qronex/sso-link` (Sanctum) → one-time signed link → `/qronex` |

The employee app **1.0.1+** calls `/api/qronex/v1`, which works on qronex.app, SmartPRS and SmartEPT. The activation email gives the company as `https://<host>/<company code>`; typing that into the app's Company field points the app at that server.

## Install in an app

1. Copy this folder to `<app>/packages/qronex-module`.
2. In `composer.json` → `autoload.psr-4`, add `"Ametecs\\Qronex\\": "packages/qronex-module/src/"`, then run `composer dump-autoload`.
3. Add `Ametecs\Qronex\QronexServiceProvider::class` to `bootstrap/providers.php`.
4. Write an adapter implementing `Ametecs\Qronex\Contracts\Host` (see `app/Qronex/Prs2Host.php` or `EptHost.php`).
5. Point `config/qronex.php` → `host` at the adapter.
6. Run `php artisan migrate`.

## Update

Change the code here, run the QRONEX tests, then copy the folder into both apps and run each app's tests:

- SmartPRS: `tests/Feature/QronexModuleTest.php`
- SmartEPT: `tests/Feature/QronexModuleTest.php`

Deploy with `migrate` + `view:clear`.

## Not included (QRONEX-only SaaS parts)

Plans, billing, Razorpay, invoices, super-admin console, the public REST API with webhooks, and SMS. Inside PRS2/EPT the host app owns billing, and punches go straight into its attendance.
