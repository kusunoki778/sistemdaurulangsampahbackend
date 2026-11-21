<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifikasi login admin: Turnstile sebagai jalur utama, captcha gambar
 * lokal sebagai cadangan bila Turnstile belum siap atau tidak bisa dihubungi.
 */
class TurnstileLoginTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.turnstile.site_key' => null,
            'services.turnstile.secret_key' => null,
            'services.turnstile.verify_url' => self::VERIFY_URL,
            'services.turnstile.timeout' => 8,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'name' => 'Admin Uji',
            'username' => 'admin_uji',
            'email' => 'admin_uji@livora.com',
            'password' => bcrypt('rahasia123'),
            'role' => 'admin',
            'status' => 'aktif',
        ]);

        Wallet::create([
            'user_id' => $admin->id,
            'saldo_koin' => 0,
            'koin_ditahan' => 0,
            'total_koin_diperoleh' => 0,
            'total_sampah_kg' => 0,
        ]);

        return $admin;
    }

    public function test_turnstile_tidak_aktif_memakai_captcha_lokal(): void
    {
        $this->admin();

        $this->getJson('/api/auth/verifikasi-keamanan')
            ->assertOk()
            ->assertJsonPath('data.turnstile_aktif', false)
            ->assertJsonPath('data.turnstile_site_key', null);

        // Login admin tanpa captcha lokal tetap ditolak.
        $this->postJson('/api/auth/login', [
            'login' => 'admin_uji',
            'password' => 'rahasia123',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');

        // Dengan captcha lokal yang benar, login lolos tanpa memanggil Cloudflare.
        Http::fake();

        $captcha = $this->getJson('/api/auth/captcha')->assertOk()->json('data');
        $jawaban = Cache::get('captcha:'.$captcha['token']);

        $this->postJson('/api/auth/login', [
            'login' => 'admin_uji',
            'password' => 'rahasia123',
            'captcha_token' => $captcha['token'],
            'captcha_jawaban' => (string) $jawaban,
        ])->assertOk()
            ->assertJsonPath('status', 'success');

        Http::assertNothingSent();
    }

    public function test_captcha_lokal_memakai_gambar_saat_gd_tersedia(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD tidak tersedia di lingkungan test.');
        }

        $captcha = $this->getJson('/api/auth/captcha')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('gambar', $captcha);
        $this->assertStringStartsWith('data:image/png;base64,', $captcha['gambar']);
        $this->assertArrayNotHasKey('pertanyaan', $captcha);
    }

    public function test_turnstile_aktif_token_tidak_sah_ditolak(): void
    {
        $this->admin();

        config([
            'services.turnstile.site_key' => 'site-key-uji',
            'services.turnstile.secret_key' => 'secret-key-uji',
        ]);

        Http::fake([
            self::VERIFY_URL => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $this->getJson('/api/auth/verifikasi-keamanan')
            ->assertOk()
            ->assertJsonPath('data.turnstile_aktif', true)
            ->assertJsonPath('data.turnstile_site_key', 'site-key-uji');

        $this->postJson('/api/auth/login', [
            'login' => 'admin_uji',
            'password' => 'rahasia123',
            'turnstile_token' => 'token-palsu',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('captcha_required', true);

        Http::assertSent(function ($request) {
            return $request->url() === self::VERIFY_URL
                && $request['secret'] === 'secret-key-uji'
                && $request['response'] === 'token-palsu';
        });
    }

    public function test_turnstile_aktif_token_sah_login_lolos(): void
    {
        $this->admin();

        config([
            'services.turnstile.site_key' => 'site-key-uji',
            'services.turnstile.secret_key' => 'secret-key-uji',
        ]);

        Http::fake([
            self::VERIFY_URL => Http::response(['success' => true], 200),
        ]);

        $this->postJson('/api/auth/login', [
            'login' => 'admin_uji',
            'password' => 'rahasia123',
            'turnstile_token' => 'token-asli',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_turnstile_tidak_bisa_dihubungi_kembali_ke_captcha_lokal(): void
    {
        $this->admin();

        config([
            'services.turnstile.site_key' => 'site-key-uji',
            'services.turnstile.secret_key' => 'secret-key-uji',
        ]);

        // Cloudflare dianggap sedang bermasalah.
        Http::fake([
            self::VERIFY_URL => Http::response('server error', 500),
        ]);

        // Tanpa captcha lokal: ditolak, bukan dibiarkan lolos.
        $this->postJson('/api/auth/login', [
            'login' => 'admin_uji',
            'password' => 'rahasia123',
            'turnstile_token' => 'token-apa-saja',
        ])->assertStatus(422);

        // Dengan captcha lokal yang benar: login tetap bisa, admin tidak
        // terkunci di luar hanya karena Cloudflare bermasalah.
        Http::fake([
            self::VERIFY_URL => Http::response('server error', 500),
        ]);

        $captcha = $this->getJson('/api/auth/captcha')->assertOk()->json('data');
        $jawaban = Cache::get('captcha:'.$captcha['token']);

        $this->postJson('/api/auth/login', [
            'login' => 'admin_uji',
            'password' => 'rahasia123',
            'turnstile_token' => 'token-apa-saja',
            'captcha_token' => $captcha['token'],
            'captcha_jawaban' => (string) $jawaban,
        ])->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
