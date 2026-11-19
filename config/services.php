<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder_sampah' => env('CLOUDINARY_FOLDER_SAMPAH', 'livora/jenis-sampah'),
        'folder_reward' => env('CLOUDINARY_FOLDER_REWARD', 'livora/rewards'),
        'folder_artikel' => env('CLOUDINARY_FOLDER_ARTIKEL', 'livora/artikels'),
        'folder_avatar' => env('CLOUDINARY_FOLDER_AVATAR', 'livora/avatar'),
    ],

    'xendit' => [
        // Disbursement payout (uang keluar): tukar koin -> saldo e-wallet.
        // Kunci dari dashboard.xendit.co (Settings -> API Keys, permission
        // MONEY-OUT). Kosong = payout Xendit otomatis menolak dan tombol
        // sandbox cepat tetap dipakai saat SANDBOX_PAYOUT aktif.
        'secret_key' => env('XENDIT_SECRET_KEY'),
        // Webhook header x-callback-token, disetel di dashboard Xendit.
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        'api_version' => env('XENDIT_API_VERSION', '2025-09-01'),
        'source_of_fund' => env('XENDIT_SOURCE_OF_FUND', 'BUSINESS_REVENUE'),
        'purpose_code' => env('XENDIT_PURPOSE_CODE', 'OTHER'),
        // Payout terkecil yang boleh dikirim (Xendit menolak nominal kecil).
        'minimum_rupiah' => (int) env('XENDIT_MINIMUM_RUPIAH', 10000),
        // Channel e-wallet yang diterima aplikasi -> routing_value_1 Xendit.
        'channel' => [
            'DANA' => 'ID_DANA',
            'OVO' => 'ID_OVO',
            'SHOPEEPAY' => 'ID_SHOPEEPAY',
            'LINKAJA' => 'ID_LINKAJA',
            'ASTRAPAY' => 'ID_ASTRAPAY',
        ],
    ],

    'turnstile' => [
        // Cloudflare Turnstile untuk login admin di CMS. Widget di halaman
        // login memakai site key, verifikasi token dilakukan server memakai
        // secret key. Keduanya dari dash.cloudflare.com (Turnstile).
        //
        // Kosong = Turnstile tidak aktif dan login admin kembali memakai
        // captcha aritmetika lokal, supaya demo tidak pernah mati karena
        // kunci belum diisi atau Cloudflare tidak terjangkau.
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        // Endpoint verifikasi resmi Cloudflare.
        'verify_url' => env(
            'TURNSTILE_VERIFY_URL',
            'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        ),
        // Batas waktu panggilan verifikasi (detik).
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 8),
    ],

    // Login Google via Firebase Auth (Web Client ID).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    // Notifikasi sistem HP — Firebase Cloud Messaging (HTTP v1).
    'fcm' => [
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
    ],

];
