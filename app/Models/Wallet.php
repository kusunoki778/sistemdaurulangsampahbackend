<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    protected $table = 'wallets';

    protected $fillable = [
        'user_id',
        'saldo_koin',
        'total_koin_diperoleh',
        'total_sampah_kg',
    ];

    protected $casts = [
        'saldo_koin' => 'integer',
        'total_koin_diperoleh' => 'integer',
        'total_sampah_kg' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaksiKoin(): HasMany
    {
        return $this->hasMany(TransaksiKoin::class, 'user_id');
    }
}
