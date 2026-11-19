<?php

namespace App\Services;

use App\Models\PermintaanReward;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integrasi Xendit Payouts v3 untuk payout koin -> saldo e-wallet.
 *
 * Xendit adalah gerbang kirim-uang (disbursement), pas dengan alur Livora:
 * koin member di-HOLD saat pengajuan, dan setelah payout SUCCEEDED koin
 * dipotong + saldo rupiah masuk (RewardClaimService::disbursSandbox).
 *
 * - Nominal SELALU dihitung backend (koin_ditukar x KURS), tidak pernah
 *   diterima dari client.
 * - Status masuk lewat dua jalur: webhook x-callback-token dan polling
 *   GET /v3/payouts/{id}. Keduanya memanggil prosesStatus() yang idempoten,
 *   jadi koin tidak pernah dipotong dua kali.
 * - Idempotency-Key memakai reference_id deterministik per permintaan.
 */
class XenditPayoutService
{
    /** Status Xendit yang berarti dana sudah sampai ke penerima. */
    private const BERHASIL = 'SUCCEEDED';

    /** Status Xendit yang membatalkan alur: koin HOLD dikembalikan. */
    private const GAGAL = ['FAILED', 'REJECTED', 'REVERSED', 'EXPIRED', 'CANCELLED'];

    public function __construct(
        private readonly RewardClaimService $rewardClaim,
    ) {}

    /** Channel e-wallet yang diterima aplikasi (label di mobile). */
    public function channelTersedia(): array
    {
        return array_keys((array) config('services.xendit.channel', []));
    }

    /**
     * Buat payout Xendit untuk satu permintaan pencairan saldo.
     * $tujuan: ['channel' => 'DANA', 'nomor' => '08xxxx', 'nama_pemilik' => '...'].
     */
    public function buatPayout(PermintaanReward $permintaan, array $tujuan): PermintaanReward
    {
        $secret = (string) config('services.xendit.secret_key');

        if ($secret === '') {
            throw new RuntimeException('Layanan pencairan belum siap. Hubungi admin Livora.');
        }

        $channel = strtoupper(trim((string) ($tujuan['channel'] ?? '')));
        $routingValue = (string) ((config('services.xendit.channel') ?? [])[$channel] ?? '');
        $nomor = (string) preg_replace('/\D+/', '', (string) ($tujuan['nomor'] ?? ''));
        $nama = trim((string) ($tujuan['nama_pemilik'] ?? ''));

        if ($routingValue === '') {
            throw new RuntimeException(
                'Channel payout tidak dikenal. Pilih salah satu: '.implode(', ', $this->channelTersedia()).'.',
            );
        }

        if ($nomor === '' || ! str_starts_with($nomor, '08') || strlen($nomor) < 10 || strlen($nomor) > 14) {
            throw new RuntimeException('Nomor e-wallet tidak valid. Format: 08xxxxxxxxxx.');
        }

        if ($nama === '') {
            throw new RuntimeException('Nama pemilik e-wallet wajib diisi.');
        }

        // Payout yang masih jalan tidak boleh dibuat ulang.
        if ($permintaan->xendit_payout_id && ! in_array($permintaan->xendit_status, self::GAGAL, true)) {
            return $permintaan;
        }

        $amount = (int) round((float) $permintaan->koin_ditukar * RewardClaimService::KURS_RUPIAH_PER_KOIN);
        $minimum = (int) config('services.xendit.minimum_rupiah', 10000);

        if ($amount < $minimum) {
            throw new RuntimeException(
                'Minimal payout Rp '.number_format($minimum, 0, ',', '.').'. Tukar koin lebih banyak dulu.',
            );
        }

        // Reference id deterministik per permintaan; dipakai juga sebagai
        // Idempotency-Key supaya request ulang mengembalikan payout asli.
        $reference = $permintaan->xendit_reference_id
            ?: sprintf('LIVORA-PO-%d-%s', $permintaan->id, substr(md5($permintaan->id.'xendit'), 0, 8));

        $payload = [
            'reference_id' => $reference,
            'recipient' => [
                'type' => 'INDIVIDUAL',
                'relationship' => 'CUSTOMER',
                'given_name' => $nama,
                'account_details' => [
                    'currency' => 'IDR',
                    'account_country' => 'ID',
                    'account_holder_name' => $nama,
                    'account_number' => $nomor,
                    'routing_type_1' => 'WALLET',
                    'routing_value_1' => $routingValue,
                ],
                'address' => [
                    'country' => 'ID',
                    'street_line_1' => 'Kantor bank sampah Livora',
                    'city' => 'Jakarta',
                ],
            ],
            'payout_details' => [
                'source_currency' => 'IDR',
                'source_amount' => $amount,
                'destination_currency' => 'IDR',
            ],
            'source_of_fund' => (string) config('services.xendit.source_of_fund', 'BUSINESS_REVENUE'),
            'purpose_code' => (string) config('services.xendit.purpose_code', 'OTHER'),
            'description' => 'Payout koin Livora #'.$permintaan->id,
        ];

        try {
            $respons = Http::withBasicAuth($secret, ':')
                ->withHeaders([
                    'api-version' => (string) config('services.xendit.api_version', '2025-09-01'),
                    'idempotency-key' => $reference,
                    'Content-Type' => 'application/json',
                ])
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.xendit.co/v3/payouts', $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Tidak dapat terhubung ke Xendit. Coba lagi beberapa saat.');
        }

