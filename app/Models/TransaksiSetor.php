<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransaksiSetor extends Model
{
    use HasFactory;

    protected $table = 'transaksi_setor';

    protected $fillable = [
        'kode_transaksi',
        'user_id',
        'admin_id',
        'total_koin',
        'status',
        'catatan',
        'qr_code',
    ];

    protected $casts = [
        'total_koin' => 'integer',
        'status' => 'string',
    ];

    /**
     * Alias kompatibilitas frontend web (ringkasan item pertama):
     * - jenis_sampah, jumlah, satuan, koin_estimasi
     */
    protected $appends = [
        'jenis_sampah',
        'jumlah',
        'satuan',
        'koin_estimasi',
        'alasan_admin',
        'nilai_tukar_id',
        'tanggal_pengajuan',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(TransaksiSetorDetail::class, 'transaksi_setor_id');
    }

    public function getJenisSampahAttribute(): ?string
    {
        $items = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        if ($items->isEmpty()) {
            return null;
        }

        $names = $items->map(fn ($d) => optional($d->jenisSampah)->nama_jenis)->filter();

        return $names->count() > 1
            ? $names->first().' +'.($names->count() - 1).' lainnya'
            : $names->first();
    }

    public function getJumlahAttribute(): float
    {
        $items = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        return (float) $items->sum('jumlah');
    }

    public function getSatuanAttribute(): ?string
    {
        $items = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        return optional($items->first()?->jenisSampah)->satuan;
    }

    public function getKoinEstimasiAttribute(): int
    {
        return (int) $this->total_koin;
    }

    public function getAlasanAdminAttribute(): ?string
    {
        return $this->catatan;
    }

    public function getNilaiTukarIdAttribute(): ?int
    {
        $items = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        return $items->first()?->jenis_sampah_id;
    }

    public function getTanggalPengajuanAttribute(): ?string
    {
        return $this->created_at?->toIso8601String();
    }
}
