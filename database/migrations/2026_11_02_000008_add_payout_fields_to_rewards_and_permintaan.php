<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            // Nilai rupiah reward saldo digital (mis. Rp20.000) untuk struk payout.
            $table->decimal('nilai_rupiah', 12, 2)->nullable()->after('koin_dibutuhkan');
        });

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->string('struk_nomor', 50)->nullable()->unique()->after('kode_klaim');
            $table->timestamp('diproses_at')->nullable()->after('confirmed_at');
            $table->timestamp('selesai_at')->nullable()->after('diproses_at');
            $table->timestamp('gagal_at')->nullable()->after('selesai_at');

            $table->enum('status', [
                'pending',
                'siap_diambil',
                'diproses',
                'selesai',
                'ditolak',
                'gagal',
                'kedaluwarsa',
                'dibatalkan',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        DB::table('permintaan_reward')
            ->where('status', 'gagal')
            ->update(['status' => 'ditolak']);

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->dropUnique(['struk_nomor']);
            $table->dropColumn(['struk_nomor', 'diproses_at', 'selesai_at', 'gagal_at']);

            $table->enum('status', [
                'pending',
                'siap_diambil',
                'diproses',
                'selesai',
                'ditolak',
                'kedaluwarsa',
                'dibatalkan',
            ])->default('pending')->change();
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->dropColumn('nilai_rupiah');
        });
    }
};
