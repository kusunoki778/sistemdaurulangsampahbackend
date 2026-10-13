<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'role',
        'avatar',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Field turunan dari wallet agar tetap kompatibel dengan frontend/web
     * dan mobile app yang sudah memakai nama lama (saldo_koin, dll).
     */
    protected $appends = [
        'saldo_koin',
        'sampah_terkumpul_kg',
        'status_member',
        'no_hp',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function transaksiSetor(): HasMany
    {
        return $this->hasMany(TransaksiSetor::class, 'user_id');
    }

    public function permintaanReward(): HasMany
    {
        return $this->hasMany(PermintaanReward::class, 'user_id');
    }

    public function transaksiKoin(): HasMany
    {
        return $this->hasMany(TransaksiKoin::class, 'user_id');
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'user_id');
    }

    public function missionProgress(): HasMany
    {
        return $this->hasMany(MissionProgress::class, 'user_id');
    }

    public function artikels(): HasMany
    {
        return $this->hasMany(Artikel::class, 'admin_id');
    }

    public function verifiedSetor(): HasMany
    {
        return $this->hasMany(TransaksiSetor::class, 'admin_id');
    }

    /* --------------------------- Accessor kompatibilitas --------------------------- */

    public function getSaldoKoinAttribute(): int
    {
        return (int) ($this->wallet?->saldo_koin ?? 0);
    }

    public function getSampahTerkumpulKgAttribute(): float
    {
        return (float) ($this->wallet?->total_sampah_kg ?? 0);
    }

    /**
     * Tier membership diturunkan dari total sampah (kg) pada wallet.
     */
    public function getStatusMemberAttribute(): string
    {
        $totalKg = (float) ($this->wallet?->total_sampah_kg ?? 0);

        if ($totalKg >= 100) {
            return 'Gold';
        }

        if ($totalKg >= 25) {
            return 'Silver';
        }

        return 'Bronze';
    }

    public function getNoHpAttribute(): ?string
    {
        return $this->attributes['phone'] ?? null;
    }

    public function setNoHpAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value;
    }
}
