<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureUserIsAdmin
 *
 * Middleware ini adalah "penjaga pintu" panel admin.
 * Wajib dipasang SETELAH auth:sanctum, sehingga $request->user()
 * sudah terisi dari Bearer token yang valid.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Autentikasi diperlukan untuk mengakses resource ini.',
            ], 401);
        }

        if (! $user->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Hanya admin yang diizinkan mengelola resource ini.',
            ], 403);
        }

        return $next($request);
    }
}
