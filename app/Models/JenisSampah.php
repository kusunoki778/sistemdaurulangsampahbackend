<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JenisSampah extends Model
{
    use HasFactory;

    protected $table = 'jenis_sampah';

    protected $fillable = [
        'kategori_id',
        'nama_jenis',
        'satuan',
        'poin_per_satuan',
        'deskripsi',
        'gambar',
    ];

    protected $casts = [
        'poin_per_satuan' => 'integer',
    ];

    /**
     * Alias kompatibilitas frontend web:
     * - jenis_sampah    -> nama_jenis
     * - koin_per_satuan -> poin_per_satuan
     */
    protected $appends = [
        'jenis_sampah',
        'koin_per_satuan',
    ];

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriSampah::class, 'kategori_id');
    }

    public function transaksiSetorDetail(): HasMany
    {
        return $this->hasMany(TransaksiSetorDetail::class, 'jenis_sampah_id');
    }

    public function getJenisSampahAttribute(): string
    {
        return $this->nama_jenis;
    }

    public function getKoinPerSatuanAttribute(): int
    {
        return (int) $this->poin_per_satuan;
    }
}
