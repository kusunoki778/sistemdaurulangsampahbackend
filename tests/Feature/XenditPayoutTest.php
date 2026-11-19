<?php

namespace Tests\Feature;

use App\Models\PermintaanReward;
use App\Models\User;
use App\Models\Wallet;
use App\Services\RewardClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Payout koin -> saldo e-wallet via Xendit Payouts v3.
 * Kunci uji disetel lewat config; HTTP dibungkus Http::fake supaya tidak
 * memanggil API nyata. Webhook memakai x-callback-token.
 */
class XenditPayoutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'xnd_development_uji';

    private const CALLBACK = 'token-callback-uji';

    private function buatUser(float $saldo = 2000): User
    {
        $user = User::factory()->create(['role' => 'user']);

        Wallet::create([
            'user_id' => $user->id,
            'saldo_koin' => $saldo,
            'koin_ditahan' => 0,
            'total_koin_diperoleh' => $saldo,
            'total_sampah_kg' => 0,
        ]);

        return $user;
    }

    private function buatPermintaan(User $user): PermintaanReward
    {
        return app(RewardClaimService::class)->konversiSaldo(
            $user,
            2000,
            'dana',
            '081234567890',
            'Budi Uji',
        );
    }

    private function denganKunci(): void
    {
        config([
            'services.xendit.secret_key' => self::SECRET,
            'services.xendit.callback_token' => self::CALLBACK,
        ]);
    }

    private function payloadPayout(string $status = 'ACCEPTED'): array
    {
        return [
            'payout_id' => 'po-123',
            'reference_id' => 'LIVORA-PO-1-abcd1234',
            'status' => $status,
        ];
    }

    public function test_membuat_payout_dan_menyimpan_data_xendit(): void
    {
        $this->denganKunci();
        $user = $this->buatUser();
        $permintaan = $this->buatPermintaan($user);

        Http::fake([
            'api.xendit.co/v3/payouts' => Http::response($this->payloadPayout(), 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/permintaan-reward/{$permintaan->id}/xendit-payout", [
                'channel' => 'DANA',
                'nomor' => '081234567890',
                'nama_pemilik' => 'Budi Uji',
            ])
            ->assertOk()
            ->assertJsonPath('data.payout_id', 'po-123')
            ->assertJsonPath('data.status_xendit', 'ACCEPTED')
            ->assertJsonPath('data.nilai_rupiah', 200000);

        $permintaan->refresh();
        $this->assertSame('po-123', $permintaan->xendit_payout_id);
        $this->assertSame('DANA', $permintaan->xendit_channel_code);
        $this->assertSame('ACCEPTED', $permintaan->xendit_status);
        $this->assertSame('081234567890', $permintaan->nomor_tujuan_digital);
        // Koin tetap di HOLD sampai payout SUCCEEDED.
        $this->assertEquals(2000.0, (float) $user->wallet->koin_ditahan);
        $this->assertEquals(2000.0, (float) $user->wallet->saldo_koin);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.xendit.co/v3/payouts'
            && $request->header('idempotency-key')[0] === $permintaan->xendit_reference_id
            && $request->header('api-version')[0] === '2025-09-01'
            && $request->data()['payout_details']['source_amount'] === 200000);
    }

    public function test_channel_tidak_dikenal_ditolak(): void
    {
        $this->denganKunci();
        $user = $this->buatUser();
        $permintaan = $this->buatPermintaan($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/permintaan-reward/{$permintaan->id}/xendit-payout", [
                'channel' => 'PAYPAL',
                'nomor' => '081234567890',
                'nama_pemilik' => 'Budi Uji',
            ])
            ->assertStatus(422)
            ->assertSee('Channel payout tidak dikenal');

        $permintaan->refresh();
        $this->assertNull($permintaan->xendit_payout_id);
    }

    public function test_nomor_e_wallet_tidak_valid_ditolak(): void
    {
        $this->denganKunci();
        $user = $this->buatUser();
        $permintaan = $this->buatPermintaan($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/permintaan-reward/{$permintaan->id}/xendit-payout", [
                'channel' => 'DANA',
                'nomor' => '62812345',
                'nama_pemilik' => 'Budi Uji',
            ])
            ->assertStatus(422)
            ->assertSee('Nomor e-wallet tidak valid');
    }

    public function test_cek_status_succeeded_memotong_koin_dan_idempoten(): void
    {
        $this->denganKunci();
        $user = $this->buatUser();
        $permintaan = $this->buatPermintaan($user);
        $permintaan->update(['xendit_payout_id' => 'po-123']);

        Http::fake([
            'api.xendit.co/v3/payouts/po-123' => Http::response($this->payloadPayout('SUCCEEDED'), 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/permintaan-reward/{$permintaan->id}/xendit-cek")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.sisa_koin', 0)
            ->assertJsonPath('data.saldo_rupiah', 200000);

        $wallet = $user->wallet->fresh();
        $this->assertEquals(0.0, (float) $wallet->koin_ditahan);
        $this->assertEquals(0.0, (float) $wallet->saldo_koin);
        $this->assertEquals(200000.0, (float) $wallet->saldo_rupiah);
        $this->assertSame('SUCCEEDED', $permintaan->fresh()->xendit_status);
        $this->assertSame('selesai', $permintaan->fresh()->status);

        // Polling ulang tidak memotong koin dua kali.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/permintaan-reward/{$permintaan->id}/xendit-cek")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertEquals(0.0, (float) $user->wallet->fresh()->saldo_koin);
        $this->assertEquals(200000.0, (float) $user->wallet->fresh()->saldo_rupiah);
    }

    public function test_status_gagal_mengembalikan_koin_hold(): void
    {
        $this->denganKunci();
        $user = $this->buatUser();
        $permintaan = $this->buatPermintaan($user);
        $permintaan->update(['xendit_payout_id' => 'po-123']);

        Http::fake([
            'api.xendit.co/v3/payouts/po-123' => Http::response($this->payloadPayout('FAILED'), 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/permintaan-reward/{$permintaan->id}/xendit-cek")
            ->assertStatus(422);

        $wallet = $user->wallet->fresh();
        $this->assertEquals(0.0, (float) $wallet->koin_ditahan);
        $this->assertEquals(2000.0, (float) $wallet->saldo_koin);
        $this->assertEquals(0.0, (float) $wallet->saldo_rupiah);
        $this->assertSame('dibatalkan', $permintaan->fresh()->status);
    }

    public function test_webhook_token_salah_ditolak(): void
    {
        $this->denganKunci();

        $this->postJson('/api/xendit/webhook', [
            'event' => 'v3_payout.succeeded',
            'data' => $this->payloadPayout('SUCCEEDED'),
        ], ['x-callback-token' => 'salah'])
            ->assertStatus(401);
    }

    public function test_webhook_tanpa_event_payout_ditolak(): void
    {
        $this->denganKunci();

        $this->postJson('/api/xendit/webhook', [
            'event' => 'invoice.paid',
            'data' => [],
        ], ['x-callback-token' => self::CALLBACK])
            ->assertStatus(422);
    }

    public function test_webhook_succeeded_menyelesaikan_payout(): void
    {
        $this->denganKunci();
        $user = $this->buatUser();
        $permintaan = $this->buatPermintaan($user);
        $permintaan->update(['xendit_payout_id' => 'po-123']);

        $this->postJson('/api/xendit/webhook', [
            'event' => 'v3_payout.succeeded',
            'data' => $this->payloadPayout('SUCCEEDED'),
        ], ['x-callback-token' => self::CALLBACK])
            ->assertNoContent();

        $this->assertSame('selesai', $permintaan->fresh()->status);
        $this->assertEquals(0.0, (float) $user->wallet->fresh()->koin_ditahan);
        $this->assertEquals(200000.0, (float) $user->wallet->fresh()->saldo_rupiah);

        // Webhook ulang (Xendit retry) tidak beri efek ganda.
        $this->postJson('/api/xendit/webhook', [
            'event' => 'v3_payout.succeeded',
            'data' => $this->payloadPayout('SUCCEEDED'),
        ], ['x-callback-token' => self::CALLBACK])
            ->assertNoContent();

        $this->assertEquals(200000.0, (float) $user->wallet->fresh()->saldo_rupiah);
    }
}
