<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_sessions', function (Blueprint $table) {
            $table->id();
            // Token asli hanya dikirim sekali ke CMS/app; yang disimpan hash-nya.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('transaksi_setor_id')->constrained('transaksi_setor')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipe', 20)->default('setor');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['transaksi_setor_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_sessions');
    }
};
