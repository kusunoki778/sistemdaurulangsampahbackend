<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konsep baru: koin hanya dari kegiatan hijau + hadiah Admin.
     * - Top Up Midtrans dihapus total (tabel `topups` ikut didrop).
     * - Kolom `saldo_rupiah` di wallets = "Dana" simulasi tempat hadiah
     *   tipe `saldo_digital` (tukar koin) mencairkan nilai rupiahnya.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('saldo_rupiah', 14, 2)->default(0)->after('koin_ditahan');
        });

        Schema::dropIfExists('topups');
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('saldo_rupiah');
        });
    }
};
