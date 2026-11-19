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
    // Lokal/testing: fallback terbuka. Produksi tanpa env: pakai FRONTEND_URL;
    // bila keduanya kosong, origin asing ditolak (fail-closed).
    'allowed_origins' => (function () {
        $daftar = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
        )));

        if ($daftar !== []) {
            return $daftar;
        }

        $env = (string) env('APP_ENV', 'production');
        if (in_array($env, ['local', 'testing'], true)) {
            return ['*'];
        }

        $frontend = trim((string) env('FRONTEND_URL', ''));

        return $frontend !== '' ? [$frontend] : [];
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
