<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perluas ENUM transaksi_koin.sumber dengan nilai 'konversi_saldo' —
 * dipakai alur tukar koin langsung ke saldo dompet (settlement tanpa
 * antre admin). Nilai lama tidak tersentuh.
 *
 * MySQL memakai ALTER ... MODIFY; SQLite (dipakai test suite) tidak
 * mengenal sintaks itu, jadi tabel dibangun ulang lewat change().
 */
return new class extends Migration
{
    /** Nilai enum setelah perluasan (urutan lama dipertahankan). */
    private const SEMUA_NILAI = [
        'setor_sampah',
        'daily_login',
        'quiz',
        'mission',
        'reward_claim',
        'refund',
        'topup',
        'konversi_saldo',
    ];

    /** Nilai enum sebelum perluasan. */
    private const NILAI_LAMA = [
        'setor_sampah',
        'daily_login',
        'quiz',
        'mission',
        'reward_claim',
        'refund',
        'topup',
    ];

    public function up(): void
    {
        $this->ubahEnum(self::SEMUA_NILAI);
    }

    public function down(): void
    {
        $this->ubahEnum(self::NILAI_LAMA);
    }

    private function ubahEnum(array $nilai): void
    {
        if (DB::getDriverName() === 'mysql') {
            $daftar = implode(',', array_map(fn (string $v) => "'".$v."'", $nilai));

            DB::statement("ALTER TABLE transaksi_koin MODIFY sumber ENUM({$daftar}) NOT NULL");

            return;
        }

        Schema::table('transaksi_koin', function (Blueprint $table) use ($nilai) {
            $table->enum('sumber', $nilai)->change();
        });
    }
};
