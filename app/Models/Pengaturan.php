<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pengaturan extends Model
{
    use HasFactory;

    protected $table = 'pengaturan';

    protected $fillable = [
        'kunci',
        'nilai',
    ];

    /**
     * Ambil nilai pengaturan sebagai string, atau default bila belum diatur.
     */
    public static function ambil(string $kunci, mixed $default = null): mixed
    {
        $pengaturan = static::where('kunci', $kunci)->first();

        return $pengaturan?->nilai ?? $default;
    }

    public static function simpan(string $kunci, mixed $nilai): void
    {
        static::updateOrCreate(
            ['kunci' => $kunci],
            ['nilai' => $nilai === null ? null : (string) $nilai],
        );
    }
}
