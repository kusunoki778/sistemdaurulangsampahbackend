<?php

namespace Tests\Feature;

use App\Models\PermintaanReward;
use App\Models\Reward;
use App\Models\RewardQrSession;
use App\Models\TransaksiKoin;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardKuponTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $role = 'user', float $saldo = 1000): User
    {
        $user = User::factory()->create(['role' => $role]);

        Wallet::create([
            'user_id' => $user->id,
            'saldo_koin' => $saldo,
            'koin_ditahan' => 0,
            'total_koin_diperoleh' => $saldo,
            'total_sampah_kg' => 0,
        ]);

        return $user;
    }

    private function buatReward(string $tipe = 'barang', float $harga = 500, int $stok = 5): Reward
    {
        return Reward::create([
            'nama_reward' => $tipe === 'barang' ? 'Tumbler Livora' : 'Saldo DANA Rp5.000',
            'tipe' => $tipe,
            'koin_dibutuhkan' => $harga,
            'stok' => $stok,
            'deskripsi' => 'Reward uji',
            'status_aktif' => true,
        ]);
    }

    private function tukar(User $user, Reward $reward, array $tambahan = []): PermintaanReward
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/permintaan-reward', array_merge([
            'reward_id' => $reward->id,
        ], $tambahan))->assertCreated();

        return PermintaanReward::findOrFail($response->json('data.permintaan.id'));
    }

    private function terbitkanQr(User $admin, PermintaanReward $permintaan): string
    {
        Sanctum::actingAs($admin);

        return $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/qr")
            ->assertOk()
            ->json('data.qr.token');
    }

    public function test_tukar_barang_menahan_koin_dan_menerbitkan_kupon(): void
    {
        $user = $this->buatUser(saldo: 1000);
        $reward = $this->buatReward(stok: 3);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/permintaan-reward', [
            'reward_id' => $reward->id,
        ])->assertCreated();

        $kode = $response->json('data.permintaan.kode_klaim');

        $this->assertMatchesRegularExpression('/^LV-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $kode);
        $response->assertJsonPath('data.permintaan.status', 'pending');
        $this->assertNotNull($response->json('data.permintaan.expires_at'));

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('1000.00', $wallet->saldo_koin);
        $this->assertSame('500.00', $wallet->koin_ditahan);
        $this->assertSame(500.0, $wallet->saldo_tersedia);
        $this->assertSame(2, $reward->fresh()->stok);
        $this->assertSame(0, TransaksiKoin::count());

        $permintaan = PermintaanReward::first();
        $this->assertTrue($permintaan->expires_at->between(
            now()->addDays(3)->subMinute(),
            now()->addDays(3)->addMinute(),
        ));
    }

    public function test_koin_yang_ditahan_mencegah_double_spend(): void
    {
        $user = $this->buatUser(saldo: 600);
        $reward = $this->buatReward(stok: 5);

        $this->tukar($user, $reward);
        $this->assertSame('500.00', Wallet::where('user_id', $user->id)->first()->koin_ditahan);

        Sanctum::actingAs($user);

        $this->postJson('/api/permintaan-reward', [
            'reward_id' => $reward->id,
        ])->assertStatus(422)->assertJsonValidationErrors('saldo');

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('600.00', $wallet->saldo_koin);
        $this->assertSame('500.00', $wallet->koin_ditahan);
        $this->assertSame(1, PermintaanReward::count());
        $this->assertSame(4, $reward->fresh()->stok);
    }

    public function test_kupon_kedaluwarsa_melepas_hold_dan_mengembalikan_stok(): void
    {
        $user = $this->buatUser(saldo: 1000);
        $reward = $this->buatReward(stok: 3);

        $permintaan = $this->tukar($user, $reward);
        $permintaan->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($user);

        $this->getJson('/api/permintaan-reward')
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'kedaluwarsa');

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('1000.00', $wallet->saldo_koin);
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame(3, $reward->fresh()->stok);
        $this->assertSame(0, TransaksiKoin::count());

        // Evaluasi ulang tidak boleh mengembalikan stok dua kali.
        $this->getJson('/api/permintaan-reward')->assertOk();
        $this->assertSame(3, $reward->fresh()->stok);
        $this->assertSame('0.00', Wallet::where('user_id', $user->id)->first()->koin_ditahan);
    }

    public function test_admin_cari_kupon_lalu_terbitkan_qr_dan_terbit_ulang(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $reward = $this->buatReward();
        $permintaan = $this->tukar($user, $reward);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/permintaan-reward/kupon/'.$permintaan->kode_klaim)
            ->assertOk()
            ->assertJsonPath('data.id', $permintaan->id)
            ->assertJsonPath('data.status', 'pending');

        $responseQr = $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/qr")
            ->assertOk()
            ->assertJsonPath('data.permintaan.status', 'siap_diambil');

        $tokenLama = $responseQr->json('data.qr.token');
        $this->assertSame('LIVORA-REWARD:'.$tokenLama, $responseQr->json('data.qr.payload'));

        $tokenBaru = $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/qr")
            ->assertOk()
            ->json('data.qr.token');

        $this->assertNotSame($tokenLama, $tokenBaru);
        $this->assertSame(2, RewardQrSession::count());

        Sanctum::actingAs($user);

        $this->postJson('/api/permintaan-reward/scan', ['token' => $tokenLama])->assertStatus(422);
        $this->postJson('/api/permintaan-reward/scan', ['token' => $tokenBaru])->assertOk();
    }

    public function test_user_scan_dan_konfirmasi_memotong_koin_sekali(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser(saldo: 1000);
        $reward = $this->buatReward();
        $permintaan = $this->tukar($user, $reward);
        $token = $this->terbitkanQr($admin, $permintaan);

        Sanctum::actingAs($user);

        $this->postJson('/api/permintaan-reward/scan', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.permintaan.status', 'siap_diambil');

        $this->postJson('/api/permintaan-reward/konfirmasi', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.permintaan.status', 'selesai')
            ->assertJsonPath('data.sisa_koin', 500);

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('500.00', $wallet->saldo_koin);
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'reward_claim')->count());
        $this->assertNotNull($permintaan->fresh()->confirmed_at);

        // Konfirmasi ulang harus idempoten.
        $this->postJson('/api/permintaan-reward/konfirmasi', ['token' => $token])->assertOk();

        $wallet->refresh();
        $this->assertSame('500.00', $wallet->saldo_koin);
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame(1, TransaksiKoin::count());
    }

    public function test_qr_reward_milik_member_lain_ditolak(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $userLain = $this->buatUser();
        $reward = $this->buatReward();
        $permintaan = $this->tukar($user, $reward);
        $token = $this->terbitkanQr($admin, $permintaan);

        Sanctum::actingAs($userLain);

        $this->postJson('/api/permintaan-reward/scan', ['token' => $token])->assertStatus(403);
        $this->postJson('/api/permintaan-reward/konfirmasi', ['token' => $token])->assertStatus(403);
    }

    public function test_admin_batalkan_kupon_melepas_hold_dan_stok(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser(saldo: 1000);
        $reward = $this->buatReward(stok: 3);
        $permintaan = $this->tukar($user, $reward);

        $token = $this->terbitkanQr($admin, $permintaan);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/batalkan", [
            'catatan_admin' => 'Stok rusak saat penyiapan.',
        ])->assertOk()->assertJsonPath('data.status', 'dibatalkan');

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('1000.00', $wallet->saldo_koin);
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame(3, $reward->fresh()->stok);
        $this->assertSame(0, TransaksiKoin::count());

        Sanctum::actingAs($user);
        $this->postJson('/api/permintaan-reward/scan', ['token' => $token])->assertStatus(422);
    }

    public function test_admin_tidak_bisa_menyelesaikan_reward_fisik_tanpa_qr(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $reward = $this->buatReward();
        $permintaan = $this->tukar($user, $reward);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/selesai")
            ->assertStatus(422);

        $this->assertSame('pending', $permintaan->fresh()->status);
        $this->assertSame('500.00', Wallet::where('user_id', $user->id)->first()->koin_ditahan);
    }

    public function test_reward_digital_tetap_memotong_koin_langsung(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser(saldo: 5000);
        $reward = $this->buatReward('saldo_digital', 2000, 2);

        Sanctum::actingAs($user);

        $this->postJson('/api/permintaan-reward', [
            'reward_id' => $reward->id,
        ])->assertStatus(422)->assertJsonValidationErrors('nomor_tujuan_digital');

        $response = $this->postJson('/api/permintaan-reward', [
            'reward_id' => $reward->id,
            'nomor_tujuan_digital' => '081234567890',
        ])->assertCreated();

        $permintaan = PermintaanReward::findOrFail($response->json('data.permintaan.id'));

        $this->assertNull($permintaan->expires_at);
        $this->assertSame('0.00', $permintaan->koin_ditahan);

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('3000.00', $wallet->saldo_koin);
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'reward_claim')->count());

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/selesai")
            ->assertOk()
            ->assertJsonPath('data.status', 'selesai');

        $this->assertSame('3000.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::count());
    }

    public function test_masa_berlaku_kupon_bisa_diatur_dari_cms(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $reward = $this->buatReward();

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/permintaan-reward/pengaturan', [
            'masa_berlaku_hari' => 5,
        ])->assertOk()->assertJsonPath('data.masa_berlaku_hari', 5);

        $this->getJson('/api/admin/permintaan-reward/pengaturan')
            ->assertOk()
            ->assertJsonPath('data.masa_berlaku_hari', 5);

        $permintaan = $this->tukar($user, $reward);

        $this->assertTrue($permintaan->expires_at->between(
            now()->addDays(5)->subMinute(),
            now()->addDays(5)->addMinute(),
        ));

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/permintaan-reward/pengaturan', [
            'masa_berlaku_hari' => 0,
        ])->assertStatus(422);
    }

    public function test_kupon_kedaluwarsa_tidak_bisa_dipindai_dan_qr_kedaluwarsa_ditolak(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $reward = $this->buatReward(stok: 3);

        $permintaan = $this->tukar($user, $reward);
        $token = $this->terbitkanQr($admin, $permintaan);

        // QR kedaluwarsa.
        RewardQrSession::query()->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($user);
        $this->postJson('/api/permintaan-reward/scan', ['token' => $token])->assertStatus(422);

        // Kupon kedaluwarsa: QR baru pun tidak boleh menyelamatkan hold.
        $tokenBaru = $this->terbitkanQr($admin, $permintaan);
        $permintaan->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($user);
        $this->postJson('/api/permintaan-reward/scan', ['token' => $tokenBaru])->assertStatus(422);

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame('1000.00', $wallet->saldo_koin);
        $this->assertSame(3, $reward->fresh()->stok);
        $this->assertSame('kedaluwarsa', $permintaan->fresh()->status);
    }

    public function test_pembatalan_data_legacy_tetap_refund_koin_yang_sudah_dipotong(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser(saldo: 500);
        $reward = $this->buatReward(stok: 0);

        $permintaan = PermintaanReward::create([
            'kode_klaim' => 'CLM-LEGACY-0001',
            'user_id' => $user->id,
            'reward_id' => $reward->id,
            'koin_ditukar' => 500,
            'koin_ditahan' => 0,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/batalkan", [
            'catatan_admin' => 'Data lama dibatalkan.',
        ])->assertOk();

        $this->assertSame('1000.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'refund')->count());
        $this->assertSame(1, $reward->fresh()->stok);
    }
}
