<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrSession extends Model
{
    use HasFactory;

    protected $table = 'qr_sessions';

    protected $fillable = [
        'token_hash',
        'transaksi_setor_id',
        'user_id',
        'tipe',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function transaksiSetor(): BelongsTo
    {
        return $this->belongsTo(TransaksiSetor::class, 'transaksi_setor_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
