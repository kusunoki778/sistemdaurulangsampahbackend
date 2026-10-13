<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KategoriSampah;
use Illuminate\Http\Request;

class KategoriSampahController extends Controller
{
    public function index()
    {
        $kategori = KategoriSampah::with('jenisSampah')->get();

        return response()->json([
            'status' => 'success',
            'data' => $kategori,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_kategori' => 'required|string|max:100|unique:kategori_sampah,nama_kategori',
            'deskripsi' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);

        $kategori = KategoriSampah::create($request->only(['nama_kategori', 'deskripsi', 'icon']));

        return response()->json([
            'status' => 'success',
            'message' => 'Kategori sampah berhasil dibuat',
            'data' => $kategori,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $kategori = KategoriSampah::findOrFail($id);

        $request->validate([
            'nama_kategori' => 'sometimes|required|string|max:100|unique:kategori_sampah,nama_kategori,'.$id,
            'deskripsi' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);

        $kategori->update($request->only(['nama_kategori', 'deskripsi', 'icon']));

        return response()->json([
            'status' => 'success',
            'message' => 'Kategori sampah berhasil diperbarui',
            'data' => $kategori,
        ]);
    }

    public function destroy($id)
    {
        $kategori = KategoriSampah::findOrFail($id);
        $kategori->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Kategori sampah berhasil dihapus',
        ]);
    }
}
