<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermintaanReward;
use App\Models\Reward;
use App\Models\TransaksiKoin;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPermintaanRewardController extends Controller
{
    /**
     * Admin lihat daftar pengajuan reward
     * GET /api/admin/permintaan-reward
     */
    public function index(Request $request)
    {
        $query = PermintaanReward::with(['user', 'reward']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $data = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Admin konfirmasi penyerahan/payout reward
     * POST /api/admin/permintaan-reward/{id}/selesai
     */
    public function selesai(Request $request, $id)
    {
        $request->validate([
            'catatan_admin' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $permintaan = PermintaanReward::with(['user', 'reward'])->findOrFail($id);

            if ($permintaan->status !== 'pending' && $permintaan->status !== 'diproses') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permintaan ini sudah diproses sebelumnya (status: '.$permintaan->status.')',
                ], 400);
            }

            $permintaan->update([
                'status' => 'selesai',
                'catatan_admin' => $request->catatan_admin ?? 'Reward telah diproses dan diserahkan.',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Permintaan reward berhasil diselesaikan!',
                'data' => $permintaan->fresh(['user', 'reward']),
            ]);
        });
    }

    /**
     * Admin tolak klaim reward -> refund koin otomatis
     * POST /api/admin/permintaan-reward/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'catatan_admin' => 'required|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $permintaan = PermintaanReward::with(['user', 'reward'])->findOrFail($id);

            if ($permintaan->status !== 'pending' && $permintaan->status !== 'diproses') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permintaan ini sudah diproses sebelumnya (status: '.$permintaan->status.')',
                ], 400);
            }

            $user = $permintaan->user;
            $reward = $permintaan->reward;

            // Refund koin ke wallet user
            $wallet = $user->wallet;
            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'saldo_koin' => 0,
                    'total_koin_diperoleh' => 0,
                    'total_sampah_kg' => 0,
                ]);
            }
            $wallet->increment('saldo_koin', $permintaan->koin_ditukar);

            // Kembalikan stok reward
            if ($reward) {
                $reward->increment('stok', 1);
            }

            // Update status permintaan
            $permintaan->update([
                'status' => 'ditolak',
                'catatan_admin' => $request->catatan_admin,
            ]);

            // Catat transaksi refund
            TransaksiKoin::create([
                'user_id' => $user->id,
                'tipe' => 'masuk',
                'jumlah_koin' => $permintaan->koin_ditukar,
                'sumber' => 'refund',
                'referensi_id' => $permintaan->id,
                'keterangan' => 'REFUND: Klaim reward "'.($reward->nama_reward ?? 'Unknown').'" ditolak.',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Permintaan reward ditolak. Koin telah dikembalikan ke wallet user.',
                'data' => $permintaan->fresh(['user', 'reward']),
            ]);
        });
    }
}
