<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_setor_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaksi_setor_id')->constrained('transaksi_setor')->cascadeOnDelete();
            $table->foreignId('jenis_sampah_id')->constrained('jenis_sampah')->cascadeOnDelete();
            $table->decimal('jumlah', 10, 2);
            $table->integer('koin_subtotal');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_setor_detail');
    }
};
