<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil pajak karyawan (satu per karyawan).
     * NPWP disimpan terenkripsi (cast 'encrypted' di model) & dimasking di response.
     */
    public function up(): void
    {
        Schema::create('employee_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            // NPWP terenkripsi → panjang teks (ciphertext) jauh lebih besar dari 16 digit.
            $table->text('npwp')->nullable();
            $table->boolean('has_npwp')->default(false);
            // Status PTKP: TK/0..TK/3, K/0..K/3 (MVP). Menentukan kategori TER A/B/C.
            $table->string('ptkp_status', 8)->default('TK/0');
            // gross = pajak ditanggung karyawan (potongan); gross_up = ditanggung perusahaan (tunjangan).
            $table->enum('tax_method', ['gross', 'gross_up'])->default('gross');
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tax_profiles');
    }
};
