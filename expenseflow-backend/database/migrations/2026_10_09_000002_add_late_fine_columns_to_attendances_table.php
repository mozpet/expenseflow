<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KETERLAMBATAN & DENDA PADA BARIS PRESENSI (2026-10-09)
 *
 * late_minutes / late_fine_amount — hasil perhitungan yang DIPERSISTEN, independen
 *   dari kolom status (checkOut() menimpa 'late' → 'early_leave' bila pulang cepat,
 *   tetapi karyawan tetap telat dan dendanya tetap berlaku).
 *
 * snap_late_* — konfigurasi denda yang BERLAKU SAAT CHECK-IN (doktrin snapshot):
 *   HRD yang mengubah tarif/mode siang hari tidak menggeser baris yang sudah jalan.
 *   SENGAJA TANPA BACKFILL — NULL = baris sebelum fitur, tanpa denda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('late_minutes')->nullable();
            $table->decimal('late_fine_amount', 12, 2)->nullable();
            $table->decimal('snap_late_fine_per_minute', 12, 2)->nullable();
            $table->string('snap_late_count_mode', 20)->nullable();
            $table->decimal('snap_late_fine_max_per_day', 12, 2)->nullable();
            $table->unsignedSmallInteger('snap_late_tolerance_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'late_minutes',
                'late_fine_amount',
                'snap_late_fine_per_minute',
                'snap_late_count_mode',
                'snap_late_fine_max_per_day',
                'snap_late_tolerance_minutes',
            ]);
        });
    }
};
