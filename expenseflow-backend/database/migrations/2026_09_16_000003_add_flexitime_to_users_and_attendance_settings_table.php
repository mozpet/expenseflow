<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('flexitime_enabled')->default(false)->after('dinas_luar_enabled');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->time('flex_arrival_start')->nullable()->default('07:00:00')->after('work_end_time');
            $table->time('flex_arrival_end')->nullable()->default('10:00:00')->after('flex_arrival_start');
            $table->time('flex_core_start')->nullable()->default('10:00:00')->after('flex_arrival_end');
            $table->time('flex_core_end')->nullable()->default('15:00:00')->after('flex_core_start');
            $table->integer('flex_target_minutes')->nullable()->default(480)->after('flex_core_end');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('flexitime_enabled');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'flex_arrival_start',
                'flex_arrival_end',
                'flex_core_start',
                'flex_core_end',
                'flex_target_minutes',
            ]);
        });
    }
};
