<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermintaanReward extends Model
{
    use HasFactory;

    protected $table = 'permintaan_reward';

    protected $fillable = [
        'kode_klaim',
        'user_id',
        'reward_id',
        'koin_ditukar',
        'status',
        'nomor_tujuan_digital',
        'catatan_admin',
    ];

    protected $casts = [
        'koin_ditukar' => 'integer',
    ];

    /**
     * Alias kompatibilitas frontend web:
     * - koin_terpakai     -> koin_ditukar
     * - kode_voucher      -> kode_klaim
     * - tanggal_pengajuan -> created_at
     */
    protected $appends = [
        'koin_terpakai',
        'kode_voucher',
        'tanggal_pengajuan',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }

    public function getKoinTerpakaiAttribute(): int
    {
        return (int) $this->koin_ditukar;
    }

    public function getKodeVoucherAttribute(): ?string
    {
        return $this->kode_klaim;
    }

    public function getTanggalPengajuanAttribute(): ?string
    {
        return $this->created_at?->toIso8601String();
    }
}
