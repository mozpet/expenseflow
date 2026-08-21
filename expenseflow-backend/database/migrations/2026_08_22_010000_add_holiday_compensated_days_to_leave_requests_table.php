<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom untuk melacak berapa hari saldo cuti yang DIKEMBALIKAN
     * akibat penambahan hari libur (nasional/cabang) yang overlap dengan
     * cuti approved karyawan. Nilai ini dipakai untuk memotong kembali
     * saldo saat libur tersebut dihapus.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->integer('holiday_compensated_days')->default(0)->after('total_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('holiday_compensated_days');
        });
    }
};
