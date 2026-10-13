<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DENDA & BONUS PADA BARIS PRESENSI (2026-10-11)
 *
 * Tiga dari enam kebijakan baru bersifat PER-KEJADIAN PRESENSI, jadi mengikuti
 * doktrin snapshot yang sama dengan denda keterlambatan:
 *
 *   early_leave_minutes / early_leave_fine_amount
 *       Menit & denda pulang cepat, dihitung saat check-out (dan saat koreksi
 *       manual HRD) memakai snap_early_leave_* — bukan setting live. Menit tetap
 *       disimpan meski modenya 'prorata' atau 'none', karena payroll memakai
 *       menitnya untuk memotong prorata jam kerja efektif.
 *
 *   missed_checkout_fine_amount
 *       Denda administrasi lupa check-out, diisi AutoCheckoutCommand saat sistem
 *       menutup presensi secara otomatis.
 *
 *   night_shift_bonus_amount
 *       Premi shift malam, dibekukan saat check-in untuk sesi yang jam mulainya
 *       >= 22:00 atau shiftnya bertanda lintas hari (cross-day).
 *
 * Tiga kebijakan lainnya (alpha, izin pribadi, insentif kehadiran penuh) bersifat
 * PER-PERIODE — dihitung PayrollCalculator dari konfigurasi kantor, dan dibekukan
 * oleh batch payroll itu sendiri (payslip approved/paid tidak bisa diubah).
 *
 * SENGAJA TANPA BACKFILL — NULL = baris sebelum fitur, tanpa denda/bonus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('early_leave_minutes')->nullable();
            $table->decimal('early_leave_fine_amount', 12, 2)->nullable();
            $table->decimal('missed_checkout_fine_amount', 12, 2)->nullable();
            $table->decimal('night_shift_bonus_amount', 12, 2)->nullable();

            $table->string('snap_early_leave_fine_mode', 20)->nullable();
            $table->decimal('snap_early_leave_fine_amount', 12, 2)->nullable();
            $table->decimal('snap_early_leave_fine_max', 12, 2)->nullable();
            $table->string('snap_missed_checkout_penalty_mode', 25)->nullable();
            $table->decimal('snap_missed_checkout_fine_amount', 12, 2)->nullable();
            $table->boolean('snap_night_shift_bonus_enabled')->nullable();
            $table->decimal('snap_night_shift_bonus_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'early_leave_minutes',
                'early_leave_fine_amount',
                'missed_checkout_fine_amount',
                'night_shift_bonus_amount',
                'snap_early_leave_fine_mode',
                'snap_early_leave_fine_amount',
                'snap_early_leave_fine_max',
                'snap_missed_checkout_penalty_mode',
                'snap_missed_checkout_fine_amount',
                'snap_night_shift_bonus_enabled',
                'snap_night_shift_bonus_amount',
            ]);
        });
    }
};
