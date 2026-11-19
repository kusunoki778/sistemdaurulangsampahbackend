<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermintaanReward;
use App\Services\QrSessionService;
use App\Services\RewardClaimService;
use App\Services\XenditPayoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PermintaanRewardController extends Controller
{
    public function __construct(
        private readonly RewardClaimService $rewardClaimService,
        private readonly QrSessionService $qrSessionService,
        private readonly XenditPayoutService $xenditService,
    ) {}

    /**
     * User klaim reward.
     * Reward fisik memakai hold koin + kupon; reward digital langsung dipotong.
     * POST /api/permintaan-reward
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reward_id' => 'required|exists:rewards,id',
            'nomor_tujuan_digital' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $permintaan = $this->rewardClaimService->klaim(
            $user,
            (int) $validated['reward_id'],
            $validated['nomor_tujuan_digital'] ?? null,
        );

        $wallet = $user->wallet?->fresh();
        $fisik = $permintaan->reward?->tipe === 'barang';

        return response()->json([
            'status' => 'success',
            'message' => $fisik
                ? 'Kupon berhasil diterbitkan. Tunjukkan kode kupon di titik pengambilan Livora.'
                : 'Penukaran reward berhasil diajukan.',
            'data' => [
                'permintaan' => $permintaan,
                'sisa_koin' => (float) ($wallet?->saldo_koin ?? 0),
                'koin_ditahan' => (float) ($wallet?->koin_ditahan ?? 0),
                'saldo_tersedia' => (float) ($wallet?->saldo_tersedia ?? 0),
            ],
        ], 201);
    }

    /**
     * Riwayat penukaran reward user (sekaligus evaluasi kupon kedaluwarsa).
     * Nomor e-wallet disamarkan di daftar; nomor lengkap hanya di struk.
     * GET /api/permintaan-reward
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $this->rewardClaimService->expireDueForUser($user->id);

        $query = PermintaanReward::with('reward')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->paginate(15);
        $data->getCollection()->each(fn ($p) => $this->samarkanNomor($p));

        $wallet = $user->wallet;

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'meta' => [
                'saldo_koin' => (float) ($wallet?->saldo_koin ?? 0),
                'koin_ditahan' => (float) ($wallet?->koin_ditahan ?? 0),
                'saldo_tersedia' => (float) ($wallet?->saldo_tersedia ?? 0),
            ],
        ]);
    }

    /**
     * Detail permintaan reward.
     * GET /api/permintaan-reward/{id}
     */
    public function show(Request $request, $id)
    {
        $permintaan = PermintaanReward::with('reward')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);
        $this->samarkanNomor($permintaan);

        return response()->json([
            'status' => 'success',
            'data' => $permintaan,
        ]);
    }

    /**
     * Struk transaksi reward yang sudah selesai.
     * Nomor e-wallet tampil lengkap di sini.
     * GET /api/permintaan-reward/{id}/struk
     */
    public function struk(Request $request, $id)
    {
        $user = $request->user();

        $permintaan = PermintaanReward::with(['reward', 'user'])->findOrFail($id);

        if ($permintaan->user_id !== $user->id && ! $user->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Struk ini bukan milik akun Anda.',
            ], 403);
        }

        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

        if ($permintaan->status !== 'selesai') {
            return response()->json([
                'status' => 'error',
                'message' => 'Struk tersedia setelah transaksi reward selesai.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->rewardClaimService->buatStruk($permintaan),
        ]);
    }

    /**
     * Ganti nomor e-wallet dengan versi tersamar sebelum dikirim ke user.
     * Hanya mengubah atribut di memori, tidak menulis ke database.
     */
    private function samarkanNomor(PermintaanReward $permintaan): void
    {
        if ($permintaan->nomor_tujuan_digital) {
            $permintaan->nomor_tujuan_digital = $permintaan->nomor_tujuan_tersamar;
        }
    }

    /**
     * User memindai QR pickup reward yang ditampilkan admin.
     * POST /api/permintaan-reward/scan
     */
    public function scan(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $session = $this->qrSessionService->findValidReward($validated['token']);
        $viaKode = ! $session || ! $session->permintaanReward;

        if ($viaKode) {
            // Fallback: user masukkan kode kupon sendiri (LV-…) tanpa QR
            // petugas — hanya miliknya, milik orang lain tidak mungkin.
            $permintaan = $this->cariPermintaanSendiri($validated['token'], $request->user()->id);

            if (! $permintaan) {
                return $this->qrTidakValid('QR/kode kupon tidak valid. Masukkan kode kupon yang benar.');
            }
        } else {
            $permintaan = $session->permintaanReward;
        }

        if ($permintaan->user_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR ini bukan milik akun Anda.',
            ], 403);
        }

        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan->load('reward'));

        if ($permintaan->status === 'kedaluwarsa') {
            return $this->qrTidakValid('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
        }

        if (! in_array($permintaan->status, ['pending', 'siap_diambil'], true)) {
            return $this->qrTidakValid('Kupon ini tidak bisa dikonfirmasi (status: '.$permintaan->status.').');
        }

        $berlakuSampai = $viaKode ? $permintaan->expires_at : $session->expires_at;
        $expiresIn = $berlakuSampai ? max(0, (int) now()->diffInSeconds($berlakuSampai, false)) : 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'token' => $validated['token'],
                'expires_at' => $berlakuSampai?->toIso8601String(),
                'expires_in' => $expiresIn,
                'permintaan' => $permintaan,
            ],
        ]);
    }

    /**
     * User konfirmasi pengambilan reward. Koin hold resmi dipotong di sini.
     * Idempoten: konfirmasi ulang tidak memotong koin lagi.
     * POST /api/permintaan-reward/konfirmasi
     */
    public function konfirmasi(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $user = $request->user();
        $session = $this->qrSessionService->findValidReward($validated['token']);

        if (! $session || ! $session->permintaanReward) {
            // Bisa jadi QR sudah dipakai untuk konfirmasi sebelumnya. Cek
            // riwayat token agar konfirmasi ulang tetap idempoten.
            $session = $this->qrSessionService->findLatestReward($validated['token']);

            if (! $session || ! $session->permintaanReward) {
                // Fallback konfirmasi lewat kode kupon yang diketik user
                // sendiri (tanpa QR petugas, tetap terbatas miliknya).
                $permintaan = $this->cariPermintaanSendiri($validated['token'], $user->id);

                if (! $permintaan) {
                    return $this->qrTidakValid('QR tidak valid atau sudah kedaluwarsa. Minta admin menerbitkan QR baru.');
                }

                $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

                if ($permintaan->status === 'kedaluwarsa') {
                    return $this->qrTidakValid('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
                }

                if ($permintaan->status === 'selesai') {
                    return $this->responsSuksesKonfirmasi($permintaan->load('reward'), true);
                }

                if (! in_array($permintaan->status, ['pending', 'siap_diambil', 'diproses'], true)) {
                    return $this->qrTidakValid('Kupon ini tidak bisa dikonfirmasi (status: '.$permintaan->status.').');
                }

                try {
                    $permintaan = $this->rewardClaimService->selesaikan(
                        $permintaan,
                        'Reward diserahkan, dikonfirmasi user via kode kupon.',
                    );
                } catch (ValidationException $e) {
                    $pesan = collect($e->errors())->flatten()->first()
                        ?? 'Kupon belum bisa dikonfirmasi. Reward digital (voucher/saldo) harus diproses admin dulu.';

                    return $this->qrTidakValid($pesan);
                }

                return $this->responsSuksesKonfirmasi($permintaan, false);
            }

            $permintaan = $session->permintaanReward;

            if ($permintaan->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'QR ini bukan milik akun Anda.',
                ], 403);
            }

            if ($permintaan->status !== 'selesai') {
                return $this->qrTidakValid('QR sudah kedaluwarsa atau tidak bisa dipakai lagi. Minta admin menerbitkan QR baru.');
            }

            return $this->responsSuksesKonfirmasi($permintaan->load('reward'), true);
        }

        $permintaan = $session->permintaanReward;

        if ($permintaan->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR ini bukan milik akun Anda.',
            ], 403);
        }

        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan->load('reward'));

        if ($permintaan->status === 'kedaluwarsa') {
            return $this->qrTidakValid('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
        }

        if (! in_array($permintaan->status, ['pending', 'siap_diambil', 'selesai'], true)) {
            return $this->qrTidakValid('Kupon ini tidak bisa dikonfirmasi (status: '.$permintaan->status.').');
        }

        $permintaan = $this->rewardClaimService->selesaikan(
            $permintaan,
            'Reward diserahkan dan dikonfirmasi user lewat QR pickup.',
        );

        $this->qrSessionService->consumeReward($session);

        return $this->responsSuksesKonfirmasi($permintaan, false);
    }

    private function responsSuksesKonfirmasi(PermintaanReward $permintaan, bool $idempoten)
    {
        $wallet = $permintaan->user?->wallet?->fresh();

        return response()->json([
            'status' => 'success',
            'message' => $idempoten
                ? 'Pengambilan reward sudah dikonfirmasi sebelumnya.'
                : 'Pengambilan reward berhasil dikonfirmasi. Koin resmi dipotong.',
            'data' => [
                'permintaan' => $permintaan,
                'koin_ditukar' => (float) $permintaan->koin_ditukar,
                'sisa_koin' => (float) ($wallet?->saldo_koin ?? 0),
                'saldo_tersedia' => (float) ($wallet?->saldo_tersedia ?? 0),
            ],
        ]);
    }

    /**
     * User ajukan tukar koin ke saldo Dana (hadiah bentuk saldo).
     * Kurs: Rp 1.000 per 10 koin.
     * POST /api/permintaan-reward/konversi-saldo
     */
    public function konversiSaldo(Request $request)
    {
        // Metode "dompet" = konversi langsung ke saldo internal (tanpa tujuan
        // eksternal). Metode lain = payout ke e-wallet/bank (butuh nomor+nama).
        $metode = (string) $request->input('metode');

        if ($metode === 'dompet') {
            $validated = $request->validate([
                'jumlah_koin' => 'required|numeric|min:10|max:1000000',
            ]);

            $permintaan = $this->rewardClaimService->konversiKeDompet(
                $request->user(),
                (float) $validated['jumlah_koin'],
            );

            $wallet = $request->user()->wallet?->fresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil! Koin sudah jadi saldo rupiah di dompetmu.',
                'data' => [
                    'permintaan_id' => $permintaan->id,
                    'kode_klaim' => $permintaan->kode_klaim,
                    'jumlah_koin' => (float) $permintaan->koin_ditukar,
                    'nilai_rupiah' => round((float) $permintaan->koin_ditukar * RewardClaimService::KURS_RUPIAH_PER_KOIN, 2),
                    'metode' => 'dompet',
                    'struk_nomor' => $permintaan->struk_nomor,
                    'saldo_rupiah' => (float) ($wallet?->saldo_rupiah ?? 0),
                ],
            ], 201);
        }

        $validated = $request->validate([
            'jumlah_koin' => 'required|numeric|min:10|max:1000000',
            'metode' => 'required|string|in:dana,ovo,shopeepay,linkaja,astrapay',
            'nomor_tujuan' => 'required|string|min:9|max:20',
            'nama_pemilik' => 'required|string|min:3|max:60',
        ]);

        $permintaan = $this->rewardClaimService->konversiSaldo(
            $request->user(),
            (float) $validated['jumlah_koin'],
            $validated['metode'],
            $validated['nomor_tujuan'],
            $validated['nama_pemilik'],
        );

        $wallet = $request->user()->wallet?->fresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Pengajuan pencairan dibuat. Koin ditahan sampai payout Xendit berhasil.',
            'data' => [
                'permintaan_id' => $permintaan->id,
                'kode_klaim' => $permintaan->kode_klaim,
                'jumlah_koin' => (float) $permintaan->koin_ditukar,
                'nilai_rupiah' => round((float) $permintaan->koin_ditukar * RewardClaimService::KURS_RUPIAH_PER_KOIN, 2),
                'metode' => $validated['metode'],
                'nomor_tujuan' => $validated['nomor_tujuan'],
                'nama_pemilik' => $validated['nama_pemilik'],
                'saldo_rupiah' => (float) ($wallet?->saldo_rupiah ?? 0),
            ],
        ], 201);
    }

    /**
     * Konfirmasi pembayaran sandbox user — simulasi disbursement ala
     * payment gateway. pending -> diproses -> selesai, Dana langsung cair.
     * POST /api/permintaan-reward/{id}/bayar-sandbox
     */
    public function bayarSandbox(Request $request, $id)
    {
        // Demo-only: jangan biarkan pengguna "mencetak" saldo rupiah di
        // produksi tanpa pembayaran. Aktifkan lewat SANDBOX_PAYOUT=true.
        if (! config('livora.sandbox_payout')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pencairan ini tidak tersedia. Gunakan jalur payout Xendit.',
            ], 403);
        }

        $permintaan = PermintaanReward::with('reward')
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (! $permintaan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pengajuan pencairan tidak ditemukan.',
            ], 404);
        }

        if ($permintaan->reward?->tipe !== 'saldo_digital') {
            return $this->qrTidakValid('Pengajuan ini bukan pencairan saldo.');

        }

        if ($permintaan->status === 'selesai') {
            return $this->responsSuksesKonfirmasi($permintaan, true);
        }

        if (! in_array($permintaan->status, ['pending'], true)) {
            return $this->qrTidakValid('Pengajuan tidak bisa diproses (status: '.$permintaan->status.').');
        }

        try {
            $permintaan = $this->rewardClaimService->disbursSandbox($permintaan);
        } catch (ValidationException $e) {
            $pesan = collect($e->errors())->flatten()->first() ?? 'Pembayaran gagal diproses.';

            return $this->qrTidakValid($pesan);
        }

        $wallet = $request->user()->wallet?->fresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Pencairan berhasil diproses. Dana telah masuk ke akun Anda.',
            'data' => [
                'permintaan' => $permintaan,
                'nilai_rupiah' => round((float) $permintaan->koin_ditukar * RewardClaimService::KURS_RUPIAH_PER_KOIN, 2),
                'sisa_koin' => (float) ($wallet?->saldo_koin ?? 0),
                'saldo_rupiah' => (float) ($wallet?->saldo_rupiah ?? 0),
            ],
        ]);
    }

    /**
     * Buat payout Xendit untuk pengajuan pencairan saldo. Koin tetap di
     * HOLD; potongan baru terjadi saat payout SUCCEEDED. Nominal dihitung
     * backend, tujuan divalidasi (channel allowlist + nomor e-wallet).
     * POST /api/permintaan-reward/{id}/xendit-payout
     */
    public function xenditPayout(Request $request, $id)
    {
        $data = $request->validate([
            'channel' => 'required|string|max:20',
            'nomor' => 'required|string|max:20',
            'nama_pemilik' => 'required|string|max:100',
        ]);

        $permintaan = PermintaanReward::with('reward')
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (! $permintaan) {
            return $this->qrTidakValid('Pengajuan pencairan tidak ditemukan.');
        }

        if ($permintaan->reward?->tipe !== 'saldo_digital') {
            return $this->qrTidakValid('Pengajuan ini bukan pencairan saldo.');
        }

        if ($permintaan->status !== 'pending') {
            return $this->qrTidakValid('Pengajuan tidak bisa dibayar (status: '.$permintaan->status.').');
        }

        // Payout kedaluwarsa tidak boleh dibayar.
        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);
        if ($permintaan->status !== 'pending') {
            return $this->qrTidakValid('Waktu pembayaran sudah habis; koin sudah kembali ke wallet.');
        }

        try {
            $permintaan = $this->xenditService->buatPayout($permintaan, $data);
        } catch (RuntimeException $e) {
            return $this->qrTidakValid($e->getMessage());
        } catch (Throwable $e) {
            \Log::error('xendit-payout gagal', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->qrTidakValid('Gagal membuat payout Xendit.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payout Xendit dibuat. Dana sedang dalam proses dikirim ke e-wallet tujuan.',
            'data' => [
                'payout_id' => $permintaan->xendit_payout_id,
                'reference_id' => $permintaan->xendit_reference_id,
                'status_xendit' => $permintaan->xendit_status,
                'channel' => $permintaan->xendit_channel_code,
                'nilai_rupiah' => round((float) $permintaan->koin_ditukar * RewardClaimService::KURS_RUPIAH_PER_KOIN, 2),
            ],
        ]);
    }

    /**
     * Polling status payout Xendit (jalur utama; webhook cadangan).
     * Idempoten: selesai tidak diproses ulang.
     * POST /api/permintaan-reward/{id}/xendit-cek
     */
    public function xenditCekStatus(Request $request, $id)
    {
        $permintaan = PermintaanReward::with('reward')
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (! $permintaan) {
            return $this->qrTidakValid('Pengajuan pencairan tidak ditemukan.');
        }

        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan->load('reward'));

        if ($permintaan->status === 'selesai') {
            $wallet = $request->user()->wallet?->fresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Payout sudah terkonfirmasi sebelumnya. Dana sudah ada di akun Anda.',
                'data' => [
                    'permintaan' => $permintaan,
                    'nilai_rupiah' => round((float) $permintaan->koin_ditukar * RewardClaimService::KURS_RUPIAH_PER_KOIN, 2),
                    'sisa_koin' => (float) ($wallet?->saldo_koin ?? 0),
                    'saldo_rupiah' => (float) ($wallet?->saldo_rupiah ?? 0),
                ],
            ]);
        }

        if (! $permintaan->xendit_payout_id) {
            return $this->qrTidakValid('Belum ada payout Xendit untuk pengajuan ini. Kirim payout terlebih dahulu.');
        }

        $status = $this->xenditService->ambilStatus((string) $permintaan->xendit_payout_id);

        if ($status === null) {
            return response()->json([
                'status' => 'pending',
                'message' => 'Status Xendit belum bisa diambil (jaringan/kunci). Coba lagi sebentar.',
                'data' => ['permintaan_status' => $permintaan->status],
            ], 202);
        }

        try {
            $permintaan = $this->xenditService->prosesStatus($status);
        } catch (RuntimeException $e) {
            return $this->qrTidakValid($e->getMessage());
        }

        if ($permintaan->status === 'selesai') {
            $wallet = $request->user()->wallet?->fresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Payout Xendit terkonfirmasi. Dana telah masuk ke akun Anda.',
                'data' => [
                    'permintaan' => $permintaan,
                    'nilai_rupiah' => round((float) $permintaan->koin_ditukar * RewardClaimService::KURS_RUPIAH_PER_KOIN, 2),
                    'sisa_koin' => (float) ($wallet?->saldo_koin ?? 0),
                    'saldo_rupiah' => (float) ($wallet?->saldo_rupiah ?? 0),
                ],
            ]);
        }

        if ($permintaan->status === 'dibatalkan') {
            return $this->qrTidakValid('Payout Xendit gagal. Koin sudah kembali ke wallet; ajukan pencairan baru.');
        }

        return response()->json([
            'status' => 'pending',
            'message' => 'Dana sedang dalam perjalanan ke e-wallet tujuan. Cek status lagi sebentar.',
            'data' => [
                'permintaan_status' => $permintaan->status,
                'status_xendit' => $permintaan->xendit_status,
            ],
        ], 202);
    }

    private function qrTidakValid(string $pesan)
    {
        return response()->json([
            'status' => 'error',
            'message' => $pesan,
        ], 422);
    }

    /**
     * Cari permintaan reward milik user ini dari masukan manual:
     * payload QR user ("LIVORA-REWARD:<id>"), id, atau kode kupon (LV-…).
     */
    private function cariPermintaanSendiri(string $input, int $userId): ?PermintaanReward
    {
        $kode = trim($input);

        if (str_contains($kode, ':')) {
            $kode = substr($kode, (int) strrpos($kode, ':') + 1);
        }

        $kode = strtoupper(preg_replace('/\s+/', '', $kode) ?? $kode);

        if ($kode === '') {
            return null;
        }

        if (ctype_digit($kode)) {
            return PermintaanReward::with('reward')
                ->where('id', (int) $kode)
                ->where('user_id', $userId)
                ->first();
        }

        return PermintaanReward::with('reward')
            ->where('kode_klaim', $kode)
            ->where('user_id', $userId)
            ->first();
    }
}
