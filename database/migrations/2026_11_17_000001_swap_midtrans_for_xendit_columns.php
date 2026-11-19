<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Midtrans Snap diganti Xendit Payouts v3.
 *
 * Midtrans adalah gerbang pembayaran (uang masuk), bukan gerbang kirim
 * uang; alur payout koin -> saldo e-wallet butuh disbursement. Kolom
 * Midtrans dihapus dan diganti kolom Xendit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->string('xendit_payout_id', 64)->nullable()->unique()->after('struk_nomor');
            $table->string('xendit_reference_id', 64)->nullable()->after('xendit_payout_id');
            $table->string('xendit_channel_code', 32)->nullable()->after('xendit_reference_id');
            $table->string('xendit_status', 40)->nullable()->after('xendit_channel_code');
            $table->json('xendit_payload')->nullable()->after('xendit_status');
        });

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->dropUnique(['midtrans_order_id']);
            $table->dropColumn(['midtrans_order_id', 'snap_token', 'snap_redirect_url', 'midtrans_payload']);
        });
    }

    public function down(): void
    {
        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->string('midtrans_order_id', 80)->nullable()->unique()->after('struk_nomor');
            $table->string('snap_token', 120)->nullable()->after('midtrans_order_id');
            $table->string('snap_redirect_url')->nullable()->after('snap_token');
            $table->json('midtrans_payload')->nullable()->after('snap_redirect_url');
        });

        Schema::table('permintaan_reward', function (Blueprint $table) {
            $table->dropUnique(['xendit_payout_id']);
            $table->dropColumn(['xendit_payout_id', 'xendit_reference_id', 'xendit_channel_code', 'xendit_status', 'xendit_payload']);
        });
    }
};
