<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermintaanReward;
use App\Models\Reward;
use App\Models\TransaksiKoin;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PermintaanRewardController extends Controller
{
    /**
     * User klaim reward (potong koin)
     * POST /api/permintaan-reward
     */
    public function store(Request $request)
    {
        $request->validate([
            'reward_id' => 'required|exists:rewards,id',
            'nomor_tujuan_digital' => 'nullable|string|max:50',
        ]);

        return DB::transaction(function () use ($request) {
            $user = $request->user();
            $reward = Reward::findOrFail($request->reward_id);

            if (! $reward->status_aktif) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reward ini tidak aktif',
                ], 400);
            }

            if ($reward->stok <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Maaf, stok reward ini sudah habis',
                ], 400);
            }

            // Cek wallet user
            $wallet = $user->wallet;
            if (! $wallet) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Wallet tidak ditemukan',
                ], 404);
            }

            if ($wallet->saldo_koin < $reward->koin_dibutuhkan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Saldo koin Anda tidak mencukupi',
                    'data' => [
                        'saldo_koin' => $wallet->saldo_koin,
                        'koin_dibutuhkan' => $reward->koin_dibutuhkan,
                    ],
                ], 400);
            }

            // Generate kode klaim
            $kodeKlaim = 'CLM-'.now()->format('Ymd').'-'.str_pad(PermintaanReward::count() + 1, 4, '0', STR_PAD_LEFT);

            // Potong saldo koin
            $wallet->decrement('saldo_koin', $reward->koin_dibutuhkan);

            // Kurangi stok reward
            $reward->decrement('stok', 1);

            // Buat permintaan reward
            $permintaan = PermintaanReward::create([
                'kode_klaim' => $kodeKlaim,
                'user_id' => $user->id,
                'reward_id' => $reward->id,
                'koin_ditukar' => $reward->koin_dibutuhkan,
                'status' => 'pending',
                'nomor_tujuan_digital' => $request->nomor_tujuan_digital ?? null,
                'catatan_admin' => 'Permintaan sedang dalam proses verifikasi oleh admin.',
            ]);

            // Catat transaksi koin keluar
            TransaksiKoin::create([
                'user_id' => $user->id,
                'tipe' => 'keluar',
                'jumlah_koin' => $reward->koin_dibutuhkan,
                'sumber' => 'reward_claim',
                'referensi_id' => $permintaan->id,
                'keterangan' => 'Klaim reward: '.$reward->nama_reward,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Penukaran reward berhasil diajukan!',
                'data' => [
                    'permintaan' => $permintaan->load('reward'),
                    'sisa_koin' => $wallet->fresh()->saldo_koin,
                ],
            ], 201);
        });
    }

    /**
     * Riwayat penukaran reward user
     * GET /api/permintaan-reward
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PermintaanReward::with('reward')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Detail permintaan reward
     * GET /api/permintaan-reward/{id}
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $permintaan = PermintaanReward::with('reward')
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $permintaan,
        ]);
    }
}
