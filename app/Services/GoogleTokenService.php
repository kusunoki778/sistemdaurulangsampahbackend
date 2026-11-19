<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifikasi Google ID Token (Sign In With Google / Firebase Auth ID token)
 * tanpa dependensi tambahan: ambil JWKS Google, cocokkan `kid`, verifikasi
 * tanda tangan RS256 via openssl, lalu cek klaim (exp, iss, aud).
 *
 * Konfigurasi: GOOGLE_CLIENT_ID di .env (Web Client ID Firebase).
 */
class GoogleTokenService
{
    private const JWK_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /**
     * @return array{sub:string, email:string, name:string, picture:?string, email_verified:bool}
     *
     * @throws \Exception Token invalid/belum dikonfigurasi.
     */
    public function verifikasi(string $idToken): array
    {
        $clientId = config('services.google.client_id');

        if (empty($clientId)) {
            throw new \Exception('Login Google belum dikonfigurasi di server.');
        }

        $bagian = explode('.', $idToken);

        if (count($bagian) !== 3) {
            throw new \Exception('Token Google tidak valid.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $bagian;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($signatureB64);

        if (! is_array($header) || ! is_array($payload)) {
            throw new \Exception('Token Google tidak valid.');
        }

        // Verifikasi tanda tangan RS256.
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new \Exception('Algoritma token Google tidak didukung.');
        }

        $pem = $this->kunciPem($header['kid'] ?? '');

        if (! $pem) {
            throw new \Exception('Kunci Google tidak ditemukan. Coba lagi.');
        }

        $ok = openssl_verify(
            $headerB64.'.'.$payloadB64,
            $signature,
            $pem,
            OPENSSL_ALGO_SHA256,
        );

        if ($ok !== 1) {
            throw new \Exception('Tanda tangan token Google tidak valid.');
        }

        // Periksa klaim standar.
        if (($payload['exp'] ?? 0) < time()) {
            throw new \Exception('Token Google sudah kedaluwarsa. Coba lagi.');
        }

        $iss = $payload['iss'] ?? '';

        if (! in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new \Exception('Penerbit token Google tidak dikenal.');
        }

        if (($payload['aud'] ?? '') !== $clientId) {
            throw new \Exception('Token Google tidak ditujukan untuk aplikasi ini.');
        }

        $email = $payload['email'] ?? '';

        if ($email === '' || empty($payload['email_verified'])) {
            throw new \Exception('Email Google belum terverifikasi.');
        }

        return [
            'sub' => (string) $payload['sub'],
            'email' => strtolower($email),
            'name' => (string) ($payload['name'] ?? ''),
            'picture' => $payload['picture'] ?? null,
            'email_verified' => (bool) $payload['email_verified'],
        ];
    }

    /** JWKS Google — cache HANYA bila fetch sukses; gagal ditempatkan langsung. */
    private function ambilJwks(): array
    {
        $cached = Cache::get('google_jwks');

        if (is_array($cached) && ! empty($cached['keys'])) {
            return $cached;
        }

        // Tak dipakai cache yang lama — ambil langsung sekarang.
        try {
            $res = Http::timeout(15)->get(self::JWK_URL);
            $jwks = $res->json();
        } catch (\Throwable $e) {
            logger()->warning('google JWKS fetch gagal', ['err' => $e->getMessage()]);
            $jwks = null;
        }

        if (empty($jwks) || empty($jwks['keys'])) {
            if (is_array($cached) && ! empty($cached['keys'])) {
                return $cached; // fallback ke cache lama yg valid.
            }

            throw new \Exception('Server tidak dapat mengambil kunci verifikasi Google. Coba lagi.');
        }

        Cache::put('google_jwks', $jwks, now()->addHours(6));

        return $jwks;
    }

    /** JWKS Google (cache 6 jam) → PEM publik milik `kid`. */
    private function kunciPem(string $kid): ?string
    {
        // Fetch JWKS — hasil gagal/kosong JANGAN di-cache.
        $jwks = $this->ambilJwks();

        foreach ($jwks['keys'] ?? [] as $kunci) {
            if (($kunci['kid'] ?? '') !== $kid) {
                continue;
            }

            $mod = $this->base64UrlDecode($kunci['n'] ?? '');
            $exp = $this->base64UrlDecode($kunci['e'] ?? '');

            // ASN.1: RSAPublicKey { modulus INTEGER, exponents INTEGER }.
            return $this->pemDariModulus($mod, $exp);
        }

        return null;
    }

    /**
     * Rangkai struktur RSAPublicKey (DER) → PEM public key.
     * Implementasi manual tanpa library X509, dengan penulis panjang
     * DER yang benar (short/long form 0x81/0x82) supaya openssl terima.
     */
    private function pemDariModulus(string $modulus, string $exponent): ?string
    {
        $mod = $this->encodeIntegerDer($modulus);
        $exp = $this->encodeIntegerDer($exponent);

        // SEQUENCE { INTEGER modulus, INTEGER exponent }
        $isi = $mod.$exp;
        $rsaPublicKey = $this->derTag("\x30", $isi).$isi;

        // SubjectPublicKeyInfo wrapper: algoritma RSA OID 1.2.840.113549.1.1.1
        // + BIT STRING berisi RSAPublicKey (byte pertama = unused bits 0x00).
        $rsaOid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitContent = "\x00".$rsaPublicKey;
        $bitString = $this->derTag("\x03", $bitContent).$bitContent;

        $spkiContent = $rsaOid.$bitString;
        $spki = $this->derTag("\x30", $spkiContent).$spkiContent;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    /** Header DER satu tag: short form <128, long form 0x81/0x82 di atasnya. */
    private function derTag(string $tag, string $content): string
    {
        $panjang = strlen($content);

        if ($panjang < 128) {
            return $tag.chr($panjang);
        }

        if ($panjang <= 255) {
            return $tag."\x81".chr($panjang);
        }

        return $tag."\x82".pack('n', $panjang);
    }

    /** INTEGER DER: strip leading 0x00, prepend 0x00 bila bit pertama set. */
    private function encodeIntegerDer(string $nilai): string
    {
        $nilai = ltrim($nilai, "\x00");

        if ($nilai === '') {
            $nilai = "\x00";
        }

        if (ord($nilai[0]) & 0x80) {
            $nilai = "\x00".$nilai;
        }

        return $this->derTag("\x02", $nilai).$nilai;
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode($this->beriTandaPlusBase64UrlDecode($data)) ?: '';
    }

    private function beriTandaPlusBase64UrlDecode(string $data): string
    {
        return strtr($data, ['-' => '+', '_' => '/']);
    }
}
