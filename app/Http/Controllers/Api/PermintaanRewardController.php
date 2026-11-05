<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermintaanReward;
use App\Services\QrSessionService;
use App\Services\RewardClaimService;
use Illuminate\Http\Request;

class PermintaanRewardController extends Controller
{
    public function __construct(
        private readonly RewardClaimService $rewardClaimService,
        private readonly QrSessionService $qrSessionService,
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

        $wallet = $user->wallet;

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(15),
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

        return response()->json([
            'status' => 'success',
            'data' => $permintaan,
        ]);
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

        if (! $session || ! $session->permintaanReward) {
            return $this->qrTidakValid('QR tidak valid atau sudah kedaluwarsa. Minta admin menerbitkan QR baru.');
        }

        $permintaan = $session->permintaanReward;

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

        $expiresIn = max(0, (int) now()->diffInSeconds($session->expires_at, false));

        return response()->json([
            'status' => 'success',
            'data' => [
                'token' => $validated['token'],
                'expires_at' => $session->expires_at->toIso8601String(),
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
                return $this->qrTidakValid('QR tidak valid atau sudah kedaluwarsa. Minta admin menerbitkan QR baru.');
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

    private function qrTidakValid(string $pesan)
    {
        return response()->json([
            'status' => 'error',
            'message' => $pesan,
        ], 422);
    }
}
