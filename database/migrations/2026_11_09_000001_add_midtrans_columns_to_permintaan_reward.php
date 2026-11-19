<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permintaan_reward', function (Blueprint $table) {
            // Midtrans Snap: verifikasi pembayaran simulasi untuk payout
            // digital (ganti koin -> saldo e-wallet).
            $table->string('midtrans_order_id', 80)->nullable()->unique()->after('struk_nomor');
            $table->string('snap_token', 120)->nullable()->after('midtrans_order_id');
            $table->text('snap_redirect_url')->nullable()->after('snap_token');
            $table->json('midtrans_payload')->nullable()->after('snap_redirect_url');
        });
    }

    public function down(): void
    {
        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->dropUnique(['midtrans_order_id']);
            $table->dropColumn(['midtrans_order_id', 'snap_token', 'snap_redirect_url', 'midtrans_payload']);
        });
    }
};
