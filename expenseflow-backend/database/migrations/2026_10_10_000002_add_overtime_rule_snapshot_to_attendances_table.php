<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNAPSHOT ATURAN LEMBUR SAAT CHECK-IN (2026-10-10)
 *
 * Aturan upah lembur kantor membeku di baris presensi. Perubahan tarif/aturan kantor
 * atau perpindahan karyawan antar kantor tidak menggeser lembur yang sudah terjadi.
 * SENGAJA TANPA BACKFILL — NULL = aturan pemerintah (PP 35/2021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('snap_overtime_rule', 20)->nullable();
            $table->decimal('snap_overtime_rate_amount', 12, 2)->nullable();
            $table->string('snap_overtime_rate_unit', 10)->nullable();
            $table->boolean('snap_overtime_rate_taxable')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'snap_overtime_rule',
                'snap_overtime_rate_amount',
                'snap_overtime_rate_unit',
                'snap_overtime_rate_taxable',
            ]);
        });
    }
};
