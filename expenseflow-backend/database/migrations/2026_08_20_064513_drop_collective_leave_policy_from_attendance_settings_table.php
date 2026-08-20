<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Keputusan 2026-08-20: kebijakan saldo cuti bersama di-hardcode menjadi 'block'.
        // Kolom ini tidak dipakai lagi → dihapus agar skema bersih.
        Schema::table('attendance_settings', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_settings', 'collective_leave_policy')) {
                $table->dropColumn('collective_leave_policy');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->string('collective_leave_policy')->default('block')->after('max_weekly_hours');
        });
    }
};
