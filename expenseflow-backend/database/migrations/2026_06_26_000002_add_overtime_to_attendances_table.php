<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Total menit kerja (check_in → check_out), diisi saat check-out.
            $table->integer('work_minutes')->nullable()->after('status');
            // Menit lembur, dihitung otomatis saat check-out (0 jika tidak ada).
            $table->integer('overtime_minutes')->default(0)->after('work_minutes');
            // Tanda hari libur/weekend (seluruh jam kerja dihitung lembur bila true).
            $table->boolean('is_holiday')->default(false)->after('overtime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['work_minutes', 'overtime_minutes', 'is_holiday']);
        });
    }
};
