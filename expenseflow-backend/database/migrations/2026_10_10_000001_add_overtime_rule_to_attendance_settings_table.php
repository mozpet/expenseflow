<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATURAN UPAH LEMBUR PER KANTOR CABANG (2026-10-10)
 *
 * overtime_rule — 'government' (PP 35/2021, perilaku lama; default) atau 'custom'
 *   (HRD menentukan nominal sendiri).
 * overtime_rate_amount / overtime_rate_unit — nominal rupiah per 'hour' atau 'minute'
 *   lembur yang disetujui. Dipakai hanya bila overtime_rule = 'custom'.
 * overtime_rate_taxable — apakah bonus lembur aturan sendiri masuk dasar bruto PPh21.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->string('overtime_rule', 20)->default('government');
            $table->decimal('overtime_rate_amount', 12, 2)->nullable();
            $table->string('overtime_rate_unit', 10)->default('hour');
            $table->boolean('overtime_rate_taxable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['overtime_rule', 'overtime_rate_amount', 'overtime_rate_unit', 'overtime_rate_taxable']);
        });
    }
};
