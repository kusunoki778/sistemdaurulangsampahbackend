<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pengaturan;
use App\Models\TopUp;
use App\Services\MidtransSnapService;
use Illuminate\Http\Request;

class AdminTopUpController extends Controller
{
    public function __construct(
        private readonly MidtransSnapService $midtrans,
    ) {}

    /**
     * Daftar semua top up member (untuk CMS).
     * GET /api/admin/top-up
     */
    public function index(Request $request)
    {
        $query = TopUp::with('user')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Lihat pengaturan top up (kurs & minimum).
     * GET /api/admin/top-up/pengaturan
     */
    public function pengaturan()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'kurs_rupiah' => $this->midtrans->kursKoin(),
                'minimum_rupiah' => $this->midtrans->minimumRupiah(),
            ],
        ]);
    }

    /**
     * Simpan pengaturan top up.
     * PUT /api/admin/top-up/pengaturan
     */
    public function simpanPengaturan(Request $request)
    {
        $validated = $request->validate([
            'kurs_rupiah' => 'required|numeric|min:1|max:1000000',
            'minimum_rupiah' => 'required|numeric|min:1000|max:10000000',
        ]);

        Pengaturan::simpan(MidtransSnapService::KUNCI_KURS, round((float) $validated['kurs_rupiah'], 2));
        Pengaturan::simpan(MidtransSnapService::KUNCI_MINIMUM, round((float) $validated['minimum_rupiah'], 2));

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan top up tersimpan.',
            'data' => [
                'kurs_rupiah' => $this->midtrans->kursKoin(),
                'minimum_rupiah' => $this->midtrans->minimumRupiah(),
            ],
        ]);
    }
}
