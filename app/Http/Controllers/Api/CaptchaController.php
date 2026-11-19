<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CaptchaService;

class CaptchaController extends Controller
{
    /**
     * Terbitkan captcha gambar baru untuk halaman login admin.
     * GET /api/auth/captcha
     */
    public function show(CaptchaService $captchaService)
    {
        return response()->json([
            'status' => 'success',
            'data' => $captchaService->buat(),
        ]);
    }
}
