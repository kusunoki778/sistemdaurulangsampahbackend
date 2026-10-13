<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PesanKontak extends Model
{
    use HasFactory;

    protected $table = 'pesan_kontak';

    protected $fillable = [
        'nama',
        'email',
        'subjek',
        'pesan',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];
}
