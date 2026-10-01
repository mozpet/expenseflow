<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Struktur & Skala Upah (Fase 6) — master JENJANG JABATAN (job levels).
 *
 * Jenjang jabatan bersifat ordinal: `rank` kecil = jenjang lebih rendah
 * (mis. 1 = Staff, 9 = Direktur). Dipakai sebagai payung bagi salary_grades
 * sehingga rentang upah dapat disusun berjenjang.
 *
 * Non-destruktif: tabel baru, tidak menyentuh tabel lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            $table->unsignedSmallInteger('rank')->default(1); // urutan jenjang (kecil = rendah)
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'job_levels_company_name_unique');
            $table->unique(['company_id', 'code'], 'job_levels_company_code_unique');
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_levels');
    }
};
