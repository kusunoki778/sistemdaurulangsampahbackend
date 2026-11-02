<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Koin disimpan sebagai desimal 2 angka tanpa pembulatan.
     * Contoh: 1,35 kg x 25 koin = 33,75 koin.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('saldo_koin', 12, 2)->default(0)->change();
            $table->decimal('total_koin_diperoleh', 12, 2)->default(0)->change();
        });

        Schema::table('transaksi_koin', function (Blueprint $table) {
            $table->decimal('jumlah_koin', 12, 2)->change();
        });

        Schema::table('transaksi_setor', function (Blueprint $table) {
            $table->decimal('total_koin', 12, 2)->default(0)->change();
        });

        Schema::table('transaksi_setor_detail', function (Blueprint $table) {
            $table->decimal('koin_subtotal', 12, 2)->change();
        });

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->decimal('koin_ditukar', 12, 2)->change();
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->decimal('koin_dibutuhkan', 12, 2)->change();
        });

        Schema::table('jenis_sampah', function (Blueprint $table) {
            $table->decimal('poin_per_satuan', 12, 2)->change();
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->decimal('koin_reward', 12, 2)->default(0)->change();
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->decimal('koin_diperoleh', 12, 2)->default(0)->change();
        });

        Schema::table('missions', function (Blueprint $table) {
            $table->decimal('koin_reward', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        // Kembali ke integer; nilai pecahan akan dipotong (truncate).
        Schema::table('wallets', function (Blueprint $table) {
            $table->integer('saldo_koin')->default(0)->change();
            $table->integer('total_koin_diperoleh')->default(0)->change();
        });

        Schema::table('transaksi_koin', function (Blueprint $table) {
            $table->integer('jumlah_koin')->change();
        });

        Schema::table('transaksi_setor', function (Blueprint $table) {
            $table->integer('total_koin')->default(0)->change();
        });

        Schema::table('transaksi_setor_detail', function (Blueprint $table) {
            $table->integer('koin_subtotal')->change();
        });

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->integer('koin_ditukar')->change();
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->integer('koin_dibutuhkan')->change();
        });

        Schema::table('jenis_sampah', function (Blueprint $table) {
            $table->integer('poin_per_satuan')->change();
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->integer('koin_reward')->default(0)->change();
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->integer('koin_diperoleh')->default(0)->change();
        });

        Schema::table('missions', function (Blueprint $table) {
            $table->integer('koin_reward')->default(0)->change();
        });
    }
};
