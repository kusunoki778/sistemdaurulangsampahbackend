<?php

namespace App\Services;

use App\Models\Pengaturan;
use App\Models\TopUp;
use App\Models\TransaksiKoin;
use App\Models\Wallet;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integrasi Midtrans Snap untuk Top Up Koin (uang masuk).
 *
 * - Snap token dibuat via HTTP client murni (tanpa package composer).
 * - Status pembayaran masuk lewat dua jalur: webhook notifikasi dan
 *   polling status saat user membuka detail top up. Keduanya memakai
 *   prosesNotifikasi() yang idempoten, jadi koin tidak pernah cair ganda.
 * - Payout/pencairan uang TIDAK dilayani service ini — payout reward tetap
 *   simulasi via CMS sesuai aturan proyek.
 */
class MidtransSnapService
{
    public const KUNCI_KURS = 'topup_kurs_rupiah';

    public const KUNCI_MINIMUM = 'topup_minimum_rupiah';

    public const KURS_DEFAULT = 10; // 1 koin = Rp 10

    public const MINIMUM_DEFAULT = 10000; // minimum top up Rp 10.000

    // ------------------------------------------------------------------
    // Pengaturan kurs & minimum (diatur admin via CMS)
    // ------------------------------------------------------------------

    public function kursKoin(): float
    {
        $kurs = (float) Pengaturan::ambil(self::KUNCI_KURS, self::KURS_DEFAULT);

        return $kurs > 0 ? $kurs : self::KURS_DEFAULT;
    }

    public function minimumRupiah(): float
    {
        $minimum = (float) Pengaturan::ambil(self::KUNCI_MINIMUM, self::MINIMUM_DEFAULT);

        return max(1000.0, $minimum);
    }

    /**
     * Konversi nominal rupiah ke koin. Dibulatkan ke bawah 2 desimal agar
     * koin yang dicairkan tidak pernah melebihi nominal yang dibayar.
     */
    public function hitungKoin(float $nominalRupiah): float
    {
        return floor(($nominalRupiah / $this->kursKoin()) * 100) / 100;
    }

    // ------------------------------------------------------------------
    // Membuat transaksi Snap
    // ------------------------------------------------------------------

    public function buatTransaksiSnap(TopUp $topUp): TopUp
    {
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '') {
            throw new RuntimeException('Server key Midtrans belum diatur di server.');
        }

        $user = $topUp->user;
        $grossAmount = (int) round((float) $topUp->nominal_rupiah);

        $payload = [
            'transaction_details' => [
                'order_id' => $topUp->order_id,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => [
                [
                    'id' => 'TOPUP-KOIN',
                    'price' => $grossAmount,
                    'quantity' => 1,
                    'name' => 'Top Up '.rtrim(rtrim((string) $topUp->koin_diterima, '0'), '.').' Koin Livora',
                ],
            ],
            'customer_details' => array_filter([
                'first_name' => $user?->name,
                'email' => $user?->email,
                'phone' => $user?->phone ?: null,
            ], fn ($nilai) => $nilai !== null && $nilai !== ''),
        ];

        try {
            // Midtrans memakai Basic auth: base64(server_key . ':').
            $respons = Http::withBasicAuth($serverKey, '')
                ->acceptJson()
                ->timeout(30)
                ->post($this->baseUrlSnap().'/snap/v1/transactions', $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Tidak dapat terhubung ke Midtrans. Coba lagi beberapa saat.');
        }

        if ($respons->failed()) {
            $pesan = $respons->json('status_message') ?? 'Midtrans menolak permintaan (HTTP '.$respons->status().').';
            throw new RuntimeException('Gagal membuat transaksi pembayaran: '.$pesan);
        }

        $topUp->update([
            'snap_token' => $respons->json('token'),
            'snap_redirect_url' => $respons->json('redirect_url'),
        ]);

        return $topUp->fresh();
    }

    // ------------------------------------------------------------------
    // Status & notifikasi
    // ------------------------------------------------------------------

    /**
     * Ambil status transaksi langsung dari API Midtrans (fallback bila
     * webhook belum sampai). Null berarti status tidak dapat diambil.
     */
    public function ambilStatusMidtrans(string $orderId): ?array
    {
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '') {
            return null;
        }

        try {
            $respons = Http::withBasicAuth($serverKey, '')
                ->acceptJson()
                ->timeout(20)
                ->get($this->baseUrlApi().'/v2/'.$orderId.'/status');
        } catch (ConnectionException) {
            return null;
        }

        if ($respons->failed()) {
            return null;
        }

        return $respons->json();
    }

    /**
     * Verifikasi signature Midtrans:
     * sha512(order_id + status_code + gross_amount + secret_key).
     */
    public function verifikasiSignature(string $orderId, string $statusCode, string $grossAmount, string $signature): bool
    {
        $secret = (string) (config('services.midtrans.signature_secret') ?: config('services.midtrans.server_key'));

        if ($secret === '' || $signature === '') {
            return false;
        }

        $diharapkan = hash('sha512', $orderId.$statusCode.$grossAmount.$secret);

        return hash_equals($diharapkan, $signature);
    }

