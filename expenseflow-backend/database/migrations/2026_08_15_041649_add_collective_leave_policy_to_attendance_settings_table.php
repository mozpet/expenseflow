<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // Kebijakan saldo cuti saat karyawan ikut Cuti Bersama:
            //   'block' (default) → tolak jika saldo habis
            //   'debt'            → izinkan meski saldo habis, saldo bisa negatif
            //   'free'            → cuti bersama tidak memotong saldo sama sekali
            $table->string('collective_leave_policy')->default('block')->after('max_weekly_hours');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('collective_leave_policy');
        });
    }
};
