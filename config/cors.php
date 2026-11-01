<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Produksi: isi CORS_ALLOWED_ORIGINS dengan domain frontend, dipisah koma.
    // Contoh: https://livora.vercel.app,https://livora-web.vercel.app
    // Bila tidak diset, fallback terbuka ('*') untuk kompatibilitas lokal.
    'allowed_origins' => array_values(
        array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
