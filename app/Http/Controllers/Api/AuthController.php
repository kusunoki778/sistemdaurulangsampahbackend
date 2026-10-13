<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Register akun user baru
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username|alpha_dash',
            'email' => 'required|string|email|max:100|unique:users,email',
            'password' => 'required|string|confirmed',
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
    public function login(Request $request)
    {
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
        $user = User::where('email', $loginInput)
            ->orWhere('username', $loginInput)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username/Email atau Password salah',
            ], 401);
        }

        if ($user->status !== 'aktif') {
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
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

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
            'password' => 'nullable|string',
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
            'email' => 'required|email|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Format email tidak valid.',
            ], 422);
        }

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'status' => 'success',
            'message' => 'Jika email terdaftar di Livora, tautan atur ulang password sudah dikirim. Periksa kotak masuk dan folder spam Anda.',
        ]);
    }

    /**
     * Atur ulang password memakai token dari email.
     * Token berlaku 60 menit, sekali pakai, dan di-hash di database.
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email|max:100',
            'password' => 'required|string|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data atur ulang password tidak lengkap.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $status = Password::broker()->reset(
            $request->only('email', 'token', 'password'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tautan tidak valid atau sudah kedaluwarsa. Minta tautan baru melalui halaman Lupa Password.',
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Password berhasil diatur ulang. Silakan masuk dengan password baru Anda.',
        ]);
    }
}
