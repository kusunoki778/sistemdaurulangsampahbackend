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

];
