<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grup Payroll (grouping + scoping MVP) — item lanjutan Payroll.
 *
 * Wadah pengelompokan karyawan untuk penjadwalan batch payroll (mis. "Staf Bulanan",
 * "Harian", "Direksi"). Keanggotaan MVP disimpan di kolom nullable `users.payroll_group_id`
 * (lihat migrasi 100026); sebuah batch (`payrolls.payroll_group_id`) opsional membatasi
 * kalkulasi hanya ke anggota grup tsb. NULL grup = perilaku lama (seluruh perusahaan),
 * sehingga sepenuhnya backward-compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unik per-perusahaan (nama & kode) — NULL kode tidak dibatasi (MySQL NULL distinct).
            $table->unique(['company_id', 'name'], 'payroll_groups_company_name_unique');
            $table->unique(['company_id', 'code'], 'payroll_groups_company_code_unique');
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_groups');
    }
};
