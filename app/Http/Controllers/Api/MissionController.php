<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mission;
use App\Models\MissionProgress;
use App\Models\TransaksiKoin;
use App\Models\TransaksiSetor;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MissionController extends Controller
{
    /**
     * Daftar misi aktif user
     * GET /api/missions
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $missions = Mission::where('status_aktif', true)
            ->orderBy('tipe')
            ->orderBy('id')
            ->get();

        $data = $missions->map(function ($mission) use ($user) {
            $judul = strtolower((string) $mission->judul);

            // Misi login harian: baris progress dipakai ulang setiap hari.
            //
            // Sumber kebenaran "sudah klaim hari ini" adalah kolom
            // terakhir_klaim_tanggal, bukan status. Alasannya status hanya
            // bisa bernilai ongoing/completed/claimed, jadi kalau memakai
            // status misi harian akan terkunci 'claimed' selamanya dan tidak
            // bisa diklaim lagi besok.
            //
            // Deteksi lewat judul, BUKAN tipe: tipe `daily` juga dipakai misi
            // lain (mis. setor sampah harian) yang progresnya bukan daily_login.
            if (str_contains($judul, 'login')) {
                $progress = MissionProgress::firstOrNew([
                    'user_id' => $user->id,
                    'mission_id' => $mission->id,
                ]);

                $tanggalKlaim = $progress->terakhir_klaim_tanggal;
                $sudahKlaimHariIni = $tanggalKlaim !== null
                    && $tanggalKlaim->isSameDay(today());

                // Login hari ini dianggap selesai begitu user membuka daftar
                // misi, karena syarat misi ini memang "login". Hadiah tetap
                // harus diklaim manual lewat tombol Klaim.
                $progress->progress = $mission->target_jumlah;
                $progress->status = $sudahKlaimHariIni ? 'claimed' : 'completed';
                $progress->save();

                return [
                    'id' => $mission->id,
                    'judul' => $mission->judul,
                    'deskripsi' => $mission->deskripsi,
                    'tipe' => $mission->tipe,
                    'target_jumlah' => $mission->target_jumlah,
                    'koin_reward' => (float) $mission->koin_reward,
                    'progress' => $mission->target_jumlah,
                    'status' => $progress->status,
                    'is_completed' => true,
                    'can_claim' => $sudahKlaimHariIni === false,
                ];
            }

            // Auto-update progress untuk misi setor sampah (dihitung dari
            // transaksi setor yang sudah approved) — dipatok sesuai tipe:
            // daily = hari ini, weekly = minggu ini.
            if (str_contains($judul, 'setor')) {
                $query = TransaksiSetor::where('user_id', $user->id)
                    ->where('status', 'approved');

                if ($mission->tipe === 'daily') {
                    $query->whereDate('created_at', today());
                } elseif ($mission->tipe === 'weekly') {
                    $query->whereBetween('created_at', [
                        now()->startOfWeek(),
                        now()->endOfWeek(),
                    ]);
                }
                $totalSetor = $query->count();

                $progress = MissionProgress::firstOrNew([
                    'user_id' => $user->id,
                    'mission_id' => $mission->id,
                ]);

                $progress->progress = max($totalSetor, 0);

                // Jangan menimpa status 'claimed' — jika ditimpa, hadiah yang
                // sudah diklaim bisa diklaim ulang pada pemuatan berikutnya.
                if ($progress->status !== 'claimed') {
                    $progress->status = $totalSetor >= $mission->target_jumlah ? 'completed' : 'ongoing';
                }

                $progress->save();

                $currentProgress = $progress->progress;
                $progressStatus = $progress->status;
            } else {
                $progress = MissionProgress::where('user_id', $user->id)
                    ->where('mission_id', $mission->id)
                    ->first();

                $currentProgress = $progress ? $progress->progress : 0;
                $progressStatus = $progress ? $progress->status : 'ongoing';
            }

            $isCompleted = $currentProgress >= $mission->target_jumlah;
            $canClaim = $isCompleted && $progressStatus !== 'claimed';

            return [
                'id' => $mission->id,
                'judul' => $mission->judul,
                'deskripsi' => $mission->deskripsi,
                'tipe' => $mission->tipe,
                'target_jumlah' => $mission->target_jumlah,
                'koin_reward' => (float) $mission->koin_reward,
                'progress' => min($currentProgress, $mission->target_jumlah),
                'status' => $progressStatus,
                'is_completed' => $isCompleted,
                'can_claim' => $canClaim,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Klaim hadiah misi
     * POST /api/missions/{id}/claim
     */
    public function claim(Request $request, $id)
    {
        $user = $request->user();
        $mission = Mission::where('status_aktif', true)->findOrFail($id);

        return DB::transaction(function () use ($user, $mission) {
            $misiLogin = str_contains(strtolower((string) $mission->judul), 'login');

            // Kunci baris progress & wallet agar klaim ganda tidak lolos.
            $progress = MissionProgress::where('user_id', $user->id)
                ->where('mission_id', $mission->id)
                ->lockForUpdate()
                ->first();

            if ($misiLogin) {
                // Misi login tidak punya syarat lain selain membuka aplikasi,
                // jadi baris progress dibuat bila belum ada.
                if (! $progress) {
                    $progress = MissionProgress::create([
                        'user_id' => $user->id,
                        'mission_id' => $mission->id,
                        'progress' => $mission->target_jumlah,
                        'status' => 'completed',
                    ]);
                }

                $tanggalKlaim = $progress->terakhir_klaim_tanggal;

                if ($tanggalKlaim !== null && $tanggalKlaim->isSameDay(today())) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Hadiah misi sudah diklaim hari ini. Kembali besok untuk klaim lagi.',
                    ], 400);
                }
            } else {
                if (! $progress) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Misi belum dimulai',
                    ], 400);
                }

                if ($progress->progress < $mission->target_jumlah) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Misi belum selesai',
                    ], 400);
                }

                if ($progress->status === 'claimed') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Hadiah misi sudah diklaim',
                    ], 400);
                }
            }

            $progress->update([
                'status' => 'claimed',
                'terakhir_klaim_tanggal' => today(),
            ]);

            // Tambah koin ke wallet
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'saldo_koin' => 0,
                    'total_koin_diperoleh' => 0,
                    'total_sampah_kg' => 0,
                ]);
            }
            $wallet->increment('saldo_koin', $mission->koin_reward);
            $wallet->increment('total_koin_diperoleh', $mission->koin_reward);

            TransaksiKoin::create([
                'user_id' => $user->id,
                'tipe' => 'masuk',
                'jumlah_koin' => $mission->koin_reward,
                'sumber' => 'mission',
                'referensi_id' => $progress->id,
                'keterangan' => 'Klaim misi: '.$mission->judul,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Hadiah misi berhasil diklaim!',
                'data' => [
                    'progress' => $progress,
                    'koin_diperoleh' => (float) $mission->koin_reward,
                    'sisa_koin' => (float) $wallet->fresh()->saldo_koin,
                ],
            ]);
        });
    }
}
