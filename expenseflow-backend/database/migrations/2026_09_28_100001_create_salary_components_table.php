<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master komponen gaji per perusahaan (tunjangan / potongan).
     * Menjadi katalog komponen yang bisa dipasang ke karyawan atau
     * dihitung otomatis saat proses payroll.
     */
    public function up(): void
    {
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 120);
            $table->enum('type', ['earning', 'deduction']);
            // fixed = nominal tetap per karyawan (employee_salary_components)
            // manual = diisi tangan saat proses payroll
            // auto = dihitung dari integrasi (presensi/lembur/struk/kasbon)
            $table->enum('calc_type', ['fixed', 'manual', 'auto'])->default('fixed');
            $table->string('category', 50)->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_components');
    }
};
