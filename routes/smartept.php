<?php

use App\Http\Controllers\ApiKeyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SmartEPT webhook — admin routes (Time & Attendance → API Keys)
|--------------------------------------------------------------------------
|
| These belong logically beside the other /app screens in routes/web.php, and
| they are HERE instead for one reason: routes/web.php is 70 KB of shared
| routing, and rewriting it wholesale over the desktop bridge is how real work
| gets silently reverted by a stale read. A brand-new file added to the router
| cannot revert anything.
|
| bootstrap/app.php loads this with the same middleware stack the /app group in
| routes/web.php uses, so these behave identically to their neighbours:
| authenticated, licence-gated, subscription-gated, demo-write-guarded.
|
| The receiver endpoint itself is NOT here — it is stateless and lives in
| routes/api.php.
|
*/

Route::post('/app/smartept-webhooks', [ApiKeyController::class, 'storeWebhook'])
    ->name('app.smartept.store');

Route::post('/app/smartept-webhooks/{id}/rotate', [ApiKeyController::class, 'rotateWebhook'])
    ->whereNumber('id')
    ->name('app.smartept.rotate');

Route::post('/app/smartept-webhooks/{id}/revoke', [ApiKeyController::class, 'revokeWebhook'])
    ->whereNumber('id')
    ->name('app.smartept.revoke');