    /**
     * Proses satu notifikasi (webhook ATAU hasil polling status).
     * Idempoten: notifikasi ulang untuk status yang sama tidak
     * mengkredit koin dua kali.
     */
    public function prosesNotifikasi(array $notif): TopUp
    {
        $orderId = (string) ($notif['order_id'] ?? '');
        $statusCode = (string) ($notif['status_code'] ?? '');
        $grossAmount = (string) ($notif['gross_amount'] ?? '');
        $signature = (string) ($notif['signature_key'] ?? '');

        if (! $this->verifikasiSignature($orderId, $statusCode, $grossAmount, $signature)) {
            throw new RuntimeException('Signature notifikasi Midtrans tidak valid.');
        }

        $topUp = TopUp::where('order_id', $orderId)->first();
        if (! $topUp) {
            throw new RuntimeException('Order top up tidak dikenal: '.$orderId);
        }

        $target = $this->petakanStatus($notif);

        return DB::transaction(function () use ($topUp, $target, $notif) {
            $locked = TopUp::lockForUpdate()->findOrFail($topUp->id);

            // Idempoten: status sudah sama, atau sudah lunas (abaikan
            // notifikasi telat seperti cancel/expire setelah paid).
            if ($locked->status === $target || $locked->status === 'paid') {
                $locked->fill(['payload_midtrans' => $notif])->save();

                return $locked;
            }

            if ($target === 'paid') {
                $this->kreditKoin($locked);
            }

            $locked->update([
                'status' => $target,
                'payload_midtrans' => $notif,
                'paid_at' => $target === 'paid' ? now() : $locked->paid_at,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Peta status Midtrans -> status topup internal.
     */
    private function petakanStatus(array $notif): string
    {
        $status = strtolower((string) ($notif['transaction_status'] ?? ''));
        $fraud = strtolower((string) ($notif['fraud_status'] ?? 'accept'));

        return match (true) {
            $status === 'settlement' => 'paid',
            $status === 'capture' && $fraud === 'accept' => 'paid',
            $status === 'capture' && $fraud === 'challenge' => 'pending',
            $status === 'pending' => 'pending',
            $status === 'expire' => 'expired',
            default => 'failed', // deny, cancel, refund, dll.
        };
    }

    private function kreditKoin(TopUp $topUp): void
    {
        $wallet = Wallet::where('user_id', $topUp->user_id)->lockForUpdate()->first();

        if (! $wallet) {
            $wallet = Wallet::create([
                'user_id' => $topUp->user_id,
                'saldo_koin' => 0,
                'koin_ditahan' => 0,
                'total_koin_diperoleh' => 0,
                'total_sampah_kg' => 0,
            ]);
        }

        $wallet->increment('saldo_koin', $topUp->koin_diterima);
        $wallet->increment('total_koin_diperoleh', $topUp->koin_diterima);

        TransaksiKoin::create([
            'user_id' => $topUp->user_id,
            'tipe' => 'masuk',
            'jumlah_koin' => $topUp->koin_diterima,
            'sumber' => 'topup',
            'referensi_id' => $topUp->id,
            'keterangan' => 'Top Up Koin via Midtrans: '.$topUp->order_id,
        ]);
    }

    // ------------------------------------------------------------------
    // Struk
    // ------------------------------------------------------------------

    /**
     * Struk top up. Pembayaran Sandbox Midtrans tetap diberi label
     * simulasi agar tidak ada kesan uang nyata berpindah tangan.
     */
    public function buatStruk(TopUp $topUp): array
    {
        $topUp->load('user');
        $payload = $topUp->payload_midtrans ?? [];
        $sandbox = (bool) config('services.midtrans.is_sandbox');

        return [
            'nomor_struk' => $topUp->order_id,
            'jenis' => 'top_up',
            'judul' => 'Struk Top Up Koin',
            'simulasi' => $sandbox,
            'status' => $topUp->status,
            'tanggal' => ($topUp->paid_at ?? $topUp->created_at)?->toIso8601String(),
            'member' => [
                'nama' => $topUp->user?->name,
                'username' => $topUp->user?->username,
            ],
            'nominal_rupiah' => (float) $topUp->nominal_rupiah,
            'kurs_rupiah' => (float) $topUp->kurs_rupiah,
            'koin_diterima' => (float) $topUp->koin_diterima,
            'metode_pembayaran' => $payload['payment_type'] ?? 'Midtrans Snap',
            'waktu_midtrans' => isset($payload['transaction_time']) ? (string) $payload['transaction_time'] : null,
            'catatan' => $sandbox
                ? 'Pembayaran Midtrans Sandbox - uang tidak nyata, hanya simulasi.'
                : null,
        ];
    }

    // ------------------------------------------------------------------
    // URL per lingkungan
    // ------------------------------------------------------------------

    private function baseUrlSnap(): string
    {
        return config('services.midtrans.is_sandbox')
            ? 'https://app.sandbox.midtrans.com'
            : 'https://app.midtrans.com';
    }

    private function baseUrlApi(): string
    {
        return config('services.midtrans.is_sandbox')
            ? 'https://api.sandbox.midtrans.com'
            : 'https://api.midtrans.com';
    }
}
