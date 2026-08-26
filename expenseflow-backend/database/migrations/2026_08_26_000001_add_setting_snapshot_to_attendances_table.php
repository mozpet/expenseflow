<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNAPSHOT PENGATURAN KANTOR SAAT CHECK-IN (2026-08-26)
 *
 * Masalah: HRD yang mengubah jam kerja/radius/toleransi/auto-checkout di tengah hari
 * langsung mengubah perhitungan presensi karyawan yang SUDAH check-in pagi hari itu
 * (mendadak dianggap telat, lembur kacau, auto-checkout menutup presensi lebih awal,
 * checkout ditolak out-of-radius).
 *
 * Solusi (standar industri ERP): saat check-in, salin (snapshot) aturan yang berlaku
 * ke baris attendances. Perhitungan check-out, lembur, early-leave, reminder,
 * auto-checkout & validasi radius checkout MEMBACA SNAPSHOT — bukan attendance_settings.
 * Perubahan setting hanya berlaku bagi karyawan yang BELUM check-in dan seluruh
 * presensi esok hari.
 *
 * Kolom snap_* nullable → baris lama (sebelum fitur) otomatis memakai perilaku lama
 * (baca attendance_settings secara live). Tidak perlu backfill data historis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Kantor acuan saat check-in (untuk validasi radius checkout)
            $table->unsignedBigInteger('snap_office_id')->nullable()->after('is_auto_checkout');
            $table->decimal('snap_office_latitude', 10, 8)->nullable();
            $table->decimal('snap_office_longitude', 11, 8)->nullable();
            $table->integer('snap_radius_meters')->nullable();

            // Jadwal efektif saat check-in (hasil resolveSchedule: shift ATAU default kantor)
            $table->string('snap_source', 10)->nullable();          // 'shift' | 'office'
            $table->time('snap_work_start_time')->nullable();        // NULL = hari libur shift
            $table->time('snap_work_end_time')->nullable();          // sudah termasuk fallback jam pulang kantor di hari libur shift
            $table->boolean('snap_is_off')->default(false);
            $table->boolean('snap_is_cross_day')->default(false);

            // Aturan lembur, pulang awal & auto-checkout saat check-in
            $table->boolean('snap_overtime_enabled')->nullable();
            $table->integer('snap_min_overtime_minutes')->nullable();
            $table->integer('snap_early_leave_tolerance_minutes')->nullable();
            $table->integer('snap_reminder_minutes')->nullable();
            $table->integer('snap_grace_minutes')->nullable();

            $table->index('snap_office_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'snap_office_id',
                'snap_office_latitude',
                'snap_office_longitude',
                'snap_radius_meters',
                'snap_source',
                'snap_work_start_time',
                'snap_work_end_time',
                'snap_is_off',
                'snap_is_cross_day',
                'snap_overtime_enabled',
                'snap_min_overtime_minutes',
                'snap_early_leave_tolerance_minutes',
                'snap_reminder_minutes',
                'snap_grace_minutes',
            ]);
        });
    }
};
