<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\Request;

/**
 * Katalog reward publik (GET /api/rewards, GET /api/rewards/{id}).
 * CRUD admin memakai AdminController::reward* (middleware admin +
 * pengawal relasi) — jangan tambahkan pintu tulis di sini.
 */
class RewardController extends Controller
{
    /**
     * Daftar katalog reward
     * GET /api/rewards
     */
    public function index(Request $request)
    {
        $query = Reward::where('status_aktif', true)
            // Reward tipe saldo_digital = reward SISTEM (stok 999999, harga 0)
            // — bukan item katalog; tukar saldo memakai banner Tukar Saldo.
            ->where('tipe', '!=', 'saldo_digital');

        if ($request->filled('tipe')) {
            $query->where('tipe', $request->tipe);
        }

        $rewards = $query->orderBy('koin_dibutuhkan', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $rewards,
        ]);
    }

    /**
     * Detail reward
     * GET /api/rewards/{id}
     */
    public function show($id)
    {
        $reward = Reward::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $reward,
        ]);
    }
}
