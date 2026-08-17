<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            // NULL = berlaku untuk semua cabang kantor dalam perusahaan.
            // Terisi attendance_setting_id = khusus libur/cuti bersama cabang kantor tersebut.
            $table->foreignId('attendance_setting_id')
                ->nullable()
                ->after('company_id')
                ->constrained('attendance_settings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropForeign(['attendance_setting_id']);
            $table->dropColumn('attendance_setting_id');
        });
    }
};
