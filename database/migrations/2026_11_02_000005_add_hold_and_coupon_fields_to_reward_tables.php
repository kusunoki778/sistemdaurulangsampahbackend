<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Koin yang sedang ditahan untuk kupon reward fisik.
            // Saldo tersedia = saldo_koin - koin_ditahan.
            $table->decimal('koin_ditahan', 12, 2)->default(0.00)->after('saldo_koin');
        });

        Schema::table('permintaan_reward', function (Blueprint $table) {
            // Nominal hold milik klaim ini (0 untuk reward digital/legacy yang
            // koinnya sudah langsung dipotong).
            $table->decimal('koin_ditahan', 12, 2)->default(0.00)->after('koin_ditukar');
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->timestamp('confirmed_at')->nullable()->after('expires_at');

            $table->enum('status', [
                'pending',
                'siap_diambil',
                'diproses',
                'selesai',
                'ditolak',
                'kedaluwarsa',
                'dibatalkan',
            ])->default('pending')->change();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        DB::table('permintaan_reward')
            ->where('status', 'siap_diambil')
            ->update(['status' => 'pending']);

        DB::table('permintaan_reward')
            ->whereIn('status', ['kedaluwarsa', 'dibatalkan'])
            ->update(['status' => 'ditolak']);

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['koin_ditahan', 'expires_at', 'confirmed_at']);
        });

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->enum('status', ['pending', 'diproses', 'selesai', 'ditolak'])
                ->default('pending')
                ->change();
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('koin_ditahan');
        });
    }
};
