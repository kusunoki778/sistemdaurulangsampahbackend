<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaksi_setor', function (Blueprint $table) {
            $table->enum('status', ['pending', 'menunggu_konfirmasi', 'approved', 'rejected'])
                ->default('pending')
                ->change();
            $table->timestamp('settled_at')->nullable()->after('status');
            $table->string('settlement_method', 20)->nullable()->after('settled_at');
        });

        Schema::table('transaksi_setor_detail', function (Blueprint $table) {
            // Snapshot nilai tukar saat transaksi dibuat, supaya riwayat tidak
            // berubah bila admin mengubah nilai tukar di kemudian hari.
            $table->decimal('poin_per_satuan', 12, 2)->nullable()->after('koin_subtotal');
        });
    }

    public function down(): void
    {
        DB::table('transaksi_setor')
            ->where('status', 'menunggu_konfirmasi')
            ->update(['status' => 'pending']);

        Schema::table('transaksi_setor', function (Blueprint $table) {
            $table->dropColumn(['settled_at', 'settlement_method']);
        });

        Schema::table('transaksi_setor', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });

        Schema::table('transaksi_setor_detail', function (Blueprint $table) {
            $table->dropColumn('poin_per_satuan');
        });
    }
};
