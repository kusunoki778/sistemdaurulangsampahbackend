<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminPermintaanRewardController;
use App\Http\Controllers\Api\AdminTransaksiSetorController;
use App\Http\Controllers\Api\ArtikelController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DailyLoginController;
use App\Http\Controllers\Api\JenisSampahController;
use App\Http\Controllers\Api\KategoriSampahController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\PermintaanRewardController;
use App\Http\Controllers\Api\PesanKontakController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\TransaksiSetorController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Livora Backend
|--------------------------------------------------------------------------
|
| Klasifikasi keamanan:
| - Publik       : bisa diakses siapa saja tanpa login
| - auth:sanctum : wajib menyertakan Bearer token hasil login
| - admin        : auth:sanctum + role admin
|
*/

// Health check (publik)
Route::get('/health', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Livora API Backend is running!',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// 1. AUTHENTICATION (publik)
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/profile', [AuthController::class, 'updateProfile']);
});

// 2. KATALOG SAMPAH, STATISTIK & EDUKASI (publik)
Route::get('/stats', [AdminController::class, 'publicStats']);
Route::get('/kategori-sampah', [KategoriSampahController::class, 'index']);
Route::get('/jenis-sampah', [JenisSampahController::class, 'index']);

Route::get('/artikels', [ArtikelController::class, 'index']);
Route::get('/artikels/{slug}', [ArtikelController::class, 'show']);

// 3. WALLET & TRANSAKSI KOIN (user login)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/transaksi-koin', [WalletController::class, 'transaksiKoin']);
});

// 4. SETOR SAMPAH OFFLINE (Direct Drop-Off)
Route::middleware('auth:sanctum')->group(function () {
    // User endpoints
    Route::post('/transaksi-setor', [TransaksiSetorController::class, 'store']);
    Route::get('/transaksi-setor', [TransaksiSetorController::class, 'index']);
    Route::get('/transaksi-setor/{id}', [TransaksiSetorController::class, 'show']);
});

// 5. REWARD & KLAIM
// Katalog reward bersifat publik (bisa dilihat tanpa login)
Route::get('/rewards', [RewardController::class, 'index']);
Route::get('/rewards/{id}', [RewardController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    // User klaim reward
    Route::post('/permintaan-reward', [PermintaanRewardController::class, 'store']);
    Route::get('/permintaan-reward', [PermintaanRewardController::class, 'index']);
    Route::get('/permintaan-reward/{id}', [PermintaanRewardController::class, 'show']);
});

// 6. GAMIFIKASI (user login)
Route::middleware('auth:sanctum')->group(function () {
    // Daily Login
    Route::post('/daily-login', [DailyLoginController::class, 'claim']);
    Route::get('/daily-login/status', [DailyLoginController::class, 'status']);

    // Daily Quiz
    Route::get('/quizzes/daily', [QuizController::class, 'daily']);
    Route::post('/quizzes/{id}/submit', [QuizController::class, 'submit']);
    Route::get('/quizzes/history', [QuizController::class, 'history']);

    // Missions
    Route::get('/missions', [MissionController::class, 'index']);
    Route::post('/missions/{id}/claim', [MissionController::class, 'claim']);
});

// 7. PESAN KONTAK (publik kirim, admin baca)
Route::post('/pesan-kontak', [PesanKontakController::class, 'store']);

// 8. ADMIN CMS (auth:sanctum + role admin)
Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function () {
        // Dashboard Stats
        Route::get('/stats', [AdminController::class, 'stats']);

        // Manajemen User
        Route::get('/users', [AdminController::class, 'getUsers']);

        // Master Data: Kategori Sampah
        Route::get('/kategori-sampah', [AdminController::class, 'kategoriIndex']);
        Route::post('/kategori-sampah', [AdminController::class, 'kategoriStore']);
        Route::put('/kategori-sampah/{id}', [AdminController::class, 'kategoriUpdate']);
        Route::delete('/kategori-sampah/{id}', [AdminController::class, 'kategoriDestroy']);

        // Master Data: Jenis Sampah
        Route::get('/jenis-sampah', [AdminController::class, 'jenisSampahIndex']);
        Route::post('/jenis-sampah', [AdminController::class, 'jenisSampahStore']);
        Route::put('/jenis-sampah/{id}', [AdminController::class, 'jenisSampahUpdate']);
        Route::delete('/jenis-sampah/{id}', [AdminController::class, 'jenisSampahDestroy']);

        // Master Data: Rewards
        Route::get('/rewards', [AdminController::class, 'rewardIndex']);
        Route::post('/rewards', [AdminController::class, 'rewardStore']);
        Route::put('/rewards/{id}', [AdminController::class, 'rewardUpdate']);
        Route::delete('/rewards/{id}', [AdminController::class, 'rewardDestroy']);

        // Master Data: Quizzes
        Route::get('/quizzes', [AdminController::class, 'quizIndex']);
        Route::post('/quizzes', [AdminController::class, 'quizStore']);
        Route::put('/quizzes/{id}', [AdminController::class, 'quizUpdate']);
        Route::delete('/quizzes/{id}', [AdminController::class, 'quizDestroy']);

        // Master Data: Missions
        Route::get('/missions', [AdminController::class, 'missionIndex']);
        Route::post('/missions', [AdminController::class, 'missionStore']);
        Route::put('/missions/{id}', [AdminController::class, 'missionUpdate']);
        Route::delete('/missions/{id}', [AdminController::class, 'missionDestroy']);

        // Verifikasi Setor Sampah
        Route::get('/transaksi-setor', [AdminTransaksiSetorController::class, 'index']);
        Route::post('/transaksi-setor/{id}/approve', [AdminTransaksiSetorController::class, 'approve']);
        Route::post('/transaksi-setor/{id}/reject', [AdminTransaksiSetorController::class, 'reject']);

        // Verifikasi Permintaan Reward
        Route::get('/permintaan-reward', [AdminPermintaanRewardController::class, 'index']);
        Route::post('/permintaan-reward/{id}/selesai', [AdminPermintaanRewardController::class, 'selesai']);
        Route::post('/permintaan-reward/{id}/reject', [AdminPermintaanRewardController::class, 'reject']);

        // Pesan Kontak
        Route::get('/pesan-kontak', [PesanKontakController::class, 'adminIndex']);
        Route::post('/pesan-kontak/{id}/read', [PesanKontakController::class, 'markRead']);
        Route::delete('/pesan-kontak/{id}', [PesanKontakController::class, 'destroy']);

        // Artikel Management
        Route::get('/artikels', [ArtikelController::class, 'adminIndex']);
        Route::post('/artikels', [ArtikelController::class, 'store']);
        Route::put('/artikels/{id}', [ArtikelController::class, 'update']);
        Route::delete('/artikels/{id}', [ArtikelController::class, 'destroy']);
    });
