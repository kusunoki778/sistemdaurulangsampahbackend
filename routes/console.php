<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bersihkan token Sanctum yang sudah kedaluwarsa setiap hari.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Payout yang masih berjalan diperiksa tiap menit supaya dana yang sudah
// sampai tetap tercatat dan notifikasi terkirim walau aplikasi ditutup.
Schedule::command('payout:cek-status')->everyMinute()->withoutOverlapping();
