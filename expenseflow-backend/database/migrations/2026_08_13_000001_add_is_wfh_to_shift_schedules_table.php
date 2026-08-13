<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mode WFH per hari pada template shift.
     * true → hari kerja ini boleh presensi mobile dari rumah tanpa validasi radius.
     */
    public function up(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->boolean('is_wfh')->default(false)->after('is_off');
        });
    }

    public function down(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->dropColumn('is_wfh');
        });
    }
};
