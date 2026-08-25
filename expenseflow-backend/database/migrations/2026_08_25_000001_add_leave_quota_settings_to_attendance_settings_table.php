<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // Kuota cuti default kantor — menggantikan hardcoded 12 hari/tahun.
            // Dipakai saat LeaveBalance dibuat pertama kali & saat reset tahunan.
            $table->unsignedTinyInteger('default_leave_quota')->default(12)->after('shift_notice_days');
            // Tanggal reset tahunan saldo cuti — TANPA TAHUN (anniversary, ulang tiap tahun).
            // Format 'MM-DD', mis. '01-01' = setiap 1 Januari. NULL = reset otomatis mati.
            $table->string('leave_reset_date', 5)->nullable()->after('default_leave_quota');
            // Kapan terakhir reset dijalankan (mencegah dobel-reset & catch-up bila server mati).
            $table->date('last_leave_reset_on')->nullable()->after('leave_reset_date');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['default_leave_quota', 'leave_reset_date', 'last_leave_reset_on']);
        });
    }
};
