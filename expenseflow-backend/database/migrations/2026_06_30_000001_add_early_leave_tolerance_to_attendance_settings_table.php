<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // NULL = fitur early leave dimatikan.
            // Integer N = karyawan dianggap pulang awal jika check-out
            // lebih dari N menit SEBELUM work_end_time (default 30 menit).
            $table->unsignedSmallInteger('early_leave_tolerance_minutes')
                ->nullable()
                ->default(30)
                ->after('min_overtime_minutes')
                ->comment('Menit toleransi sebelum jam pulang. NULL = fitur nonaktif.');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('early_leave_tolerance_minutes');
        });
    }
};
