<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batas waktu presensi telat (menit setelah jam masuk).
     * Jika NULL → presensi tetap bisa dilakukan kapan saja.
     * Contoh: 120 → karyawan hanya bisa presensi masuk sampai 2 jam setelah jam masuk.
     */
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_checkin_cutoff_minutes')
                ->nullable()
                ->after('late_tolerance_minutes')
                ->comment('Maks menit telat yang masih diizinkan presensi. NULL = tidak ada batas.');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('late_checkin_cutoff_minutes');
        });
    }
};
