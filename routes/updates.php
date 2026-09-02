<?php

use App\Http\Controllers\ClientUpdateController;
use App\Http\Controllers\UpdateServerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SELF-UPDATE ROUTES (Ejaz's self-update flow chart, 2 Sep 2026)
|--------------------------------------------------------------------------
|
| Declared in their own file, and mounted from bootstrap/app.php, for the same
| reason routes/smartept.php is: 70 KB of shared routing in routes/web.php
| never has to be rewritten to add five routes, and a stale read of that file
| can never silently revert real work.
|
| Two halves, two audiences:
|
|   PLATFORM (vendor side, SaaS only)
|     GET  /update/package/{token}      token download — the licence key never
|                                       travels in a URL, so it can never land
|                                       in an access log or a proxy cache
|
|   ON-PREM CLIENT (admin only)
|     POST /app/updates/download        fetch + sha256-verify the package
|     POST /app/updates/install         hand off to updater/updater.php
|     POST /app/updates/reset           forget a half-finished attempt
|
| The existing /app/updates/status, /app/updates/check and /app/updates/apply
| routes stay in routes/web.php and are untouched.
|
*/

// ---------------------------------------------------------------- platform side
// Public server-to-server, like the rest of the /update/* family. Stateless on
// purpose: a 300 MB package download has no business starting a session.
if (config('smartprs.deployment') !== 'onprem') {
    Route::get('/update/package/{token}', [UpdateServerController::class, 'packageDownload'])
        ->where('token', '[A-Za-z0-9]{16,64}')
        ->middleware('throttle:20,1')
        ->name('update.package');
}

// --------------------------------------------------------------- on-prem client
// Same stack as the /app group in routes/web.php; Admin/Super Admin is enforced
// inside the controller.
Route::middleware([
    'web',
    'auth',
    \App\Http\Middleware\LicenseGate::class,
    \App\Http\Middleware\EnsureSubscriptionActive::class,
    \App\Http\Middleware\DemoWriteGuard::class,
    \App\Http\Middleware\EditionGuard::class,
])->group(function () {
    Route::post('/app/updates/download', [ClientUpdateController::class, 'download'])
        ->middleware('throttle:5,10')->name('app.updates.download');
    Route::post('/app/updates/install', [ClientUpdateController::class, 'install'])
        ->middleware('throttle:5,10')->name('app.updates.install');
    Route::post('/app/updates/reset', [ClientUpdateController::class, 'resetUpdate'])
        ->middleware('throttle:20,1')->name('app.updates.reset');
});
