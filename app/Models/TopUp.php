<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopUp extends Model
{
    use HasFactory;

    protected $table = 'topups';

    protected $fillable = [
        'user_id',
        'order_id',
        'nominal_rupiah',
        'kurs_rupiah',
        'koin_diterima',
        'status',
        'snap_token',
        'snap_redirect_url',
        'paid_at',
        'payload_midtrans',
    ];

    protected $casts = [
        'nominal_rupiah' => 'decimal:2',
        'kurs_rupiah' => 'decimal:2',
        'koin_diterima' => 'decimal:2',
        'paid_at' => 'datetime',
        'payload_midtrans' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Koin yang diterima sudah final saat pembayaran berhasil;
     * top up tidak memakai mekanisme hold seperti reward.
     */
    public function isLunas(): bool
    {
        return $this->status === 'paid';
    }
}
