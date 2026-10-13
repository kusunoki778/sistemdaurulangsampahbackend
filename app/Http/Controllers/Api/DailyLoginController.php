<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransaksiKoin;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyLoginController extends Controller
{
    /**
     * Klaim koin harian (daily login reward)
     * POST /api/daily-login
     */
    public function claim(Request $request)
    {
        $user = $request->user();

        // Cek apakah sudah klaim hari ini
        $todayClaim = TransaksiKoin::where('user_id', $user->id)
            ->where('sumber', 'daily_login')
            ->whereDate('created_at', today())
            ->first();

        if ($todayClaim) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda sudah mengklaim hadiah harian hari ini',
                'data' => [
                    'already_claimed' => true,
                    'claimed_at' => $todayClaim->created_at,
                ],
            ], 400);
        }

        $koinReward = 10; // Fixed daily login reward

        return DB::transaction(function () use ($user, $koinReward) {
            $wallet = $user->wallet;
            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'saldo_koin' => 0,
                    'total_koin_diperoleh' => 0,
                    'total_sampah_kg' => 0,
                ]);
            }

            $wallet->increment('saldo_koin', $koinReward);
            $wallet->increment('total_koin_diperoleh', $koinReward);

            $transaksi = TransaksiKoin::create([
                'user_id' => $user->id,
                'tipe' => 'masuk',
                'jumlah_koin' => $koinReward,
                'sumber' => 'daily_login',
                'keterangan' => 'Hadiah login harian',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Selamat! Anda mendapatkan '.$koinReward.' koin dari login harian!',
                'data' => [
                    'koin_diperoleh' => $koinReward,
                    'sisa_koin' => $wallet->fresh()->saldo_koin,
                    'transaksi' => $transaksi,
                ],
            ]);
        });
    }

    /**
     * Cek status daily login
     * GET /api/daily-login/status
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $todayClaim = TransaksiKoin::where('user_id', $user->id)
            ->where('sumber', 'daily_login')
            ->whereDate('created_at', today())
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'already_claimed' => $todayClaim !== null,
                'claimed_at' => $todayClaim?->created_at,
                'koin_reward' => 10,
            ],
        ]);
    }
}
