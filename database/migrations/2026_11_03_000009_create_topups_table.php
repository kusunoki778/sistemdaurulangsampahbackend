<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('order_id', 64)->unique();
            $table->decimal('nominal_rupiah', 12, 2);
            // Kurs disimpan sebagai snapshot saat transaksi dibuat agar
            // perubahan kurs admin tidak mengubah top-up lama.
            $table->decimal('kurs_rupiah', 12, 2);
            $table->decimal('koin_diterima', 12, 2);
            $table->enum('status', ['pending', 'paid', 'failed', 'expired'])->default('pending');
            $table->string('snap_token')->nullable();
            $table->string('snap_redirect_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('payload_midtrans')->nullable();
            $table->timestamps();
        });

        // Sumber baru untuk ledger koin: uang masuk dari top up.
        Schema::table('transaksi_koin', function (Blueprint $table) {
            $table->enum('sumber', [
                'setor_sampah',
                'daily_login',
                'quiz',
                'mission',
                'reward_claim',
                'refund',
                'topup',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_koin', function (Blueprint $table) {
            $table->enum('sumber', [
                'setor_sampah',
                'daily_login',
                'quiz',
                'mission',
                'reward_claim',
                'refund',
            ])->change();
        });

        Schema::dropIfExists('topups');
    }
};
