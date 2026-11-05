<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminPermintaanRewardController;
use App\Http\Controllers\Api\AdminTopUpController;
use App\Http\Controllers\Api\AdminTransaksiSetorController;
use App\Http\Controllers\Api\ArtikelController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DailyLoginController;
use App\Http\Controllers\Api\JenisSampahController;
use App\Http\Controllers\Api\KategoriSampahController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\PermintaanRewardController;
use App\Http\Controllers\Api\PesanKontakController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\TopUpController;
use App\Http\Controllers\Api\TopUpWebhookController;
use App\Http\Controllers\Api\TransaksiSetorController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\DB;
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

// Health check (publik) — sekalian menandai status database agar ping
// monitor (UptimeRobot) membantu menjaga koneksi tetap hangat.
Route::get('/health', function () {
    $database = 'disconnected';
    try {
        DB::select('SELECT 1');
        $database = 'connected';
    } catch (Throwable $e) {
        // Database belum siap — health tetap 200 agar monitor tidak salah alarm.
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Livora API Backend is running!',
        'database' => $database,
        'timestamp' => now()->toIso8601String(),
    ]);
});

// 1. AUTHENTICATION (publik)
// Login & register dibatasi 5x per menit per IP — memperlambat brute force
// password dan pembuatan akun massal (forgot/reset sudah throttle di bawah).
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
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

    // Top Up Koin via Midtrans Snap
    Route::get('/top-up', [TopUpController::class, 'index']);
    Route::post('/top-up', [TopUpController::class, 'store']);
    Route::get('/top-up/{id}', [TopUpController::class, 'show']);
    Route::get('/top-up/{id}/struk', [TopUpController::class, 'struk']);
});

// Webhook notifikasi pembayaran Midtrans (publik, server-to-server).
// Keamanan bergantung pada verifikasi signature SHA-512.
Route::post('/top-up/notification', [TopUpWebhookController::class, 'notification'])
    ->middleware('throttle:60,1');

// 4. SETOR SAMPAH OFFLINE (Direct Drop-Off)
// Admin yang menginput berat di drop point; user hanya memindai QR dan
// mengonfirmasi nominal. Pengajuan mandiri dari user sudah dipensiunkan.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/transaksi-setor', [TransaksiSetorController::class, 'index']);
    Route::post('/transaksi-setor/scan', [TransaksiSetorController::class, 'scan'])->middleware('throttle:60,1');
    Route::post('/transaksi-setor/konfirmasi', [TransaksiSetorController::class, 'konfirmasi'])->middleware('throttle:60,1');
    Route::get('/transaksi-setor/{id}', [TransaksiSetorController::class, 'show']);
});

// 6. CHAT (publik, rate-limited)
Route::post('/chat', [ChatController::class, 'chat'])->middleware('throttle:10,1');
// Katalog reward bersifat publik (bisa dilihat tanpa login)
Route::get('/rewards', [RewardController::class, 'index']);
Route::get('/rewards/{id}', [RewardController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    // User klaim reward + kupon pickup
    Route::post('/permintaan-reward', [PermintaanRewardController::class, 'store']);
    Route::get('/permintaan-reward', [PermintaanRewardController::class, 'index']);
    Route::get('/permintaan-reward/{id}', [PermintaanRewardController::class, 'show']);

    // QR pickup reward (dipindai user di titik pengambilan)
    Route::post('/permintaan-reward/scan', [PermintaanRewardController::class, 'scan'])
        ->middleware('throttle:30,1');
    Route::post('/permintaan-reward/konfirmasi', [PermintaanRewardController::class, 'konfirmasi'])
        ->middleware('throttle:30,1');

    // Struk reward (payout digital = Simulasi; admin juga boleh melihat)
    Route::get('/permintaan-reward/{id}/struk', [PermintaanRewardController::class, 'struk']);
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

        // Verifikasi Setor Sampah (admin input di drop point + QR konfirmasi)
        Route::get('/transaksi-setor', [AdminTransaksiSetorController::class, 'index']);
        Route::post('/transaksi-setor', [AdminTransaksiSetorController::class, 'store']);
        Route::post('/transaksi-setor/{id}/qr', [AdminTransaksiSetorController::class, 'reissueQr']);
        Route::post('/transaksi-setor/{id}/selesaikan', [AdminTransaksiSetorController::class, 'selesaikan']);
        Route::post('/transaksi-setor/{id}/batalkan', [AdminTransaksiSetorController::class, 'batalkan']);

        // Verifikasi Permintaan Reward (kupon + QR pickup)
        Route::get('/permintaan-reward', [AdminPermintaanRewardController::class, 'index']);
        Route::get('/permintaan-reward/pengaturan', [AdminPermintaanRewardController::class, 'pengaturan']);
        Route::put('/permintaan-reward/pengaturan', [AdminPermintaanRewardController::class, 'simpanPengaturan']);
        Route::get('/permintaan-reward/kupon/{kode}', [AdminPermintaanRewardController::class, 'cariKupon']);
        Route::post('/permintaan-reward/{id}/qr', [AdminPermintaanRewardController::class, 'terbitkanQr']);
        Route::post('/permintaan-reward/{id}/proses', [AdminPermintaanRewardController::class, 'proses']);
        Route::post('/permintaan-reward/{id}/selesai', [AdminPermintaanRewardController::class, 'selesai']);
        Route::post('/permintaan-reward/{id}/gagal', [AdminPermintaanRewardController::class, 'tandaiGagal']);
        Route::post('/permintaan-reward/{id}/batalkan', [AdminPermintaanRewardController::class, 'batalkan']);

        // Top Up Koin (kurs & minimum + riwayat semua member)
        Route::get('/top-up/pengaturan', [AdminTopUpController::class, 'pengaturan']);
        Route::put('/top-up/pengaturan', [AdminTopUpController::class, 'simpanPengaturan']);
        Route::get('/top-up', [AdminTopUpController::class, 'index']);

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
