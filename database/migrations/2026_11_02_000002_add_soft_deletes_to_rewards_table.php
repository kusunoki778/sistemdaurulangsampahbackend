<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reward tidak boleh dihapus permanen karena riwayat klaim user
     * (permintaan_reward) harus tetap bisa ditelusuri.
     */
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
