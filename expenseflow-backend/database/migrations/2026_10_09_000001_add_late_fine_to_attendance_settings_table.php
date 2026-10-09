<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DENDA KETERLAMBATAN PER KANTOR CABANG (2026-10-09)
 *
 * late_fine_per_minute — rupiah denda per menit keterlambatan. NULL atau 0 = tidak
 *   ada denda (perilaku lama; kantor existing tidak berubah).
 *
 * late_count_mode — dari mana menit telat mulai dihitung:
 *   'after_tolerance'  : hanya kelebihan di atas toleransi (08:16, toleransi 15 → 1 menit)
 *   'after_work_start' : selama masih dalam toleransi TIDAK dicatat telat sama sekali;
 *                        begitu toleransi terlewati, telat dihitung dari jam masuk
 *                        (08:16 → 16 menit).
 *
 * late_fine_max_per_day — batas atas denda per hari (rupiah). NULL = tanpa batas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->decimal('late_fine_per_minute', 12, 2)->nullable()->after('late_tolerance_minutes');
            $table->string('late_count_mode', 20)->default('after_tolerance')->after('late_fine_per_minute');
            $table->decimal('late_fine_max_per_day', 12, 2)->nullable()->after('late_count_mode');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['late_fine_per_minute', 'late_count_mode', 'late_fine_max_per_day']);
        });
    }
};
