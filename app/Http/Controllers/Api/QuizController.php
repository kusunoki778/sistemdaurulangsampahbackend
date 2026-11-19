<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\TransaksiKoin;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class QuizController extends Controller
{
    /**
     * Ambil kuis harian aktif
     * GET /api/quizzes/daily
     *
     * Respons memuat "quiz" (kuis terbaru, tetap dipakai build aplikasi lama)
     * dan "daftar" (seluruh kuis aktif) supaya member bisa memilih kuis mana
     * yang ingin dikerjakan, bukan hanya kuis yang paling baru dibuat.
     */
    public function daily(Request $request)
    {
        $semua = Quiz::where('status_aktif', true)
            ->with('questions')
            ->latest()
            ->get();

        if ($semua->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak ada kuis harian tersedia saat ini',
            ], 404);
        }

        $user = $request->user();

        // Satu query untuk semua percobaan hari ini, bukan satu query per kuis.
        $percobaanHariIni = QuizAttempt::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->get()
            ->keyBy('quiz_id');

        $daftar = $semua->map(function (Quiz $quiz) use ($percobaanHariIni) {
            $attempt = $percobaanHariIni->get($quiz->id);

            return [
                'id' => $quiz->id,
                'judul' => $quiz->judul,
                'deskripsi' => $quiz->deskripsi,
                'koin_reward' => (float) $quiz->koin_reward,
                'jumlah_soal' => $quiz->questions->count(),
                'already_attempted' => $attempt !== null,
                'skor' => $attempt?->skor,
            ];
        })->values();

        $quiz = $semua->first();
        $todayAttempt = $percobaanHariIni->get($quiz->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'quiz' => $quiz,
                'already_attempted' => $todayAttempt !== null,
                'attempt' => $todayAttempt,
                'daftar' => $daftar,
            ],
        ]);
    }

    /**
     * Soal satu kuis, dipakai aplikasi member.
     * GET /api/quizzes/{id}/soal
     *
     * Jawaban benar tidak pernah dikirim ke aplikasi; penilaian tetap
     * dilakukan di server lewat endpoint submit.
     */
    public function soalKuis(Request $request, $id)
    {
        $quiz = Quiz::where('status_aktif', true)->findOrFail($id);

        $soal = $quiz->questions()
            ->orderBy('id')
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'pertanyaan' => $q->pertanyaan,
                'opsi_a' => $q->opsi_a,
                'opsi_b' => $q->opsi_b,
                'opsi_c' => $q->opsi_c,
                'opsi_d' => $q->opsi_d,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $soal,
        ]);
    }

    /**
     * Submit jawaban kuis
     * POST /api/quizzes/{id}/submit
     */
    public function submit(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*' => 'required|in:a,b,c,d',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Format jawaban tidak valid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $quiz = Quiz::with('questions')->findOrFail($id);
        $user = $request->user();

        // Cek apakah sudah mencoba hari ini
        $existing = QuizAttempt::where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->whereDate('created_at', today())
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda sudah mengerjakan kuis ini hari ini',
                'data' => $existing,
            ], 400);
        }

        // Hitung skor
        $questions = $quiz->questions;
        $benar = 0;
        $total = $questions->count();

        foreach ($questions as $index => $question) {
            $userAnswer = $request->answers[$index] ?? null;
            if ($userAnswer && strtolower($userAnswer) === strtolower($question->jawaban_benar)) {
                $benar++;
            }
        }

        $skor = $total > 0 ? (int) round(($benar / $total) * 100) : 0;
        $lulus = $benar >= ceil($total * 0.7); // Lulus jika >= 70% benar
        $koinDiperoleh = $lulus ? (float) $quiz->koin_reward : 0;

        return DB::transaction(function () use ($user, $quiz, $skor, $lulus, $koinDiperoleh, $benar, $total) {
            // Buat attempt
            $attempt = QuizAttempt::create([
                'user_id' => $user->id,
                'quiz_id' => $quiz->id,
                'skor' => $skor,
                'lulus' => $lulus,
                'koin_diperoleh' => $koinDiperoleh,
            ]);

            // Jika lulus, tambahkan koin ke wallet
            if ($lulus && $koinDiperoleh > 0) {
                $wallet = $user->wallet;
                if (! $wallet) {
                    $wallet = Wallet::create([
                        'user_id' => $user->id,
                        'saldo_koin' => 0,
                        'total_koin_diperoleh' => 0,
                        'total_sampah_kg' => 0,
                    ]);
                }
                $wallet->increment('saldo_koin', $koinDiperoleh);
                $wallet->increment('total_koin_diperoleh', $koinDiperoleh);

                TransaksiKoin::create([
                    'user_id' => $user->id,
                    'tipe' => 'masuk',
                    'jumlah_koin' => $koinDiperoleh,
                    'sumber' => 'quiz',
                    'referensi_id' => $attempt->id,
                    'keterangan' => "Kuis harian: {$quiz->judul} ({$benar}/{$total} benar)",
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => $lulus ? 'Selamat! Anda lulus kuis dan mendapat koin!' : 'Sayang sekali, Anda belum lulus kuis ini.',
                'data' => [
                    'attempt' => $attempt,
                    'skor' => $skor,
                    'benar' => $benar,
                    'total' => $total,
                    'lulus' => $lulus,
                    'koin_diperoleh' => $koinDiperoleh,
                    'sisa_koin' => $user->wallet?->fresh()->saldo_koin ?? 0,
                ],
            ]);
        });
    }

    /**
     * Riwayat kuis user
     * GET /api/quizzes/history
     */
    public function history(Request $request)
    {
        $user = $request->user();

        $attempts = QuizAttempt::with('quiz')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $attempts,
        ]);
    }
}
