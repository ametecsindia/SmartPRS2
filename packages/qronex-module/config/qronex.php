<?php

return [
    // Host adapter class implementing Ametecs\Qronex\Contracts\Host (set by each app).
    'host' => env('QRONEX_HOST_ADAPTER'),
    // Admin screens live at /{admin_prefix}; the phone API at /api/qronex/v1; entrance displays at /{admin_prefix}/display/{gate}.
    'admin_prefix' => 'qronex',
    // Middleware for the admin screens. Signed-out visitors are sent to the host's home/login by AdminAccess.
    'admin_middleware' => ['web'],
    'activation_hours' => 72,
];
