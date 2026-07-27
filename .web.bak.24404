<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\SalaryRunController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

// rev 103: on-premise editions (SmartPRS-L1/L2/L3 — SMARTPRS_EDITION in .env).
// A client's own server has no SaaS marketing surface: the root goes straight
// to the app, and signup/demo/quote/lead routes are simply not registered.
$spOnPrem = \App\Services\Edition::isOnPrem();

// Public marketing landing page (SaaS only).
// rev 109: a tenant's CUSTOM DOMAIN never shows the marketing site — its root
// goes straight to their branded login.
if ($spOnPrem) {
    Route::get('/', function () { return redirect('/app'); })->name('landing');
} else {
    Route::get('/', function (Illuminate\Http\Request $r) {
        if (App\Http\Controllers\AuthController::tenantByHost($r->getHost())) {
            return redirect('/login');
        }

        return app()->call([app(LandingController::class), 'show']);
    })->name('landing');
}

// rev 89: public demo-request (lead) form on the landing page. Throttled.
// rev 118 (Ejaz bug: "sometimes the form errors and doesn't submit"): a landing
// page left open past the session lifetime had a STALE CSRF token → 419, shown
// to the visitor as the generic error. The form is public and protected by a
// honeypot + throttle, so CSRF is exempted here (same pattern as the webhooks)
// — the demo request now goes through every time, fresh tab or hours-old tab.
if (! $spOnPrem) {
    Route::post('/lead', [App\Http\Controllers\LeadController::class, 'store'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->middleware('throttle:20,1')->name('lead.store');

    // rev 111: public legal/policy pages (Razorpay LIVE + DPDP/IT-Rules compliance).
    // whereIn-constrained — matches ONLY the 9 known slugs, nothing else at root.
    Route::get('/{policy_slug}', [App\Http\Controllers\PolicyController::class, 'show'])
        ->whereIn('policy_slug', array_keys(App\Http\Controllers\PolicyController::PAGES))
        ->name('policy.show');
}

// Public offer-acceptance page (candidate, no login — secured by the token).
Route::get('/offer/{token}', [App\Http\Controllers\OfferAcceptController::class, 'show'])->name('offer.show');
Route::post('/offer/{token}/accept', [App\Http\Controllers\OfferAcceptController::class, 'accept'])->name('offer.accept');

// Public NDA / confidentiality e-sign page (agent, no login — secured by the token).
Route::get('/nda/{token}', [App\Http\Controllers\NdaSignController::class, 'show'])->name('nda.show');
Route::post('/nda/{token}/sign', [App\Http\Controllers\NdaSignController::class, 'sign'])->name('nda.sign')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

// rev 119: MOBILE APP device gate — public API consumed by the hybrid app
// BEFORE login (no session/cookie → CSRF-exempt, like the webhooks). Tenant is
// resolved by host (custom domain) or the workspace slug the app sends.
Route::post('/api/mobile/register', [App\Http\Controllers\MobileDeviceController::class, 'register'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:60,1')->name('api.mobile.register');
Route::post('/api/mobile/status', [App\Http\Controllers\MobileDeviceController::class, 'status'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:120,1')->name('api.mobile.status');
Route::post('/api/mobile/push-token', [App\Http\Controllers\MobileDeviceController::class, 'pushToken'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:30,1')->name('api.mobile.push');

// rev173d — PUBLIC sample RBI audit report (marketing site: landing RBI section + FAQ).
// Illustrative data only, generic SmartPRS branding, rate-limited.
Route::get('/sample-audit-report.pdf', [App\Http\Controllers\ComplianceController::class, 'publicSampleAuditPdf'])
    ->middleware('throttle:20,1')->name('public.sample.audit');

// Public off-roll agent email verification (no login — secured by the token).
Route::get('/agent/verify/{token}', [App\Http\Controllers\OffrollAgentController::class, 'verifyEmail'])->name('agent.verify');

// Public off-roll agent LIVE EARNINGS page (no login — token-secured, read-only).
Route::get('/agent/earnings/{token}', [App\Http\Controllers\OffrollAgentController::class, 'publicEarnings'])->name('agent.earnings');

// Public transfer-order acknowledgement (employee, no login — token-secured).
Route::get('/transfer/accept/{token}', [App\Http\Controllers\TransferController::class, 'accept'])->name('transfer.accept');

// PUBLIC self-serve signup + Razorpay checkout (amounts computed server-side;
// payment verified by HMAC signature; provisioning idempotent per signup).
// rev 103: SaaS only — an on-prem licence box must never provision workspaces.
if (! $spOnPrem) {
    Route::get('/signup', [App\Http\Controllers\SignupController::class, 'show'])->name('signup.show');
    Route::post('/signup/order', [App\Http\Controllers\SignupController::class, 'createOrder'])->name('signup.order');
    Route::post('/signup/complete', [App\Http\Controllers\SignupController::class, 'complete'])->name('signup.complete');

    // rev 97: PUBLIC LIVE DEMO — lead-capture form → auto-login to the shared demo
    // workspace with the guided tour. Demo resets every 3 hours (routes/console.php).
    Route::get('/demo', [App\Http\Controllers\DemoAccessController::class, 'show'])->name('demo.show');
    // rev185 (Ejaz): request → auto passkey (email + WhatsApp) → timed entry.
    Route::post('/demo/request', [App\Http\Controllers\DemoAccessController::class, 'requestPin'])->middleware('throttle:8,1')->name('demo.request');
    Route::post('/demo/start', [App\Http\Controllers\DemoAccessController::class, 'start'])->middleware('throttle:15,1')->name('demo.start');

    // rev 104: EDITION DEMONSTRATIONS — /app1 (L1) /app2 (L2) /app3 (L3).
    // One-click sales-team entries into the demo workspace under that licence
    // (session edition override; see Edition::current). /demo stays OTP-gated.
    Route::get('/app{n}', [App\Http\Controllers\DemoAccessController::class, 'editionShow'])
        ->whereIn('n', ['1', '2', '3'])->name('demo.edition');
    Route::post('/app{n}/start', [App\Http\Controllers\DemoAccessController::class, 'editionStart'])
        ->whereIn('n', ['1', '2', '3'])->middleware('throttle:20,1')->name('demo.edition.start');

    // rev 105: /teamdemo — the COMPLETE platform, team-driven, unrestricted.
    Route::get('/teamdemo', fn () => app(App\Http\Controllers\DemoAccessController::class)->editionShow('full'))->name('demo.team');
    Route::post('/teamdemo/start', fn (Illuminate\Http\Request $r) => app(App\Http\Controllers\DemoAccessController::class)->editionStart($r, 'full'))
        ->middleware('throttle:20,1')->name('demo.team.start');

    // rev 96: quotation flow — "Send a Quotation" + public pay link.
    Route::post('/signup/quote', [App\Http\Controllers\SignupController::class, 'quote'])->middleware('throttle:10,1')->name('signup.quote');
    // rev 112: live coupon validation on the signup page.
    Route::post('/signup/coupon-check', [App\Http\Controllers\SignupController::class, 'couponCheck'])->middleware('throttle:20,1')->name('signup.coupon');
    // rev 113: the cart catches the email → exclusive offer auto-applies.
    Route::post('/signup/exclusive-check', [App\Http\Controllers\SignupController::class, 'exclusiveCheck'])->middleware('throttle:20,1')->name('signup.exclusive');
    Route::get('/quote/{token}', [App\Http\Controllers\SignupController::class, 'showQuote'])->name('quote.show');
    Route::get('/quote/{token}/pdf', [App\Http\Controllers\SignupController::class, 'quotePdf'])->name('quote.pdf');
    Route::post('/quote/{token}/order', [App\Http\Controllers\SignupController::class, 'quoteOrder'])->middleware('throttle:20,1')->name('quote.order');
    Route::post('/quote/{token}/complete', [App\Http\Controllers\SignupController::class, 'quoteComplete'])->name('quote.complete');

    // Razorpay payment webhook (public, server-to-server). CSRF-exempt at the route
    // level — the source tree has no bootstrap/app.php to register exceptions in.
    // Security is the X-Razorpay-Signature HMAC check inside the controller.
    Route::post('/webhooks/razorpay', [App\Http\Controllers\BillingController::class, 'razorpayWebhook'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->name('webhooks.razorpay');
}

// rev 107: THE UPDATE SERVER + licence authority (SaaS platform only; SRS FR-5).
// Public server-to-server endpoints — CSRF-exempt, throttled, key-validated
// inside the controller. On-prem installations call these from anywhere.
if (! $spOnPrem) {
    Route::post('/update/activate', [App\Http\Controllers\UpdateServerController::class, 'activate'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->middleware('throttle:10,1')->name('update.activate');
    Route::post('/update/heartbeat', [App\Http\Controllers\UpdateServerController::class, 'heartbeat'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->middleware('throttle:30,1')->name('update.heartbeat');
    Route::post('/update/check', [App\Http\Controllers\UpdateServerController::class, 'check'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->middleware('throttle:30,1')->name('update.check');
    Route::get('/update/download/{version}', [App\Http\Controllers\UpdateServerController::class, 'download'])
        ->middleware('throttle:10,1')->name('update.download');

    // rev 107b: PUBLIC licence-invoice pay page (token-secured, Razorpay).
    Route::get('/licence/{token}', [App\Http\Controllers\OnpremClientController::class, 'payShow'])->name('licence.pay');
    Route::get('/licence/{token}/pdf', [App\Http\Controllers\OnpremClientController::class, 'invoicePdf'])->name('licence.pdf');
    Route::post('/licence/{token}/order', [App\Http\Controllers\OnpremClientController::class, 'payOrder'])->middleware('throttle:20,1')->name('licence.order');
    Route::post('/licence/{token}/complete', [App\Http\Controllers\OnpremClientController::class, 'payComplete'])->name('licence.complete');
}

// rev 94: Interakt WhatsApp delivery/read status webhook (public; CSRF-exempt).
// rev 103: needs the Volume Hiring module — registered for SaaS and L3 only.
if (! $spOnPrem || \App\Services\Edition::level() >= 3) {
    Route::post('/webhooks/interakt', [App\Http\Controllers\RecruitMessagingController::class, 'interaktWebhook'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->name('webhooks.interakt');
}

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1'); // rev165 SECURITY: cap brute-force / credential-stuffing (5/min per IP)
    // Dedicated platform Super Admin login portal.
    Route::get('/super', [AuthController::class, 'showSuper'])->name('login.super');
    // Branded per-company login portal: /c/{company-slug}
    Route::get('/c/{slug}', [AuthController::class, 'showBranded'])->name('login.branded');
    // Forgot / reset password (public).
    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendReset'])->name('password.email')->middleware('throttle:6,1');
    Route::get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'doReset'])->name('password.update')->middleware('throttle:6,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// rev 132: DYNAMIC WEB/PWA app icon + manifest — built from the logged-in
// tenant's uploaded branding logo (falls back to the SmartPRS icon). Public so
// the browser can fetch them while adding to the home screen; they read the
// session to resolve the company.
Route::get('/app-icon.png', [App\Http\Controllers\AppIconController::class, 'icon'])->name('appicon');
Route::get('/apple-touch-icon.png', [App\Http\Controllers\AppIconController::class, 'appleTouchIcon']);
Route::get('/apple-touch-icon-precomposed.png', [App\Http\Controllers\AppIconController::class, 'appleTouchIcon']);
Route::get('/app.webmanifest', [App\Http\Controllers\AppIconController::class, 'manifest'])->name('webmanifest');

Route::middleware(['auth', App\Http\Middleware\LicenseGate::class, App\Http\Middleware\EnsureSubscriptionActive::class, App\Http\Middleware\DemoWriteGuard::class, App\Http\Middleware\EditionGuard::class])->group(function () {
    // rev 107: ON-PREM activation + Administration → Updates (admin-guarded
    // inside the controllers; LicenseGate forces unactivated installs here).
    Route::get('/app/activate', [App\Http\Controllers\ClientUpdateController::class, 'activateShow'])->name('licence.activate');
    Route::post('/app/activate', [App\Http\Controllers\ClientUpdateController::class, 'activatePost'])->middleware('throttle:10,1');
    Route::get('/app/updates/status', [App\Http\Controllers\ClientUpdateController::class, 'status']);
    Route::post('/app/updates/check', [App\Http\Controllers\ClientUpdateController::class, 'check'])->middleware('throttle:10,1');
    Route::post('/app/updates/apply', [App\Http\Controllers\ClientUpdateController::class, 'apply'])->middleware('throttle:5,10');

    // rev 107: SaaS panel — On-Prem Clients (sales desk) + Releases (updates).
    Route::get('/admin/onprem', [App\Http\Controllers\OnpremClientController::class, 'index'])->name('admin.onprem');
    Route::post('/admin/onprem', [App\Http\Controllers\OnpremClientController::class, 'save'])->name('admin.onprem.save');
    Route::post('/admin/onprem/{id}/payment', [App\Http\Controllers\OnpremClientController::class, 'payment'])->name('admin.onprem.payment');
    Route::post('/admin/onprem/{id}/invoice', [App\Http\Controllers\OnpremClientController::class, 'invoice'])->name('admin.onprem.invoice');
    Route::post('/admin/onprem/{id}/partial', [App\Http\Controllers\OnpremClientController::class, 'partialToggle'])->name('admin.onprem.partial');
    Route::post('/admin/onprem/{id}/key', [App\Http\Controllers\OnpremClientController::class, 'issueKey'])->name('admin.onprem.key');
    Route::post('/admin/onprem/{id}/offline-key', [App\Http\Controllers\OnpremClientController::class, 'offlineKey'])->name('admin.onprem.offlinekey');
    Route::post('/admin/onprem/{id}/renew', [App\Http\Controllers\OnpremClientController::class, 'renewAmc'])->name('admin.onprem.renew');
    Route::post('/admin/onprem/{id}/deactivate', [App\Http\Controllers\OnpremClientController::class, 'deactivate'])->name('admin.onprem.deactivate');
    Route::post('/admin/onprem/{id}/revoke', [App\Http\Controllers\OnpremClientController::class, 'revoke'])->name('admin.onprem.revoke');
    Route::post('/admin/onprem/{id}/delete', [App\Http\Controllers\OnpremClientController::class, 'destroy'])->name('admin.onprem.delete');
    Route::get('/admin/releases', [App\Http\Controllers\ReleaseController::class, 'index'])->name('admin.releases');
    Route::post('/admin/releases', [App\Http\Controllers\ReleaseController::class, 'upload'])->name('admin.releases.upload');
    Route::post('/admin/releases/{id}/apply', [App\Http\Controllers\ReleaseController::class, 'applyPlatform'])->name('admin.releases.apply');
    Route::post('/admin/releases/{id}/publish', [App\Http\Controllers\ReleaseController::class, 'publish'])->name('admin.releases.publish');

    // Super-admin admin panel (landing CMS + platform staff).
    Route::get('/admin/landing', [LandingController::class, 'editor'])->name('landing.editor');
    Route::post('/admin/landing', [LandingController::class, 'save'])->name('landing.save');
    Route::post('/admin/landing/reset', [LandingController::class, 'reset'])->name('landing.reset');   // rev173d
    // rev 92: WhatsApp template registry (platform + tenant scoped inside the controller).
    Route::get('/app/wa-templates', [App\Http\Controllers\WaTemplateController::class, 'index']);
    Route::get('/app/wa-templates/export', [App\Http\Controllers\WaTemplateController::class, 'export']);
    Route::post('/app/wa-templates', [App\Http\Controllers\WaTemplateController::class, 'save']);
    Route::post('/app/wa-templates/{id}/delete', [App\Http\Controllers\WaTemplateController::class, 'destroy']);
    Route::post('/app/wa-templates/{id}/status', [App\Http\Controllers\WaTemplateController::class, 'setStatus']);
    Route::post('/app/wa-templates/{id}/test', [App\Http\Controllers\WaTemplateController::class, 'testSend']);

    Route::get('/admin/quotations', [App\Http\Controllers\SignupController::class, 'quotations'])->name('admin.quotations');
    // rev 186 (Ejaz): manual payment entry (paid / partial / due-credit) →
    // workspace provisioned immediately; also records balance instalments.
    Route::post('/admin/quotations/{id}/payment', [App\Http\Controllers\SignupController::class, 'adminPayment'])->whereNumber('id')->name('admin.quotations.pay');
    Route::get('/admin/leads', [App\Http\Controllers\LeadController::class, 'index'])->name('admin.leads');
    Route::post('/admin/leads/{id}', [App\Http\Controllers\LeadController::class, 'update'])->name('admin.leads.update');
    // rev 112: discount coupons (marketing campaigns).
    Route::get('/admin/coupons', [App\Http\Controllers\CouponController::class, 'index'])->name('admin.coupons');
    Route::post('/admin/coupons', [App\Http\Controllers\CouponController::class, 'save'])->name('admin.coupons.save');
    Route::post('/admin/coupons/exclusive', [App\Http\Controllers\CouponController::class, 'sendExclusive'])->name('admin.coupons.exclusive');
    Route::post('/admin/coupons/{id}/toggle', [App\Http\Controllers\CouponController::class, 'toggle'])->name('admin.coupons.toggle');
    Route::post('/admin/coupons/{id}/delete', [App\Http\Controllers\CouponController::class, 'destroy'])->name('admin.coupons.delete');
    Route::get('/admin/staff', [StaffController::class, 'index'])->name('admin.staff');
    Route::post('/admin/staff', [StaffController::class, 'store'])->name('admin.staff.store');
    Route::put('/admin/staff/{user}', [StaffController::class, 'update'])->name('admin.staff.update');
    Route::delete('/admin/staff/{user}', [StaffController::class, 'destroy'])->name('admin.staff.destroy');

    // Full prototype engine (113 screens) — the primary UI.
    Route::get('/app/data', [App\Http\Controllers\AppDataController::class, 'bootstrap'])->name('app.data');
    Route::post('/app/employees', [App\Http\Controllers\AppDataController::class, 'storeEmployee'])->name('app.employees.store');
    Route::post('/app/employees/import', [App\Http\Controllers\AppDataController::class, 'importEmployees'])->name('app.employees.import');
    Route::post('/app/employees/bulk-delete', [App\Http\Controllers\AppDataController::class, 'bulkDeleteEmployees'])->name('app.employees.bulkdel');
    Route::post('/app/employees/{code}/status', [App\Http\Controllers\AppDataController::class, 'setEmployeeStatus'])->name('app.employees.status');   // rev183 Active/Inactive toggle
    Route::post('/app/employees/{code}/backup', [App\Http\Controllers\AppDataController::class, 'backupEmployee'])->name('app.employees.backup');   // rev183b backup to Old data
    Route::post('/app/employees/{code}/backup-cancel', [App\Http\Controllers\AppDataController::class, 'cancelBackup'])->name('app.employees.backupcancel');   // rev183d cancel grace
    Route::get('/app/employees/{code}/backup-file', [App\Http\Controllers\AppDataController::class, 'employeeBackupFile'])->name('app.employees.backupfile');
    Route::get('/app/employees/{code}/archive-detail', [App\Http\Controllers\AppDataController::class, 'archiveDetail'])->name('app.employees.archivedetail');
    Route::get('/app/employees/template', [App\Http\Controllers\AppDataController::class, 'employeeTemplate'])->name('app.employees.template');
    Route::get('/app/payslip/{code}/pdf', [App\Http\Controllers\AppDataController::class, 'payslipPdf'])->name('app.payslip.pdf');
    Route::get('/app/statutory/{type}/pdf', [App\Http\Controllers\AppDataController::class, 'statutoryPdf'])->name('app.statutory.pdf');
    Route::get('/app/kb', [App\Http\Controllers\KbController::class, 'index'])->name('app.kb');
    Route::post('/app/kb', [App\Http\Controllers\KbController::class, 'store'])->name('app.kb.store');
    Route::put('/app/kb/{id}', [App\Http\Controllers\KbController::class, 'update'])->name('app.kb.update');
    Route::delete('/app/kb/{id}', [App\Http\Controllers\KbController::class, 'destroy'])->name('app.kb.destroy');
    Route::post('/app/kb/reorder', [App\Http\Controllers\KbController::class, 'reorder'])->name('app.kb.reorder');
    // Code of Conduct — read + acknowledge.
    Route::get('/app/code-of-conduct', [App\Http\Controllers\CodeOfConductController::class, 'show'])->name('app.coc');
    Route::post('/app/code-of-conduct/ack', [App\Http\Controllers\CodeOfConductController::class, 'acknowledge'])->name('app.coc.ack');
    // rev 115: predefined Commission & Incentive SCHEMES (hierarchy-scoped
    // creation, claim autofill, caps, announcements). for-me BEFORE {id} routes.
    Route::get('/app/schemes', [App\Http\Controllers\SchemeController::class, 'index'])->name('app.schemes');
    Route::get('/app/schemes/for-me', [App\Http\Controllers\SchemeController::class, 'forMe'])->name('app.schemes.forme');
    Route::post('/app/schemes', [App\Http\Controllers\SchemeController::class, 'save'])->name('app.schemes.save');
    Route::post('/app/schemes/{id}/withdraw', [App\Http\Controllers\SchemeController::class, 'withdraw'])->name('app.schemes.withdraw');
    Route::post('/app/schemes/{id}/decide', [App\Http\Controllers\SchemeController::class, 'decide'])->name('app.schemes.decide');
    Route::post('/app/schemes/{id}/reopen', [App\Http\Controllers\SchemeController::class, 'reopen'])->name('app.schemes.reopen');
    // Commission / Incentive bulk calculation engine.
    Route::get('/app/incentive/template', [App\Http\Controllers\IncentiveController::class, 'template'])->name('app.incentive.template');
    Route::post('/app/incentive/calculate', [App\Http\Controllers\IncentiveController::class, 'calculate'])->name('app.incentive.calc');
    Route::post('/app/incentive/commit', [App\Http\Controllers\IncentiveController::class, 'commit'])->name('app.incentive.commit');
    // rev181 — BANK / NBFC PAYOUT & BILLING PACK: payout register + TDS 194H
    // annexure + GST service invoice, per bank per month (collection-industry USP).
    // rev181b — the Salary Calculation Guide: the engine's rules + demo FAQs,
    // documented INSIDE the app (all logged-in roles; read-only).
    Route::get('/app/calc-guide', [App\Http\Controllers\CalcGuideController::class, 'show'])->name('app.calcguide');
    Route::get('/app/bank-pack/data', [App\Http\Controllers\BankPackController::class, 'data'])->name('app.bankpack.data');
    Route::post('/app/bank-pack/invoice', [App\Http\Controllers\BankPackController::class, 'invoiceSave'])->name('app.bankpack.invoice');
    // Financial Year (set active FY + per-FY summary).
    Route::get('/app/fin-year', [App\Http\Controllers\FinYearController::class, 'index'])->name('app.finyear');
    Route::post('/app/fin-year/set', [App\Http\Controllers\FinYearController::class, 'setActive'])->name('app.finyear.set');
    // My Subscription (tenant admin): own plan/expiry/invoices + self-serve renew or upgrade via Razorpay.
    // rev 100: per-screen help guide (the ⓘ popup) — content in ScreenHelpController.
    Route::get('/app/help/{screen}', [App\Http\Controllers\ScreenHelpController::class, 'show'])->name('app.help');

    Route::get('/app/my-subscription', [App\Http\Controllers\TenantBillingController::class, 'index'])->name('app.mysub');
    Route::post('/app/my-subscription/quote', [App\Http\Controllers\TenantBillingController::class, 'quote'])->name('app.mysub.quote');
    Route::post('/app/my-subscription/renew/order', [App\Http\Controllers\TenantBillingController::class, 'renewOrder'])->name('app.mysub.renew.order');
    Route::post('/app/my-subscription/renew/complete', [App\Http\Controllers\TenantBillingController::class, 'renewComplete'])->name('app.mysub.renew.complete');
    Route::get('/app/my-subscription/invoice/{id}/pdf', [App\Http\Controllers\TenantBillingController::class, 'invoicePdf'])->whereNumber('id')->name('app.mysub.invoice.pdf');
    // Statutory rate settings (editable PF/ESI/PT/TDS/194H/no-PAN rates).
    Route::get('/app/settings', [App\Http\Controllers\SettingsController::class, 'index'])->name('app.settings');
    Route::post('/app/settings', [App\Http\Controllers\SettingsController::class, 'save'])->name('app.settings.save');
    // Bulk attendance upload with approval gate (rev 82).
    Route::get('/app/attendance-bulk', [App\Http\Controllers\AttendanceBulkController::class, 'index'])->name('app.attbulk');
    Route::get('/app/attendance-bulk/template', [App\Http\Controllers\AttendanceBulkController::class, 'template'])->name('app.attbulk.template');
    Route::post('/app/attendance-bulk/upload', [App\Http\Controllers\AttendanceBulkController::class, 'upload'])->name('app.attbulk.upload');
    Route::post('/app/attendance-bulk/{batch}/decide', [App\Http\Controllers\AttendanceBulkController::class, 'decide'])->name('app.attbulk.decide');
    Route::post('/app/attendance-bulk/row/{id}/delete', [App\Http\Controllers\AttendanceBulkController::class, 'deleteRow'])->whereNumber('id')->name('app.attbulk.row.del');
    Route::get('/app/attendance-report', [App\Http\Controllers\AttendanceReportController::class, 'report'])->name('app.attreport');
    Route::get('/app/attendance-report/pdf', [App\Http\Controllers\AttendanceReportController::class, 'reportPdf'])->name('app.attreport.pdf');
    // In-app self punch (web / mobile / desktop) with optional GPS.
    Route::get('/app/attendance/punch-status', [App\Http\Controllers\AttendanceReportController::class, 'punchStatus'])->name('app.punch.status');
    Route::post('/app/attendance/punch', [App\Http\Controllers\AttendanceReportController::class, 'punch'])->name('app.punch');
    // SMTP / email settings (+ send test) and company-wise branding.
    Route::get('/app/mail-settings', [App\Http\Controllers\ConfigController::class, 'mailIndex'])->name('app.mail');
    Route::post('/app/mail-settings', [App\Http\Controllers\ConfigController::class, 'mailSave'])->name('app.mail.save');
    Route::post('/app/mail-settings/test', [App\Http\Controllers\ConfigController::class, 'mailTest'])->name('app.mail.test');
    Route::get('/app/mail-log', [App\Http\Controllers\ConfigController::class, 'mailLog'])->name('app.mail.log');
    // Field-force compliance: DRA/PCC expiry alerts (screen + manual send).
    Route::get('/app/compliance-alerts', [App\Http\Controllers\ComplianceController::class, 'alerts'])->name('app.compliance.alerts');
    Route::post('/app/compliance-alerts/run', [App\Http\Controllers\ComplianceController::class, 'runNow'])->name('app.compliance.run');
    // Company user logins (Admin + HR) + self change-password.
    Route::get('/app/users', [App\Http\Controllers\UserController::class, 'list'])->name('app.users');
    Route::post('/app/users', [App\Http\Controllers\UserController::class, 'save'])->name('app.users.save');
    Route::post('/app/users/{id}/invite', [App\Http\Controllers\UserController::class, 'invite'])->name('app.users.invite');
    Route::post('/app/users/{id}/status', [App\Http\Controllers\UserController::class, 'setStatus'])->name('app.users.status');
    Route::post('/app/users/{id}/password', [App\Http\Controllers\UserController::class, 'setPassword'])->name('app.users.password');
    Route::post('/app/change-password', [App\Http\Controllers\AuthController::class, 'changePassword'])->name('app.change.password');
    // rev 170: mandatory create-your-password screen for freshly provisioned
    // admins (no current password needed — guarded by users.must_set_password).
    Route::get('/app/first-password', [App\Http\Controllers\AuthController::class, 'showFirstPassword'])->name('app.first.password');
    Route::post('/app/first-password', [App\Http\Controllers\AuthController::class, 'doFirstPassword'])->name('app.first.password.save')->middleware('throttle:10,1');
    // SaaS Platform (super admin): tenant provisioning + plans.
    Route::get('/app/saas/tenants', [App\Http\Controllers\SaasController::class, 'tenants'])->name('app.saas.tenants');
    Route::post('/app/saas/tenants', [App\Http\Controllers\SaasController::class, 'provisionTenant'])->name('app.saas.tenants.create');
    Route::post('/app/saas/tenants/{id}', [App\Http\Controllers\SaasController::class, 'updateTenant'])->name('app.saas.tenants.update');
    Route::post('/app/saas/tenants/{id}/status', [App\Http\Controllers\SaasController::class, 'tenantStatus'])->name('app.saas.tenants.status');
    Route::post('/app/saas/tenants/{id}/plan', [App\Http\Controllers\SaasController::class, 'tenantPlan'])->name('app.saas.tenants.plan');
    // rev185: Demo Requests register (passkey-gated live demo) — Super Admin only.
    Route::get('/app/saas/demo-requests', [App\Http\Controllers\DemoAccessController::class, 'saasList'])->name('app.saas.demoreq');
    Route::post('/app/saas/demo-requests/hours', [App\Http\Controllers\DemoAccessController::class, 'saasHours'])->name('app.saas.demoreq.hours');
    Route::post('/app/saas/demo-requests/{id}/resend', [App\Http\Controllers\DemoAccessController::class, 'saasResend'])->name('app.saas.demoreq.resend');
    Route::post('/app/saas/demo-requests/{id}/revoke', [App\Http\Controllers\DemoAccessController::class, 'saasRevoke'])->name('app.saas.demoreq.revoke');
    Route::get('/app/saas/plans', [App\Http\Controllers\SaasController::class, 'plans'])->name('app.saas.plans');
    Route::post('/app/saas/plans', [App\Http\Controllers\SaasController::class, 'savePlan'])->name('app.saas.plans.save');
    Route::post('/app/saas/plans/delete', [App\Http\Controllers\SaasController::class, 'deletePlan'])->name('app.saas.plans.delete');
    // SaaS billing (super admin): subscriptions, invoices, payments, gateways + Razorpay test mode.
    Route::get('/app/billing/subscriptions', [App\Http\Controllers\BillingController::class, 'subscriptions'])->name('app.billing.subs');
    Route::post('/app/billing/subscriptions', [App\Http\Controllers\BillingController::class, 'saveSubscription'])->name('app.billing.subs.save');
    Route::get('/app/billing/invoices', [App\Http\Controllers\BillingController::class, 'invoices'])->name('app.billing.invoices');
    Route::post('/app/billing/invoices/generate', [App\Http\Controllers\BillingController::class, 'generateInvoice'])->name('app.billing.invoices.gen');
    Route::post('/app/billing/invoices/pay', [App\Http\Controllers\BillingController::class, 'payInvoice'])->name('app.billing.invoices.pay');
    Route::get('/app/billing/payments', [App\Http\Controllers\BillingController::class, 'payments'])->name('app.billing.payments');
    Route::get('/app/billing/gateways', [App\Http\Controllers\BillingController::class, 'gateways'])->name('app.billing.gateways');
    Route::post('/app/billing/gateways', [App\Http\Controllers\BillingController::class, 'saveGateway'])->name('app.billing.gateways.save');
    Route::post('/app/billing/razorpay/order', [App\Http\Controllers\BillingController::class, 'razorpayOrder'])->name('app.billing.rzp.order');
    Route::post('/app/billing/razorpay/verify', [App\Http\Controllers\BillingController::class, 'razorpayVerify'])->name('app.billing.rzp.verify');
    Route::get('/app/billing/invoices/{id}/pdf', [App\Http\Controllers\BillingController::class, 'invoicePdf'])->whereNumber('id')->name('app.billing.invoices.pdf');
    Route::post('/app/billing/invoices/email', [App\Http\Controllers\BillingController::class, 'emailInvoiceNow'])->name('app.billing.invoices.email');
    Route::get('/app/branding', [App\Http\Controllers\ConfigController::class, 'brandingIndex'])->name('app.branding');
    Route::post('/app/branding', [App\Http\Controllers\ConfigController::class, 'brandingSave'])->name('app.branding.save');
    // rev 131: company logo UPLOAD (multipart) + serve (in-app <img>; PDFs read the local file).
    Route::post('/app/branding/logo', [App\Http\Controllers\ConfigController::class, 'brandingLogoUpload'])->name('app.branding.logo.upload');
    Route::post('/app/branding/app-logo', [App\Http\Controllers\ConfigController::class, 'appLogoUpload'])->name('app.branding.applogo');
    Route::get('/app/branding/logo/{companyId}', [App\Http\Controllers\ConfigController::class, 'brandingLogoServe'])->name('app.branding.logo');
    Route::get('/app/attendance-report/logs/{code}/{date}', [App\Http\Controllers\AttendanceReportController::class, 'logs'])->name('app.attreport.logs');
    Route::post('/app/attendance-report/rating', [App\Http\Controllers\AttendanceReportController::class, 'saveRating'])->name('app.attreport.rating');
    Route::get('/app/state', [App\Http\Controllers\AppStateController::class, 'show'])->name('app.state');
    Route::post('/app/state', [App\Http\Controllers\AppStateController::class, 'save'])->name('app.state.save');
    // Leave — real DB-backed with hierarchy approval (apply / approve-reject / inbox).
    Route::get('/app/leaves', [LeaveController::class, 'listLeaves'])->name('app.leaves');
    Route::get('/app/leaves/balances/{employee}', [LeaveController::class, 'balances'])->name('app.leaves.balances');
    Route::post('/app/leaves', [LeaveController::class, 'apply'])->name('app.leaves.apply');
    Route::post('/app/leaves/{id}/decide', [LeaveController::class, 'decide'])->name('app.leaves.decide');
    // Generic request + approval engine (expenses/advances/loans/commissions/etc).
    Route::get('/app/requests/{module}', [App\Http\Controllers\RequestController::class, 'list'])->name('app.requests');
    // rev 83 (Ejaz): commission bulk upload (Excel/CSV parsed client-side) —
    // every row lands as PENDING with the employee's own hierarchy approver.
    Route::post('/app/requests/commissions/bulk', [App\Http\Controllers\RequestController::class, 'bulkCommissions'])->name('app.requests.comm.bulk');
    // rev 84 (Ejaz, USP): edit-after-approval (diff-logged), manual lock, history trail.
    // rev 116: collection evidence — proof upload/view + Accounts confirmation stage.
    Route::post('/app/requests/commissions/proof-upload', [App\Http\Controllers\RequestController::class, 'proofUpload'])->name('app.requests.comm.proof');
    Route::get('/app/requests/commissions/{id}/proof', [App\Http\Controllers\RequestController::class, 'proofServe'])->name('app.requests.comm.proof.view');
    Route::post('/app/requests/commissions/{id}/accounts', [App\Http\Controllers\RequestController::class, 'accountsDecide'])->name('app.requests.comm.accounts');
    Route::post('/app/requests/commissions/{id}/update', [App\Http\Controllers\RequestController::class, 'updateCommission'])->name('app.requests.comm.update');
    Route::post('/app/requests/commissions/{id}/lock', [App\Http\Controllers\RequestController::class, 'lockCommission'])->name('app.requests.comm.lock');
    // rev181 — BOUNCE: cheque returned / settlement cancelled — auto-clawback
    // when money was already paid; auto-reject when it wasn't.
    Route::post('/app/requests/commissions/{id}/bounce', [App\Http\Controllers\RequestController::class, 'bounce'])->name('app.requests.comm.bounce');
    Route::get('/app/requests/commissions/{id}/history', [App\Http\Controllers\RequestController::class, 'commissionHistory'])->name('app.requests.comm.history');
    // rev 85 (Ejaz): disbursement — partial payments + per-employee ledger.
    Route::get('/app/requests/commissions/ledger', [App\Http\Controllers\RequestController::class, 'commissionLedger'])->name('app.requests.comm.ledger');
    Route::post('/app/requests/commissions/{id}/pay', [App\Http\Controllers\RequestController::class, 'payCommission'])->name('app.requests.comm.pay');
    // rev181c — printable payment voucher for each recorded separate payout.
    Route::get('/app/requests/commissions/payments/{pid}/voucher', [App\Http\Controllers\RequestController::class, 'commissionPaymentVoucher'])->whereNumber('pid')->name('app.requests.comm.voucher');
    Route::post('/app/requests/commissions/clean-orphans', [App\Http\Controllers\RequestController::class, 'cleanOrphanCommissions'])->name('app.requests.comm.orphans');
    // rev 87 (Ejaz): salary disbursements (partial, per employee+month) for the ledger.
    Route::post('/app/requests/salary-pay', [App\Http\Controllers\RequestController::class, 'salaryPay'])->name('app.requests.salary.pay');
    Route::post('/app/requests/{module}', [App\Http\Controllers\RequestController::class, 'apply'])->name('app.requests.apply');
    Route::post('/app/requests/{module}/{id}/decide', [App\Http\Controllers\RequestController::class, 'decide'])->name('app.requests.decide');
    // rev 83b (Ejaz): increment letter PDF + HR's one-click manual application.
    Route::get('/app/increments/{id}/letter', [App\Http\Controllers\IncrementController::class, 'letter'])->name('app.increments.letter');
    Route::post('/app/increments/{id}/apply', [App\Http\Controllers\IncrementController::class, 'applyCtc'])->name('app.increments.apply');
    // rev 84b (Ejaz): view/edit/delete — delete only BEFORE approval; edit until applied.
    Route::post('/app/increments/{id}/update', [App\Http\Controllers\IncrementController::class, 'update'])->name('app.increments.update');
    Route::post('/app/increments/{id}/delete', [App\Http\Controllers\IncrementController::class, 'destroy'])->name('app.increments.delete');
    // Unified Approvals Inbox (leaves + all request modules).
    Route::get('/app/approvals', [App\Http\Controllers\RequestController::class, 'inbox'])->name('app.approvals');
    // Transfer Order letter (PDF) — managers or the transferred employee.
    Route::get('/app/transfers/{id}/letter', [App\Http\Controllers\TransferController::class, 'letter'])->whereNumber('id')->name('app.transfers.letter');
    // Salary run approval — two-step (HR → Finance), individual + bulk.
    Route::get('/app/salary-runs', [App\Http\Controllers\SalaryApprovalController::class, 'listRuns'])->name('app.salaryruns');
    Route::post('/app/salary-runs/{id}/decide', [App\Http\Controllers\SalaryApprovalController::class, 'decide'])->name('app.salaryruns.decide');
    Route::post('/app/salary-runs/bulk', [App\Http\Controllers\SalaryApprovalController::class, 'bulk'])->name('app.salaryruns.bulk');
    Route::get('/app/salary-runs/{id}/sheet', [App\Http\Controllers\SalaryApprovalController::class, 'sheet'])->name('app.salaryruns.sheet');
    // Bank disbursement (NEFT) file for a run: preview (JSON) + download (CSV).
    Route::get('/app/salary-runs/{id}/bank-file/preview', [App\Http\Controllers\SalaryApprovalController::class, 'bankFilePreview'])->name('app.salaryruns.bankfile.preview');
    Route::get('/app/salary-runs/{id}/bank-file', [App\Http\Controllers\SalaryApprovalController::class, 'bankFile'])->name('app.salaryruns.bankfile');
    // Per-employee salary line lifecycle: hold/review/approve/disburse + employee e-sign acknowledgement.
    Route::post('/app/salary-lines/{id}/decide', [App\Http\Controllers\SalaryApprovalController::class, 'lineDecide'])->name('app.salarylines.decide');
    Route::post('/app/salary-lines/bulk', [App\Http\Controllers\SalaryApprovalController::class, 'lineBulk'])->name('app.salarylines.bulk');
    Route::post('/app/salary-lines/{id}/acknowledge', [App\Http\Controllers\SalaryApprovalController::class, 'acknowledge'])->name('app.salarylines.ack');
    // Signed salary voucher PDF (with e-sign acknowledgement block).
    Route::get('/app/salary-lines/{id}/voucher', [App\Http\Controllers\SalaryApprovalController::class, 'voucher'])->name('app.salarylines.voucher');
    // Generate Payroll — create a real draft run + payslips for a month (front of the flow).
    Route::get('/app/payroll/preview', [App\Http\Controllers\PayrollGenController::class, 'preview'])->name('app.payroll.preview');
    // LIVE SALARY — one employee's running-month earnings till today (strict hierarchy).
    Route::get('/app/live-salary/data', [App\Http\Controllers\PayrollGenController::class, 'liveSalary'])->name('app.livesalary');
    // rev178 — SALARY SIMULATOR: what-if payslip with every variable, computed by the real engine (all roles, read-only).
    Route::post('/app/salary-simulate', [App\Http\Controllers\PayrollGenController::class, 'simulate'])->name('app.salary.simulate');
    Route::post('/app/payroll/generate', [App\Http\Controllers\PayrollGenController::class, 'generate'])->name('app.payroll.generate');
    // Reports — preview + CSV export over existing data (employees/payslips/leaves/attendance).
    Route::get('/app/reports/preview', [App\Http\Controllers\ReportController::class, 'preview'])->name('app.reports.preview');
    Route::get('/app/reports/export', [App\Http\Controllers\ReportController::class, 'export'])->name('app.reports.export');
    // Computed statutory reports (gratuity / professional tax).
    Route::get('/app/statutory-report/{type}', [App\Http\Controllers\StatutoryController::class, 'report'])->name('app.statutory.report');
    // rev172 — per-agent RBI compliance audit report PDF (own-company branded).
    Route::get('/app/compliance/agent-audit/{code}/pdf', [App\Http\Controllers\ComplianceController::class, 'agentAuditPdf'])->name('app.compliance.agentaudit.pdf');
    // rev173b — BULK audit reports (?codes=EMP-1,EMP-2,…) from Statutory & Compliance → Audit Reports.
    Route::get('/app/compliance/agent-audit-bulk/pdf', [App\Http\Controllers\ComplianceController::class, 'agentAuditBulkPdf'])->name('app.compliance.agentaudit.bulk');
    // rev173c — client-branded SAMPLE audit report (illustrative data, ?company=<name>).
    Route::get('/app/compliance/agent-audit-sample/pdf', [App\Http\Controllers\ComplianceController::class, 'agentAuditSamplePdf'])->name('app.compliance.agentaudit.sample');
    // rev173d — PUBLIC sample audit report for the marketing site (registered here,
    // but reachable without login — see the standalone route below this group).
    // HR letter PDF (merge a template with the employee's data) + email it.
    Route::get('/app/letters/{id}/pdf', [App\Http\Controllers\LetterController::class, 'pdf'])->name('app.letters.pdf');
    Route::post('/app/letters/{id}/email', [App\Http\Controllers\LetterController::class, 'email'])->name('app.letters.email');
    Route::post('/app/letters/{id}/accept-link', [App\Http\Controllers\LetterController::class, 'sendAcceptLink'])->name('app.letters.acceptlink');
    // Computed read-only screens (live-salary / points-scores / test-reports / attrition / activity-logs).
    Route::get('/app/computed/{type}', [App\Http\Controllers\ComputedController::class, 'report'])->name('app.computed');
    // Employee ID card PDF + photo upload.
    Route::get('/app/idcard/{code}/pdf', [App\Http\Controllers\LetterController::class, 'idcard'])->name('app.idcard.pdf');
    Route::post('/app/idcard/{code}/photo', [App\Http\Controllers\LetterController::class, 'uploadPhoto'])->name('app.idcard.photo');
    Route::post('/app/my-photo', [App\Http\Controllers\LetterController::class, 'uploadMyPhoto'])->name('app.myphoto');
    Route::get('/app/my-photo', [App\Http\Controllers\LetterController::class, 'myPhoto'])->name('app.myphoto.get');
    Route::get('/app/emp-photo/{code}', [App\Http\Controllers\LetterController::class, 'servePhoto'])->name('app.empphoto');
    // Send Message / broadcast (real email delivery).
    Route::post('/app/send-message', [App\Http\Controllers\SendMessageController::class, 'send'])->name('app.sendmessage');
    // Points: auto-apply attendance-based rules into the ledger for a month.
    Route::post('/app/points/auto-apply', [App\Http\Controllers\PointsController::class, 'autoApply'])->name('app.points.autoapply');
    // Tests engine: question bank (admin) + take-test + auto-score (employee).
    Route::get('/app/tests/list', [App\Http\Controllers\TestController::class, 'list'])->name('app.tests.list');
    Route::post('/app/tests/questions', [App\Http\Controllers\TestController::class, 'saveQuestion'])->name('app.tests.qsave');
    Route::post('/app/tests/questions/{id}/delete', [App\Http\Controllers\TestController::class, 'deleteQuestion'])->whereNumber('id')->name('app.tests.qdel');
    Route::get('/app/tests/{id}/questions', [App\Http\Controllers\TestController::class, 'questions'])->whereNumber('id')->name('app.tests.questions');
    Route::get('/app/tests/{id}/take', [App\Http\Controllers\TestController::class, 'take'])->whereNumber('id')->name('app.tests.take');
    Route::post('/app/tests/{id}/submit', [App\Http\Controllers\TestController::class, 'submit'])->whereNumber('id')->name('app.tests.submit');
    // Biometric device: in-app sync (pull punches into attendance_logs).
    Route::post('/app/device/{id}/sync', [App\Http\Controllers\DeviceController::class, 'syncById'])->whereNumber('id')->name('app.device.sync');
    // rev157: Biometric Device Setup — frontend-managed cloud-attendance API config (eTimeOffice).
    Route::get('/app/biometric-config', [App\Http\Controllers\BiometricConfigController::class, 'show'])->name('app.bioconfig');
    Route::post('/app/biometric-config', [App\Http\Controllers\BiometricConfigController::class, 'save'])->name('app.bioconfig.save');
    Route::post('/app/biometric-config/test', [App\Http\Controllers\BiometricConfigController::class, 'test'])->name('app.bioconfig.test');
    Route::post('/app/biometric-config/sync', [App\Http\Controllers\BiometricConfigController::class, 'sync'])->name('app.bioconfig.sync');
    // rev161: Employee Document Tracker — real file uploads (list / upload / download / delete).
    Route::get('/app/documents-mgr', [App\Http\Controllers\DocumentController::class, 'index'])->name('app.docmgr');
    Route::post('/app/documents-mgr/upload', [App\Http\Controllers\DocumentController::class, 'upload'])->name('app.docmgr.upload');
    Route::get('/app/documents-mgr/{id}/download', [App\Http\Controllers\DocumentController::class, 'download'])->whereNumber('id')->name('app.docmgr.download');
    Route::post('/app/documents-mgr/{id}/delete', [App\Http\Controllers\DocumentController::class, 'destroy'])->whereNumber('id')->name('app.docmgr.delete');
    Route::post('/app/documents-mgr/employee/{emp}/delete-all', [App\Http\Controllers\DocumentController::class, 'destroyForEmployee'])->whereNumber('emp')->name('app.docmgr.delemp');
    // Employee Self-Service snapshot (own profile/payslips/attendance/leave/notices).
    Route::get('/app/ess/me', [App\Http\Controllers\EssController::class, 'me'])->name('app.ess.me');
    Route::post('/app/ess/update', [App\Http\Controllers\EssController::class, 'updateProfile'])->name('app.ess.update');
    // Onboarding checklist workflow.
    Route::get('/app/onboarding/board', [App\Http\Controllers\OnboardingController::class, 'board'])->name('app.onboarding.board');
    Route::post('/app/onboarding/item/{id}/toggle', [App\Http\Controllers\OnboardingController::class, 'toggle'])->whereNumber('id')->name('app.onboarding.toggle');
    // Performance review workflow.
    Route::get('/app/performance/board', [App\Http\Controllers\PerformanceController::class, 'board'])->name('app.performance.board');
    Route::post('/app/performance/{id}/advance', [App\Http\Controllers\PerformanceController::class, 'advance'])->whereNumber('id')->name('app.performance.advance');
    // Recruitment / ATS — pipeline board, candidates, job openings, hire→employee.
    Route::get('/app/recruitment/board', [App\Http\Controllers\RecruitmentController::class, 'board'])->name('app.recruit.board');
    Route::post('/app/recruitment/candidate', [App\Http\Controllers\RecruitmentController::class, 'saveCandidate'])->name('app.recruit.cand');
    Route::post('/app/recruitment/import', [App\Http\Controllers\RecruitmentController::class, 'importCandidates'])->name('app.recruit.import');
    Route::post('/app/recruitment/import-rows', [App\Http\Controllers\RecruitmentController::class, 'importRows'])->name('app.recruit.import.rows');
    Route::get('/app/recruitment/pool', [App\Http\Controllers\RecruitmentController::class, 'pool'])->name('app.recruit.pool');
    Route::post('/app/recruitment/candidate/{id}/assign', [App\Http\Controllers\RecruitmentController::class, 'assignToReq'])->name('app.recruit.assign');
    Route::post('/app/recruitment/assign-bulk', [App\Http\Controllers\RecruitmentController::class, 'assignBulk'])->name('app.recruit.assign.bulk');
    // Off-roll agent KYC — photo/document uploads + contact verification.
    Route::get('/app/offroll-agent/{id}/profile', [App\Http\Controllers\OffrollAgentController::class, 'profile'])->whereNumber('id')->name('app.offroll.profile');
    Route::post('/app/offroll-agent/{id}/contact', [App\Http\Controllers\OffrollAgentController::class, 'saveContact'])->whereNumber('id')->name('app.offroll.contact');
    Route::post('/app/offroll-agent/{id}/upload/{slot}', [App\Http\Controllers\OffrollAgentController::class, 'uploadDoc'])->whereNumber('id')->name('app.offroll.upload');
    Route::get('/app/offroll-agent/{id}/file/{slot}', [App\Http\Controllers\OffrollAgentController::class, 'serveDoc'])->whereNumber('id')->name('app.offroll.file');
    Route::post('/app/offroll-agent/{id}/verify-email', [App\Http\Controllers\OffrollAgentController::class, 'sendEmailVerify'])->whereNumber('id')->name('app.offroll.verifyemail');
    Route::post('/app/offroll-agent/{id}/verify-mobile', [App\Http\Controllers\OffrollAgentController::class, 'setMobileVerified'])->whereNumber('id')->name('app.offroll.verifymobile');
    // Off-roll agent LIVE EARNINGS (rev 80): ledger + add entry + approve + share link.
    Route::get('/app/offroll-agent/{id}/earnings', [App\Http\Controllers\OffrollAgentController::class, 'earnings'])->whereNumber('id')->name('app.offroll.earnings');
    Route::post('/app/offroll-agent/{id}/earnings', [App\Http\Controllers\OffrollAgentController::class, 'addEarning'])->whereNumber('id')->name('app.offroll.earnings.add');
    Route::post('/app/offroll-agent/earnings/{eid}/decide', [App\Http\Controllers\OffrollAgentController::class, 'decideEarning'])->whereNumber('eid')->name('app.offroll.earnings.decide');
    Route::post('/app/offroll-agent/{id}/earnings-link', [App\Http\Controllers\OffrollAgentController::class, 'sendEarningsLink'])->whereNumber('id')->name('app.offroll.earnings.link');
    Route::get('/app/recruitment/template', [App\Http\Controllers\RecruitmentController::class, 'template'])->name('app.recruit.template');
    Route::post('/app/recruitment/candidate/{id}/stage', [App\Http\Controllers\RecruitmentController::class, 'moveStage'])->name('app.recruit.stage');
    Route::post('/app/recruitment/candidate/{id}/hire', [App\Http\Controllers\RecruitmentController::class, 'convertToEmployee'])->name('app.recruit.hire');
    Route::post('/app/recruitment/candidate/{id}/patch', [App\Http\Controllers\RecruitmentController::class, 'patchCandidate'])->name('app.recruit.patch');
    Route::post('/app/recruitment/candidate/{id}/start-onboarding', [App\Http\Controllers\RecruitmentController::class, 'startOnboarding'])->name('app.recruit.onboard');
    Route::post('/app/recruitment/candidate/{id}/delete', [App\Http\Controllers\RecruitmentController::class, 'deleteCandidate'])->name('app.recruit.cand.del');
    Route::post('/app/recruitment/job', [App\Http\Controllers\RecruitmentController::class, 'saveJob'])->name('app.recruit.job');
    Route::post('/app/recruitment/job/{id}/approve', [App\Http\Controllers\RecruitmentController::class, 'approveJob'])->name('app.recruit.job.approve');
    Route::post('/app/recruitment/job/{id}/reject', [App\Http\Controllers\RecruitmentController::class, 'rejectJob'])->name('app.recruit.job.reject');
    Route::post('/app/recruitment/job/{id}/delete', [App\Http\Controllers\RecruitmentController::class, 'deleteJob'])->name('app.recruit.job.del');
    // Interviews — schedule, feedback, scorecards (per candidate).
    // rev 95: Hiring Drives (interview events: panel + venue + map + funnel).
    Route::get('/app/recruitment/drives', [App\Http\Controllers\HiringDriveController::class, 'index'])->name('app.drives');
    Route::post('/app/recruitment/drive', [App\Http\Controllers\HiringDriveController::class, 'save'])->name('app.drive.save');
    Route::get('/app/recruitment/drive/{id}', [App\Http\Controllers\HiringDriveController::class, 'show'])->name('app.drive.show');
    Route::post('/app/recruitment/drive/{id}/delete', [App\Http\Controllers\HiringDriveController::class, 'destroy'])->name('app.drive.del');
    Route::post('/app/recruitment/drive/{id}/status', [App\Http\Controllers\HiringDriveController::class, 'setStatus'])->name('app.drive.status');
    Route::post('/app/recruitment/drive/{id}/candidate/{cid}', [App\Http\Controllers\HiringDriveController::class, 'updateCandidate'])->name('app.drive.cand');
    Route::get('/app/recruitment/drive/{id}/export', [App\Http\Controllers\HiringDriveController::class, 'export'])->name('app.drive.export');

    // rev 94: bulk WhatsApp campaigns for recruitment + tracking.
    Route::post('/app/recruitment/messages/send', [App\Http\Controllers\RecruitMessagingController::class, 'send'])->name('app.recruit.msg.send');
    Route::get('/app/recruitment/campaigns', [App\Http\Controllers\RecruitMessagingController::class, 'campaigns'])->name('app.recruit.campaigns');
    Route::get('/app/recruitment/campaign/{id}', [App\Http\Controllers\RecruitMessagingController::class, 'campaign'])->name('app.recruit.campaign');
    Route::get('/app/recruitment/campaign/{id}/export', [App\Http\Controllers\RecruitMessagingController::class, 'export'])->name('app.recruit.campaign.export');
    Route::post('/app/recruitment/message/{id}/response', [App\Http\Controllers\RecruitMessagingController::class, 'setResponse'])->name('app.recruit.msg.response');

    Route::get('/app/recruitment/candidate/{id}/interviews', [App\Http\Controllers\RecruitmentController::class, 'interviews'])->name('app.recruit.iv.list');
    Route::post('/app/recruitment/interview', [App\Http\Controllers\RecruitmentController::class, 'saveInterview'])->name('app.recruit.iv.save');
    Route::post('/app/recruitment/interview/{id}/delete', [App\Http\Controllers\RecruitmentController::class, 'deleteInterview'])->name('app.recruit.iv.del');
    // Live dashboard widgets + global search.
    Route::get('/app/dashboard/stats', [App\Http\Controllers\DashboardController::class, 'stats'])->name('app.dashboard.stats');
    Route::get('/app/search', [App\Http\Controllers\DashboardController::class, 'search'])->name('app.search');
    // Master data — real DB (departments / branches / banks / designations).
    Route::get('/app/master/{type}', [App\Http\Controllers\MasterController::class, 'list'])->name('app.master');
    Route::post('/app/master/{type}', [App\Http\Controllers\MasterController::class, 'save'])->name('app.master.save');
    Route::post('/app/master/{type}/import', [App\Http\Controllers\MasterController::class, 'import'])->name('app.master.import');
    Route::get('/app/master/{type}/template', [App\Http\Controllers\MasterController::class, 'template'])->name('app.master.template');
    Route::post('/app/master/{type}/{id}/delete', [App\Http\Controllers\MasterController::class, 'delete'])->name('app.master.delete');
    // rev 119: mobile device approval (tenant admin/HR).
    Route::get('/app/mobile-devices/list', [App\Http\Controllers\MobileDeviceController::class, 'index'])->name('app.mobiledevices');
    Route::post('/app/mobile-devices/{id}/approve', [App\Http\Controllers\MobileDeviceController::class, 'approve'])->name('app.mobiledevices.approve');
    Route::post('/app/mobile-devices/{id}/reject', [App\Http\Controllers\MobileDeviceController::class, 'reject'])->name('app.mobiledevices.reject');
    Route::post('/app/mobile-devices/{id}/revoke', [App\Http\Controllers\MobileDeviceController::class, 'revoke'])->name('app.mobiledevices.revoke');

    Route::get('/app/{screen?}', [AppController::class, 'show'])->name('app');

    // Legacy first-pass native module pages are superseded by the /app prototype
    // (and target the pre-overhaul schema). Redirect any stray visits to the app.
    foreach (['dashboard', 'employees', 'departments', 'attendance', 'leave', 'loans', 'salary-runs', 'payslips', 'devices', 'tenants'] as $legacy) {
        Route::get('/'.$legacy, fn () => redirect('/app'));
    }
});

// Public candidate/existing-employee SELF-ONBOARDING portal (token-secured, no login).
Route::get('/self-onboard/{token}', [\App\Http\Controllers\SelfOnboardingController::class, 'start'])->name('selfonboard.start');
Route::post('/self-onboard/{token}/otp/send', [\App\Http\Controllers\SelfOnboardingController::class, 'otpSend'])->name('selfonboard.otp.send');
Route::post('/self-onboard/{token}/otp/verify', [\App\Http\Controllers\SelfOnboardingController::class, 'otpVerify'])->name('selfonboard.otp.verify');
Route::post('/self-onboard/{token}/save', [\App\Http\Controllers\SelfOnboardingController::class, 'save'])->name('selfonboard.save');
Route::post('/self-onboard/{token}/selfie', [\App\Http\Controllers\SelfOnboardingController::class, 'selfie'])->name('selfonboard.selfie');
Route::post('/self-onboard/{token}/document', [\App\Http\Controllers\SelfOnboardingController::class, 'document'])->name('selfonboard.document');
Route::post('/self-onboard/{token}/submit', [\App\Http\Controllers\SelfOnboardingController::class, 'submit'])->name('selfonboard.submit');
Route::get('/self-onboard/{token}/selfie', [\App\Http\Controllers\SelfOnboardingController::class, 'selfieImg'])->name('selfonboard.selfie.img');

// HR VERIFICATION CONSOLE for self-onboarding (authenticated; role-gated in controller).
Route::middleware(['auth'])->group(function () {
    Route::get('/app/self-onboarding', [\App\Http\Controllers\SelfOnboardingController::class, 'hrConsole'])->name('app.selfonboard.console');
    Route::get('/app/self-onboarding/list', [\App\Http\Controllers\SelfOnboardingController::class, 'hrList'])->name('app.selfonboard.list');
    Route::get('/app/self-onboarding/{id}', [\App\Http\Controllers\SelfOnboardingController::class, 'hrShow'])->whereNumber('id');
    Route::get('/app/self-onboarding/{id}/selfie', [\App\Http\Controllers\SelfOnboardingController::class, 'hrSelfie'])->whereNumber('id');
    Route::get('/app/self-onboarding/{id}/doc/{doc}', [\App\Http\Controllers\SelfOnboardingController::class, 'hrDoc'])->whereNumber('id')->whereNumber('doc');
    Route::post('/app/self-onboarding/{id}/correction', [\App\Http\Controllers\SelfOnboardingController::class, 'hrCorrection'])->whereNumber('id');
    Route::post('/app/self-onboarding/{id}/verify', [\App\Http\Controllers\SelfOnboardingController::class, 'hrVerify'])->whereNumber('id');
});
