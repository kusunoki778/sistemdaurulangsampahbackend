<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiKoin extends Model
{
    use HasFactory;

    protected $table = 'transaksi_koin';

    protected $fillable = [
        'user_id',
        'tipe',
        'jumlah_koin',
        'sumber',
        'referensi_id',
        'keterangan',
    ];

    protected $casts = [
        'jumlah_koin' => 'integer',
        'referensi_id' => 'integer',
    ];

    /**
     * Alias kompatibilitas frontend web:
     * - jenis_transaksi -> label dari sumber
     * - deskripsi       -> keterangan
     */
    protected $appends = [
        'jenis_transaksi',
        'deskripsi',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getJenisTransaksiAttribute(): string
    {
        return match ($this->sumber) {
            'setor_sampah' => 'Tukar Sampah',
            'reward_claim' => 'Redeem',
            'refund' => 'Refund',
            'daily_login' => 'Bonus Harian',
            'quiz' => 'Bonus Kuis',
            'mission' => 'Bonus Misi',
            default => ucfirst((string) $this->sumber),
        };
    }

    public function getDeskripsiAttribute(): string
    {
        return (string) $this->keterangan;
    }
}
