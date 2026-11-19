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

    /*
    |--------------------------------------------------------------------------
    | Pencairan saldo mode sandbox
    |--------------------------------------------------------------------------
    |
    | Endpoint POST /api/permintaan-reward/{id}/bayar-sandbox menyelesaikan
    | pencairan saldo TANPA pembayaran nyata (simulasi demo). Di produksi
    | endpoint ini dimatikan secara default supaya saldo rupiah tidak bisa
    | dicetak sendiri oleh pengguna.
    |
    | Setel SANDBOX_PAYOUT=true di .env/Render HANYA bila sedang demo dan
    | memang ingin memperlihatkan alur pencairan simulasi.
    |
    */

    'sandbox_payout' => filter_var(
        env('SANDBOX_PAYOUT', env('APP_ENV', 'production') !== 'production'),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
