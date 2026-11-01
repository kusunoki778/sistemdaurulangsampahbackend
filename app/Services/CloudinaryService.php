<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Upload gambar ke Cloudinary lewat REST API (tanpa package tambahan).
 *
 * Kredensial dibaca dari config/services.php (env CLOUDINARY_*).
 * Dipakai endpoint admin untuk mengisi kolom `gambar` (URL string) pada
 * jenis sampah, reward, dan artikel.
 */
class CloudinaryService
{
    /**
     * Ambil nilai gambar untuk disimpan: hasil upload file (gambar_file)
     * bila ada, atau URL string manual (gambar) bila tidak.
     *
     * Melempar ValidationException bila upload gagal supaya admin
     * mendapat pesan yang jelas, bukan gambar yang hilang diam-diam.
     */
    public function resolveGambar(Request $request, string $folder): ?string
    {
        if (! $request->hasFile('gambar_file')) {
            return $request->input('gambar');
        }

        $url = $this->upload($request->file('gambar_file'), $folder);

        if ($url === null) {
            throw ValidationException::withMessages([
                'gambar_file' => 'Gagal mengunggah gambar ke Cloudinary. Periksa konfigurasi CLOUDINARY_* di server.',
            ]);
        }

        return $url;
    }

    /**
     * Upload satu file ke Cloudinary. Mengembalikan secure_url atau null
     * bila gagal (kesalahan dicatat di log, tidak meledak ke user).
     */
    public function upload(UploadedFile $file, string $folder): ?string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (! $cloudName || ! $apiKey || ! $apiSecret) {
            Log::warning('Upload Cloudinary dilewati: CLOUDINARY_* belum dikonfigurasi.');

            return null;
        }

        // Tanda tangan signed upload: parameter diurutkan abjad lalu di-sha1
        // bersama api_secret (aturan resmi Cloudinary).
        $timestamp = time();
        $toSign = 'folder='.$folder.'&timestamp='.$timestamp;
        $signature = sha1($toSign.$apiSecret);

        $response = Http::asMultipart()
            ->attach('file', $file->getContent(), $file->getClientOriginalName())
            ->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
                ['name' => 'api_key', 'contents' => $apiKey],
                ['name' => 'timestamp', 'contents' => (string) $timestamp],
                ['name' => 'folder', 'contents' => $folder],
                ['name' => 'signature', 'contents' => $signature],
            ]);

        if ($response->failed()) {
            Log::warning('Upload Cloudinary gagal', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('secure_url');
    }
}
