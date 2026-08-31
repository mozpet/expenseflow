<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambah kolom min_checkout_interval_minutes di attendance_settings
        if (Schema::hasTable('attendance_settings')) {
            Schema::table('attendance_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('attendance_settings', 'min_checkout_interval_minutes')) {
                    $table->unsignedSmallInteger('min_checkout_interval_minutes')
                        ->nullable()
                        ->default(10)
                        ->after('early_leave_tolerance_minutes')
                        ->comment('Jeda minimal menit sebelum diperbolehkan checkout setelah checkin');
                }
            });
        }

        // 2. Tambah kolom snap_min_checkout_interval_minutes di attendances
        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                if (! Schema::hasColumn('attendances', 'snap_min_checkout_interval_minutes')) {
                    $table->unsignedSmallInteger('snap_min_checkout_interval_minutes')
                        ->nullable()
                        ->after('snap_early_leave_tolerance_minutes')
                        ->comment('Snapshot min_checkout_interval_minutes saat checkin');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('attendance_settings')) {
            Schema::table('attendance_settings', function (Blueprint $table) {
                if (Schema::hasColumn('attendance_settings', 'min_checkout_interval_minutes')) {
                    $table->dropColumn('min_checkout_interval_minutes');
                }
            });
        }

        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                if (Schema::hasColumn('attendances', 'snap_min_checkout_interval_minutes')) {
                    $table->dropColumn('snap_min_checkout_interval_minutes');
                }
            });
        }
    }
};
