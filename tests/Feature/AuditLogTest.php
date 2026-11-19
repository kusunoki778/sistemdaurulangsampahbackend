<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pencatatan audit log.
 *
 * Halaman CMS-nya sudah dihapus, tetapi pencatatan tetap berjalan karena
 * dipakai login, perubahan kata sandi, dan perubahan saldo koin. Fokus uji
 * sekarang: data sensitif tidak pernah tersimpan mentah dan aksi tulis admin
 * tetap tercatat.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function buatAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'username' => 'admin_uji']);
    }

    public function test_data_sensitif_disamarkan(): void
    {
        $log = app(AuditLogService::class)->catat('admin_action', [
            'description' => 'Uji penyamaran data sensitif',
            'status_code' => 201,
            'metadata' => [
                'input' => [
                    'password' => 'rahasia123',
                    'captcha_token' => 'token-captcha',
                    'api_key' => 'kunci-rahasia',
                    'email' => 'admin@livora.com',
                    'nomor_hp' => '081234567890',
                    'nama' => 'Data Aman',
                ],
            ],
        ]);

        $this->assertNotNull($log);

        $metadata = json_encode($log->fresh()->metadata);

        // Kata sandi, token, dan kunci API tidak boleh tersimpan mentah.
        $this->assertStringNotContainsString('rahasia123', $metadata);
        $this->assertStringNotContainsString('token-captcha', $metadata);
        $this->assertStringNotContainsString('kunci-rahasia', $metadata);
        $this->assertStringContainsString('[disamarkan]', $metadata);

        // Email dan nomor telepon disamarkan (huruf pertama dipertahankan).
        $this->assertStringNotContainsString('admin@livora.com', $metadata);
        $this->assertStringNotContainsString('081234567890', $metadata);
        $this->assertStringContainsString('a****@livora.com', $metadata);
        $this->assertStringContainsString('********7890', $metadata);

        // Data yang tidak sensitif tetap utuh agar log berguna.
        $this->assertStringContainsString('Data Aman', $metadata);
    }

    public function test_aksi_tulis_admin_tercatat_otomatis(): void
    {
        $admin = $this->buatAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/kategori-sampah', ['nama_kategori' => 'Kategori Uji Log'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin_action',
            'actor_username' => 'admin_uji',
            'method' => 'POST',
            'status_code' => 201,
        ]);
    }

    public function test_aksi_baca_tidak_dicatat(): void
    {
        $admin = $this->buatAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/kategori-sampah')->assertOk();

        // Aksi baca tidak ikut dicatat supaya tabel tidak membengkak.
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin_action']);
    }

    public function test_pencatatan_tidak_menggagalkan_aksi_utama(): void
    {
        // Event dengan metadata ekstrem tetap tidak melempar exception.
        $log = app(AuditLogService::class)->catat('login_failed', [
            'actor_username' => Str::random(300),
            'metadata' => ['data' => ['dalam' => ['lebih' => ['dalam' => ['lagi' => ['terlalu' => 'dalam']]]]]],
        ]);

        $this->assertNotNull($log);
        $this->assertSame('login_failed', $log->event);
    }
}
