<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransaksiKoin;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyLoginController extends Controller
{
    /**
     * Tangga hadiah login mingguan — posisi 1..7 lalu kembali ke 1.
     * Total siklus 180 koin di bawah hasil satu kali setor panic sampah,
     * agar menyetor tetap jauh lebih menguntungkan daripada cuma login.
     */
    public const HADIAH_MINGGUAN = [10, 15, 20, 25, 30, 35, 50];

    /**
     * Klaim koin hadiah login (tangga mingguan).
     * POST /api/daily-login
     */
    public function claim(Request $request)
    {
        $user = $request->user();

        // Cek apakah sudah klaim hari ini.
        $klaimHariIni = TransaksiKoin::where('user_id', $user->id)
            ->where('sumber', 'daily_login')
            ->whereDate('created_at', today())
            ->first();

        if ($klaimHariIni) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda sudah mengklaim hadiah harian hari ini',
                'data' => [
                    'already_claimed' => true,
                    'claimed_at' => $klaimHariIni->created_at,
                ],
            ], 400);
        }

        return DB::transaction(function () use ($user) {
            $wallet = $user->wallet;
            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'saldo_koin' => 0,
                    'total_koin_diperoleh' => 0,
                    'total_sampah_kg' => 0,
                    'total_sampah_liter' => 0,
                ]);
            }

            // Posisi hari berikutnya: lanjut rentetan bila kemarin klaim,
            // reset ke hari 1 bila bolong sehari (atau belum pernah klaim).
            $rentetan = $this->rentetanSaatIni($user->id);
            $klaimKemarin = $this->tanggalKlaimTerakhir($user->id);

            $hariSekarang = ($klaimKemarin !== null && $klaimKemarin->isYesterday())
                ? $rentetan + 1
                : 1;

            $posisi = ($hariSekarang - 1) % 7; // 0..6 dalam siklus mingguan
            $koinReward = self::HADIAH_MINGGUAN[$posisi];

            $wallet->increment('saldo_koin', $koinReward);
            $wallet->increment('total_koin_diperoleh', $koinReward);

            $transaksi = TransaksiKoin::create([
                'user_id' => $user->id,
                'tipe' => 'masuk',
                'jumlah_koin' => $koinReward,
                'sumber' => 'daily_login',
                'keterangan' => 'Hadiah login harian (Hari '.$hariSekarang.')',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Selamat! Hari '.$hariSekarang.' mingguan: Anda '
                    .'mendapatkan '.$koinReward.' koin!',
                'data' => [
                    'koin_diperoleh' => $koinReward,
                    'hari' => $hariSekarang,
                    'posisi_siklus' => $posisi + 1,
                    'sisa_koin' => (float) $wallet->fresh()->saldo_koin,
                    'transaksi' => $transaksi,
                ],
            ]);
        });
    }

    /**
     * Cek status daily login untuk kartu kalender 7 hari di beranda.
     * GET /api/daily-login/status
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $klaimTerakhir = $this->tanggalKlaimTerakhir($user->id);
        $haruDiklaim = ($klaimTerakhir !== null && $klaimTerakhir->isToday());

        // Rentetan beruntun berakhir di klaim terakhir (hari ini atau kemarin).
        // Bila klaim terakhir sudah bolong (lebih tua dari kemarin), rentetan
        // hangus: klaim berikutnya kembali ke Hari 1.
        $hariBerikutnya = 1;
        $terisiSiklus = 0;

        if ($klaimTerakhir !== null
            && ($klaimTerakhir->isToday() || $klaimTerakhir->isYesterday())) {
            $rentetan = $this->rentetanSaatIni($user->id);

            // Kotak terisi dalam siklus = posisi siklus klaim terakhir:
            // Hari ke-n -> posisi ((n - 1) % 7) + 1.
            $terisiSiklus = (($rentetan - 1) % 7) + 1;
            $hariBerikutnya = $rentetan + 1;
        }

        $posisi = ($hariBerikutnya - 1) % 7;
        $koinBerikutnya = self::HADIAH_MINGGUAN[$posisi];

        $jumlahKlaimHariIni = null;
        if ($haruDiklaim) {
            $jumlahKlaimHariIni = (float) TransaksiKoin::where('user_id', $user->id)
                ->where('sumber', 'daily_login')
                ->whereDate('created_at', today())
                ->sum('jumlah_koin');
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'already_claimed' => $haruDiklaim,
                'claimed_at' => $klaimTerakhir?->toIso8601String(),
                'koin_diperoleh_hari_ini' => $jumlahKlaimHariIni,
                'hari_berikutnya' => $hariBerikutnya,
                'posisi_siklus' => $posisi + 1,
                'koin_besok' => $koinBerikutnya,
                'hadiah_mingguan' => self::HADIAH_MINGGUAN,
                'terisi_siklus' => $terisiSiklus,
            ],
        ]);
    }

    /**
     * Rentetan klaim beruntun (hari) berakhir di klaim terakhir.
     * Dihitung dari tanggal riwayat TransaksiKoin — tidak perlu kolom baru.
     */
    private function rentetanSaatIni(int $userId): int
    {
        $tanggal = TransaksiKoin::where('user_id', $userId)
            ->where('sumber', 'daily_login')
            ->get()
            ->pluck('created_at')
            ->map(fn ($created) => $created->toDateString())
            ->unique()
            ->values();

        if ($tanggal->isEmpty()) {
            return 0;
        }

        // Hitung beruntun mundur dari tanggal klaim terakhir.
        $rentetan = 0;
        $kursor = Carbon::parse($tanggal->last());

        while ($tanggal->contains($kursor->toDateString())) {
            $rentetan++;
            $kursor = $kursor->copy()->subDay();
        }

        return $rentetan;
    }

    private function tanggalKlaimTerakhir(int $userId)
    {
        return TransaksiKoin::where('user_id', $userId)
            ->where('sumber', 'daily_login')
            ->orderByDesc('created_at')
            ->value('created_at');
    }
}
