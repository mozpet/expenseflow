<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // Menit sebelum work_start_time yang menjadi batas awal presensi WFH.
            // NULL = tidak ada pembatasan (bebas kapan saja).
            // Default 120 = karyawan WFH boleh presensi 2 jam sebelum jam masuk.
            $table->integer('wfh_checkin_window_minutes')->nullable()->default(120)->after('allow_wfh');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('wfh_checkin_window_minutes');
        });
    }
};
