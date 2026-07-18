<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // Toggle: apakah batas jam kerja per minggu diberlakukan?
            // false (default) = tidak ada batas → fleksibel untuk perusahaan yang bayar lembur
            // true = template shift ditolak jika total jam melebihi max_weekly_hours
            $table->boolean('enforce_weekly_hours')->default(false)->after('auto_checkout_grace_minutes');

            // Batas jam kerja per minggu (hanya berlaku jika enforce_weekly_hours=true)
            // Default 40 jam sesuai UU No. 13/2003 Pasal 77
            // Range: 40–168 jam (168 = 24 jam × 7 hari, batas absolut)
            $table->unsignedSmallInteger('max_weekly_hours')->default(40)->nullable()->after('enforce_weekly_hours');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['enforce_weekly_hours', 'max_weekly_hours']);
        });
    }
};
