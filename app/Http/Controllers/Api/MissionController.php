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
            // Auto-update progress untuk misi setor sampah
            // (dihitung dari transaksi setor yang sudah approved)
            if (str_contains(strtolower((string) $mission->judul), 'setor')) {
                $totalSetor = TransaksiSetor::where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->count();

                $progress = MissionProgress::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'mission_id' => $mission->id,
                    ],
                    [
                        'progress' => max($totalSetor, 0),
                        'status' => $totalSetor >= $mission->target_jumlah ? 'ready' : 'ongoing',
                    ]
                );

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
                'koin_reward' => $mission->koin_reward,
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

        $progress = MissionProgress::where('user_id', $user->id)
            ->where('mission_id', $mission->id)
            ->first();

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

        return DB::transaction(function () use ($user, $mission, $progress) {
            $progress->update([
                'status' => 'claimed',
            ]);

            // Tambah koin ke wallet
            $wallet = $user->wallet;
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
                    'koin_diperoleh' => $mission->koin_reward,
                    'sisa_koin' => $wallet->fresh()->saldo_koin,
                ],
            ]);
        });
    }
}
