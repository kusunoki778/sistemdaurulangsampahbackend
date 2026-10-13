<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('judul');
            $table->text('deskripsi')->nullable();
            $table->enum('tipe', ['daily', 'weekly']);
            $table->integer('target_jumlah');
            $table->integer('koin_reward')->default(0);
            $table->boolean('status_aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('mission_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mission_id')->constrained('missions')->cascadeOnDelete();
            $table->integer('progress')->default(0);
            $table->enum('status', ['ongoing', 'completed', 'claimed'])->default('ongoing');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_progress');
        Schema::dropIfExists('missions');
    }
};
