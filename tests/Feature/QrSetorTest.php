<?php

namespace Tests\Feature;

use App\Models\JenisSampah;
use App\Models\KategoriSampah;
use App\Models\QrSession;
use App\Models\TransaksiKoin;
use App\Models\TransaksiSetor;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QrSetorTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $role = 'user'): User
    {
        $user = User::factory()->create(['role' => $role]);

        Wallet::create([
            'user_id' => $user->id,
            'saldo_koin' => 0,
            'total_koin_diperoleh' => 0,
            'total_sampah_kg' => 0,
        ]);

        return $user;
    }

    private function buatJenisSampah(float $poinPerSatuan = 25): JenisSampah
    {
        $kategori = KategoriSampah::create(['nama_kategori' => 'Plastik']);

        return JenisSampah::create([
            'kategori_id' => $kategori->id,
            'nama_jenis' => 'Botol PET',
            'satuan' => 'kg',
            'poin_per_satuan' => $poinPerSatuan,
        ]);
    }

    /**
     * @return array{0: TransaksiSetor, 1: string}
     */
    private function buatTransaksiDenganQr(User $admin, User $user, JenisSampah $jenis, float $jumlah = 1.35): array
    {
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/transaksi-setor', [
            'user_id' => $user->id,
            'items' => [
                ['jenis_sampah_id' => $jenis->id, 'jumlah' => $jumlah],
            ],
        ])->assertCreated();

        return [
            TransaksiSetor::findOrFail($response->json('data.transaksi.id')),
            $response->json('data.qr.token'),
        ];
    }

    public function test_admin_membuat_setoran_lalu_qr_terbit_tanpa_langsung_mencairkan_koin(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/transaksi-setor', [
            'user_id' => $user->id,
            'items' => [
                ['jenis_sampah_id' => $jenis->id, 'jumlah' => 1.35],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.transaksi.status', 'menunggu_konfirmasi');
        $response->assertJsonPath('data.transaksi.total_koin', '33.75');
        $response->assertJsonPath('data.transaksi.details.0.poin_per_satuan', '25.00');

        $token = $response->json('data.qr.token');
        $this->assertNotEmpty($token);
        $this->assertSame('LIVORA-SETOR:'.$token, $response->json('data.qr.payload'));

        $this->assertDatabaseHas('qr_sessions', [
            'token_hash' => hash('sha256', $token),
            'used_at' => null,
            'revoked_at' => null,
        ]);

        // Berat sudah diinput admin, tetapi koin belum cair sebelum user konfirmasi.
        $this->assertSame('0.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(0, TransaksiKoin::count());
    }

    public function test_user_scan_lalu_konfirmasi_mencairkan_koin_sekali_saja(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        [$transaksi, $token] = $this->buatTransaksiDenganQr($admin, $user, $jenis);

        Sanctum::actingAs($user);

        $this->postJson('/api/transaksi-setor/scan', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.transaksi.total_koin', '33.75')
            ->assertJsonPath('data.transaksi.status', 'menunggu_konfirmasi');

        $this->postJson('/api/transaksi-setor/konfirmasi', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.koin_diperoleh', 33.75)
            ->assertJsonPath('data.sisa_koin', 33.75);

        $transaksi->refresh();
        $this->assertSame('approved', $transaksi->status);
        $this->assertSame('qr', $transaksi->settlement_method);
        $this->assertNotNull($transaksi->settled_at);
        $this->assertSame('33.75', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'setor_sampah')->count());

        // Konfirmasi ulang harus idempoten (tidak menambah koin lagi).
        $this->postJson('/api/transaksi-setor/konfirmasi', ['token' => $token])
            ->assertOk();

        $this->assertSame('33.75', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::count());
        $this->assertNotNull(QrSession::first()->used_at);
    }

    public function test_qr_kedaluwarsa_tidak_bisa_dipakai(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        [, $token] = $this->buatTransaksiDenganQr($admin, $user, $jenis);

        QrSession::query()->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($user);

        $this->postJson('/api/transaksi-setor/scan', ['token' => $token])->assertStatus(422);
        $this->postJson('/api/transaksi-setor/konfirmasi', ['token' => $token])->assertStatus(422);

        $this->assertSame('0.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
    }

    public function test_qr_milik_member_lain_ditolak(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $userLain = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        [, $token] = $this->buatTransaksiDenganQr($admin, $user, $jenis);

        Sanctum::actingAs($userLain);

        $this->postJson('/api/transaksi-setor/scan', ['token' => $token])->assertStatus(403);
        $this->postJson('/api/transaksi-setor/konfirmasi', ['token' => $token])->assertStatus(403);
    }

    public function test_admin_bisa_menyelesaikan_tanpa_konfirmasi(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        [$transaksi, $token] = $this->buatTransaksiDenganQr($admin, $user, $jenis);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/transaksi-setor/{$transaksi->id}/selesaikan")
            ->assertOk();

        $transaksi->refresh();
        $this->assertSame('approved', $transaksi->status);
        $this->assertSame('admin', $transaksi->settlement_method);
        $this->assertSame('33.75', Wallet::where('user_id', $user->id)->first()->saldo_koin);

        // Token yang terbit sebelumnya otomatis dicabut, tetapi konfirmasi
        // user setelah fallback admin tetap idempoten (tidak menambah koin).
        Sanctum::actingAs($user);
        $this->postJson('/api/transaksi-setor/konfirmasi', ['token' => $token])->assertOk();
        $this->assertSame('33.75', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::count());
    }

    public function test_terbit_ulang_qr_mematikan_token_lama(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        [$transaksi, $tokenLama] = $this->buatTransaksiDenganQr($admin, $user, $jenis);

        Sanctum::actingAs($admin);

        $tokenBaru = $this->postJson("/api/admin/transaksi-setor/{$transaksi->id}/qr")
            ->assertOk()
            ->json('data.qr.token');

        $this->assertNotSame($tokenLama, $tokenBaru);

        Sanctum::actingAs($user);

        $this->postJson('/api/transaksi-setor/scan', ['token' => $tokenLama])->assertStatus(422);
        $this->postJson('/api/transaksi-setor/scan', ['token' => $tokenBaru])->assertOk();
    }

    public function test_batalkan_transaksi_tidak_mencairkan_koin(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        [$transaksi, $token] = $this->buatTransaksiDenganQr($admin, $user, $jenis);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/transaksi-setor/{$transaksi->id}/batalkan", [
            'catatan' => 'Sampah tidak sesuai kriteria.',
        ])->assertOk();

        $this->assertSame('rejected', $transaksi->fresh()->status);
        $this->assertSame('0.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);

        Sanctum::actingAs($user);
        $this->postJson('/api/transaksi-setor/scan', ['token' => $token])->assertStatus(422);
    }

    public function test_pengajuan_setor_mandiri_dari_user_sudah_dipensiunkan(): void
    {
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah();

        Sanctum::actingAs($user);

        $this->postJson('/api/transaksi-setor', [
            'items' => [['jenis_sampah_id' => $jenis->id, 'jumlah' => 1]],
        ])->assertStatus(405);
    }

    public function test_admin_tidak_bisa_membuat_setoran_untuk_akun_admin(): void
    {
        $admin = $this->buatUser('admin');
        $jenis = $this->buatJenisSampah();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/transaksi-setor', [
            'user_id' => $admin->id,
            'items' => [['jenis_sampah_id' => $jenis->id, 'jumlah' => 1]],
        ])->assertStatus(422);
    }
}
