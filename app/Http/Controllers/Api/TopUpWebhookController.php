<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransSnapService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Webhook notifikasi pembayaran Midtrans (server-to-server, publik).
 * Keamanan bergantung pada verifikasi signature SHA-512, bukan auth.
 */
class TopUpWebhookController extends Controller
{
    public function __construct(
        private readonly MidtransSnapService $midtrans,
    ) {}

    /**
     * Terima notifikasi pembayaran dari Midtrans.
     * POST /api/top-up/notification
     */
    public function notification(Request $request)
    {
        try {
            $topUp = $this->midtrans->prosesNotifikasi($request->all());
        } catch (RuntimeException $e) {
            // Signature tidak valid atau order tidak dikenal.
            $pesan = $e->getMessage();

            $kode = str_contains($pesan, 'tidak dikenal') ? 404 : 403;

            return response()->json([
                'status' => 'error',
                'message' => $pesan,
            ], $kode);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order top up tidak dikenal.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Notifikasi diproses.',
            'data' => [
                'order_id' => $topUp->order_id,
                'status' => $topUp->status,
            ],
        ]);
    }
}
