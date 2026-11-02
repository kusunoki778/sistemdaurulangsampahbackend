<?php

namespace Tests\Feature;

use App\Models\JenisSampah;
use App\Models\KategoriSampah;
use App\Models\Mission;
use App\Models\MissionProgress;
use App\Models\PermintaanReward;
use App\Models\Reward;
use App\Models\TransaksiKoin;
use App\Models\TransaksiSetor;
use App\Models\TransaksiSetorDetail;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KoinDesimalTest extends TestCase
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

    private function buatJenisSampah(float $poinPerSatuan): JenisSampah
    {
        $kategori = KategoriSampah::create(['nama_kategori' => 'Plastik']);

        return JenisSampah::create([
            'kategori_id' => $kategori->id,
            'nama_jenis' => 'Botol PET',
            'satuan' => 'kg',
            'poin_per_satuan' => $poinPerSatuan,
        ]);
    }

    public function test_setor_menghitung_koin_desimal_tanpa_pembulatan(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah(25);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/transaksi-setor', [
            'user_id' => $user->id,
            'items' => [
                ['jenis_sampah_id' => $jenis->id, 'jumlah' => 1.35],
            ],
        ])->assertCreated();

        $transaksi = TransaksiSetor::findOrFail($response->json('data.transaksi.id'));
        $detail = TransaksiSetorDetail::where('transaksi_setor_id', $transaksi->id)->firstOrFail();

        // 1,35 kg x 25 koin = 33,75 koin (tidak dibulatkan menjadi 34).
        $this->assertSame('25.00', $detail->poin_per_satuan);
        $this->assertSame('33.75', $detail->koin_subtotal);
        $this->assertSame('33.75', $transaksi->total_koin);

        // Fallback admin mencairkan koin desimal tanpa pembulatan.
        $this->postJson("/api/admin/transaksi-setor/{$transaksi->id}/selesaikan")->assertOk();

        $this->assertSame('33.75', TransaksiKoin::first()->jumlah_koin);
        $this->assertSame('33.75', Wallet::where('user_id', $user->id)->first()->saldo_koin);

        // Accessor user tetap mengembalikan angka (bukan string).
        $this->assertSame(33.75, $user->fresh()->saldo_koin);
    }

    public function test_klaim_misi_tidak_bisa_dua_kali(): void
    {
        $user = $this->buatUser();

        $mission = Mission::create([
            'judul' => 'Setor sampah harian',
            'deskripsi' => 'Setor minimal 1 kali',
            'tipe' => 'daily',
            'target_jumlah' => 1,
            'koin_reward' => 12.5,
            'status_aktif' => true,
        ]);

        MissionProgress::create([
            'user_id' => $user->id,
            'mission_id' => $mission->id,
            'progress' => 1,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/missions/{$mission->id}/claim")->assertOk();
        $this->postJson("/api/missions/{$mission->id}/claim")->assertStatus(400);

        $this->assertSame('12.50', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'mission')->count());
        $this->assertSame('claimed', MissionProgress::first()->status);
    }

    public function test_progress_misi_tidak_pernah_menulis_status_ready(): void
    {
        $user = $this->buatUser();

        $mission = Mission::create([
            'judul' => 'Setor sampah pertama',
            'tipe' => 'daily',
            'target_jumlah' => 1,
            'koin_reward' => 10,
            'status_aktif' => true,
        ]);

        TransaksiSetor::create([
            'kode_transaksi' => 'TRX-S-TEST-2',
            'user_id' => $user->id,
            'total_koin' => 10,
            'status' => 'approved',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/missions')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.can_claim', true);

        // Status 'claimed' harus dipertahankan saat daftar dimuat ulang.
        MissionProgress::where('mission_id', $mission->id)->update(['status' => 'claimed']);

        $this->getJson('/api/missions')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'claimed')
            ->assertJsonPath('data.0.can_claim', false);
    }

    public function test_kode_transaksi_unik_antar_pengajuan(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();
        $jenis = $this->buatJenisSampah(25);

        Sanctum::actingAs($admin);

        $pertama = $this->postJson('/api/admin/transaksi-setor', [
            'user_id' => $user->id,
            'items' => [['jenis_sampah_id' => $jenis->id, 'jumlah' => 1.35]],
        ])->assertCreated();

        $kedua = $this->postJson('/api/admin/transaksi-setor', [
            'user_id' => $user->id,
            'items' => [['jenis_sampah_id' => $jenis->id, 'jumlah' => 2.4]],
        ])->assertCreated();

        $this->assertNotSame(
            $pertama->json('data.transaksi.kode_transaksi'),
            $kedua->json('data.transaksi.kode_transaksi')
        );

        $this->assertSame('33.75', TransaksiSetor::orderBy('id')->first()->total_koin);
        $this->assertSame('60.00', TransaksiSetor::orderByDesc('id')->first()->total_koin);
    }

    public function test_hapus_reward_tidak_menghapus_riwayat_klaim(): void
    {
        $admin = $this->buatUser('admin');
        $user = $this->buatUser();

        $reward = Reward::create([
            'nama_reward' => 'Tumbler Livora',
            'tipe' => 'barang',
            'koin_dibutuhkan' => 500,
            'stok' => 3,
            'status_aktif' => true,
        ]);

        $permintaan = PermintaanReward::create([
            'kode_klaim' => 'LV-TEST-0001',
            'user_id' => $user->id,
            'reward_id' => $reward->id,
            'koin_ditukar' => 500,
            'status' => 'selesai',
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/rewards/{$reward->id}")->assertOk();

        $this->assertSoftDeleted('rewards', ['id' => $reward->id]);
        $this->assertDatabaseHas('permintaan_reward', ['id' => $permintaan->id]);

        // Katalog publik tidak menampilkan reward yang sudah dihapus.
        $this->getJson('/api/rewards')->assertOk()->assertJsonCount(0, 'data');
    }
}
