<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
    use HasFactory;

    protected $table = 'rewards';

    protected $fillable = [
        'nama_reward',
        'tipe',
        'koin_dibutuhkan',
        'stok',
        'gambar',
        'deskripsi',
        'status_aktif',
    ];

    protected $casts = [
        'koin_dibutuhkan' => 'integer',
        'stok' => 'integer',
        'status_aktif' => 'boolean',
    ];

    /**
     * Alias kompatibilitas frontend web:
     * - koin_harga -> koin_dibutuhkan
     * - kategori   -> label dari tipe reward
     */
    protected $appends = [
        'koin_harga',
        'kategori',
    ];

    public function permintaanReward(): HasMany
    {
        return $this->hasMany(PermintaanReward::class, 'reward_id');
    }

    public function getKoinHargaAttribute(): int
    {
        return (int) $this->koin_dibutuhkan;
    }

    public function getKategoriAttribute(): string
    {
        return match ($this->tipe) {
            'barang' => 'Produk',
            'voucher' => 'Voucher',
            'saldo_digital' => 'Saldo Digital',
            default => 'Umum',
        };
    }
}
