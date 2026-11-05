<?php

namespace App\Services;

use App\Models\Pengaturan;
use App\Models\PermintaanReward;
use App\Models\Reward;
use App\Models\TransaksiKoin;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RewardClaimService
{
    public const KUNCI_MASA_BERLAKU = 'reward_masa_berlaku_hari';

    /**
     * Tukar koin dengan reward.
     *
     * Reward fisik (`barang`) memakai mekanisme hold: koin tidak dipotong dari
     * saldo, tetapi dicatat di `wallets.koin_ditahan` sampai user mengambil
     * barang dan memindai QR di titik pengambilan. Reward digital langsung
     * memotong saldo karena tidak ada pengambilan fisik.
     */
    public function klaim(User $user, int $rewardId, ?string $nomorTujuan): PermintaanReward
    {
        return DB::transaction(function () use ($user, $rewardId, $nomorTujuan) {
            // Urutan lock: reward -> wallet. Semua alur yang menyentuh reward
            // dan wallet memakai urutan ini agar tidak deadlock.
            $reward = Reward::whereKey($rewardId)->lockForUpdate()->firstOrFail();

            if (! $reward->status_aktif) {
                throw ValidationException::withMessages([
                    'reward_id' => 'Reward ini sedang tidak aktif.',
                ]);
            }

            if ((int) $reward->stok <= 0) {
                throw ValidationException::withMessages([
                    'reward_id' => 'Maaf, stok reward ini sudah habis.',
                ]);
            }

            $wallet = $this->lockWallet($user);
            $harga = round((float) $reward->koin_dibutuhkan, 2);
            $fisik = $reward->tipe === 'barang';
            $saldoTersedia = round((float) $wallet->saldo_koin - (float) $wallet->koin_ditahan, 2);

            if ($fisik) {
                if ($saldoTersedia + 0.0001 < $harga) {
                    throw ValidationException::withMessages([
                        'saldo' => 'Saldo koin tidak mencukupi. Tersedia '
                            .$this->formatKoin($saldoTersedia)
                            .' koin, dibutuhkan '.$this->formatKoin($harga).' koin.',
                    ]);
                }
            } else {
                if ((float) $wallet->saldo_koin + 0.0001 < $harga) {
                    throw ValidationException::withMessages([
                        'saldo' => 'Saldo koin tidak mencukupi. Saldo Anda '
                            .$this->formatKoin((float) $wallet->saldo_koin)
                            .' koin, dibutuhkan '.$this->formatKoin($harga).' koin.',
                    ]);
                }

                if ($reward->tipe === 'saldo_digital' && blank($nomorTujuan)) {
                    throw ValidationException::withMessages([
                        'nomor_tujuan_digital' => 'Nomor HP e-wallet wajib diisi untuk reward saldo digital.',
                    ]);
                }
            }

            $reward->decrement('stok');

            if ($fisik) {
                $wallet->increment('koin_ditahan', $harga);
            } else {
                $wallet->decrement('saldo_koin', $harga);
            }

            $permintaan = PermintaanReward::create([
                'kode_klaim' => $this->buatKodeKupon(),
                'user_id' => $user->id,
                'reward_id' => $reward->id,
                'koin_ditukar' => $harga,
                'koin_ditahan' => $fisik ? $harga : 0,
                'status' => 'pending',
                'expires_at' => $fisik ? now()->addDays($this->masaBerlakuHari()) : null,
                'nomor_tujuan_digital' => $reward->tipe === 'saldo_digital' ? trim((string) $nomorTujuan) : null,
                'catatan_admin' => $fisik
                    ? 'Kupon terbit. Tunjukkan kode kupon di titik pengambilan Livora.'
                    : 'Permintaan sedang dalam proses verifikasi oleh admin.',
            ]);

            // Ledger hanya dibuat saat koin benar-benar berpindah. Untuk reward
            // fisik, pemotongan dicatat nanti saat pickup dikonfirmasi.
            if (! $fisik) {
                TransaksiKoin::create([
                    'user_id' => $user->id,
                    'tipe' => 'keluar',
                    'jumlah_koin' => $harga,
                    'sumber' => 'reward_claim',
                    'referensi_id' => $permintaan->id,
                    'keterangan' => 'Klaim reward: '.$reward->nama_reward,
                ]);
            }

            return $permintaan->load('reward');
        });
    }

    public function saldoTersedia(User $user): float
    {
        $wallet = $user->wallet;

        return $wallet ? round((float) $wallet->saldo_koin - (float) $wallet->koin_ditahan, 2) : 0.0;
    }

    public function masaBerlakuHari(): int
    {
        $hari = (int) Pengaturan::ambil(
            self::KUNCI_MASA_BERLAKU,
            config('livora.reward_expiry_days', 3),
        );

        return max(1, min(30, $hari));
    }

    /**
     * Evaluasi kupon kedaluwarsa milik satu user (lazy expiry, tanpa cron).
     */
    public function expireDueForUser(int $userId): int
    {
        return $this->expireQuery(PermintaanReward::where('user_id', $userId));
    }

    /**
     * Evaluasi seluruh kupon kedaluwarsa (dipakai halaman admin).
     */
    public function expireDue(): int
    {
        return $this->expireQuery(PermintaanReward::query());
    }

    /**
     * Kembalikan hold koin + stok bila kupon sudah melewati expires_at.
     */
    public function expireIfNeeded(PermintaanReward $permintaan): PermintaanReward
    {
        if (! $permintaan->expires_at || $permintaan->expires_at->isFuture()) {
            return $permintaan;
        }

        if (! in_array($permintaan->status, ['pending', 'siap_diambil'], true)) {
            return $permintaan;
        }

        return DB::transaction(function () use ($permintaan) {
            $locked = PermintaanReward::lockForUpdate()->findOrFail($permintaan->id);

            // Recheck setelah lock — bisa saja sudah diproses request lain.
            if (! $locked->expires_at
                || $locked->expires_at->isFuture()
                || ! in_array($locked->status, ['pending', 'siap_diambil'], true)) {
                return $locked->load('reward');
            }

            $reward = $this->lockReward($locked);
            $this->lepasHold($locked);
            $this->kembalikanStok($reward);

            $locked->update([
                'status' => 'kedaluwarsa',
                'koin_ditahan' => 0,
                'catatan_admin' => 'Kupon kedaluwarsa otomatis. Koin hold dilepas dan stok dikembalikan.',
            ]);

            return $locked->fresh(['reward']);
        });
    }

    /**
     * Selesaikan pickup reward fisik atau proses reward digital.
     * Idempoten: pemanggilan ulang tidak memotong koin dua kali.
     */
    public function selesaikan(PermintaanReward $permintaan, ?string $catatan = null): PermintaanReward
    {
        return DB::transaction(function () use ($permintaan, $catatan) {
            $locked = PermintaanReward::with('reward')->lockForUpdate()->findOrFail($permintaan->id);

            if ($locked->status === 'selesai') {
                return $locked;
            }

            if (! in_array($locked->status, ['pending', 'siap_diambil', 'diproses'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini tidak bisa diselesaikan (status: '.$locked->status.').',
                ]);
            }

            if ($locked->expires_at && $locked->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'status' => 'Kupon sudah kedaluwarsa. Muat ulang data untuk melihat status terbaru.',
                ]);
            }

            $hold = round((float) $locked->koin_ditahan, 2);

            if ($hold > 0) {
                $wallet = $this->lockWallet($locked->user);
                $wallet->saldo_koin = max(0, round((float) $wallet->saldo_koin - $hold, 2));
                $wallet->koin_ditahan = max(0, round((float) $wallet->koin_ditahan - $hold, 2));
                $wallet->save();

                TransaksiKoin::create([
                    'user_id' => $locked->user_id,
                    'tipe' => 'keluar',
                    'jumlah_koin' => $hold,
                    'sumber' => 'reward_claim',
                    'referensi_id' => $locked->id,
                    'keterangan' => 'Ambil reward: '.($locked->reward?->nama_reward ?? 'Reward'),
                ]);
            }

            $locked->update([
                'status' => 'selesai',
                'koin_ditahan' => 0,
                'confirmed_at' => now(),
                'catatan_admin' => $catatan ?: 'Reward telah diserahkan dan koin resmi dipotong.',
            ]);

            return $locked->fresh(['reward', 'user']);
        });
    }

    /**
     * Batalkan klaim/kupon: lepas hold atau refund koin lama yang sudah
     * terlanjur dipotong, lalu kembalikan stok.
     */
    public function batalkan(PermintaanReward $permintaan, string $alasan): PermintaanReward
    {
        return DB::transaction(function () use ($permintaan, $alasan) {
            $locked = PermintaanReward::lockForUpdate()->findOrFail($permintaan->id);

            if (! in_array($locked->status, ['pending', 'siap_diambil', 'diproses'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini tidak bisa dibatalkan (status: '.$locked->status.').',
                ]);
            }

            if ($locked->expires_at && $locked->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'status' => 'Kupon sudah kedaluwarsa. Muat ulang data untuk melihat status terbaru.',
                ]);
            }

            $hold = round((float) $locked->koin_ditahan, 2);
            $reward = $this->lockReward($locked);

            if ($hold > 0) {
                $this->lepasHold($locked);
            } else {
                // Legacy / reward digital: koin sudah dipotong saat klaim.
                $wallet = $this->lockWallet($locked->user);
                $wallet->increment('saldo_koin', $locked->koin_ditukar);

                TransaksiKoin::create([
                    'user_id' => $locked->user_id,
                    'tipe' => 'masuk',
                    'jumlah_koin' => $locked->koin_ditukar,
                    'sumber' => 'refund',
                    'referensi_id' => $locked->id,
                    'keterangan' => 'REFUND: Klaim reward dibatalkan admin.',
                ]);
            }

            $this->kembalikanStok($reward);

            $locked->update([
                'status' => 'dibatalkan',
                'koin_ditahan' => 0,
                'catatan_admin' => $alasan,
            ]);

            return $locked->fresh(['reward', 'user']);
        });
    }

    private function expireQuery($query): int
    {
        $ids = $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('status', ['pending', 'siap_diambil'])
            ->pluck('id');

        foreach ($ids as $id) {
            $permintaan = PermintaanReward::find($id);

            if ($permintaan) {
                $this->expireIfNeeded($permintaan);
            }
        }

        return $ids->count();
    }

    /**
     * Lepas hold koin tanpa menyentuh saldo (koin tidak pernah dipotong).
     * Lock order: reward (sudah dikunci pemanggil) -> wallet.
     */
    private function lepasHold(PermintaanReward $permintaan): void
    {
        $hold = round((float) $permintaan->koin_ditahan, 2);

        if ($hold <= 0) {
            return;
        }

        $wallet = $this->lockWallet($permintaan->user);
        $wallet->koin_ditahan = max(0, round((float) $wallet->koin_ditahan - $hold, 2));
        $wallet->save();
    }

    private function kembalikanStok(?Reward $reward): void
    {
        if ($reward) {
            $reward->increment('stok');
        }
    }

    private function lockReward(PermintaanReward $permintaan): ?Reward
    {
        if (! $permintaan->reward_id) {
            return null;
        }

        return Reward::withTrashed()->lockForUpdate()->find($permintaan->reward_id);
    }

    private function lockWallet(User $user): Wallet
    {
        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

        if (! $wallet) {
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'saldo_koin' => 0,
                'koin_ditahan' => 0,
                'total_koin_diperoleh' => 0,
                'total_sampah_kg' => 0,
            ]);
        }

        return $wallet;
    }

    private function buatKodeKupon(): string
    {
        // Hindari karakter mirip: I, O, 0, 1.
        $alfabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($percobaan = 0; $percobaan < 10; $percobaan++) {
            $kode = 'LV-';

            for ($i = 0; $i < 8; $i++) {
                if ($i === 4) {
                    $kode .= '-';
                }

                $kode .= $alfabet[random_int(0, strlen($alfabet) - 1)];
            }

            if (! PermintaanReward::where('kode_klaim', $kode)->exists()) {
                return $kode;
            }
        }

        throw new RuntimeException('Gagal membuat kode kupon unik.');
    }

    private function formatKoin(float $nilai): string
    {
        return number_format($nilai, 2, ',', '.');
    }
}
