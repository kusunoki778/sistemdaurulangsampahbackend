<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Captcha gambar sekali pakai untuk login admin.
 *
 * Teks captcha tidak pernah dikirim ke browser. Server menyimpan jawabannya
 * di cache dengan token acak, lalu token hangus setelah dipakai atau setelah
 * 5 menit. Bila GD atau font tidak tersedia di server, captcha jatuh ke soal
 * aritmetika supaya login admin tetap punya verifikasi.
 */
class CaptchaService
{
    private const TTL_SECONDS = 300;

    private const PANJANG = 5;

    /** Tanpa huruf dan angka yang mudah tertukar: 0/O, 1/I/L, 5/S. */
    private const KARAKTER = '2346789ABCDEFGHJKMNPQRTUVWXYZ';

    /**
     * Buat captcha baru beserta token sekali pakai.
     *
     * @return array{token: string, gambar?: string, pertanyaan?: string, kedaluwarsa_detik: int}
     */
    public function buat(): array
    {
        if (! function_exists('imagecreatetruecolor') || ! is_file($this->pathFont())) {
            return $this->buatAritmetika();
        }

        $teks = '';
        for ($i = 0; $i < self::PANJANG; $i++) {
            $teks .= self::KARAKTER[random_int(0, strlen(self::KARAKTER) - 1)];
        }

        $token = Str::random(40);

        Cache::put('captcha:'.$token, $teks, self::TTL_SECONDS);

        return [
            'token' => $token,
            'gambar' => $this->gambar($teks),
            'kedaluwarsa_detik' => self::TTL_SECONDS,
        ];
    }

    /**
     * Validasi jawaban dan hanguskan token, baik jawaban benar maupun salah.
     */
    public function validasi(?string $token, mixed $jawaban): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        if ($jawaban === null || trim((string) $jawaban) === '') {
            return false;
        }

        $tersimpan = Cache::pull('captcha:'.$token);

        if ($tersimpan === null) {
            return false;
        }

        return hash_equals(
            strtoupper((string) $tersimpan),
            strtoupper(trim((string) $jawaban))
        );
    }

    /**
     * Gambar captcha PNG dalam bentuk data URI, langsung bisa dipakai
     * sebagai atribut src pada elemen img.
     */
    private function gambar(string $teks): string
    {
        $lebar = 220;
        $tinggi = 78;

        $kanvas = imagecreatetruecolor($lebar, $tinggi);
        imageantialias($kanvas, true);

        $latar = imagecolorallocate($kanvas, 246, 248, 245);
        imagefilledrectangle($kanvas, 0, 0, $lebar, $tinggi, $latar);

        // Titik-titik halus sebagai pengganggu pembacaan mesin.
        for ($i = 0; $i < 380; $i++) {
            $warna = imagecolorallocate(
                $kanvas,
                random_int(188, 220),
                random_int(198, 226),
                random_int(188, 220)
            );
            imagesetpixel($kanvas, random_int(0, $lebar), random_int(0, $tinggi), $warna);
        }

        // Garis tipis melintang, tidak menutupi huruf.
        for ($i = 0; $i < 4; $i++) {
            $warna = imagecolorallocate(
                $kanvas,
                random_int(140, 180),
                random_int(165, 200),
                random_int(140, 180)
            );
            imageline($kanvas, 0, random_int(0, $tinggi), $lebar, random_int(0, $tinggi), $warna);
        }

        $font = $this->pathFont();
        $bayangan = imagecolorallocate($kanvas, 205, 213, 205);
        $x = 14;

        foreach (str_split($teks) as $huruf) {
            $ukuran = random_int(26, 31);
            $sudut = random_int(-15, 15);
            $y = random_int(50, 64);
            $warna = imagecolorallocate(
                $kanvas,
                random_int(20, 55),
                random_int(75, 105),
                random_int(35, 70)
            );

            imagettftext($kanvas, $ukuran, $sudut, $x + 1, $y + 1, $bayangan, $font, $huruf);
            imagettftext($kanvas, $ukuran, $sudut, $x, $y, $warna, $font, $huruf);

            $x += random_int(36, 41);
        }

        ob_start();
        imagepng($kanvas);
        $isi = (string) ob_get_clean();
        imagedestroy($kanvas);

        return 'data:image/png;base64,'.base64_encode($isi);
    }

    private function pathFont(): string
    {
        return resource_path('fonts/DejaVuSans-Bold.ttf');
    }

    /**
     * Cadangan bila GD tidak aktif: soal aritmetika sederhana.
     *
     * @return array{token: string, pertanyaan: string, kedaluwarsa_detik: int}
     */
    private function buatAritmetika(): array
    {
        $angkaPertama = random_int(2, 9);
        $angkaKedua = random_int(2, 9);
        $operasi = random_int(0, 1) === 0 ? '+' : '-';

        if ($operasi === '-' && $angkaKedua > $angkaPertama) {
            [$angkaPertama, $angkaKedua] = [$angkaKedua, $angkaPertama];
        }

        $jawaban = $operasi === '+' ? $angkaPertama + $angkaKedua : $angkaPertama - $angkaKedua;
        $token = Str::random(40);

        Cache::put('captcha:'.$token, (string) $jawaban, self::TTL_SECONDS);

        return [
            'token' => $token,
            'pertanyaan' => $angkaPertama.' '.$operasi.' '.$angkaKedua.' = ?',
            'kedaluwarsa_detik' => self::TTL_SECONDS,
        ];
    }
}
