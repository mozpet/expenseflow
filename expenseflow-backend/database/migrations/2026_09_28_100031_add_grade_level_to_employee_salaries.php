<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Struktur & Skala Upah (Fase 6) — tautan penempatan golongan pada riwayat gaji.
 *
 * Golongan & jenjang disimpan PADA BARIS GAJI (bukan pada users) agar bersifat
 * ber-riwayat: promosi/penyesuaian golongan tercatat efektif per tanggal, sama
 * seperti nominal gaji pokok. Keduanya nullable (nullOnDelete) → data lama aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->foreignId('salary_grade_id')->nullable()->after('user_id')
                ->constrained('salary_grades')->nullOnDelete();
            $table->foreignId('job_level_id')->nullable()->after('salary_grade_id')
                ->constrained('job_levels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_level_id');
            $table->dropConstrainedForeignId('salary_grade_id');
        });
    }
};
