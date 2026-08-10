<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioning jadwal shift.
     *
     * Sebelumnya `shift_schedules` punya unique(shift_id, day_of_week) — satu shift
     * hanya punya SATU jadwal per hari. Saat HRD mengedit jam kerja shift yang sedang
     * dipakai karyawan, perubahan langsung menimpa jadwal lama → mengubah jadwal
     * karyawan yang sedang aktif (bahkan di tengah jam kerja) → bug.
     *
     * Solusi: tambah kolom `effective_date`. Setiap perubahan jam kerja membuat
     * BARIS BARU (versi) dengan effective_date = hari ini + N hari (mengikuti
     * setting "Minimum Notice Perubahan Shift" per kantor). Query memilih versi
     * dengan effective_date <= tanggal yang ditanyakan.
     *
     * Data lama di-backfill: effective_date = 1970-01-01 (paling awal) agar tetap
     * menjadi versi yang terpilih sampai ada versi baru yang lebih baru.
     */
    public function up(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->date('effective_date')->nullable()->after('shift_id');
        });

        // Backfill data lama ke tanggal paling awal (berlaku sejak dahulu kala)
        DB::table('shift_schedules')->update(['effective_date' => '1970-01-01']);

        // Set NOT NULL setelah backfill
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->date('effective_date')->nullable(false)->default('1970-01-01')->change();
        });

        // Ganti unique constraint: boleh punya banyak versi per (shift, hari),
        // tapi tidak boleh dua versi dengan tanggal efektif yang sama.
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->dropUnique(['shift_id', 'day_of_week']);
            $table->unique(['shift_id', 'day_of_week', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table) {
            // Hapus versi selain versi efektif pertama (paling awal) sebelum drop kolom
            DB::statement('DELETE s1 FROM shift_schedules s1
                INNER JOIN shift_schedules s2 ON s2.shift_id = s1.shift_id
                    AND s2.day_of_week = s1.day_of_week
                    AND s2.effective_date > s1.effective_date');

            $table->dropUnique(['shift_id', 'day_of_week', 'effective_date']);
            $table->unique(['shift_id', 'day_of_week']);
            $table->dropColumn('effective_date');
        });
    }
};
