<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiSetorDetail extends Model
{
    use HasFactory;

    protected $table = 'transaksi_setor_detail';

    protected $fillable = [
        'transaksi_setor_id',
        'jenis_sampah_id',
        'jumlah',
        'koin_subtotal',
    ];

    protected $casts = [
        'jumlah' => 'decimal:2',
        'koin_subtotal' => 'integer',
    ];

    public function transaksiSetor(): BelongsTo
    {
        return $this->belongsTo(TransaksiSetor::class, 'transaksi_setor_id');
    }

    public function jenisSampah(): BelongsTo
    {
        return $this->belongsTo(JenisSampah::class, 'jenis_sampah_id');
    }
}
