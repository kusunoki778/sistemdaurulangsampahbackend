<?php

namespace Tests\Feature;

use App\Models\PermintaanReward;
use App\Models\Reward;
use App\Models\TransaksiKoin;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardDigitalPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $role = 'user', float $saldo = 5000): User
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

    private function buatRewardDana(float $harga = 2000, int $stok = 5): Reward
    {
        return Reward::create([
            'nama_reward' => 'Saldo DANA Rp20.000',
            'tipe' => 'saldo_digital',
            'koin_dibutuhkan' => $harga,
            'nilai_rupiah' => 20000,
            'stok' => $stok,
            'deskripsi' => 'Payout simulasi ke e-wallet DANA',
            'status_aktif' => true,
        ]);
    }

    private function tukar(User $user, Reward $reward): PermintaanReward
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/permintaan-reward', [
            'reward_id' => $reward->id,
            'nomor_tujuan_digital' => '081234567890',
        ])->assertCreated();

        return PermintaanReward::findOrFail($response->json('data.permintaan.id'));
    }

    public function test_alur_payout_digital_pending_diproses_selesai(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $reward = $this->buatRewardDana();
        $permintaan = $this->tukar($user, $reward);

        Sanctum::actingAs($admin);

        // Selesai langsung dari pending ditolak — harus lewat diproses.
        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/selesai")
            ->assertStatus(422);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/proses")
            ->assertOk()
            ->assertJsonPath('data.status', 'diproses');

        $this->assertNotNull($permintaan->fresh()->diproses_at);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/selesai")
            ->assertOk()
            ->assertJsonPath('data.status', 'selesai');

        $permintaan->refresh();
        $this->assertNotNull($permintaan->selesai_at);
        $this->assertMatchesRegularExpression('/^STR-\d{8}-[0-9A-F]{6}$/', $permintaan->struk_nomor);

        // Koin sudah dipotong saat klaim; tidak ada pemotongan kedua.
        $this->assertSame('3000.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'reward_claim')->count());
    }

    public function test_struk_payout_menampilkan_nomor_lengkap_dan_label_simulasi(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $reward = $this->buatRewardDana();
        $permintaan = $this->tukar($user, $reward);

        Sanctum::actingAs($user);

        // Belum selesai -> struk belum tersedia.
        $this->getJson("/api/permintaan-reward/{$permintaan->id}/struk")->assertStatus(422);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/proses")->assertOk();
        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/selesai")->assertOk();

        Sanctum::actingAs($user);

        $struk = $this->getJson("/api/permintaan-reward/{$permintaan->id}/struk")
            ->assertOk()
            ->json('data');

        $this->assertSame('payout_digital', $struk['jenis']);
        $this->assertTrue($struk['simulasi']);
        $this->assertSame('081234567890', $struk['nomor_tujuan']);
        $this->assertSame(20000.0, (float) $struk['reward']['nilai_rupiah']);
        $this->assertSame(2000.0, (float) $struk['koin']);
        $this->assertNotEmpty($struk['nomor_struk']);

        // Admin juga boleh melihat struk member.
        Sanctum::actingAs($admin);
        $this->getJson("/api/permintaan-reward/{$permintaan->id}/struk")->assertOk();
    }

    public function test_nomor_tujuan_disamarkan_di_riwayat_user(): void
    {
        $user = $this->buatUser();
        $reward = $this->buatRewardDana();
        $this->tukar($user, $reward);

        Sanctum::actingAs($user);

        $item = $this->getJson('/api/permintaan-reward')
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame('0812••••7890', $item['nomor_tujuan_digital']);
        $this->assertStringNotContainsString('081234567890', json_encode($item));

        // Admin tetap melihat nomor lengkap.
        $admin = $this->buatUser('admin');
        Sanctum::actingAs($admin);

        $itemAdmin = $this->getJson('/api/admin/permintaan-reward')
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame('081234567890', $itemAdmin['nomor_tujuan_digital']);
    }

    public function test_payout_gagal_mengembalikan_koin_dan_stok(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $reward = $this->buatRewardDana(stok: 3);
        $permintaan = $this->tukar($user, $reward);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/gagal", [
            'catatan_admin' => 'Nomor e-wallet tidak terdaftar.',
        ])->assertOk()->assertJsonPath('data.status', 'gagal');

        $this->assertNotNull($permintaan->fresh()->gagal_at);

        // Koin kembali penuh + ledger refund + stok kembali.
        $this->assertSame('5000.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'refund')->count());
        $this->assertSame(3, $reward->fresh()->stok);

        // Struk tidak tersedia untuk payout gagal.
        Sanctum::actingAs($user);
        $this->getJson("/api/permintaan-reward/{$permintaan->id}/struk")->assertStatus(422);
    }

    public function test_struk_pickup_fisik_tidak_berlabel_simulasi(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser(saldo: 1000);

        $reward = Reward::create([
            'nama_reward' => 'Tumbler Livora',
            'tipe' => 'barang',
            'koin_dibutuhkan' => 500,
            'stok' => 3,
            'status_aktif' => true,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/permintaan-reward', ['reward_id' => $reward->id])
            ->assertCreated();
        $permintaan = PermintaanReward::findOrFail($response->json('data.permintaan.id'));

        Sanctum::actingAs($admin);
        $token = $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/qr")
            ->assertOk()
            ->json('data.qr.token');

        Sanctum::actingAs($user);
        $this->postJson('/api/permintaan-reward/konfirmasi', ['token' => $token])->assertOk();

        $struk = $this->getJson("/api/permintaan-reward/{$permintaan->id}/struk")
            ->assertOk()
            ->json('data');

        $this->assertSame('pickup_fisik', $struk['jenis']);
        $this->assertFalse($struk['simulasi']);
        $this->assertNull($struk['nomor_tujuan']);
        $this->assertNotEmpty($struk['nomor_struk']);
    }
}
