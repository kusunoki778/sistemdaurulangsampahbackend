<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransaksiSetor;
use App\Services\QrSessionService;
use App\Services\SetorSettlementService;
use Illuminate\Http\Request;

class TransaksiSetorController extends Controller
{
    public function __construct(
        private readonly QrSessionService $qrSessions,
        private readonly SetorSettlementService $settlement,
    ) {}

    /**
     * Riwayat transaksi setor user
     * GET /api/transaksi-setor
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = TransaksiSetor::with(['details.jenisSampah'])
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Detail transaksi setor
     * GET /api/transaksi-setor/{id}
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $transaksi = TransaksiSetor::with(['details.jenisSampah'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $transaksi,
        ]);
    }

    /**
     * User memindai QR sesi -> tampilkan rincian nominal sebelum konfirmasi.
     * POST /api/transaksi-setor/scan
     */
    public function scan(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:255',
        ]);

        $session = $this->qrSessions->findValid($request->token);

        if (! $session) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR tidak valid atau sudah kedaluwarsa. Minta admin menerbitkan QR baru.',
            ], 422);
        }

        if ($session->user_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR ini bukan untuk akun Anda.',
            ], 403);
        }

        $transaksi = $session->transaksiSetor;

        if (! $transaksi || $transaksi->status !== 'menunggu_konfirmasi') {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi ini sudah tidak menunggu konfirmasi.',
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'QR valid. Periksa rincian lalu konfirmasi.',
            'data' => [
                'token' => $this->qrSessions->normalizeToken($request->token),
                'expires_at' => $session->expires_at->toIso8601String(),
                'expires_in' => max(0, (int) now()->diffInSeconds($session->expires_at, false)),
                'transaksi' => $transaksi,
            ],
        ]);
    }

    /**
     * User menekan "Konfirmasi" -> koin dicairkan.
     * Aman dipanggil dua kali: konfirmasi ulang tidak menambah koin lagi.
     * POST /api/transaksi-setor/konfirmasi
     */
    public function konfirmasi(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $session = $this->qrSessions->findValid($request->token);

        if (! $session) {
            // Idempotensi: token yang sudah dipakai atau dicabut karena
            // transaksi telah diselesaikan (mis. fallback admin) tetap
            // mengembalikan sukses tanpa mencairkan koin lagi.
            $existing = $this->qrSessions->findLatest($request->token);

            if ($existing && $existing->user_id === $user->id && $existing->transaksiSetor?->status === 'approved') {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Transaksi ini sudah dikonfirmasi/diselesaikan sebelumnya.',
                    'data' => [
                        'transaksi' => $existing->transaksiSetor,
                        'koin_diperoleh' => (float) $existing->transaksiSetor->total_koin,
                        'sisa_koin' => (float) ($user->wallet?->saldo_koin ?? 0),
                    ],
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'QR tidak valid atau sudah kedaluwarsa. Minta admin menerbitkan QR baru.',
            ], 422);
        }

        if ($session->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR ini bukan untuk akun Anda.',
            ], 403);
        }

        $transaksi = $session->transaksiSetor;

        if (! $transaksi) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.',
            ], 404);
        }

        // Bisa terjadi bila admin memakai fallback saat user masih membuka layar.
        if ($transaksi->status === 'approved') {
            $this->qrSessions->consume($session);

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi ini sudah diselesaikan sebelumnya.',
                'data' => [
                    'transaksi' => $transaksi,
                    'koin_diperoleh' => (float) $transaksi->total_koin,
                    'sisa_koin' => (float) ($user->wallet?->fresh()->saldo_koin ?? 0),
                ],
            ]);
        }

        if ($transaksi->status !== 'menunggu_konfirmasi') {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi ini tidak bisa dikonfirmasi (status: '.$transaksi->status.').',
            ], 400);
        }

        $transaksi = $this->settlement->settle($transaksi, 'qr');
        $this->qrSessions->consume($session);

        return response()->json([
            'status' => 'success',
            'message' => 'Konfirmasi berhasil. Koin sudah masuk ke wallet Anda.',
            'data' => [
                'transaksi' => $transaksi->fresh(['details.jenisSampah', 'admin']),
                'koin_diperoleh' => (float) $transaksi->total_koin,
                'sisa_koin' => (float) ($user->wallet?->fresh()->saldo_koin ?? 0),
            ],
        ]);
    }
}
