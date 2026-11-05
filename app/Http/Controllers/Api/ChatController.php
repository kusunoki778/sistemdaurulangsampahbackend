<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Chatbot Gemini: respons pertanyaan user tentang Livora,
 * sampah, reward, top-up, dan topik lainnya.
 */
class ChatController extends Controller
{
    private const FAQ = [
        'apa itu livora' => 'Livora adalah platform bank sampah digital yang memudahkan Anda menabung sampah, mendapatkan koin, dan menukarnya dengan reward menarik.',
        'bagaimana cara setor sampah' => 'Setor sampah di lokasi drop point Livora kami. Admin akan memverifikasi berat sampah Anda, dan koin langsung masuk ke wallet.',
        'bagaimana cara tukar reward' => 'Pilih reward di katalog, tukar dengan koin yang tersedia. Terima kupon dengan QR, kemudian ambil reward di drop point.',
        'biaya' => 'Semua layanan Livora gratis. Tidak ada biaya setor, tukar reward, atau top-up (hanya biaya Midtrans untuk pembayaran yang berlaku di bank normal).',
    ];

    /**
     * Chat dengan Gemini (atau fallback FAQ).
     * POST /api/chat { message }
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $userMessage = trim($validated['message']);

        if (empty($userMessage)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pesan tidak boleh kosong.',
            ], 422);
        }

        // Coba Gemini dulu
        $geminiReply = $this->tanyaGemini($userMessage);

        if ($geminiReply) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'reply' => $geminiReply,
                    'source' => 'gemini',
                ],
            ]);
        }

        // Fallback FAQ
        $faqReply = $this->cariDiFaq($userMessage);

        if ($faqReply) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'reply' => $faqReply,
                    'source' => 'faq',
                ],
            ]);
        }

        // Fallback generik
        return response()->json([
            'status' => 'success',
            'data' => [
                'reply' => 'Maaf, saya kurang memahami pertanyaan Anda. Coba tanyakan tentang cara setor sampah, tukar reward, atau hal lain tentang Livora.',
                'source' => 'fallback',
            ],
        ]);
    }

    private function tanyaGemini(string $userMessage): ?string
    {
        $apiKey = (string) env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            return null;
        }

        $prompt = 'Kamu adalah asisten Livora, platform bank sampah digital Indonesia. '
            .'Jawab pertanyaan berikut dengan singkat, ramah, dan informatif dalam Bahasa Indonesia. '
            .'Fokus pada Livora, sampah, koin, reward, dan pembayaran. '
            ."\n\nPertanyaan: $userMessage";

        try {
            $respons = Http::timeout(15)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent', [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'safetySettings' => [
                        [
                            'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                            'threshold' => 'BLOCK_NONE',
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 150,
                    ],
                ], [
                    'x-goog-api-key' => $apiKey,
                ]);

            if ($respons->failed()) {
                return null;
            }

            $teks = $respons->json('candidates.0.content.parts.0.text');

            return is_string($teks) ? trim($teks) : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function cariDiFaq(string $userMessage): ?string
    {
        $userLower = strtolower($userMessage);

        foreach (self::FAQ as $tanya => $jawab) {
            if (str_contains($userLower, $tanya)) {
                return $jawab;
            }
        }

        return null;
    }
}
