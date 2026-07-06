<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // Aktifkan perhitungan lembur otomatis saat check-out.
            $table->boolean('overtime_enabled')->default(true)->after('wfh_checkin_window_minutes');
            // Ambang minimal menit lewat jam pulang agar dihitung lembur (default 30 menit).
            $table->integer('min_overtime_minutes')->default(30)->after('overtime_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['overtime_enabled', 'min_overtime_minutes']);
        });
    }
};
