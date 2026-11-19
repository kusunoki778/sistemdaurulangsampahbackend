<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use Illuminate\Http\Request;

/**
 * Pendaftaran token notifikasi FCM dari aplikasi mobile.
 *
 * POST /fcm-token  {token, platform?}      — daftar/refresh token perangkat
 * DELETE /fcm-token {token?}               — cabut token (logout/uninstall)
 *
 * Setiap login di perangkat memanggil POST; token yang sama di-upsert,
 * jadi tidak menumpuk baris.
 */
class FcmTokenController extends Controller
{
    public function simpan(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|min:20|max:500',
            'platform' => 'nullable|in:android,ios,web',
        ]);

        $fcm = FcmToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? 'android',
                'terakhir_dipakai_at' => now(),
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Token notifikasi terdaftar.',
            'data' => ['id' => $fcm->id],
        ]);
    }

    public function hapus(Request $request)
    {
        $request->validate([
            'token' => 'required|string|min:20|max:500',
        ]);

        // Cumil boleh hapus milik sendiri (tabel ber-user_id).
        FcmToken::where('user_id', $request->user()->id)
            ->where('token', $request->input('token'))
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Token notifikasi dilepas.',
        ]);
    }
}
