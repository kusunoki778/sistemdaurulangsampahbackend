<?php

namespace App\Services;

use App\Models\TransaksiKoin;
use App\Models\TransaksiSetor;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetorSettlementService
{
    public function __construct(
        private readonly FcmService $fcm,
    ) {}

    /**
     * Cairkan koin untuk transaksi setor.
     *
     * Aman dipanggil berulang / bersamaan: baris transaksi dikunci dan status
     * dicek ulang di dalam transaksi database, jadi koin hanya bertambah sekali.
     *
     * @param  string  $method  'qr' (dikonfirmasi user) atau 'admin' (fallback)
     */
    public function settle(TransaksiSetor $transaksi, string $method): TransaksiSetor
    {
        $terkunci = DB::transaction(function () use ($transaksi, $method, &$terbaru) {
            $terbaru = false;
            $locked = TransaksiSetor::with(['details.jenisSampah'])
                ->lockForUpdate()
                ->findOrFail($transaksi->id);

            // Sudah cair sebelumnya -> idempoten, jangan tambah koin lagi.
            if ($locked->status === 'approved') {
                return $locked;
            }

            if ($locked->status === 'rejected') {
                throw ValidationException::withMessages([
                    'status' => 'Transaksi yang sudah dibatalkan tidak bisa diselesaikan.',
                ]);
            }

            if ($locked->details->isEmpty()) {
                throw ValidationException::withMessages([
                    'details' => 'Transaksi tidak memiliki rincian sampah.',
                ]);
            }

            $totalKoin = 0.0;
            $totalBeratKg = 0.0;
            $totalLiter = 0.0;

            foreach ($locked->details as $detail) {
                $totalKoin += (float) $detail->koin_subtotal;
                $totalBeratKg += $this->beratKg($detail);
                // Total liter mentah (tanpa konversi), biar member melihat
                // berapa literaliter yang disetor — tampil berdampingan kg.
                if ($detail->jenisSampah?->satuan === 'liter') {
                    $totalLiter += (float) $detail->jumlah;
                }
            }

            $totalKoin = round($totalKoin, 2);
            $totalBeratKg = round($totalBeratKg, 2);
            $totalLiter = round($totalLiter, 2);

            $wallet = Wallet::where('user_id', $locked->user_id)->lockForUpdate()->first();

            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $locked->user_id,
                    'saldo_koin' => 0,
                    'total_koin_diperoleh' => 0,
                    'total_sampah_kg' => 0,
                    'total_sampah_liter' => 0,
                ]);
            }

            $wallet->increment('saldo_koin', $totalKoin);
            $wallet->increment('total_koin_diperoleh', $totalKoin);
            $wallet->increment('total_sampah_kg', $totalBeratKg);
            $wallet->increment('total_sampah_liter', $totalLiter);

            $locked->update([
                'status' => 'approved',
                'total_koin' => $totalKoin,
                'settled_at' => now(),
                'settlement_method' => $method,
            ]);
            $terbaru = true;

            TransaksiKoin::create([
                'user_id' => $locked->user_id,
                'tipe' => 'masuk',
                'jumlah_koin' => $totalKoin,
                'sumber' => 'setor_sampah',
                'referensi_id' => $locked->id,
                'keterangan' => 'Setor sampah disetujui: '.$locked->kode_transaksi,
            ]);

            return $locked;
        });

        // Koin sudah sah → notifikasi sistem HP via FCM. Dikirim di luar
        // transaksi DB, dan kegagalan kirim tidak boleh merusak settlement.
        // (panggilan-ulang idempoten tidak memicu notifikasi dobel)
        if ($terbaru) {
            $this->fcm->kirimKeUser(
                $terkunci->user_id,
                'Koin bertambah',
                sprintf('Setoran %s disetujui. +%.0f koin sudah masuk ke dompet Livora kamu.', $terkunci->kode_transaksi, (float) $terkunci->total_koin),
                ['tipe' => 'setor_disetujui', 'transaksi_id' => $terkunci->id],
            );
        }

        return $terkunci;
    }

    /**
     * Konversi berat ke kg untuk statistik dampak.
     * Catatan: jumlah per satuan TIDAK dijumlahkan mentah antar satuan —
     * kg, liter, dan pcs dikonversi terpisah agar tidak tercampur.
     */
    private function beratKg($detail): float
    {
        $jumlah = (float) $detail->jumlah;
        $satuan = $detail->jenisSampah?->satuan;

        return match ($satuan) {
            'kg' => $jumlah,
            'liter' => $jumlah * 0.9,
            'pcs' => $jumlah * 0.05,
            default => 0.0,
        };
    }
}
