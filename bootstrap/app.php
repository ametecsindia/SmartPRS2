<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // SBB public API + SmartEPT webhook receiver — stateless, mounted at /api.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // SmartEPT webhook ADMIN screens. Same stack as the /app group in
            // routes/web.php, declared in their own file so that 70 KB of shared
            // routing never has to be rewritten to add three routes.
            Route::middleware([
                'web',
                'auth',
                \App\Http\Middleware\LicenseGate::class,
                \App\Http\Middleware\EnsureSubscriptionActive::class,
                \App\Http\Middleware\DemoWriteGuard::class,
                \App\Http\Middleware\EditionGuard::class,
            ])->group(__DIR__.'/../routes/smartept.php');

            // SELF-UPDATE (flow chart, 2 Sep 2026): the platform's token package
            // download plus the on-prem download/install/reset endpoints. Same
            // reasoning as above — its own file, so routes/web.php is never
            // rewritten to add five routes. Each half declares its own stack.
            Route::group([], __DIR__.'/../routes/updates.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            // 'api-key'         any valid key
            // 'api-key:ingest'  a valid key that also holds the 'ingest' scope
            'api-key' => \App\Http\Middleware\ApiKeyAuth::class,
            // SmartEPT pushes with an HMAC signature and no key header.
            'smartept-webhook' => \App\Http\Middleware\SmarteptSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
