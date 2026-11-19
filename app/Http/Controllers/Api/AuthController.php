<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogService;
use App\Services\CaptchaService;
use App\Services\CloudinaryService;
use App\Services\GoogleTokenService;
use App\Services\TurnstileService;
use Carbon\Carbon;
use Illuminate\Cache\RedisStore;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    /**
     * Register akun user baru
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username|alpha_dash',
            'email' => 'required|string|email|max:100|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi pendaftaran gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'username' => strtolower($request->username),
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
            'phone' => $request->phone ?? $request->no_hp,
            'role' => 'user',
        ]);

        // Buat wallet untuk user baru
        Wallet::create([
            'user_id' => $user->id,
            'saldo_koin' => 0,
            'total_koin_diperoleh' => 0,
            'total_sampah_kg' => 0,
        ]);

        $token = $user->createToken('livora-mobile')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Pendaftaran berhasil! Selamat datang di Livora.',
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Login untuk User & Admin
     */
    public function login(
        Request $request,
        CaptchaService $captchaService,
        TurnstileService $turnstileService,
    ) {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string', // username atau email
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username/email dan password wajib diisi',
                'errors' => $validator->errors(),
            ], 422);
        }

        $loginInput = $request->input('login');

        // Lockout per akun+IP: melengkapi throttle route (5/menit/IP) agar
        // tebakan password berulang ke satu akun ikut dibatasi 10x/10 menit.
        // Batas per akun (tanpa IP) mencegah brute force terdistribusi yang
        // memalsukan X-Forwarded-For.
        $loginKey = 'login:'.Str::lower((string) $loginInput).'|'.$request->ip();
        $akunKey = 'login-akun:'.Str::lower((string) $loginInput);
        if (RateLimiter::tooManyAttempts($loginKey, 10) || RateLimiter::tooManyAttempts($akunKey, 20)) {
            $detik = max(
                RateLimiter::availableIn($loginKey),
                RateLimiter::availableIn($akunKey),
            );

            $this->auditLogService->catat('login_blocked', [
                'actor' => null,
                'actor_username' => (string) $loginInput,
                'description' => 'Login diblokir karena terlalu banyak percobaan.',
                'status_code' => 429,
                'metadata' => ['retry_after' => $detik],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Terlalu banyak percobaan login. Coba lagi dalam '.max(1, (int) ceil($detik / 60)).' menit.',
                'retry_after' => $detik,
            ], 429);
        }

        $user = User::where('email', $loginInput)
            ->orWhere('username', $loginInput)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($loginKey, 600);
            RateLimiter::hit($akunKey, 900);

            $this->auditLogService->catat('login_failed', [
                'actor' => null,
                'actor_username' => (string) $loginInput,
                'description' => 'Login gagal: kredensial tidak cocok.',
                'status_code' => 401,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Username/Email atau Password salah',
            ], 401);
        }

        // Verifikasi anti-bot hanya diwajibkan untuk akun admin. Login member di
        // aplikasi mobile tetap tanpa verifikasi tambahan. Pemeriksaan dilakukan
        // SETELAH password benar supaya percobaan menebak username admin tetap
        // terlihat sebagai kegagalan login biasa.
        //
        // Dua jalur dipakai bergantian:
        // 1. Turnstile dari Cloudflare (utama). Dipakai bila secret key terisi.
        // 2. Captcha gambar lokal (cadangan). Dipakai bila Turnstile belum
        //    dikonfigurasi atau sedang tidak bisa dihubungi, supaya admin tidak
        //    pernah terkunci di luar hanya karena masalah jaringan.
        if ($user->isAdmin()) {
            $hasilTurnstile = $turnstileService->verifikasi(
                $request->input('turnstile_token'),
                $request->ip(),
            );

            $lolos = match ($hasilTurnstile) {
                true => true,
                false => false,
                // Verifikasi tidak tersedia: pakai captcha lokal sebagai cadangan.
                null => $captchaService->validasi(
                    $request->input('captcha_token'),
                    $request->input('captcha_jawaban'),
                ),
            };

            if (! $lolos) {
                $this->auditLogService->catat('login_captcha_failed', [
                    'actor' => $user,
                    'description' => 'Login admin gagal: verifikasi anti-bot tidak lolos.',
                    'status_code' => 422,
                    'metadata' => ['metode' => $hasilTurnstile === false ? 'turnstile' : 'captcha_lokal'],
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Verifikasi keamanan salah atau kedaluwarsa. Coba lagi.',
                    'captcha_required' => true,
                    'errors' => [
                        'captcha_jawaban' => ['Verifikasi keamanan salah atau kedaluwarsa.'],
                    ],
                ], 422);
            }
        }

        RateLimiter::clear($loginKey);
        RateLimiter::clear($akunKey);

        if ($user->status !== 'aktif') {
            $this->auditLogService->catat('login_inactive', [
                'actor' => $user,
                'description' => 'Login ditolak karena akun tidak aktif.',
                'status_code' => 403,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Akun Anda tidak aktif. Hubungi administrator.',
            ], 403);
        }

        // Pastikan wallet ada
        if (! $user->wallet) {
            Wallet::create([
                'user_id' => $user->id,
                'saldo_koin' => 0,
                'total_koin_diperoleh' => 0,
                'total_sampah_kg' => 0,
            ]);
        }

        $token = $user->createToken('livora-mobile')->plainTextToken;

        $this->auditLogService->catat('login_success', [
            'actor' => $user,
            'description' => 'Login berhasil.',
            'status_code' => 200,
            'metadata' => ['role' => $user->role],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Login berhasil',
            'data' => [
                'user' => $user->load('wallet'),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Login / daftar otomatis via Google (Firebase Auth).
     * Mobile mengirim ID token Google; server verifikasi tanda tangan + klaim,
     * lalu temukan-atau-buat akun (google_id / email).
     * POST /api/auth/google
     */
    public function loginGoogle(Request $request, GoogleTokenService $google)
    {
        $validator = Validator::make($request->all(), [
            'id_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'ID token Google wajib diisi',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $profil = $google->verifikasi($request->input('id_token'));
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($profil['email_verified'] === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email Google belum terverifikasi. Gunakan login biasa.',
            ], 422);
        }

        $user = User::where('google_id', $profil['sub'])
            ->orWhere('email', $profil['email'])
            ->first();

        if ($user) {
            // Pencurian akun ditahan di sini: akun yang SUDAH terikat Google
            // lain (google_id berbeda) tidak boleh dipindahkan walau email
            // cocok & terverifikasi — minta login password biasa saja.
            if ($user->google_id && $user->google_id !== $profil['sub']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email ini sudah terhubung dengan akun Google lain. Masuk memakai password biasa.',
                ], 409);
            }

            if ($user->status !== 'aktif') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Akun Anda tidak aktif. Hubungi administrator.',
                ], 403);
            }

            // Ikat google_id hanya bila akun belum pernah terikat (email Google
            // sama yang terverifikasi boleh sekali jadi).
            if (! $user->google_id) {
                $user->update([
                    'google_id' => $profil['sub'],
                    'avatar' => $user->avatar ?? $profil['picture'],
                ]);
            }
        } else {
            $nama = $profil['name'] !== '' ? $profil['name'] : explode('@', $profil['email'])[0];
            $username = $this->buatUsernameUnik($nama);

            $user = User::create([
                'name' => $nama,
                'username' => $username,
                'email' => $profil['email'],
                'google_id' => $profil['sub'],
                'avatar' => $profil['picture'],
                // Password acak (tidak pernah dipakai via Google).
                'password' => Hash::make(uniqid('google-', true)),
                'role' => 'user',
            ]);

            Wallet::create([
                'user_id' => $user->id,
                'saldo_koin' => 0,
                'total_koin_diperoleh' => 0,
                'total_sampah_kg' => 0,
            ]);
        }

        // Pastikan wallet ada
        if (! $user->wallet) {
            Wallet::create([
                'user_id' => $user->id,
                'saldo_koin' => 0,
                'total_koin_diperoleh' => 0,
                'total_sampah_kg' => 0,
            ]);
        }

        $token = $user->createToken('livora-mobile')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login berhasil',
            'data' => [
                'user' => $user->load('wallet'),
                'token' => $token,
            ],
        ]);
    }

    /** Username dari nama penuh + nomor acak agar unik. */
    private function buatUsernameUnik(string $nama): string
    {
        $basis = strtolower(preg_replace('/[^a-z0-9]/i', '', $nama) ?? '');
        $basis = substr($basis !== '' ? $basis : 'member', 0, 30);

        do {
            $kandidat = $basis.rand(10, 999);
        } while (User::where('username', $kandidat)->exists());

        return $kandidat;
    }

    /**
     * Ambil data profil user saat ini
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('wallet');

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
            ],
        ]);
    }

    /**
     * Logout - cabut token
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        $this->auditLogService->catat('logout', [
            'actor' => $user,
            'description' => 'Logout dan token dicabut.',
            'status_code' => 200,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Anda berhasil keluar dari akun Livora.',
        ]);
    }

    /**
     * Update profil
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|string',
            'password' => 'nullable|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi edit profil gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->filled('name')) {
            $user->name = $request->name;
        }
        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }
        if ($request->has('avatar')) {
            $user->avatar = $request->avatar;
        }
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Profil berhasil diperbarui',
            'data' => [
                'user' => $user->load('wallet'),
            ],
        ]);
    }

    /**
     * Upload foto profil (multipart).
     * POST /api/auth/avatar
     *
     * Foto diunggah ke Cloudinary dan kolom `avatar` menyimpan URL lengkap.
     * Penyimpanan di disk server sengaja tidak dipakai sebagai jalur utama
     * karena disk container produksi bersifat sementara: foto akan hilang
     * setiap deploy atau restart. Disk lokal hanya dipakai sebagai cadangan
     * bila Cloudinary belum dikonfigurasi atau sedang gagal, supaya unggah
     * foto tetap bisa dilakukan saat pengembangan offline.
     *
     * Akun Google tetap memakai URL lengkap dari Google.
     */
    /**
     * Hapus berkas avatar lama dari disk lokal bila memang tersimpan di sana.
     *
     * URL Cloudinary (diawali http) dan URL Google tidak disentuh, karena
     * bukan milik disk ini.
     */
    private function hapusAvatarLokal(?string $avatar): void
    {
        if (! $avatar || str_starts_with($avatar, 'http')) {
            return;
        }

        if (! str_starts_with($avatar, 'uploads/avatar/')) {
            return;
        }

        $path = public_path($avatar);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function avatar(Request $request, CloudinaryService $cloudinary)
    {
        $request->validate([
            'foto' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();
        $file = $request->file('foto');

        if (! $file->isValid()) {
            return response()->json([
                'status' => 'error',
                'message' => 'File foto tidak valid atau rusak.',
            ], 422);
        }

        // Jalur utama: Cloudinary, supaya foto tetap ada setelah deploy.
        $folderCloudinary = (string) (config('services.cloudinary.folder_avatar') ?: 'livora/avatar');
        $urlCloudinary = $cloudinary->upload($file, $folderCloudinary);

        if ($urlCloudinary !== null) {
            // Foto lama di disk lokal (dari versi sebelumnya) dibersihkan
            // supaya tidak menumpuk tanpa terpakai.
            $this->hapusAvatarLokal($user->avatar);

            $user->avatar = $urlCloudinary;
            $user->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Foto profil diperbarui.',
                'data' => [
                    'foto_url' => $user->foto_url,
                ],
            ]);
        }

        // Cadangan: disk lokal. Dipakai hanya bila Cloudinary tidak siap.
        $folder = public_path('uploads/avatar');
        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Ekstensi WAJIB diturunkan dari tipe file yang lolos validasi, bukan
        // dari nama kiriman klien. Tanpa ini, file gambar bernama "x.php"
        // bisa tersimpan sebagai .php di public/ dan dieksekusi web server.
        $ekstensi = strtolower($file->extension() ?: 'jpg');
        if (! in_array($ekstensi, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ekstensi = 'jpg';
        }
        $nama = 'avatar-'.$user->id.'-'.bin2hex(random_bytes(5)).'.'.$ekstensi;
        $file->move($folder, $nama);

        // Hapus foto lokal sebelumnya agar folder tidak menumpuk.
        $this->hapusAvatarLokal($user->avatar);

        $user->avatar = 'uploads/avatar/'.$nama;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Foto profil diperbarui.',
            'data' => [
                'foto_url' => $user->foto_url,
            ],
        ]);
    }

    /**
     * Ubah password (verifikasi password lama dulu).
     * POST /api/auth/change-password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password_lama' => 'required|string',
            'password_baru' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if (! Hash::check($request->password_lama, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password lama tidak sesuai.',
                'errors' => ['password_lama' => ['Password lama tidak sesuai.']],
            ], 422);
        }

        $user->password = Hash::make($request->password_baru);
        $user->save();

        // Cabut sesi lain supaya token lama tidak tetap hidup setelah
        // password diganti. Sesi yang sedang dipakai tetap dipertahankan.
        $sesiSekarang = $user->currentAccessToken()?->id;
        $tokenLain = $user->tokens();
        if ($sesiSekarang) {
            $tokenLain->where('id', '!=', $sesiSekarang);
        }
        $tokenLain->delete();

        $this->auditLogService->catat('password_changed', [
            'actor' => $user,
            'description' => 'Password diubah dan sesi lain dicabut.',
            'status_code' => 200,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password berhasil diubah.',
        ]);
    }

    /**
     * Lupa password: kirim tautan atur ulang ke email user (mandiri,
     * tanpa campur tangan admin — sesuai keputusan keamanan proyek).
     * POST /api/auth/forgot-password
     *
     * Respons selalu sukses terlepas email terdaftar atau tidak
     * (anti user-enumeration: pihak ketiga tidak bisa menebak akun).
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Format tidak valid.',
            ], 422);
        }

        $input = trim($request->input('email'));

        // Terima USERNAME juga (mis. "lepi") — menghindari salah ketik email
        // panjang. Kode selalu dikirim ke email bawaan yang tersimpan.
        if (str_contains($input, '@')) {
            $user = User::whereRaw('LOWER(TRIM(email)) = ?', [strtolower($input)])->first();
        } else {
            $user = User::whereRaw('LOWER(TRIM(username)) = ?', [strtolower($input)])->first();
        }

        // Anti-enumerasi: respons selalu sama (HTTP 200 + pesan netral) bila
        // email/username tidak terdaftar — penyerang tidak bisa memetakan
        // akun mana yang ada. Screen mobile tetap menampilkan pesan netral ini.
        if (! $user) {
            return response()->json([
                'status' => 'success',
                'message' => 'Jika email terdaftar di Livora, kode atur ulang sudah dikirim ke email tersebut. Cek folder masuk maupun spam.',
            ]);
        }

        // Throttle: satu kode per 60 detik per email.
        $terakhir = DB::table('password_reset_tokens')->where('email', $user->email)->value('created_at');
        if ($terakhir && Carbon::parse($terakhir)->greaterThan(Carbon::now()->subSeconds(60))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode baru belum bisa diminta. Tunggu 60 detik sejak permintaan terakhir, lalu coba lagi.',
            ], 429);
        }

        // Kode 6 digit (ala OTP apk terkenal) — pendek, satu baris, tidak mungkin
        // rusak saat disalin dari email seperti token panjang 64 huruf.
        $kode = (string) random_int(100000, 999999);

        // Kode baru menggantikan kode lama untuk email yang sama. Hitungan
        // percobaan juga direset karena ini kode yang benar-benar baru.
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        cache()->forget('reset_attempts:'.strtolower($user->email));
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', $kode),
            'created_at' => Carbon::now(),
        ]);

        $isi = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;text-align:center">
    <h2 style="margin:0 0 8px;color:#16a34a">Kode Atur Ulang Password Livora</h2>
    <p style="color:#555">Masukkan kode berikut di aplikasi Livora (layar Lupa Kata Sandi):</p>
    <div style="font-size:34px;font-weight:800;letter-spacing:10px;margin:20px 0;color:#16a34a">{$kode}</div>
    <p style="color:#555">Kode berlaku 60 menit dan hanya bisa dipakai satu kali.</p>
    <p style="color:#999;font-size:12px">Jika kamu tidak meminta kode ini, abaikan email ini — password kamu tidak berubah.</p>
</div>
HTML;

        try {
            Mail::html($isi, function ($pesan) use ($user) {
                $pesan->to($user->email)
                    ->subject('Kode Atur Ulang Password Livora');
            });
        } catch (Throwable $e) {
            // Kirim gagal = hapus kode, jangan ada kode "menggantung" tanpa email.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            \Log::error('forgot-password gagal kirim email', ['email' => $user->email, 'error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengirim email. Coba lagi beberapa saat.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Kode 6 digit sudah dikirim ke email kamu. Buka email TERBARU dari Livora, lalu masukkan kodenya di aplikasi.',
        ]);
    }

    /**
     * Atur ulang password memakai kode 6 digit dari email.
     * Kode berlaku 60 menit, sekali pakai, dan disimpan sebagai hash SHA-256.
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|digits:6',
            'email' => 'required|email|max:100',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data atur ulang password tidak lengkap. Kode harus 6 angka, password minimal 8 karakter.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $emailInput = strtolower(trim($request->input('email')));

        // Batas percobaan per kode (anti brute-force 6 digit): gagal 5x,
        // kode langsung hangus — harus minta kode baru di Lupa Password.
        $kunciPercobaan = 'reset_attempts:'.$emailInput;
        /** @var FilesystemAdapter|RedisStore $s */
        $percobaan = (int) cache()->get($kunciPercobaan, 0);
        if ($percobaan >= 5) {
            DB::table('password_reset_tokens')->where('email', $emailInput)->delete();
            cache()->forget($kunciPercobaan);

            return response()->json([
                'status' => 'error',
                'message' => 'Terlalu banyak percobaan. Kode ini hangus — minta kode baru di halaman Lupa Password.',
            ], 429);
        }

        $baris = DB::table('password_reset_tokens')->where('email', $emailInput)->first();

        if (! $baris || ! hash_equals($baris->token, hash('sha256', $request->input('token')))) {
            cache()->put($kunciPercobaan, $percobaan + 1, now()->addMinutes(60));

            return response()->json([
                'status' => 'error',
                'message' => 'Kode salah. Cek lagi 6 digit di email TERBARU dari Livora, atau minta kode baru di halaman Lupa Password.',
            ], 400);
        }

        if (Carbon::parse($baris->created_at)->lessThan(Carbon::now()->subMinutes(60))) {
            DB::table('password_reset_tokens')->where('email', $emailInput)->delete();

            return response()->json([
                'status' => 'error',
                'message' => 'Kode sudah kedaluwarsa (berlaku 60 menit). Minta kode baru di halaman Lupa Password.',
            ], 400);
        }

        $user = User::where('email', $emailInput)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email tidak terdaftar di Livora.',
            ], 400);
        }

        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();

        // Reset password mencabut SEMUA token, termasuk sesi yang mungkin
        // masih aktif di perangkat lain.
        $user->tokens()->delete();

        $this->auditLogService->catat('password_reset', [
            'actor' => $user,
            'description' => 'Password diatur ulang lewat kode email dan semua sesi dicabut.',
            'status_code' => 200,
        ]);

        // Sekali pakai: kode langsung hangus setelah password diganti.
        DB::table('password_reset_tokens')->where('email', $emailInput)->delete();
        cache()->forget($kunciPercobaan);

        return response()->json([
            'status' => 'success',
            'message' => 'Password berhasil diatur ulang. Silakan masuk dengan password baru Anda.',
        ]);
    }
}
