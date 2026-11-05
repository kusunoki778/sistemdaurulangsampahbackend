<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reward extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'rewards';

    protected $fillable = [
        'nama_reward',
        'tipe',
        'koin_dibutuhkan',
        'nilai_rupiah',
        'stok',
        'gambar',
        'deskripsi',
        'status_aktif',
    ];

    protected $casts = [
        'koin_dibutuhkan' => 'decimal:2',
        'nilai_rupiah' => 'decimal:2',
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

    public function getKoinHargaAttribute(): float
    {
        return (float) $this->koin_dibutuhkan;
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
