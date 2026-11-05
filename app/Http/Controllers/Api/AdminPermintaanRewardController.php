<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pengaturan;
use App\Models\PermintaanReward;
use App\Services\QrSessionService;
use App\Services\RewardClaimService;
use Illuminate\Http\Request;

class AdminPermintaanRewardController extends Controller
{
    public function __construct(
        private readonly RewardClaimService $rewardClaimService,
        private readonly QrSessionService $qrSessionService,
    ) {}

    /**
     * Admin lihat daftar pengajuan reward (lazy expiry dijalankan lebih dulu).
     * GET /api/admin/permintaan-reward
     */
    public function index(Request $request)
    {
        $this->rewardClaimService->expireDue();

        $query = PermintaanReward::with(['user', 'reward']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('tipe')) {
            $query->whereHas('reward', fn ($q) => $q->where('tipe', $request->tipe));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('kode_klaim', 'like', '%'.$search.'%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%'.$search.'%')
                            ->orWhere('username', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
            });
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->orderBy('id', 'desc')->paginate(20),
        ]);
    }

    /**
     * Admin mencari kupon berdasarkan kode (mis. LV-ABCD-2345).
     * GET /api/admin/permintaan-reward/kupon/{kode}
     */
    public function cariKupon($kode)
    {
        $kode = strtoupper(trim((string) $kode));

        $permintaan = PermintaanReward::with(['user', 'reward'])
            ->where('kode_klaim', $kode)
            ->first();

        if (! $permintaan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kupon dengan kode '.$kode.' tidak ditemukan.',
            ], 404);
        }

        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

        return response()->json([
            'status' => 'success',
            'data' => $permintaan,
        ]);
    }

    /**
     * Admin memvalidasi kupon lalu menampilkan QR pickup untuk dipindai user.
     * POST /api/admin/permintaan-reward/{id}/qr
     */
    public function terbitkanQr($id)
    {
        $permintaan = PermintaanReward::with(['user', 'reward'])->findOrFail($id);
        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

        if ($permintaan->status === 'kedaluwarsa') {
            return $this->gagal('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
        }

        if (! in_array($permintaan->status, ['pending', 'siap_diambil'], true)) {
            return $this->gagal('Kupon ini tidak bisa diproses (status: '.$permintaan->status.').');
        }

        if ($permintaan->reward?->tipe !== 'barang') {
            return $this->gagal('QR pickup hanya untuk reward fisik (barang).');
        }

        if ($permintaan->status === 'pending') {
            $permintaan->update(['status' => 'siap_diambil']);
        }

        $qr = $this->qrSessionService->issueReward($permintaan);

        return response()->json([
            'status' => 'success',
            'message' => 'QR pickup diterbitkan. Minta user memindai QR ini.',
            'data' => [
                'permintaan' => $permintaan->fresh(['user', 'reward']),
                'qr' => $qr,
            ],
        ]);
    }

    /**
     * Admin mulai memproses payout reward digital (simulasi).
     * pending -> diproses.
     * POST /api/admin/permintaan-reward/{id}/proses
     */
    public function proses(Request $request, $id)
    {
        $validated = $request->validate([
            'catatan_admin' => 'nullable|string|max:500',
        ]);

        $permintaan = PermintaanReward::with(['user', 'reward'])->findOrFail($id);
        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

        if ($permintaan->status === 'kedaluwarsa') {
            return $this->gagal('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
        }

        $permintaan = $this->rewardClaimService->proses($permintaan, $validated['catatan_admin'] ?? null);

        return response()->json([
            'status' => 'success',
            'message' => 'Payout disimulasikan: permintaan sedang diproses.',
            'data' => $permintaan,
        ]);
    }

    /**
     * Payout digital gagal -> refund koin + kembalikan stok.
     * POST /api/admin/permintaan-reward/{id}/gagal
     */
    public function tandaiGagal(Request $request, $id)
    {
        $validated = $request->validate([
            'catatan_admin' => 'required|string|max:500',
        ]);

        $permintaan = PermintaanReward::with(['user', 'reward'])->findOrFail($id);
        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

        if ($permintaan->status === 'kedaluwarsa') {
            return $this->gagal('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
        }

        $permintaan = $this->rewardClaimService->tandaiGagal($permintaan, $validated['catatan_admin']);

        return response()->json([
            'status' => 'success',
            'message' => 'Payout ditandai gagal. Koin dikembalikan ke wallet member.',
            'data' => $permintaan,
        ]);
    }

    /**
     * Admin menyelesaikan reward digital/legacy tanpa QR.
     * Reward fisik baru (punya hold koin) wajib lewat QR pickup.
     * POST /api/admin/permintaan-reward/{id}/selesai
     */
    public function selesai(Request $request, $id)
    {
        $validated = $request->validate([
            'catatan_admin' => 'nullable|string|max:500',
        ]);

        $permintaan = PermintaanReward::with(['user', 'reward'])->findOrFail($id);
        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

        if ($permintaan->status === 'kedaluwarsa') {
            return $this->gagal('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
        }

        if ((float) $permintaan->koin_ditahan > 0) {
            return $this->gagal('Reward fisik harus diselesaikan lewat QR pickup yang dipindai user.');
        }

        $permintaan = $this->rewardClaimService->selesaikan($permintaan, $validated['catatan_admin'] ?? null);

        $this->qrSessionService->revokeRewardFor($permintaan);

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan reward berhasil diselesaikan.',
            'data' => $permintaan,
        ]);
    }

    /**
     * Admin membatalkan kupon. Hold koin dilepas (atau refund untuk data
     * legacy/digital) dan stok dikembalikan.
     * POST /api/admin/permintaan-reward/{id}/batalkan
     */
    public function batalkan(Request $request, $id)
    {
        $validated = $request->validate([
            'catatan_admin' => 'required|string|max:500',
        ]);

        $permintaan = PermintaanReward::with(['user', 'reward'])->findOrFail($id);
        $permintaan = $this->rewardClaimService->expireIfNeeded($permintaan);

        if ($permintaan->status === 'kedaluwarsa') {
            return $this->gagal('Kupon sudah kedaluwarsa. Koin sudah dikembalikan otomatis.');
        }

        $permintaan = $this->rewardClaimService->batalkan($permintaan, $validated['catatan_admin']);

        $this->qrSessionService->revokeRewardFor($permintaan);

        return response()->json([
            'status' => 'success',
            'message' => 'Kupon dibatalkan. Koin dan stok sudah dikembalikan.',
            'data' => $permintaan,
        ]);
    }

    /**
     * Pengaturan masa berlaku kupon (hari).
     * GET /api/admin/permintaan-reward/pengaturan
     */
    public function pengaturan()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'masa_berlaku_hari' => $this->rewardClaimService->masaBerlakuHari(),
            ],
        ]);
    }

    /**
     * PUT /api/admin/permintaan-reward/pengaturan
     */
    public function simpanPengaturan(Request $request)
    {
        $validated = $request->validate([
            'masa_berlaku_hari' => 'required|integer|min:1|max:30',
        ]);

        Pengaturan::simpan(
            RewardClaimService::KUNCI_MASA_BERLAKU,
            $validated['masa_berlaku_hari'],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Masa berlaku kupon diperbarui menjadi '.$validated['masa_berlaku_hari'].' hari.',
            'data' => [
                'masa_berlaku_hari' => $this->rewardClaimService->masaBerlakuHari(),
            ],
        ]);
    }

    private function gagal(string $pesan)
    {
        return response()->json([
            'status' => 'error',
            'message' => $pesan,
        ], 422);
    }
}
