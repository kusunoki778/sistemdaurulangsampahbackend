<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function balasanGemini(string $teks): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $teks]]]],
            ],
        ];
    }

    public function test_pertanyaan_umum_dijawab_faq_tanpa_memanggil_gemini(): void
    {
        Http::fake();

        $response = $this->postJson('/api/chat', ['message' => 'apa itu livora']);

        $response->assertOk()->assertJsonPath('data.source', 'faq');
        $this->assertStringContainsString(
            'bank sampah digital',
            (string) $response->json('data.reply'),
        );

        Http::assertNothingSent();
    }

    public function test_topik_baru_dikenali_faq(): void
    {
        Http::fake();

        $pencairan = $this->postJson('/api/chat', ['message' => 'berapa minimal pencairan']);
        $pencairan->assertOk()->assertJsonPath('data.source', 'faq');
        $this->assertStringContainsString(
            '100 koin',
            (string) $pencairan->json('data.reply'),
        );

        $tier = $this->postJson('/api/chat', ['message' => 'apa itu gold member']);
        $tier->assertOk()->assertJsonPath('data.source', 'faq');
        $this->assertStringContainsString(
            '100 kg',
            (string) $tier->json('data.reply'),
        );

        $rupiah = $this->postJson('/api/chat', ['message' => '1 koin berapa rupiah']);
        $rupiah->assertOk()->assertJsonPath('data.source', 'faq');
        $this->assertStringContainsString(
            'Rp 100',
            (string) $rupiah->json('data.reply'),
        );

        Http::assertNothingSent();
    }

    public function test_jawaban_gemini_disimpan_di_cache_untuk_pertanyaan_sama(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(
                $this->balasanGemini('Jawaban dari AI.'),
            ),
        ]);

        $pertama = $this->postJson('/api/chat', ['message' => 'pertanyaan unik uji cache']);
        $pertama->assertOk()
            ->assertJsonPath('data.source', 'gemini')
            ->assertJsonPath('data.reply', 'Jawaban dari AI.');

        $kedua = $this->postJson('/api/chat', ['message' => 'pertanyaan unik uji cache']);
        $kedua->assertOk()
            ->assertJsonPath('data.source', 'gemini')
            ->assertJsonPath('data.reply', 'Jawaban dari AI.');

        Http::assertSentCount(1);
    }

    public function test_riwayat_percakapan_tidak_memakai_cache(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(
                $this->balasanGemini('Jawaban lanjutan.'),
            ),
        ]);

        $payload = [
            'message' => 'cerita unik lanjutan',
            'riwayat' => [['peran' => 'user', 'teks' => 'halo']],
        ];

        $this->postJson('/api/chat', $payload)->assertOk();
        $this->postJson('/api/chat', $payload)->assertOk();

        Http::assertSentCount(2);
    }

    public function test_gemini_gagal_memberi_balasan_umum(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(['error' => 'sibuk'], 503),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'pertanyaan unik gagal']);

        $response->assertOk()->assertJsonPath('data.source', 'fallback');
    }
}
