<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNAPSHOT PENGATURAN FLEXITIME SAAT CHECK-IN (2026-09-16)
 *
 * Membekukan aturan jam kerja fleksibel (flexitime) yang berlaku saat karyawan check-in:
 * - Status hak akses flexitime karyawan (snap_flexitime_enabled)
 * - Window kedatangan kantor (snap_flex_arrival_start, snap_flex_arrival_end)
 * - Jam inti kantor (snap_flex_core_start, snap_flex_core_end)
 * - Target durasi kerja harian kantor (snap_flex_target_minutes)
 *
 * Sehingga jika HRD mengubah aturan jam kantor atau toggle flexitime karyawan di tengah hari,
 * sesi presensi yang sedang aktif hari itu 100% terlindungi dan tidak terjadi bug perhitungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('snap_flexitime_enabled')->nullable()->default(false)->after('snap_grace_minutes');
            $table->time('snap_flex_arrival_start')->nullable()->after('snap_flexitime_enabled');
            $table->time('snap_flex_arrival_end')->nullable()->after('snap_flex_arrival_start');
            $table->time('snap_flex_core_start')->nullable()->after('snap_flex_arrival_end');
            $table->time('snap_flex_core_end')->nullable()->after('snap_flex_core_start');
            $table->integer('snap_flex_target_minutes')->nullable()->after('snap_flex_core_end');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'snap_flexitime_enabled',
                'snap_flex_arrival_start',
                'snap_flex_arrival_end',
                'snap_flex_core_start',
                'snap_flex_core_end',
                'snap_flex_target_minutes',
            ]);
        });
    }
};
