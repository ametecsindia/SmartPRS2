<?php

// QR attendance module (packages/qronex-module). Admin screens at /qronex, phone API at /api/qronex/v1.
return [
    'host' => App\Qronex\Prs2Host::class,
    'admin_prefix' => 'qronex',
    'admin_middleware' => ['web', 'auth', App\Http\Middleware\LicenseGate::class, App\Http\Middleware\EnsureSubscriptionActive::class, App\Http\Middleware\DemoWriteGuard::class],
    'activation_hours' => 72,
];
