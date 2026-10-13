<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_koin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('tipe', ['masuk', 'keluar']);
            $table->integer('jumlah_koin');
            $table->enum('sumber', ['setor_sampah', 'daily_login', 'quiz', 'mission', 'reward_claim', 'refund']);
            $table->unsignedBigInteger('referensi_id')->nullable();
            $table->string('keterangan', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_koin');
    }
};
