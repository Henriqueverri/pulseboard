<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | FRONTEND_URL must be an explicit origin (not *) when credentials are used.
    | Required for Sanctum SPA cookie authentication.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Readable by the SPA (cross-origin): Retry-After on 429, X-Request-Id for support.
    'exposed_headers' => ['Retry-After', 'X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,

];
