<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermintaanReward extends Model
{
    use HasFactory;

    protected $table = 'permintaan_reward';

    protected $fillable = [
        'kode_klaim',
        'struk_nomor',
        'user_id',
        'reward_id',
        'koin_ditukar',
        'koin_ditahan',
        'status',
        'expires_at',
        'confirmed_at',
        'diproses_at',
        'selesai_at',
        'gagal_at',
        'nomor_tujuan_digital',
        'catatan_admin',
    ];

    protected $casts = [
        'koin_ditukar' => 'decimal:2',
        'koin_ditahan' => 'decimal:2',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'diproses_at' => 'datetime',
        'selesai_at' => 'datetime',
        'gagal_at' => 'datetime',
    ];

    /**
     * Alias kompatibilitas frontend web:
     * - koin_terpakai     -> koin_ditukar
     * - kode_voucher      -> kode_klaim
     * - kode_kupon        -> kode_klaim
     * - tanggal_pengajuan -> created_at
     * - berlaku_sampai    -> expires_at
     */
    protected $appends = [
        'koin_terpakai',
        'kode_voucher',
        'kode_kupon',
        'tanggal_pengajuan',
        'berlaku_sampai',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }

    public function rewardQrSessions(): HasMany
    {
        return $this->hasMany(RewardQrSession::class, 'permintaan_reward_id');
    }

    public function getKoinTerpakaiAttribute(): float
    {
        return (float) $this->koin_ditukar;
    }

    public function getKodeVoucherAttribute(): ?string
    {
        return $this->kode_klaim;
    }

    public function getKodeKuponAttribute(): ?string
    {
        return $this->kode_klaim;
    }

    public function getTanggalPengajuanAttribute(): ?string
    {
        return $this->created_at?->toIso8601String();
    }

    public function getBerlakuSampaiAttribute(): ?string
    {
        return $this->expires_at?->toIso8601String();
    }

    /**
     * Nomor e-wallet yang disamarkan untuk daftar riwayat user.
     * Nomor lengkap hanya tampil di struk dan di sisi admin.
     */
    public function getNomorTujuanTersamarAttribute(): ?string
    {
        $nomor = (string) ($this->nomor_tujuan_digital ?? '');

        if ($nomor === '') {
            return null;
        }

        if (strlen($nomor) <= 8) {
            return str_repeat('•', max(2, strlen($nomor) - 2)).substr($nomor, -2);
        }

        return substr($nomor, 0, 4)
            .str_repeat('•', max(4, strlen($nomor) - 8))
            .substr($nomor, -4);
    }

    /**
     * Reward fisik memakai kupon + QR pickup; reward digital tidak.
     */
    public function isFisik(): bool
    {
        return $this->reward?->tipe === 'barang';
    }
}
