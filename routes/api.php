<?php

use App\Http\Controllers\Api\PublicApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SmartPRS public API (v1) — Smart Biometric Bridge
|--------------------------------------------------------------------------
|
| STATELESS. These routes deliberately live outside routes/web.php: the `web`
| group starts a session and issues a cookie on every request, and an
| on-premise service posting a batch of punches every few seconds does not
| want either. No session, no CSRF, no cookie — just the API key.
|
| Registered in bootstrap/app.php via withRouting(api: ...), which mounts this
| file under the /api prefix. So the paths below resolve to:
|
|     GET  /api/v1/ping
|     POST /api/v1/attendance/punches
|
| The unauthenticated /iclock ADMS endpoints in routes/web.php are untouched:
| real ZKTeco hardware speaks that protocol and cannot present a credential.
|
*/

Route::prefix('v1')->middleware('throttle:300,1')->group(function () {
    Route::get('ping', [PublicApiController::class, 'ping'])->middleware('api-key');
    Route::post('attendance/punches', [PublicApiController::class, 'ingestPunches'])->middleware('api-key:ingest');
});
