<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TurnstileService;

/**
 * Konfigurasi verifikasi keamanan untuk halaman login admin.
 *
 * Halaman login perlu tahu widget mana yang harus ditampilkan: Turnstile dari
 * Cloudflare bila sudah dikonfigurasi, atau captcha gambar lokal sebagai
 * cadangan. Yang dikirim hanya site key, yang memang bersifat publik. Secret
 * key tidak pernah keluar dari server.
 */
class TurnstileController extends Controller
{
    /**
     * GET /api/auth/verifikasi-keamanan
     */
    public function show(TurnstileService $turnstileService)
    {
        $aktif = $turnstileService->aktif();
        $siteKey = trim((string) config('services.turnstile.site_key'));

        // Kunci publik tidak boleh kosong meski secret sudah diisi. Tanpa site
        // key widget tidak bisa dirender, jadi anggap belum siap.
        if ($aktif && $siteKey === '') {
            $aktif = false;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'turnstile_aktif' => $aktif,
                'turnstile_site_key' => $aktif ? $siteKey : null,
            ],
        ]);
    }
}
