<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // true  → karyawan kerja lapangan, presensi mobile wajib dalam radius kantor/lokasi kerja
            // false → karyawan WFH bebas, presensi mobile tanpa cek lokasi (default)
            $table->boolean('radius_enabled')->default(false)->after('wfh_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('radius_enabled');
        });
    }
};
