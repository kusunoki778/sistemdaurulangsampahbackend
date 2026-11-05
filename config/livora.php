<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Masa berlaku QR transaksi setor
    |--------------------------------------------------------------------------
    |
    | QR sesi hanya berlaku singkat agar tidak bisa dipakai ulang di kemudian
    | hari. Admin dapat menerbitkan QR baru bila kedaluwarsa.
    |
    */

    'qr_ttl_minutes' => (int) env('QR_TTL_MINUTES', 3),

    /*
    |--------------------------------------------------------------------------
    | Masa berlaku kupon reward fisik
    |--------------------------------------------------------------------------
    |
    | Nilai default ini bisa ditimpa admin lewat CMS (tabel pengaturan).
    | Kupon yang sudah terbit menyimpan snapshot expires_at sendiri.
    |
    */

    'reward_expiry_days' => (int) env('REWARD_EXPIRY_DAYS', 3),

];
