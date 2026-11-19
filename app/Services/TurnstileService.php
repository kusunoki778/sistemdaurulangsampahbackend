<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifikasi Cloudflare Turnstile untuk login admin.
 *
 * Alurnya: widget Turnstile di halaman login menghasilkan token sekali pakai,
 * token dikirim bersama kredensial, lalu server memverifikasinya ke Cloudflare
 * memakai secret key. Token hanya sah sekali dan berumur pendek.
 *
 * Prinsip penting: kelas ini tidak pernah membuat login admin gagal hanya
 * karena masalah teknis. Kalau kunci belum diisi, Cloudflare tidak terjangkau,
 * atau timeout, verifikasi dianggap tidak tersedia dan login admin kembali
 * memakai captcha gambar lokal. Tujuannya supaya CMS tetap bisa dipakai
 * saat demo atau saat internet bermasalah.
 */
class TurnstileService
{
    /**
     * Apakah Turnstile dikonfigurasi dan harus dipakai.
     */
    public function aktif(): bool
    {
        return trim((string) config('services.turnstile.secret_key')) !== '';
    }

    /**
     * Verifikasi token Turnstile dari klien.
     *
     * @return bool|null true = sah, false = tidak sah, null = verifikasi tidak
     *                   tersedia sehingga pemanggil harus memakai jalur lain.
     */
    public function verifikasi(?string $token, ?string $ip = null): ?bool
    {
        if (! $this->aktif()) {
            return null;
        }

        if (! is_string($token) || trim($token) === '') {
            // Klien memakai jalur verifikasi lokal (centang "Saya bukan robot"
            // dan captcha gambar). Ini bukan token palsu, jadi kembalikan
            // null agar pemanggil memakai captcha lokal sebagai cadangan.
            return null;
        }

        $secret = (string) config('services.turnstile.secret_key');
        $url = (string) config('services.turnstile.verify_url');
        $batasDetik = (int) config('services.turnstile.timeout', 8);

        $payload = [
            'secret' => $secret,
            'response' => $token,
        ];

        if ($ip) {
            $payload['remoteip'] = $ip;
        }

        try {
            $respons = Http::asForm()
                ->timeout($batasDetik)
                ->post($url, $payload);
        } catch (\Throwable $e) {
            // Jaringan bermasalah: jangan kunci admin di luar.
            Log::warning('Turnstile tidak dapat dihubungi: '.$e->getMessage());

            return null;
        }

        if (! $respons->successful()) {
            Log::warning('Turnstile menjawab HTTP '.$respons->status());

            return null;
        }

        $data = $respons->json();

        if (! is_array($data) || ! array_key_exists('success', $data)) {
            Log::warning('Turnstile menjawab format tidak dikenal.');

            return null;
        }

        if ($data['success'] === true) {
            return true;
        }

        // Kumpulkan kode galat untuk penelusuran di log.
        $galat = $data['error-codes'] ?? [];
        if (is_array($galat) && $galat !== []) {
            Log::info('Turnstile menolak token: '.implode(', ', $galat));
        }

        return false;
    }
}
