<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use App\Models\TransaksiSetor;
use App\Models\TransaksiSetorDetail;
use App\Models\User;
use App\Services\QrSessionService;
use App\Services\SetorSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTransaksiSetorController extends Controller
{
    public function __construct(
        private readonly QrSessionService $qrSessions,
        private readonly SetorSettlementService $settlement,
    ) {}

    /**
     * Admin lihat daftar transaksi setor
     * GET /api/admin/transaksi-setor
     */
    public function index(Request $request)
    {
        $query = TransaksiSetor::with(['user', 'details.jenisSampah', 'admin', 'qrSessions']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_transaksi', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        $data = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Admin membuat transaksi setor langsung (user datang ke drop point),
     * lalu sistem menerbitkan QR sesi 3 menit untuk dikonfirmasi user.
     * POST /api/admin/transaksi-setor
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'items' => 'required|array|min:1',
            'items.*.jenis_sampah_id' => 'required|exists:jenis_sampah,id',
            'items.*.jumlah' => 'required|numeric|min:0.01|max:100000',
            'catatan' => 'nullable|string|max:500',
        ]);

        $user = User::whereKey($validated['user_id'])->where('role', 'user')->first();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun yang dipilih bukan member.',
            ], 422);
        }

        $transaksi = DB::transaction(function () use ($validated, $request) {
            $kode = 'TRX-S-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));

            $transaksi = TransaksiSetor::create([
                'kode_transaksi' => $kode,
                'user_id' => $validated['user_id'],
                'admin_id' => $request->user()->id,
                'total_koin' => 0,
                // Berat sudah diverifikasi admin di drop point, tinggal
                // konfirmasi nominal oleh user lewat QR.
                'status' => 'menunggu_konfirmasi',
                'catatan' => $validated['catatan'] ?? null,
            ]);

            $totalKoin = 0.0;

            foreach ($validated['items'] as $item) {
                // Satuan selalu mengikuti master jenis sampah — tidak pernah
                // diambil dari client, sehingga kg/liter tidak bisa tertukar.
                $jenis = JenisSampah::findOrFail($item['jenis_sampah_id']);
                $jumlah = (float) $item['jumlah'];
                $subtotal = round((float) $jenis->poin_per_satuan * $jumlah, 2);

                TransaksiSetorDetail::create([
                    'transaksi_setor_id' => $transaksi->id,
                    'jenis_sampah_id' => $jenis->id,
                    'jumlah' => $jumlah,
                    'koin_subtotal' => $subtotal,
                    'poin_per_satuan' => $jenis->poin_per_satuan,
                ]);

                $totalKoin += $subtotal;
            }

            $transaksi->update(['total_koin' => round($totalKoin, 2)]);

            return $transaksi;
        });

        $qr = $this->qrSessions->issue($transaksi);

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi setor dibuat. Tampilkan QR agar member mengonfirmasi nominal.',
            'data' => [
                'transaksi' => $transaksi->fresh(['user', 'details.jenisSampah', 'admin', 'qrSessions']),
                'qr' => $qr,
            ],
        ], 201);
    }

    /**
     * Terbitkan ulang QR (mis. QR sebelumnya kedaluwarsa).
     * POST /api/admin/transaksi-setor/{id}/qr
     */
    public function reissueQr($id)
    {
        $transaksi = TransaksiSetor::with('user')->findOrFail($id);

        if ($transaksi->status !== 'menunggu_konfirmasi') {
            return response()->json([
                'status' => 'error',
                'message' => 'QR hanya bisa diterbitkan untuk transaksi yang menunggu konfirmasi (status: '.$transaksi->status.').',
            ], 400);
        }

        $qr = $this->qrSessions->issue($transaksi);

        return response()->json([
            'status' => 'success',
            'message' => 'QR baru diterbitkan. Token lama otomatis tidak berlaku.',
            'data' => [
                'transaksi' => $transaksi->fresh(['user', 'details.jenisSampah', 'admin']),
                'qr' => $qr,
            ],
        ]);
    }

    /**
     * Fallback: selesaikan transaksi tanpa konfirmasi user.
     * Koin tetap dicairkan dan aksi tercatat (settlement_method = admin).
     * POST /api/admin/transaksi-setor/{id}/selesaikan
     */
    public function selesaikan($id)
    {
        $transaksi = TransaksiSetor::findOrFail($id);

        if (! in_array($transaksi->status, ['pending', 'menunggu_konfirmasi'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi ini sudah diproses sebelumnya (status: '.$transaksi->status.')',
            ], 400);
        }

        $transaksi = $this->settlement->settle($transaksi, 'admin');
        $this->qrSessions->revokeFor($transaksi);

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi diselesaikan tanpa konfirmasi QR. Koin sudah dicairkan ke wallet member.',
            'data' => $transaksi->fresh(['user', 'details.jenisSampah', 'admin']),
        ]);
    }

    /**
     * Batalkan transaksi sebelum koin dicairkan.
     * POST /api/admin/transaksi-setor/{id}/batalkan
     */
    public function batalkan(Request $request, $id)
    {
        $request->validate([
            'catatan' => 'required|string|max:500',
        ]);

        $transaksi = TransaksiSetor::findOrFail($id);

        if (! in_array($transaksi->status, ['pending', 'menunggu_konfirmasi'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi ini sudah diproses sebelumnya (status: '.$transaksi->status.')',
            ], 400);
        }

        $transaksi->update([
            'status' => 'rejected',
            'admin_id' => $request->user()->id,
            'catatan' => $request->catatan,
        ]);

        $this->qrSessions->revokeFor($transaksi);

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi setor dibatalkan. Koin tidak dicairkan.',
            'data' => $transaksi->fresh(['user', 'details.jenisSampah', 'admin']),
        ]);
    }
}
