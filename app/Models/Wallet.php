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
        'koin_ditahan',
        'total_koin_diperoleh',
        'total_sampah_kg',
    ];

    protected $casts = [
        'saldo_koin' => 'decimal:2',
        'koin_ditahan' => 'decimal:2',
        'total_koin_diperoleh' => 'decimal:2',
        'total_sampah_kg' => 'decimal:2',
    ];

    protected $appends = [
        'saldo_tersedia',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaksiKoin(): HasMany
    {
        return $this->hasMany(TransaksiKoin::class, 'user_id');
    }

    /**
     * Saldo yang boleh dipakai untuk klaim baru (saldo - koin ditahan).
     */
    public function getSaldoTersediaAttribute(): float
    {
        return round((float) $this->saldo_koin - (float) $this->koin_ditahan, 2);
    }
}
