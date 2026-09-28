<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | ponytail: this file didn't exist, so Laravel's built-in HandleCors
    | middleware (global since Laravel 9+) had nothing to match and never sent
    | CORS headers on ANY response. The mobile app's WebView calls
    | /api/mobile/register|status|push-token cross-origin (its own origin is
    | https://localhost / capacitor://localhost, never the SmartPRS domain),
    | so those requests need CORS headers or the browser engine blocks the
    | response client-side even though the server answers fine.
    |
    | These three routes are public, unauthenticated, throttled, and send no
    | cookies (supports_credentials stays false) — so a wildcard origin is
    | safe per the CORS spec (wildcard + no credentials is explicitly allowed).
    | If you later want to lock this to known app origins only, replace '*'
    | with ['https://localhost', 'capacitor://localhost'].
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
