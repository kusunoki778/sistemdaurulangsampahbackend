<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    /**
     * Daftar katalog reward
     * GET /api/rewards
     */
    public function index(Request $request)
    {
        $query = Reward::where('status_aktif', true);

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

    /**
     * Admin: Create reward
     * POST /api/admin/rewards
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama_reward' => 'required|string|max:150',
            'tipe' => 'required|in:barang,voucher,saldo_digital',
            'koin_dibutuhkan' => 'required|integer|min:1',
            'stok' => 'required|integer|min:0',
            'gambar' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'status_aktif' => 'boolean',
        ]);

        $reward = Reward::create($request->only([
            'nama_reward', 'tipe', 'koin_dibutuhkan', 'stok', 'gambar', 'deskripsi', 'status_aktif',
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Reward berhasil dibuat',
            'data' => $reward,
        ], 201);
    }

    /**
     * Admin: Update reward
     * PUT /api/admin/rewards/{id}
     */
    public function update(Request $request, $id)
    {
        $reward = Reward::findOrFail($id);

        $request->validate([
            'nama_reward' => 'sometimes|required|string|max:150',
            'tipe' => 'sometimes|required|in:barang,voucher,saldo_digital',
            'koin_dibutuhkan' => 'sometimes|required|integer|min:1',
            'stok' => 'sometimes|required|integer|min:0',
            'gambar' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'status_aktif' => 'boolean',
        ]);

        $reward->update($request->only([
            'nama_reward', 'tipe', 'koin_dibutuhkan', 'stok', 'gambar', 'deskripsi', 'status_aktif',
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Reward berhasil diperbarui',
            'data' => $reward,
        ]);
    }

    /**
     * Admin: Delete reward
     * DELETE /api/admin/rewards/{id}
     */
    public function destroy($id)
    {
        $reward = Reward::findOrFail($id);
        $reward->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Reward berhasil dihapus',
        ]);
    }
}
