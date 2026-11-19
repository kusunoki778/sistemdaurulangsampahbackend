<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XenditPayoutService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Webhook payout Xendit (server-to-server, publik).
 * Keamanan: header x-callback-token dibandingkan hash_equals dengan
 * services.xendit.callback_token. Respons 204 supaya Xendit berhenti retry.
 */
class XenditWebhookController extends Controller
{
    public function __construct(
        private readonly XenditPayoutService $xendit,
    ) {}

    public function terima(Request $request)
    {
        $token = (string) $request->header('x-callback-token', '');
        $harapan = (string) config('services.xendit.callback_token');

        if ($harapan === '' || ! hash_equals($harapan, $token)) {
            \Log::warning('Webhook payout Xendit ditolak: callback token tidak valid.');

            return response()->json([
                'status' => 'error',
                'message' => 'Callback token Xendit tidak valid.',
            ], 401);
        }

        $event = (string) $request->input('event', '');

        if (! str_starts_with($event, 'v3_payout.')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event payout tidak dikenal: '.$event,
            ], 422);
        }

        try {
            $permintaan = $this->xendit->prosesStatus((array) $request->input('data', []));
        } catch (RuntimeException $e) {
            \Log::error('Webhook payout Xendit gagal', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->noContent();
    }
}
