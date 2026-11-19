<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pisahkan pencatatan satuan liter di wallet.
 *
 * Sebelumnya liter (mis. minyak jelantah) dikonversi diam-diam ke kg
 * (1 L = 0,9 kg) dan dicampur ke total_sampah_kg — member tidak pernah
 * melihat berapa liter yang disetor. Kolom baru menyimpan total liter
 * mentah (tanpa konversi) agar bisa ditampilkan berdampingan: "X kg · Y L".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('total_sampah_liter', 10, 2)->default(0)->after('total_sampah_kg');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('total_sampah_liter');
        });
    }
};
