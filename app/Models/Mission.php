<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mission extends Model
{
    use HasFactory;

    protected $table = 'missions';

    protected $fillable = [
        'judul',
        'deskripsi',
        'tipe',
        'target_jumlah',
        'koin_reward',
        'status_aktif',
    ];

    protected $casts = [
        'target_jumlah' => 'integer',
        'koin_reward' => 'integer',
        'status_aktif' => 'boolean',
    ];

    public function progress(): HasMany
    {
        return $this->hasMany(MissionProgress::class, 'mission_id');
    }
}
