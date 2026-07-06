<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * wfh_enabled: di-toggle HRD dari web.
     * true  → karyawan mode WFH, presensi dari rumah (tanpa cek lokasi kantor).
     * false → karyawan mode kantor, presensi wajib di radius kantor.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wfh_enabled')->default(false)->after('attendance_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wfh_enabled');
        });
    }
};
