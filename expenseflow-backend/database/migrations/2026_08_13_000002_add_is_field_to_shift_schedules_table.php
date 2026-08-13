<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mode Lapangan per hari pada template shift.
     * true -> presensi mobile dari lapangan (wajib dalam radius lokasi kantor terdekat).
     * Hanya berlaku jika is_wfh = true.
     */
    public function up(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->boolean('is_field')->default(false)->after('is_wfh');
        });
    }

    public function down(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->dropColumn('is_field');
        });
    }
};
