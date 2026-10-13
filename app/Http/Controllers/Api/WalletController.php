<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransaksiKoin;
use App\Models\Wallet;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Lihat saldo & total dampak lingkungan user
     * GET /api/wallet
     */
    public function show(Request $request)
    {
        $user = $request->user();

        $wallet = $user->wallet;

        if (! $wallet) {
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'saldo_koin' => 0,
                'total_koin_diperoleh' => 0,
                'total_sampah_kg' => 0,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'saldo_koin' => $wallet->saldo_koin,
                'total_koin_diperoleh' => $wallet->total_koin_diperoleh,
                'total_sampah_kg' => (float) $wallet->total_sampah_kg,
                'status_member' => $user->status_member ?? 'Bronze',
            ],
        ]);
    }

    /**
     * Riwayat mutasi koin
     * GET /api/transaksi-koin
     */
    public function transaksiKoin(Request $request)
    {
        $user = $request->user();

        $query = TransaksiKoin::where('user_id', $user->id)->orderBy('id', 'desc');

        if ($request->filled('tipe')) {
            $query->where('tipe', $request->tipe);
        }

        if ($request->filled('sumber')) {
            $query->where('sumber', $request->sumber);
        }

        $data = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }
}
