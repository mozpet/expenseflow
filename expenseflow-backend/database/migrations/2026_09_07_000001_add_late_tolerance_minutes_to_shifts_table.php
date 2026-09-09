<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Menambahkan kolom late_tolerance_minutes opsional pada tabel shifts (Item 14 Kategori D).
     * Jika bernilai NULL, maka jadwal kehadiran akan otomatis fallback ke late_tolerance_minutes
     * pada level kantor/cabang (attendance_settings).
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_tolerance_minutes')
                ->nullable()
                ->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('late_tolerance_minutes');
        });
    }
};
