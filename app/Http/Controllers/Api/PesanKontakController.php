<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PesanKontak;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PesanKontakController extends Controller
{
    /**
     * User kirim pesan dari landing page
     * POST /api/pesan-kontak
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'subjek' => 'required|string|max:150',
            'pesan' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi pesan gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $pesan = PesanKontak::create([
            'nama' => $request->nama,
            'email' => $request->email,
            'subjek' => $request->subjek,
            'pesan' => $request->pesan,
            'status' => 'unread',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pesan berhasil dikirim! Tim kami akan merespons segera.',
            'data' => $pesan,
        ], 201);
    }

    /**
     * Admin baca daftar pesan
     * GET /api/admin/pesan-kontak
     */
    public function adminIndex(Request $request)
    {
        $query = PesanKontak::orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Admin tandai pesan dibaca
     * POST /api/admin/pesan-kontak/{id}/read
     */
    public function markRead($id)
    {
        $pesan = PesanKontak::findOrFail($id);

        if ($pesan->status === 'unread') {
            $pesan->update(['status' => 'read']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pesan ditandai sebagai dibaca',
            'data' => $pesan,
        ]);
    }

    /**
     * Admin hapus pesan
     * DELETE /api/admin/pesan-kontak/{id}
     */
    public function destroy($id)
    {
        $pesan = PesanKontak::findOrFail($id);
        $pesan->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Pesan berhasil dihapus',
        ]);
    }
}
