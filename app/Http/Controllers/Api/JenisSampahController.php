<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use Illuminate\Http\Request;

class JenisSampahController extends Controller
{
    public function index(Request $request)
    {
        $query = JenisSampah::with('kategori');

        if ($request->filled('kategori_id')) {
            $query->where('kategori_id', $request->kategori_id);
        }

        $jenisSampah = $query->orderBy('nama_jenis')->get();

        return response()->json([
            'status' => 'success',
            'data' => $jenisSampah,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'kategori_id' => 'required|exists:kategori_sampah,id',
            'nama_jenis' => 'required|string|max:100',
            'satuan' => 'required|in:kg,liter,pcs',
            'poin_per_satuan' => 'required|integer|min:1',
            'deskripsi' => 'nullable|string',
            'gambar' => 'nullable|string',
        ]);

        $jenisSampah = JenisSampah::create($request->only([
            'kategori_id', 'nama_jenis', 'satuan', 'poin_per_satuan', 'deskripsi', 'gambar',
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis sampah berhasil dibuat',
            'data' => $jenisSampah->load('kategori'),
        ], 201);
    }

    public function show($id)
    {
        $jenisSampah = JenisSampah::with('kategori')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $jenisSampah,
        ]);
    }

    public function update(Request $request, $id)
    {
        $jenisSampah = JenisSampah::findOrFail($id);

        $request->validate([
            'kategori_id' => 'sometimes|required|exists:kategori_sampah,id',
            'nama_jenis' => 'sometimes|required|string|max:100',
            'satuan' => 'sometimes|required|in:kg,liter,pcs',
            'poin_per_satuan' => 'sometimes|required|integer|min:1',
            'deskripsi' => 'nullable|string',
            'gambar' => 'nullable|string',
        ]);

        $jenisSampah->update($request->only([
            'kategori_id', 'nama_jenis', 'satuan', 'poin_per_satuan', 'deskripsi', 'gambar',
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis sampah berhasil diperbarui',
            'data' => $jenisSampah->load('kategori'),
        ]);
    }

    public function destroy($id)
    {
        $jenisSampah = JenisSampah::findOrFail($id);
        $jenisSampah->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis sampah berhasil dihapus',
        ]);
    }
}
