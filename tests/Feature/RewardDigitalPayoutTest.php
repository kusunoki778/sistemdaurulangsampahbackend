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

/**
 * Alur payout digital memakai jalur konversi-saldo (reward sistem nilai
 * dinamis), bukan POST /permintaan-reward dengan reward saldo_digital.
 */
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

    private function tukar(User $user, int $koin = 2000): PermintaanReward
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/permintaan-reward/konversi-saldo', [
            'jumlah_koin' => $koin,
            'metode' => 'dana',
            'nomor_tujuan' => '081234567890',
            'nama_pemilik' => 'Budi Uji',
        ])->assertCreated();

        return PermintaanReward::findOrFail($response->json('data.permintaan_id'));
    }

    public function test_alur_payout_digital_pending_diproses_selesai(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $permintaan = $this->tukar($user);

        // Koin hanya di-HOLD saat pengajuan, belum dipotong.
        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('5000.00', $wallet->saldo_koin);
        $this->assertSame('2000.00', $wallet->koin_ditahan);

        Sanctum::actingAs($admin);

        // Selesai langsung dari pending ditolak - harus lewat diproses.
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

        // HOLD dilepas: 2000 koin dipotong, saldo rupiah masuk.
        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('3000.00', $wallet->saldo_koin);
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame('200000.00', $wallet->saldo_rupiah);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'reward_claim')->count());
    }

    public function test_struk_payout_menampilkan_nomor_lengkap_tanpa_label_uji_coba(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $permintaan = $this->tukar($user);

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
        $this->assertArrayNotHasKey('simulasi', $struk);
        $this->assertSame('081234567890', $struk['nomor_tujuan']);
        $this->assertSame(2000.0, (float) $struk['koin']);
        $this->assertNotEmpty($struk['nomor_struk']);

        // Admin juga boleh melihat struk member.
        Sanctum::actingAs($admin);
        $this->getJson("/api/permintaan-reward/{$permintaan->id}/struk")->assertOk();
    }

    public function test_nomor_tujuan_disamarkan_di_riwayat_user(): void
    {
        $user = $this->buatUser();
        $this->tukar($user);

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

    public function test_payout_gagal_mengembalikan_koin(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $permintaan = $this->tukar($user);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/permintaan-reward/{$permintaan->id}/batalkan", [
            'catatan_admin' => 'Nomor e-wallet tidak terdaftar.',
        ])->assertOk()->assertJsonPath('data.status', 'dibatalkan');

        // Hold dilepas: koin tidak pernah keluar dari saldo_koin, jadi
        // saldo kembali penuh tanpa ledger refund (tidak ada double count).
        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame('5000.00', $wallet->saldo_koin);
        $this->assertSame('0.00', $wallet->koin_ditahan);
        $this->assertSame(0, TransaksiKoin::where('sumber', 'refund')->count());

        // Struk tidak tersedia untuk payout gagal.
        Sanctum::actingAs($user);
        $this->getJson("/api/permintaan-reward/{$permintaan->id}/struk")->assertStatus(422);
    }

    public function test_struk_pickup_fisik_tanpa_label_uji_coba(): void
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
        $this->assertArrayNotHasKey('simulasi', $struk);
        $this->assertNull($struk['nomor_tujuan']);
        $this->assertNotEmpty($struk['nomor_struk']);
    }
}
