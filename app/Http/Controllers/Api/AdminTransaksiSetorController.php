<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransaksiKoin;
use App\Models\TransaksiSetor;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTransaksiSetorController extends Controller
{
    /**
     * Admin lihat daftar pengajuan setor
     * GET /api/admin/transaksi-setor
     */
    public function index(Request $request)
    {
        $query = TransaksiSetor::with(['user', 'details.jenisSampah', 'admin']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $data = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Admin validasi & konfirmasi berat fisik -> approve
     * POST /api/admin/transaksi-setor/{id}/approve
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'details' => 'sometimes|array',
            'details.*.id' => 'required_with:details|exists:transaksi_setor_detail,id',
            'details.*.jumlah_valid' => 'required_with:details|numeric|min:0.01',
            'catatan' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $transaksi = TransaksiSetor::with(['details.jenisSampah', 'user'])->findOrFail($id);

            if ($transaksi->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaksi ini sudah diproses sebelumnya (status: '.$transaksi->status.')',
                ], 400);
            }

            $user = $transaksi->user;
            $totalKoin = 0;
            $totalBeratKg = 0;

            // Jika admin mengirim detail validasi, gunakan itu; jika tidak, gunakan estimasi user
            if ($request->filled('details')) {
                foreach ($request->details as $detailInput) {
                    $detail = $transaksi->details->find($detailInput['id']);
                    if ($detail) {
                        $jumlahValid = (float) $detailInput['jumlah_valid'];
                        $koinSubtotal = (int) round($detail->jenisSampah->poin_per_satuan * $jumlahValid);

                        $detail->update([
                            'jumlah' => $jumlahValid,
                            'koin_subtotal' => $koinSubtotal,
                        ]);

                        $totalKoin += $koinSubtotal;

                        // Hitung berat kg untuk statistik
                        if ($detail->jenisSampah->satuan === 'kg') {
                            $totalBeratKg += $jumlahValid;
                        } elseif ($detail->jenisSampah->satuan === 'liter') {
                            $totalBeratKg += $jumlahValid * 0.9;
                        } elseif ($detail->jenisSampah->satuan === 'pcs') {
                            $totalBeratKg += $jumlahValid * 0.05;
                        }
                    }
                }
            } else {
                // Gunakan estimasi user asli
                foreach ($transaksi->details as $detail) {
                    $totalKoin += $detail->koin_subtotal;

                    if ($detail->jenisSampah->satuan === 'kg') {
                        $totalBeratKg += $detail->jumlah;
                    } elseif ($detail->jenisSampah->satuan === 'liter') {
                        $totalBeratKg += $detail->jumlah * 0.9;
                    } elseif ($detail->jenisSampah->satuan === 'pcs') {
                        $totalBeratKg += $detail->jumlah * 0.05;
                    }
                }
            }

            // Update transaksi
            $transaksi->update([
                'status' => 'approved',
                'total_koin' => $totalKoin,
                'admin_id' => $request->user()->id,
                'catatan' => $request->catatan ?? $transaksi->catatan,
            ]);

            // Update wallet user
            $wallet = $user->wallet;
            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'saldo_koin' => 0,
                    'total_koin_diperoleh' => 0,
                    'total_sampah_kg' => 0,
                ]);
            }

            $wallet->increment('saldo_koin', $totalKoin);
            $wallet->increment('total_koin_diperoleh', $totalKoin);
            $wallet->increment('total_sampah_kg', $totalBeratKg);

            // Catat transaksi koin masuk
            TransaksiKoin::create([
                'user_id' => $user->id,
                'tipe' => 'masuk',
                'jumlah_koin' => $totalKoin,
                'sumber' => 'setor_sampah',
                'referensi_id' => $transaksi->id,
                'keterangan' => 'Setor sampah disetujui: '.$transaksi->kode_transaksi,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi setor berhasil disetujui! Koin ditambahkan ke wallet user.',
                'data' => $transaksi->fresh(['user', 'details.jenisSampah', 'admin']),
            ]);
        });
    }

    /**
     * Admin tolak pengajuan setor
     * POST /api/admin/transaksi-setor/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'catatan' => 'required|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $transaksi = TransaksiSetor::with('user')->findOrFail($id);

            if ($transaksi->status !== 'pending') {
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

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi setor ditolak',
                'data' => $transaksi->fresh(['user', 'details.jenisSampah', 'admin']),
            ]);
        });
    }
}
