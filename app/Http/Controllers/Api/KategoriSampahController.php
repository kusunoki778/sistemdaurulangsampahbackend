<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KategoriSampah;

/**
 * Endpoint publik daftar kategori sampah (GET /api/kategori-sampah).
 * CRUD admin memakai AdminController agar kena middleware + pengawal
 * relasi yang sama dengan master data lainnya.
 */
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
}
