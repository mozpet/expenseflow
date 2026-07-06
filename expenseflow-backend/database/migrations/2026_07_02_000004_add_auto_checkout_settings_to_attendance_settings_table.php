<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // Berapa menit setelah work_end_time baru reminder dikirim ke karyawan (default 30 menit)
            $table->integer('checkout_reminder_minutes')->default(30)->after('min_overtime_minutes');
            // Berapa menit setelah work_end_time sistem otomatis checkout karyawan (default 60 menit)
            $table->integer('auto_checkout_grace_minutes')->default(60)->after('checkout_reminder_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['checkout_reminder_minutes', 'auto_checkout_grace_minutes']);
        });
    }
};
