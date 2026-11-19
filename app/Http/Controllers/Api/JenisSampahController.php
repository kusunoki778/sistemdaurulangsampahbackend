<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use Illuminate\Http\Request;

/**
 * Endpoint publik daftar jenis sampah (GET /api/jenis-sampah) — dipakai
 * katalog web & mobile. CRUD admin memakai AdminController agar kena
 * middleware + pengawal relasi yang sama dengan master data lainnya.
 */
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
}
