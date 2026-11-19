<?php

namespace App\Console\Commands;

use App\Models\PermintaanReward;
use App\Services\XenditPayoutService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sinkronkan status payout yang masih berjalan.
 *
 * Sebelum ini status hanya diperbarui saat member membuka layar pencairan
 * (polling) atau saat webhook masuk. Kalau aplikasi ditutup, dana bisa sudah
 * sampai di e-wallet tetapi status Livora masih menunggu dan notifikasi tidak
 * pernah terkirim. Perintah ini menutup celah itu.
 */
class CekStatusPayout extends Command
{
    protected $signature = 'payout:cek-status {--limit=50}';

    protected $description = 'Perbarui status payout yang masih berjalan agar koin dan notifikasi tidak menunggu aplikasi dibuka.';

    public function handle(XenditPayoutService $xendit): int
    {
        $daftar = PermintaanReward::query()
            ->whereIn('status', ['pending', 'diproses'])
            ->whereNotNull('xendit_payout_id')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $berhasil = 0;

        foreach ($daftar as $permintaan) {
            $status = $xendit->ambilStatus((string) $permintaan->xendit_payout_id);

            if ($status === null) {
                $this->warn('Status tidak terbaca: permintaan #'.$permintaan->id);

                continue;
            }

            try {
                $xendit->prosesStatus($status);
                $berhasil++;
                $this->line(sprintf(
                    'Permintaan #%d: %s',
                    $permintaan->id,
                    strtoupper((string) ($status['status'] ?? 'tidak diketahui')),
                ));
            } catch (Throwable $e) {
                report($e);
                $this->error('Gagal memproses permintaan #'.$permintaan->id.': '.$e->getMessage());
            }
        }

        $this->info('Payout diperiksa: '.$daftar->count().', diproses: '.$berhasil.'.');

        return self::SUCCESS;
    }
}
