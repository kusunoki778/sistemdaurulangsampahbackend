<?php

namespace Tests\Feature;

use App\Models\Pengaturan;
use App\Models\TopUp;
use App\Models\TransaksiKoin;
use App\Models\User;
use App\Models\Wallet;
use App\Services\MidtransSnapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopUpMidtransTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'test-server-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.client_key' => 'test-client-key',
            'services.midtrans.is_sandbox' => true,
            'services.midtrans.signature_secret' => null,
        ]);
    }

    private function buatUser(float $saldo = 0): User
    {
        $acak = strtolower(Str::random(6));

        $user = User::factory()->create([
            'name' => 'Member Uji',
            'username' => 'member'.$acak,
            'email' => $acak.'@uji.test',
            'phone' => '081234567890',
            'role' => 'user',
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'saldo_koin' => $saldo,
            'koin_ditahan' => 0,
            'total_koin_diperoleh' => $saldo,
            'total_sampah_kg' => 0,
        ]);

        return $user;
    }

    private function fakeSnap(): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token-uji',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vttoken/snap-token-uji',
            ], 201),
        ]);
    }

    private function payloadNotifikasi(TopUp $topUp, string $status = 'settlement'): array
    {
        return [
            'order_id' => $topUp->order_id,
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'transaction_status' => $status,
            'fraud_status' => 'accept',
            'payment_type' => 'qris',
            'transaction_time' => '2026-11-03 10:00:00',
            'signature_key' => $this->signature($topUp->order_id, '200', '10000.00'),
        ];
    }

    private function signature(string $orderId, string $statusCode, string $grossAmount): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.self::SERVER_KEY);
    }

    public function test_konversi_nominal_ke_koin_mengikuti_kurs_admin(): void
    {
        $user = $this->buatUser();
        Pengaturan::simpan(MidtransSnapService::KUNCI_KURS, 20); // 1 koin = Rp 20
        $this->fakeSnap();

        Sanctum::actingAs($user);

        $respons = $this->postJson('/api/top-up', ['nominal_rupiah' => 10000])
            ->assertCreated()
            ->assertJsonPath('data.top_up.status', 'pending')
            ->assertJsonPath('data.top_up.koin_diterima', '500.00')
            ->assertJsonPath('data.snap_token', 'snap-token-uji');

        $orderId = $respons->json('data.top_up.order_id');
        $this->assertMatchesRegularExpression('/^TOP-\d{8}-[0-9A-F]{6}$/', $orderId);
        $this->assertNotNull($respons->json('data.redirect_url'));

        $this->fakeSnap();
        // Kurs 20 + minimum 20.000: nominal pas minimum harus lolos.
        Pengaturan::simpan(MidtransSnapService::KUNCI_MINIMUM, 20000);
        $this->postJson('/api/top-up', ['nominal_rupiah' => 20000])
            ->assertCreated()
            ->assertJsonPath('data.top_up.koin_diterima', '1000.00');
    }

    public function test_nominal_di_bawah_minimum_ditolak(): void
    {
        $user = $this->buatUser();
        Sanctum::actingAs($user);

        // Default minimum Rp 10.000.
        $this->postJson('/api/top-up', ['nominal_rupiah' => 5000])
            ->assertStatus(422);

        // Tidak ada baris top up yang tercipta.
        $this->assertSame(0, TopUp::count());
    }

    public function test_webhook_signature_valid_mengkredit_koin(): void
    {
        $user = $this->buatUser();
        $topUp = TopUp::create([
            'user_id' => $user->id,
            'order_id' => 'TOP-20261103-AAAAAA',
            'nominal_rupiah' => 10000,
            'kurs_rupiah' => 10,
            'koin_diterima' => 1000,
            'status' => 'pending',
        ]);

        $this->postJson('/api/top-up/notification', $this->payloadNotifikasi($topUp))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $topUp->refresh();
        $this->assertSame('paid', $topUp->status);
        $this->assertNotNull($topUp->paid_at);
        $this->assertSame('qris', $topUp->payload_midtrans['payment_type']);

        // Koin masuk + ledger sumber topup.
        $this->assertSame('1000.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'topup')->count());
    }

    public function test_webhook_signature_tidak_valid_ditolak(): void
    {
        $user = $this->buatUser();
        $topUp = TopUp::create([
            'user_id' => $user->id,
            'order_id' => 'TOP-20261103-BBBBBB',
            'nominal_rupiah' => 10000,
            'kurs_rupiah' => 10,
            'koin_diterima' => 1000,
            'status' => 'pending',
        ]);

        $payload = $this->payloadNotifikasi($topUp);
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson('/api/top-up/notification', $payload)
            ->assertStatus(403);

        $this->assertSame('pending', $topUp->fresh()->status);
        $this->assertSame('0.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(0, TransaksiKoin::count());
    }

    public function test_webhook_idempoten_tidak_mengkredit_ganda(): void
    {
        $user = $this->buatUser();
        $topUp = TopUp::create([
            'user_id' => $user->id,
            'order_id' => 'TOP-20261103-CCCCCC',
            'nominal_rupiah' => 10000,
            'kurs_rupiah' => 10,
            'koin_diterima' => 1000,
            'status' => 'pending',
        ]);

        $payload = $this->payloadNotifikasi($topUp);

        // Notifikasi pertama: koin cair.
        $this->postJson('/api/top-up/notification', $payload)->assertOk();

        // Notifikasi ulang (Midtrans mengirim ulang bila respons lambat).
        $this->postJson('/api/top-up/notification', $payload)->assertOk();

        $this->assertSame('1000.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'topup')->count());
    }

    public function test_notifikasi_expire_mengubah_status_tanpa_koin(): void
    {
        $user = $this->buatUser();
        $topUp = TopUp::create([
            'user_id' => $user->id,
            'order_id' => 'TOP-20261103-DDDDDD',
            'nominal_rupiah' => 10000,
            'kurs_rupiah' => 10,
            'koin_diterima' => 1000,
            'status' => 'pending',
        ]);

        $this->postJson('/api/top-up/notification', $this->payloadNotifikasi($topUp, 'expire'))
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertSame('expired', $topUp->fresh()->status);
        $this->assertSame('0.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
    }

    public function test_struk_top_up_hanya_setelah_lunas(): void
    {
        $user = $this->buatUser();
        $topUp = TopUp::create([
            'user_id' => $user->id,
            'order_id' => 'TOP-20261103-EEEEEE',
            'nominal_rupiah' => 10000,
            'kurs_rupiah' => 10,
            'koin_diterima' => 1000,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        // Belum lunas -> struk ditolak.
        $this->getJson("/api/top-up/{$topUp->id}/struk")->assertStatus(422);

        $this->postJson('/api/top-up/notification', $this->payloadNotifikasi($topUp))->assertOk();

        $struk = $this->getJson("/api/top-up/{$topUp->id}/struk")
            ->assertOk()
            ->json('data');

        $this->assertSame('top_up', $struk['jenis']);
        $this->assertTrue($struk['simulasi']); // Sandbox Midtrans = simulasi
        $this->assertSame(10000.0, (float) $struk['nominal_rupiah']);
        $this->assertSame(1000.0, (float) $struk['koin_diterima']);
        $this->assertSame('TOP-20261103-EEEEEE', $struk['nomor_struk']);

        // Member lain tidak boleh melihat struk ini.
        $orangLain = $this->buatUser();
        Sanctum::actingAs($orangLain);
        $this->getJson("/api/top-up/{$topUp->id}/struk")->assertStatus(403);
    }

    public function test_detail_pending_merefresh_status_dari_midtrans(): void
    {
        $user = $this->buatUser();
        $topUp = TopUp::create([
            'user_id' => $user->id,
            'order_id' => 'TOP-20261103-FFFFFF',
            'nominal_rupiah' => 10000,
            'kurs_rupiah' => 10,
            'koin_diterima' => 1000,
            'status' => 'pending',
        ]);

        // Webhook belum sampai, tapi API status Midtrans sudah settlement.
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response($this->payloadNotifikasi($topUp), 200),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/top-up/{$topUp->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        // Koin ikut cair lewat jalur polling ini.
        $this->assertSame('1000.00', Wallet::where('user_id', $user->id)->first()->saldo_koin);
        $this->assertSame(1, TransaksiKoin::where('sumber', 'topup')->count());
    }

    public function test_admin_mengatur_kurs_dan_minimum_dan_melihat_riwayat(): void
    {
        $admin = $this->buatUser();
        $admin->update(['role' => 'admin']);
        $user = $this->buatUser();

        $topUp = TopUp::create([
            'user_id' => $user->id,
            'order_id' => 'TOP-20261103-GGGGGG',
            'nominal_rupiah' => 10000,
            'kurs_rupiah' => 10,
            'koin_diterima' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/top-up/pengaturan', [
            'kurs_rupiah' => 25,
            'minimum_rupiah' => 25000,
        ])->assertOk()
            ->assertJsonPath('data.kurs_rupiah', 25)
            ->assertJsonPath('data.minimum_rupiah', 25000);

        $this->assertSame('25', Pengaturan::where('kunci', 'topup_kurs_rupiah')->first()->nilai);

        $this->getJson('/api/admin/top-up')
            ->assertOk()
            ->assertJsonPath('data.data.0.order_id', $topUp->order_id);

        // User biasa tidak boleh akses pengaturan admin.
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/top-up/pengaturan')->assertStatus(403);
    }
}
