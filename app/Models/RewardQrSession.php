<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardQrSession extends Model
{
    use HasFactory;

    protected $table = 'reward_qr_sessions';

    protected $fillable = [
        'token_hash',
        'permintaan_reward_id',
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

    public function permintaanReward(): BelongsTo
    {
        return $this->belongsTo(PermintaanReward::class, 'permintaan_reward_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
