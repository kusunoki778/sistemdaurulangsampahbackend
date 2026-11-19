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
    public function __construct(
        private readonly FcmService $fcm,
    ) {}

    public const KUNCI_MASA_BERLAKU = 'reward_masa_berlaku_hari';

    /** Kurs tukar koin ke saldo Dana: Rp 100 per koin (= Rp 1.000 per 10 koin). */
    public const KURS_RUPIAH_PER_KOIN = 100;

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
        $permintaan = DB::transaction(function () use ($user, $rewardId, $nomorTujuan) {
            // Urutan lock: reward -> wallet. Semua alur yang menyentuh reward
            // dan wallet memakai urutan ini agar tidak deadlock.
            $reward = Reward::whereKey($rewardId)->lockForUpdate()->firstOrFail();

            if (! $reward->status_aktif) {
                throw ValidationException::withMessages([
                    'reward_id' => 'Reward ini sedang tidak aktif.',
                ]);
            }

            // Reward tipe saldo_digital = reward SISTEM (nilai dinamis, stok
            // tak terbatas). Tukar koin-ke-saldo wajib lewat konversiSaldo()
            // supaya nominalnya mengikuti jumlah koin user — tidak bisa
            // diklaim lewat katalog (mencegah klaim nominal salah/0 koin).
            if ($reward->tipe === 'saldo_digital') {
                throw ValidationException::withMessages([
                    'reward_id' => 'Tukar ke saldo e-wallet memakai menu "Tukar Koin ke Saldo" supaya nominalnya diisi kamu sendiri.',
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

        // Notifikasi sistem HP — dikirim SETELAH commit DB (bukan di dalam),
        // supaya network call tidak menahan kunci transaksi.
        $fisik = $permintaan->reward?->tipe === 'barang';

        $this->fcm->kirimKeUser(
            $user->id,
            $fisik ? 'Kupon reward siap diambil' : 'Reward kamu sedang diproses',
            $fisik
                ? sprintf('Kupon %s untuk "%s" sudah aktif. Tunjukkan kode ini ke admin saat pengambilan.', $permintaan->kode_klaim, $permintaan->reward?->nama_reward)
                : sprintf('Pengajuan "%s" sedang diverifikasi admin.', $permintaan->reward?->nama_reward),
            ['tipe' => 'reward_kupon', 'permintaan_id' => $permintaan->id],
        );

        return $permintaan;
    }

    public function saldoTersedia(User $user): float
    {
        $wallet = $user->wallet;

        return $wallet ? round((float) $wallet->saldo_koin - (float) $wallet->koin_ditahan, 2) : 0.0;
    }

    /**
     * Tukar koin ke saldo Dana (hadiah bentuk saldo).
     * Koin memakai mekanisme HOLD yang sama dengan reward fisik: tidak
     * dipotong dulu, hanya dipegang di `koin_ditahan`. Pemotongan & ledger
     * dicatat saat pembayaran sandbox dikonfirmasi (selesaikan). Kalau user
     * tidak melanjutkan, kupon kedaluwarsa otomatis (expireIfNeeded) dan
     * koin kembali utuh — tidak ada koin yang terkunci diam-diam.
     */
    public function konversiSaldo(User $user, float $jumlahKoin, string $metode, string $nomorTujuan, string $namaPemilik): PermintaanReward
    {
        return DB::transaction(function () use ($user, $jumlahKoin, $metode, $nomorTujuan, $namaPemilik) {
            $wallet = $this->lockWallet($user);
            $jumlah = round($jumlahKoin, 2);
            $tersedia = round((float) $wallet->saldo_koin - (float) $wallet->koin_ditahan, 2);

            if ($jumlah <= 0) {
                throw ValidationException::withMessages([
                    'jumlah_koin' => 'Jumlah koin yang ditukar minimal 1 koin.',
                ]);
            }

            if ($tersedia + 0.0001 < $jumlah) {
                throw ValidationException::withMessages([
                    'jumlah_koin' => 'Saldo koin tidak cukup. Tersedia '
                        .$this->formatKoin($tersedia).' koin, dibutuhkan '.$this->formatKoin($jumlah).' koin.',
                ]);
            }

            $reward = $this->rewardSistemSaldo();

            // HOLD — saldo_koin tidak disentuh di langkah ini (transparan:
            // user tetap melihat saldonya, hanya koin "terkunci payout").
            $wallet->increment('koin_ditahan', $jumlah);

            $permintaan = PermintaanReward::create([
                'kode_klaim' => $this->buatKodeKupon(),
                'user_id' => $user->id,
                'reward_id' => $reward->id,
                'koin_ditukar' => $jumlah,
                'koin_ditahan' => $jumlah,
                'nomor_tujuan_digital' => $nomorTujuan,
                'status' => 'pending',
                'expires_at' => now()->addDays($this->masaBerlakuHari()),
                'catatan_admin' => 'Payout via '.str($metode)->title()
                    .' atas nama '.$namaPemilik.' ke nomor '
                    .substr($nomorTujuan, 0, 4).'xxxx'.substr($nomorTujuan, -4)
                    .'. Koin ditahan (belum dipotong); menunggu payout Xendit.',
            ]);

            return $permintaan->load('reward');
        });
    }

    /**
     * Tukar koin -> saldo rupiah INTERNAL dompet (langsung, tanpa antre
     * payout/QRIS). Satu transaksi atomik: koin dipotong, rupiah masuk,
     * permintaan langsung berstatus selesai (berstruk) + mutasi koin dicatat.
     */
    public function konversiKeDompet(User $user, float $jumlahKoin): PermintaanReward
    {
        return DB::transaction(function () use ($user, $jumlahKoin) {
            $wallet = $this->lockWallet($user);
            $jumlah = round($jumlahKoin, 2);
            $tersedia = round((float) $wallet->saldo_koin - (float) $wallet->koin_ditahan, 2);

            if ($jumlah < 10) {
                throw ValidationException::withMessages([
                    'jumlah_koin' => 'Tukar ke dompet minimal 10 koin.',
                ]);
            }

            if ($tersedia + 0.0001 < $jumlah) {
                throw ValidationException::withMessages([
                    'jumlah_koin' => 'Saldo koin tidak cukup. Tersedia '
                        .$this->formatKoin($tersedia).' koin, dibutuhkan '.$this->formatKoin($jumlah).' koin.',
                ]);
            }

            $masuk = round($jumlah * self::KURS_RUPIAH_PER_KOIN, 2);

            $wallet->saldo_koin = max(0, round((float) $wallet->saldo_koin - $jumlah, 2));
            $wallet->save();
            $wallet->increment('saldo_rupiah', $masuk);

            $reward = $this->rewardSistemSaldo();
            $strukNomor = $this->buatNomorStruk();

            $permintaan = PermintaanReward::create([
                'kode_klaim' => $this->buatKodeKupon(),
                'user_id' => $user->id,
                'reward_id' => $reward->id,
                'koin_ditukar' => $jumlah,
                'koin_ditahan' => 0,
                'nomor_tujuan_digital' => 'Dompet Livora',
                'status' => 'selesai',
                'expires_at' => now()->addDays($this->masaBerlakuHari()),
                'confirmed_at' => now(),
                'selesai_at' => now(),
                'struk_nomor' => $strukNomor,
                'catatan_admin' => 'Tukar koin langsung ke saldo dompet. Koin dipotong '
                    .$this->formatKoin($jumlah).'; saldo rupiah bertambah Rp '
                    .number_format($masuk, 2, ',', '.').'.',
            ]);

            TransaksiKoin::create([
                'user_id' => $user->id,
                'tipe' => 'keluar',
                'jumlah_koin' => $jumlah,
                'sumber' => 'konversi_saldo',
                'referensi_id' => $permintaan->id,
                'keterangan' => 'Tukar koin ke saldo dompet',
            ]);

            $this->fcm->kirimKeUser(
                $user->id,
                'Saldo sudah masuk',
                sprintf('Rp %s sudah ditambahkan ke saldo dompet Livora kamu.', number_format($masuk, 0, ',', '.')),
                ['tipe' => 'dana_cair', 'permintaan_id' => $permintaan->id],
            );

            return $permintaan->fresh('reward');
        });
    }

    /**
     * Konfirmasi pembayaran di sandbox (simulasi disbursement ala gateway).
     * pending -> diproses -> selesai; Dana langsung mencair ke saldo_rupiah.
     * Idempoten: permintaan selesai diperiksa ulang tanpa efek ganda.
     * $catatan memungkinkan sumber konfirmasi berbeda (tombol sandbox user
     * vs webhook/polling Xendit) tetap jujur di catatan admin/struk.
     */
    public function disbursSandbox(PermintaanReward $permintaan, ?string $catatan = null): PermintaanReward
    {
        // Kupon payout sudah kedaluwarsa? Kembalikan koin dulu (status
        // kedaluwarsa + catatan) supaya user tidak membayar kupon yang
        // sebenarnya sudah batal — transparansi saldo.
        $permintaan = $this->expireIfNeeded($permintaan->load('reward'));
        if ($permintaan->status === 'kedaluwarsa') {
            throw ValidationException::withMessages([
                'status' => 'Waktu pembayaran payout sudah habis. Koinmu sudah kembali ke wallet — ajukan tukar saldo baru.',
            ]);
        }

        $permintaan = $this->proses($permintaan, 'Pencairan diproses otomatis.');

        return $this->selesaikan(
            $permintaan,
            $catatan ?? 'Pembayaran terkonfirmasi. Dana dicairkan ke '
                .substr((string) $permintaan->nomor_tujuan_digital, 0, 4)
                .'xxxx'.substr((string) $permintaan->nomor_tujuan_digital, -4).'.',
        );
    }

    /**
     * Reward sistem untuk pencairan saldo Dana (tidak tampil di katalog
     * sebagai stok fisik; nilainya dinamis mengikut jumlah koin user).
     */
    private function rewardSistemSaldo(): Reward
    {
        $reward = Reward::withTrashed()->firstOrCreate(
            ['nama_reward' => 'Tukar Koin ke Saldo Dana', 'tipe' => 'saldo_digital'],
            [
                'koin_dibutuhkan' => 0,
                'nilai_rupiah' => null,
                'stok' => 999999,
                'deskripsi' => 'Reward sistem: tukar koin menjadi saldo Dana (nilai dinamis per pengajuan).',
                'status_aktif' => true,
            ],
        );

        if ($reward->deleted_at !== null) {
            $reward->restore();
        }

        return $reward;
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
     * Susun data struk untuk transaksi yang sudah selesai.
     * Nomor e-wallet tampil lengkap di sini (di daftar riwayat user disamarkan).
     */
    public function buatStruk(PermintaanReward $permintaan): array
    {
        $permintaan->load(['reward', 'user']);

        $fisik = $permintaan->reward?->tipe === 'barang';
        $nilaiRupiah = $permintaan->reward?->nilai_rupiah;

        return [
            'nomor_struk' => $permintaan->struk_nomor,
            'jenis' => $fisik ? 'pickup_fisik' : 'payout_digital',
            'judul' => $fisik ? 'Bukti Pengambilan Reward' : 'Struk Pencairan Saldo Digital',
            'status' => 'selesai',
            'tanggal' => ($permintaan->selesai_at ?? $permintaan->confirmed_at)?->toIso8601String(),
            'kode_klaim' => $permintaan->kode_klaim,
            'member' => [
                'nama' => $permintaan->user?->name,
                'username' => $permintaan->user?->username,
            ],
            'reward' => [
                'nama' => $permintaan->reward?->nama_reward,
                'tipe' => $permintaan->reward?->tipe,
                'nilai_rupiah' => $nilaiRupiah !== null ? (float) $nilaiRupiah : null,
            ],
            'koin' => (float) $permintaan->koin_ditukar,
            'nomor_tujuan' => $fisik ? null : $permintaan->nomor_tujuan_digital,
            'metode' => $fisik
                ? 'Pengambilan langsung di titik Livora'
                : 'Transfer ke e-wallet member',
            'catatan' => $permintaan->catatan_admin,
        ];
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

        $hasil = DB::transaction(function () use ($permintaan) {
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

        $this->fcm->kirimKeUser(
            $hasil->user_id,
            'Kupon kedaluwarsa',
            sprintf('Kupon "%s" tidak diambil — koinmu (%s) sudah kembali ke wallet.', $hasil->reward?->nama_reward, number_format((float) $hasil->koin_ditukar, 0, ',', '.')),
            ['tipe' => 'reward_kedaluwarsa', 'permintaan_id' => $hasil->id],
        );

        return $hasil;
    }

    /**
     * Selesaikan pickup reward fisik atau proses reward digital.
     * Idempoten: pemanggilan ulang tidak memotong koin dua kali.
     */
    public function selesaikan(PermintaanReward $permintaan, ?string $catatan = null): PermintaanReward
    {
        $hasil = DB::transaction(function () use ($permintaan, $catatan) {
            $locked = PermintaanReward::with('reward')->lockForUpdate()->findOrFail($permintaan->id);

            if ($locked->status === 'selesai') {
                return $locked;
            }

            if (! in_array($locked->status, ['pending', 'siap_diambil', 'diproses'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini tidak bisa diselesaikan (status: '.$locked->status.').',
                ]);
            }

            // Payout saldo digital wajib melewati "diproses" dulu (pending ->
            // diproses -> selesai). Voucher dan reward fisik boleh langsung
            // diselesaikan karena tidak ada pencairan dana bertahap.
            if ($locked->reward?->tipe === 'saldo_digital' && $locked->status === 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Proses payout terlebih dahulu sebelum menandai selesai.',
                ]);
            }

            // Expiry hanya relevan selama kupon belum diambil/diproses.
            // Status "diproses" sudah diambil alih admin, jadi jangan blokir
            // penyelesaian payout hanya karena tanggal kupon lewat.
            if (in_array($locked->status, ['pending', 'siap_diambil'], true)
                && $locked->expires_at
                && $locked->expires_at->isPast()) {
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
                    'keterangan' => $locked->reward?->tipe === 'saldo_digital'
                        ? 'Tukar koin ke saldo Dana (payout dikonfirmasi)'
                        : 'Ambil reward: '.($locked->reward?->nama_reward ?? 'Reward'),
                ]);
            }

            // Hadiah tukar koin ke saldo: koin dibayar penuh, dana rupiah
            // langsung dicatat ke wallet member setelah payout berhasil.
            // Kurs satu-satunya sumber: KURS_RUPIAH_PER_KOIN — tidak bergantung
            // pada nilai_rupiah reward (supaya koin <-> rupiah konsisten).
            $danaMasuk = 0.0;
            if ($locked->reward?->tipe === 'saldo_digital') {
                $danaMasuk = round(
                    (float) $locked->koin_ditukar * self::KURS_RUPIAH_PER_KOIN,
                    2,
                );
                $wallet = $this->lockWallet($locked->user);
                $wallet->increment('saldo_rupiah', $danaMasuk);
            }

            $catatanJadi = $catatan ?: 'Reward telah diserahkan dan koin resmi dipotong.';
            if ($danaMasuk > 0) {
                $catatanJadi .= ' Dana: Rp '.number_format($danaMasuk, 2, ',', '.')
                    .' masuk ke saldo Livora member.';
            }

            $locked->update([
                'status' => 'selesai',
                'koin_ditahan' => 0,
                'confirmed_at' => now(),
                'selesai_at' => now(),
                'struk_nomor' => $locked->struk_nomor ?: $this->buatNomorStruk(),
                'catatan_admin' => $catatanJadi,
            ]);

            return $locked->fresh(['reward', 'user']);
        });

        $fisik = $hasil->reward?->tipe === 'barang';

        $tipeReward = $hasil->reward?->tipe;
        $jumlahRupiah = number_format(
            (float) $hasil->koin_ditukar * self::KURS_RUPIAH_PER_KOIN,
            0,
            ',',
            '.',
        );
        $channel = $this->labelChannel($hasil);
        $nomor = (string) $hasil->nomor_tujuan_digital;
        $nomorSamar = strlen($nomor) > 8
            ? ' '.substr($nomor, 0, 4).'xxxx'.substr($nomor, -4)
            : '';

        if ($tipeReward === 'barang') {
            $judul = 'Reward berhasil diambil';
            $pesan = sprintf('"%s" sudah diambil. Selamat menikmati!', $hasil->reward?->nama_reward);
        } elseif ($tipeReward === 'saldo_digital') {
            $judul = 'Saldo sudah masuk';
            $pesan = sprintf('Rp %s sudah masuk ke %s%s. Cek saldo e-wallet kamu.', $jumlahRupiah, $channel, $nomorSamar);
        } else {
            $judul = 'Reward berhasil diambil';
            $pesan = sprintf('"%s" sudah diklaim. Cek riwayat reward untuk rinciannya.', $hasil->reward?->nama_reward);
        }

        $this->fcm->kirimKeUser(
            $hasil->user_id,
            $judul,
            $pesan,
            ['tipe' => $fisik ? 'reward_selesai' : 'dana_cair', 'permintaan_id' => $hasil->id],
        );

        return $hasil;
    }

    /**
     * Admin mulai memproses payout reward digital.
     * pending -> diproses.
     */
    public function proses(PermintaanReward $permintaan, ?string $catatan = null): PermintaanReward
    {
        return DB::transaction(function () use ($permintaan, $catatan) {
            $locked = PermintaanReward::with('reward')->lockForUpdate()->findOrFail($permintaan->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya permintaan berstatus pending yang bisa diproses (status: '.$locked->status.').',
                ]);
            }

            if ($locked->reward?->tipe === 'barang') {
                throw ValidationException::withMessages([
                    'status' => 'Reward fisik diproses lewat kupon + QR pickup, bukan payout digital.',
                ]);
            }

            $locked->update([
                'status' => 'diproses',
                'diproses_at' => now(),
                // Payout digital yang sudah diambil alih admin tidak lagi
                // tunduk pada masa berlaku kupon.
                'expires_at' => null,
                'catatan_admin' => $catatan ?: 'Pencairan sedang diproses.',
            ]);

            return $locked->fresh(['reward', 'user']);
        });
    }

    /**
     * Payout digital gagal -> refund koin + kembalikan stok + status gagal.
     */
    public function tandaiGagal(PermintaanReward $permintaan, string $alasan): PermintaanReward
    {
        $hasil = DB::transaction(function () use ($permintaan, $alasan) {
            $locked = PermintaanReward::lockForUpdate()->findOrFail($permintaan->id);

            if (! in_array($locked->status, ['pending', 'diproses'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini tidak bisa ditandai gagal (status: '.$locked->status.').',
                ]);
            }

            if (round((float) $locked->koin_ditahan, 2) > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Reward fisik yang masih menahan koin harus dibatalkan lewat menu Batalkan.',
                ]);
            }

            $reward = $this->lockReward($locked);

            $wallet = $this->lockWallet($locked->user);
            $wallet->increment('saldo_koin', $locked->koin_ditukar);

            TransaksiKoin::create([
                'user_id' => $locked->user_id,
                'tipe' => 'masuk',
                'jumlah_koin' => $locked->koin_ditukar,
                'sumber' => 'refund',
                'referensi_id' => $locked->id,
                'keterangan' => 'REFUND: Payout reward digital gagal.',
            ]);

            $this->kembalikanStok($reward);

            $locked->update([
                'status' => 'gagal',
                'gagal_at' => now(),
                'catatan_admin' => $alasan,
            ]);

            return $locked->fresh(['reward', 'user']);
        });

        if ($hasil->koin_ditukar > 0) {
            $this->fcm->kirimKeUser(
                $hasil->user_id,
                'Reward dibatalkan admin',
                sprintf('Payout "%s" gagal — %s koin sudah kembali ke wallet kamu.', $hasil->reward?->nama_reward, number_format((float) $hasil->koin_ditukar, 0, ',', '.')),
                ['tipe' => 'reward_refund', 'permintaan_id' => $hasil->id],
            );
        }

        return $hasil;
    }

    /**
     * Batalkan klaim/kupon: lepas hold atau refund koin lama yang sudah
     * terlanjur dipotong, lalu kembalikan stok.
     */
    public function batalkan(PermintaanReward $permintaan, string $alasan): PermintaanReward
    {
        $hasil = DB::transaction(function () use ($permintaan, $alasan) {
            $locked = PermintaanReward::lockForUpdate()->findOrFail($permintaan->id);

            if (! in_array($locked->status, ['pending', 'siap_diambil', 'diproses'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini tidak bisa dibatalkan (status: '.$locked->status.').',
                ]);
            }

            // Payout yang sudah diproses admin tetap harus bisa dibatalkan
            // walau tanggal kupon sudah lewat, supaya hold koin bisa dilepas.
            if (in_array($locked->status, ['pending', 'siap_diambil'], true)
                && $locked->expires_at
                && $locked->expires_at->isPast()) {
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

        if ($hasil->koin_ditukar > 0) {
            $this->fcm->kirimKeUser(
                $hasil->user_id,
                'Kupon dibatalkan admin',
                sprintf('Kupon "%s" dibatalkan — koinmu sudah kembali ke wallet.', $hasil->reward?->nama_reward),
                ['tipe' => 'reward_refund', 'permintaan_id' => $hasil->id],
            );
        }

        return $hasil;
    }

    /**
     * Nama channel payout untuk teks notifikasi. Diambil dari kode channel
     * Xendit supaya member melihat DANA/OVO/ShopeePay sesuai pilihannya,
     * bukan nama yang selalu sama.
     */
    private function labelChannel(PermintaanReward $permintaan): string
    {
        $kode = strtoupper((string) $permintaan->xendit_channel_code);

        return match ($kode) {
            'DANA' => 'DANA',
            'OVO' => 'OVO',
            'SHOPEEPAY' => 'ShopeePay',
            'LINKAJA' => 'LinkAja',
            'ASTRAPAY' => 'AstraPay',
            default => 'e-wallet',
        };
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

    private function buatNomorStruk(): string
    {
        for ($percobaan = 0; $percobaan < 10; $percobaan++) {
            $nomor = 'STR-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

            if (! PermintaanReward::where('struk_nomor', $nomor)->exists()) {
                return $nomor;
            }
        }

        throw new RuntimeException('Gagal membuat nomor struk unik.');
    }

    private function formatKoin(float $nilai): string
    {
        return number_format($nilai, 2, ',', '.');
    }
}
