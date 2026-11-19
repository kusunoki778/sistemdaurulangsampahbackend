<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionProgress;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Misi "Login Harian" diklaim manual sekali per hari.
 *
 * Yang diuji:
 * 1. Membuka daftar misi tidak otomatis memberi koin.
 * 2. Klaim pertama hari ini berhasil dan menambah saldo.
 * 3. Klaim kedua pada hari yang sama ditolak.
 * 4. Keesokan hari hadiah bisa diklaim lagi.
 */
class MisiLoginHarianTest extends TestCase
{
    use RefreshDatabase;

    private function anggota(): User
    {
        $user = User::factory()->create(['role' => 'user']);

        Wallet::create([
            'user_id' => $user->id,
            'saldo_koin' => 0,
            'koin_ditahan' => 0,
            'total_koin_diperoleh' => 0,
            'total_sampah_kg' => 0,
        ]);

        return $user;
    }

    private function misiLogin(): Mission
    {
        return Mission::create([
            'judul' => 'Login Harian',
            'deskripsi' => 'Buka aplikasi setiap hari untuk mendapat koin.',
            'tipe' => 'daily',
            'target_jumlah' => 1,
            'koin_reward' => 10,
            'status_aktif' => true,
        ]);
    }

    public function test_membuka_daftar_misi_tidak_otomatis_memberi_koin(): void
    {
        $user = $this->anggota();
        $misi = $this->misiLogin();

        Sanctum::actingAs($user);

        $res = $this->getJson('/api/missions');

        $res->assertOk();

        $item = collect($res->json('data'))->firstWhere('id', $misi->id);

        $this->assertNotNull($item);
        $this->assertTrue($item['can_claim']);
        $this->assertSame('completed', $item['status']);

        // Saldo belum berubah: hadiah hanya masuk lewat tombol Klaim.
        $this->assertSame(0.0, (float) $user->wallet->fresh()->saldo_koin);
    }

    public function test_klaim_perdana_menambah_saldo_dan_klaim_kedua_ditolak(): void
    {
        $user = $this->anggota();
        $misi = $this->misiLogin();

        Sanctum::actingAs($user);

        $this->postJson("/api/missions/{$misi->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.koin_diperoleh', 10);

        $this->assertSame(10.0, (float) $user->wallet->fresh()->saldo_koin);

        // Klaim kedua di hari yang sama harus ditolak.
        $this->postJson("/api/missions/{$misi->id}/claim")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Hadiah misi sudah diklaim hari ini. Kembali besok untuk klaim lagi.');

        // Saldo tetap 10, bukan 20.
        $this->assertSame(10.0, (float) $user->wallet->fresh()->saldo_koin);
    }

    public function test_hari_berikutnya_bisa_klaim_lagi(): void
    {
        $user = $this->anggota();
        $misi = $this->misiLogin();

        Sanctum::actingAs($user);

        $this->postJson("/api/missions/{$misi->id}/claim")->assertOk();

        // Majukan waktu satu hari.
        $this->travel(1)->days();

        $res = $this->getJson('/api/missions');
        $res->assertOk();

        $item = collect($res->json('data'))->firstWhere('id', $misi->id);
        $this->assertTrue($item['can_claim'], 'Besoknya misi login harus bisa diklaim lagi.');

        $this->postJson("/api/missions/{$misi->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.koin_diperoleh', 10);

        $this->assertSame(20.0, (float) $user->wallet->fresh()->saldo_koin);

        $progress = MissionProgress::where('user_id', $user->id)
            ->where('mission_id', $misi->id)
            ->first();

        $this->assertNotNull($progress);
        $this->assertSame(today()->toDateString(), $progress->terakhir_klaim_tanggal->toDateString());
    }
}
