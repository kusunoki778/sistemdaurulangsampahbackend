<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TopUp;
use App\Services\MidtransSnapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TopUpController extends Controller
{
    public function __construct(
        private readonly MidtransSnapService $midtrans,
    ) {}

    /**
     * Riwayat top up user.
     * GET /api/top-up
     */
    public function index(Request $request)
    {
        $data = TopUp::where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Buat transaksi top up baru + minta Snap token ke Midtrans.
     * POST /api/top-up { nominal_rupiah }
     */
    public function store(Request $request)
    {
        $minimum = $this->midtrans->minimumRupiah();

        $validated = $request->validate([
            'nominal_rupiah' => ['required', 'numeric', 'min:'.$minimum, 'max:10000000'],
        ]);

        $nominal = round((float) $validated['nominal_rupiah'], 2);
        $kurs = $this->midtrans->kursKoin();
        $koin = $this->midtrans->hitungKoin($nominal);

        if ($koin <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Nominal terlalu kecil untuk dikonversi menjadi koin.',
            ], 422);
        }

        try {
            $topUp = DB::transaction(function () use ($request, $nominal, $kurs, $koin) {
                $topUp = TopUp::create([
                    'user_id' => $request->user()->id,
                    'order_id' => $this->buatOrderId(),
                    'nominal_rupiah' => $nominal,
                    'kurs_rupiah' => $kurs,
                    'koin_diterima' => $koin,
                    'status' => 'pending',
                ]);

                // Snap token dibuat di dalam transaksi DB agar kegagalan
                // Midtrans tidak meninggalkan baris pending tanpa token.
                return app(MidtransSnapService::class)->buatTransaksiSnap($topUp);
            });
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi top up dibuat. Selesaikan pembayaran di halaman Midtrans.',
            'data' => [
                'top_up' => $topUp,
                'snap_token' => $topUp->snap_token,
                'redirect_url' => $topUp->snap_redirect_url,
            ],
        ], 201);
    }

    /**
     * Detail top up. Bila masih pending, status diperbarui dari Midtrans
     * (fallback bila webhook belum sampai).
     * GET /api/top-up/{id}
     */
    public function show(Request $request, $id)
    {
        $topUp = TopUp::where('user_id', $request->user()->id)->findOrFail($id);

        $topUp = $this->segarkanStatus($topUp);

        return response()->json([
            'status' => 'success',
            'data' => $topUp,
        ]);
    }

    /**
     * Struk top up (tersedia setelah pembayaran berhasil).
     * GET /api/top-up/{id}/struk
     */
    public function struk(Request $request, $id)
    {
        $user = $request->user();

        $topUp = TopUp::with('user')->findOrFail($id);

        if ($topUp->user_id !== $user->id && ! $user->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Struk ini bukan milik akun Anda.',
            ], 403);
        }

        $topUp = $this->segarkanStatus($topUp);

        if (! $topUp->isLunas()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Struk tersedia setelah pembayaran berhasil.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->midtrans->buatStruk($topUp),
        ]);
    }

    /**
     * Segarkan status dari Midtrans untuk top up yang masih pending.
     * Gagal menghubungi Midtrans tidak membuat endpoint error — cukup
     * kembalikan data terakhir yang diketahui.
     */
    private function segarkanStatus(TopUp $topUp): TopUp
    {
        if ($topUp->status !== 'pending') {
            return $topUp;
        }

        $notif = $this->midtrans->ambilStatusMidtrans($topUp->order_id);

        if (! $notif || empty($notif['status_code'])) {
            return $topUp;
        }

        try {
            return $this->midtrans->prosesNotifikasi($notif);
        } catch (RuntimeException) {
            return $topUp;
        }
    }

    private function buatOrderId(): string
    {
        for ($percobaan = 0; $percobaan < 10; $percobaan++) {
            $orderId = 'TOP-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

            if (! TopUp::where('order_id', $orderId)->exists()) {
                return $orderId;
            }
        }

        throw new RuntimeException('Gagal membuat order ID unik.');
    }
}
