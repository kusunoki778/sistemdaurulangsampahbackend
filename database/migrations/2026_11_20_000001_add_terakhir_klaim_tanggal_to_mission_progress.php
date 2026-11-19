<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misi harian (misalnya Login Harian) harus bisa diklaim ulang setiap hari.
 *
 * Kolom status hanya bernilai ongoing/completed/claimed tanpa informasi
 * tanggal, sehingga misi harian yang sudah diklaim akan terkunci selamanya.
 * Kolom terakhir_klaim_tanggal menyimpan tanggal klaim terakhir supaya
 * pemeriksaan "sudah klaim hari ini" jadi eksplisit dan tidak bergantung
 * pada updated_at yang bisa tersentuh kode lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_progress', function (Blueprint $table) {
            $table->date('terakhir_klaim_tanggal')
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('mission_progress', function (Blueprint $table) {
            $table->dropColumn('terakhir_klaim_tanggal');
        });
    }
};
