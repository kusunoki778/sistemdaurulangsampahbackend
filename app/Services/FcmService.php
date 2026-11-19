<?php

namespace App\Services;

use App\Models\FcmToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim notifikasi sistem HP (FCM HTTP v1) tanpa dependensi tambahan:
 *
 * 1. Autentikasi service account: bongkar JSON kredensial (.env
 *    FCM_SERVICE_ACCOUNT_JSON), rakit JWT RS256 ditandatangani pakai
 *    openssl (pola yang sama dengan GoogleTokenService), tukar dengan
 *    access token di OAuth2 Google (cache sampai ~50 menit).
 * 2. Kirim pesan ke endpoint messages:send per token perangkat.
 * 3. Token yang sudah tidak valid (UNREGISTERED/INVALID_ARGUMENT)
 *    dihapus otomatis supaya tabel tetap bersih.
 *
 * Kegagalan kirim TIDAK boleh menggagalkan transaksi utama: semua
 * pengecualian ditelan + dicatat ke log.
 */
class FcmService
{
    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?array $kredensial = null;

    /** Kirim notifikasi ke semua perangkat milik satu user. */
    public function kirimKeUser(int $userId, string $judul, string $isi, array $data = []): void
    {
        try {
            if (! $this->siap()) {
                return; // kredensial belum dipasang — dilewati senyap.
            }

            $token = FcmToken::where('user_id', $userId)
                ->orderByDesc('terakhir_dipakai_at')
                ->pluck('token');

            if ($token->isEmpty()) {
                return; // user belum punya perangkat terdaftar.
            }

            foreach ($token as $deviceToken) {
                $this->kirim($deviceToken, $judul, $isi, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('FCM: gagal kirim notifikasi', ['user_id' => $userId, 'err' => $e->getMessage()]);
        }
    }

    /** Kirim ke satu token, dan tangani token kadaluarsa (hapus dari tabel). */
    private function kirim(string $deviceToken, string $judul, string $isi, array $data): void
    {
        try {
            $resHttp = Http::timeout(15)
                ->withToken($this->accessToken())
                ->post($this->urlKirim(), $this->pesan($deviceToken, $judul, $isi, $data));

            if ($resHttp->successful()) {
                FcmToken::where('token', $deviceToken)->update([
                    'terakhir_dipakai_at' => now(),
                ]);

                return;
            }

            $body = $resHttp->json('error', []);
            $status = $body['status'] ?? '';
            $kode = $resHttp->status();

            // Token mati / di-uninstall / format salah → buang dari tabel.
            if ($status === 'UNREGISTERED' || ($kode === 404)) {
                FcmToken::where('token', $deviceToken)->delete();

                return;
            }

            Log::warning('FCM: server menolak pesan', ['status' => $status, 'kode' => $kode]);
        } catch (\Throwable $e) {
            Log::warning('FCM: gagal kirim ke token', ['err' => $e->getMessage()]);
        }
    }

    /** Bangun payload messages:send (notifikasi + data + android prioritas). */
    private function pesan(string $deviceToken, string $judul, string $isi, array $data): array
    {
        return [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => mb_substr($judul, 0, 100),
                    'body' => mb_substr($isi, 0, 200),
                ],
                // Seluruh nilai data wajib string.
                'data' => collect($data)->map(fn ($v) => (string) $v)->all(),
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => 'livora_transaksi',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
            ],
        ];
    }

    private function urlKirim(): string
    {
        $projectId = $this->kredensial()['project_id'] ?? '';

        return "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    }

    /** Access token OAuth2 dengan cache (hidup ±1 jam, kita pakai 50 menit). */
    private function accessToken(): string
    {
        $cached = Cache::get('fcm_access_token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $kred = $this->kredensial();
        $now = time();
        $jwt = $this->jwtServiceAccount(
            [
                'iss' => $kred['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::OAUTH_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ],
            $kred['private_key'],
        );

        $res = Http::asForm()->post(self::OAUTH_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $res->successful()) {
            throw new \Exception('OAuth2 FCM gagal: '.$res->status().' '.substr($res->body(), 0, 200));
        }

        $token = (string) ($res->json('access_token') ?? '');
        $expire = (int) ($res->json('expires_in') ?? 3600);

        if ($token === '') {
            throw new \Exception('OAuth2 FCM tidak mengembalikan access_token.');
        }

        Cache::put('fcm_access_token', $token, now()->addSeconds(max(60, $expire - 600)));

        return $token;
    }

    /**
     * JWT RS256 ditandatangani private key service account.
     * Sama pola verifikasi Google: base64url + openssl_sign SHA256.
     */
    private function jwtServiceAccount(array $klaim, string $privateKey): string
    {
        $b64 = fn (string $d): string => rtrim(strtr(base64_encode($d), ['+' => '-', '/' => '_']), '=');

        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode($klaim));
        $bagian = $header.'.'.$payload;

        $kunci = openssl_pkey_get_private($privateKey);
        if ($kunci === false) {
            throw new \Exception('Private key FCM tidak valid: '.openssl_error_string());
        }

        openssl_sign($bagian, $tanda, $kunci, OPENSSL_ALGO_SHA256);

        return $bagian.'.'.$b64($tanda);
    }

    /** Muat kredensial JSON dari .env; null bila file belum ada. */
    private function kredensial(): ?array
    {
        if ($this->kredensial !== null) {
            return $this->kredensial;
        }

        $path = trim((string) config('services.fcm.service_account_json', ''));

        if ($path === '') {
            $path = storage_path('app/firebase/fcm-service-account.json');
        } else {
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, base_path($path));
        }

        if (! is_file($path)) {
            return null;
        }

        $isi = json_decode((string) file_get_contents($path), true);

        if (! is_array($isi) || empty($isi['private_key']) || empty($isi['project_id'])) {
            throw new \Exception('Service account FCM tidak valid (field wajib kurang).');
        }

        $this->kredensial = $isi;

        return $this->kredensial;
    }

    /** true bila kredensial FCM sudah terpasang dan bisa dibaca. */
    private function siap(): bool
    {
        try {
            return $this->kredensial() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
