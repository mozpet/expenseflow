<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kolom mandiri ke shift_pattern_items agar setiap hari siklus
     * memiliki nama, warna, toleransi telat, dan status WFH sendiri
     * tanpa bergantung pada tabel shifts (template shift).
     *
     * Template shift (shift_id) tetap dipertahankan sebagai referensi asal preset,
     * namun bukan lagi sumber utama data jadwal.
     */
    public function up(): void
    {
        Schema::table('shift_pattern_items', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('shift_id')
                ->comment('Nama shift mandiri hari ini (misal: Dinas Pagi). Null = fallback ke shift->name atau PatternName·H{N}');
            $table->string('color', 7)->nullable()->after('name')
                ->comment('Warna hex badge (#6366f1). Null = fallback ke shift->color atau #6366f1');
            $table->unsignedSmallInteger('late_tolerance_minutes')->nullable()->after('break_minutes')
                ->comment('Toleransi telat khusus (menit). Null = fallback ke office default');
            $table->boolean('is_wfh')->default(false)->after('is_cross_day')
                ->comment('Apakah hari siklus ini WFH');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_pattern_items', function (Blueprint $table) {
            $table->dropColumn(['name', 'color', 'late_tolerance_minutes', 'is_wfh']);
        });
    }
};
