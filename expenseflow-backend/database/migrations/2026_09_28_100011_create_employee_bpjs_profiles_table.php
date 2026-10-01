<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil kepesertaan BPJS per karyawan (Fase 2).
     *
     * Menentukan apakah karyawan ikut BPJS Kesehatan & Ketenagakerjaan serta
     * kelas risiko JKK (menentukan tarif JKK 0,24%–1,74%). Nomor kepesertaan
     * disimpan terenkripsi (AES) & hanya ditampilkan termasking.
     *
     * Satu profil per karyawan. Bila karyawan TIDAK punya profil → dianggap
     * belum terdaftar BPJS (tidak ada iuran otomatis) — perilaku aman.
     */
    public function up(): void
    {
        Schema::create('employee_bpjs_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Data pribadi karyawan → jangan sampai hilang saat user dihapus.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Nomor kepesertaan (terenkripsi di DB).
            $table->text('bpjs_kes_no')->nullable();
            $table->text('bpjs_tk_no')->nullable();

            // Status kepesertaan program.
            $table->boolean('has_bpjs_kes')->default(true);
            $table->boolean('has_bpjs_tk')->default(true);
            // Kepesertaan JKP (PP 37/2021 & PP 6/2025). Tidak menambah potongan karyawan.
            $table->boolean('has_jkp')->default(true);

            // Kelas risiko JKK: 1=sangat rendah … 5=sangat tinggi (menentukan tarif JKK).
            $table->unsignedTinyInteger('jkk_risk_class')->default(1);

            // Opsi: upah dibayarkan perusahaan penuh (untuk BPJS Kes) — MVP tidak dipakai.
            $table->timestamps();

            $table->unique('user_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bpjs_profiles');
    }
};