        if ($respons->failed()) {
            $pesan = $respons->json('message')
                ?? 'Xendit menolak permintaan (HTTP '.$respons->status().').';
            $detail = $respons->json('errors');

            if ($detail !== null) {
                $pesan .= ' Detail: '.json_encode($detail, JSON_UNESCAPED_SLASHES);
            }

            throw new RuntimeException('Gagal membuat payout: '.$pesan);
        }

        $permintaan->update([
            'xendit_payout_id' => $respons->json('payout_id'),
            'xendit_reference_id' => $reference,
            'xendit_channel_code' => $channel,
            'xendit_status' => $respons->json('status'),
            'xendit_payload' => array_merge(
                (array) $permintaan->xendit_payload,
                ['request' => $payload, 'response' => $respons->json()],
            ),
            'nomor_tujuan_digital' => $nomor,
        ]);

        return $permintaan->fresh();
    }

    /**
     * Ambil status payout langsung dari API Xendit (polling, jalur utama
     * karena webhook ngrok free kadang terhalang interstitial).
     * Null berarti status tidak dapat diambil (jaringan/kunci hilang).
     */
    public function ambilStatus(string $payoutId): ?array
    {
        $secret = (string) config('services.xendit.secret_key');

        if ($secret === '' || $payoutId === '') {
            return null;
        }

        try {
            $respons = Http::withBasicAuth($secret, ':')
                ->withHeaders(['api-version' => (string) config('services.xendit.api_version', '2025-09-01')])
                ->acceptJson()
                ->timeout(20)
                ->get('https://api.xendit.co/v3/payouts/'.$payoutId);
        } catch (ConnectionException) {
            return null;
        }

        if ($respons->failed()) {
            return null;
        }

        return $respons->json();
    }

    /**
     * Proses satu status payout (webhook ATAU hasil polling).
     * Idempoten: status ulang untuk payout yang sama tidak beri efek ganda.
     *
     * - SUCCEEDED -> payout diselesaikan (koin HOLD dipotong, saldo rupiah
     *   masuk, struk + FCM oleh RewardClaimService).
     * - FAILED/REJECTED/REVERSED/EXPIRED/CANCELLED -> koin HOLD dikembalikan
     *   ke wallet dan pengajuan dibatalkan.
     */
    public function prosesStatus(array $data): PermintaanReward
    {
        $payoutId = (string) ($data['payout_id'] ?? '');

        if ($payoutId === '') {
            throw new RuntimeException('Payload payout Xendit tidak dikenal.');
        }

        $permintaan = PermintaanReward::where('xendit_payout_id', $payoutId)->first();

        if (! $permintaan) {
            throw new RuntimeException('Payout tidak dikenal: '.$payoutId);
        }

        $status = strtoupper((string) ($data['status'] ?? ''));

        // Audit payload terlepas dari hasil.
        $permintaan->update([
            'xendit_status' => $status,
            'xendit_payload' => array_merge(
                (array) $permintaan->xendit_payload,
                ['last_status' => $data],
            ),
        ]);

        if ($status === self::BERHASIL) {
            if ($permintaan->status === 'selesai') {
                return $permintaan->fresh();
            }

            return $this->rewardClaim->disbursSandbox(
                $permintaan,
                'Payout Xendit terkonfirmasi. Dana dicairkan ke '
                    .substr((string) $permintaan->nomor_tujuan_digital, 0, 4)
                    .'xxxx'.substr((string) $permintaan->nomor_tujuan_digital, -4).'.',
            );
        }

        if (in_array($status, self::GAGAL, true)) {
            if (in_array($permintaan->status, ['pending', 'diproses'], true)) {
                return $this->rewardClaim->batalkan(
                    $permintaan,
                    'Payout Xendit gagal ('.$status.'): '
                        .(string) ($data['failure_code'] ?? 'tanpa kode'),
                );
            }
        }

        return $permintaan->fresh();
    }
}
