<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Artikel extends Model
{
    use HasFactory;

    protected $table = 'artikels';

    protected $fillable = [
        'judul',
        'slug',
        'kategori',
        'isi',
        'gambar',
        'admin_id',
        'status',
        'views',
    ];

    protected $casts = [
        'views' => 'integer',
        'status' => 'string',
    ];

    /**
     * Alias kolom lama agar kompatibel dengan frontend web:
     * - `author`   -> relasi admin pembuat artikel
     * - `deskripsi_singkat` / `konten_lengkap` -> alias isi artikel
     * - `tanggal_publikasi` -> tanggal dibuat
     */
    protected $appends = [
        'author',
        'deskripsi_singkat',
        'konten_lengkap',
        'tanggal_publikasi',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function getAuthorAttribute(): ?User
    {
        return $this->admin;
    }

    public function getDeskripsiSingkatAttribute(): string
    {
        return mb_strimwidth((string) $this->isi, 0, 160, '...');
    }

    public function getKontenLengkapAttribute(): string
    {
        return (string) $this->isi;
    }

    public function getTanggalPublikasiAttribute(): ?string
    {
        return $this->created_at?->toDateString();
    }
}
