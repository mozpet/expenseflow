<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan akun Jurnal Akuntansi / General Ledger per-perusahaan — Fase 4 (lanjutan).
 *
 * Satu baris = override kode/nama akun untuk sebuah POS jurnal (bucket) kanonik
 * milik satu perusahaan. Himpunan pos kanonik + default-nya didefinisikan di
 * `config/payroll_gl.php`; tabel ini HANYA menyimpan override (yang berbeda dari
 * default). Bila sebuah perusahaan belum punya baris untuk suatu pos, composer
 * memakai kode/nama default dari config → ekspor GL selalu jalan out-of-the-box.
 *
 * `key` WAJIB salah satu key bucket kanonik di config (divalidasi di controller).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_gl_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key', 60);           // key bucket kanonik (config/payroll_gl.php)
            $table->string('account_code', 40);  // kode akun COA (override)
            $table->string('account_name', 150); // nama akun COA (override)
            $table->timestamps();

            // Satu override per (perusahaan, pos jurnal).
            $table->unique(['company_id', 'key']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_gl_accounts');
    }
};
