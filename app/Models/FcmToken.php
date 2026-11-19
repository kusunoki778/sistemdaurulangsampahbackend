<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Token FCM satu perangkat milik satu user. */
class FcmToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'terakhir_dipakai_at',
    ];

    protected function casts(): array
    {
        return [
            'terakhir_dipakai_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
