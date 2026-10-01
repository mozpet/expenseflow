<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Struktur & Skala Upah (Fase 6) — master GOLONGAN UPAH (salary grades).
 *
 * Tiap golongan menyimpan rentang upah min–mid–maks. Validasi penempatan gaji
 * pokok karyawan (EmployeeSalaryController) memeriksa nominal berada dalam
 * rentang golongan yang dipilih (Permenaker 1/2017 — struktur & skala upah).
 *
 * `job_level_id` opsional (nullOnDelete) agar golongan tetap hidup walau
 * jenjang jabatan dihapus — FK finansial tidak memakai cascade destruktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_level_id')->nullable()->constrained('job_levels')->nullOnDelete();
            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            $table->decimal('min_salary', 15, 2)->default(0);
            $table->decimal('mid_salary', 15, 2)->nullable();
            $table->decimal('max_salary', 15, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'salary_grades_company_name_unique');
            $table->unique(['company_id', 'code'], 'salary_grades_company_code_unique');
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'job_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_grades');
    }
};
