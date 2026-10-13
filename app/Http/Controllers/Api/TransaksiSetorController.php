<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use App\Models\TransaksiSetor;
use App\Models\TransaksiSetorDetail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransaksiSetorController extends Controller
{
    /**
     * User buat pengajuan setor sampah manual
     * POST /api/transaksi-setor
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.jenis_sampah_id' => 'required|exists:jenis_sampah,id',
            'items.*.jumlah' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data setor sampah tidak valid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($request, $user) {
            // Generate kode transaksi
            $kode = 'TRX-S-'.Carbon::now()->format('Ymd').'-'.str_pad(TransaksiSetor::count() + 1, 3, '0', STR_PAD_LEFT);

            $transaksi = TransaksiSetor::create([
                'kode_transaksi' => $kode,
                'user_id' => $user->id,
                'total_koin' => 0,
                'status' => 'pending',
                'catatan' => $request->catatan ?? null,
            ]);

            $totalKoin = 0;
            $totalBeratKg = 0;

            foreach ($request->items as $item) {
                $jenis = JenisSampah::find($item['jenis_sampah_id']);
                $jumlah = (float) $item['jumlah'];
                $koinSubtotal = (int) round($jenis->poin_per_satuan * $jumlah);

                TransaksiSetorDetail::create([
                    'transaksi_setor_id' => $transaksi->id,
                    'jenis_sampah_id' => $jenis->id,
                    'jumlah' => $jumlah,
                    'koin_subtotal' => $koinSubtotal,
                ]);

                $totalKoin += $koinSubtotal;

                // Konversi ke kg untuk statistik total_sampah_kg
                if ($jenis->satuan === 'kg') {
                    $totalBeratKg += $jumlah;
                } elseif ($jenis->satuan === 'liter') {
                    $totalBeratKg += $jumlah * 0.9; // estimasi 1 liter ≈ 0.9 kg
                } elseif ($jenis->satuan === 'pcs') {
                    $totalBeratKg += $jumlah * 0.05; // estimasi 1 pcs ≈ 0.05 kg
                }
            }

            $transaksi->update(['total_koin' => $totalKoin]);

            return response()->json([
                'status' => 'success',
                'message' => 'Pengajuan setor sampah berhasil dikirim! Silakan bawa sampah ke titik pengumpulan Livora.',
                'data' => $transaksi->load('details.jenisSampah'),
            ], 201);
        });
    }

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
}
