<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 2 — persiapan BPJS.
     *
     * Melebarkan dua kolom enum menjadi string agar tipe aturan & asal baris
     * slip bisa bertambah tanpa migrasi enum berulang (mis. BPJS: 'bpjs_kes',
     * 'bpjs_tk', dan sumber baris 'bpjs'). Validasi nilai yang diizinkan
     * dipindahkan ke level aplikasi (konstanta model), bukan enum DB.
     *
     * Alasan: enum DB sulit di-ALTER lintas driver (MySQL vs SQLite). String +
     * validasi aplikasi lebih fleksibel untuk roadmap yang terus menambah tipe
     * statutory (minimum_wage, natura, dll).
     */
    public function up(): void
    {
        // Tipe aturan statutory: ptkp | pph21_ter | pasal17 | bpjs_kes | bpjs_tk | ...
        Schema::table('statutory_rule_versions', function (Blueprint $table) {
            $table->string('type', 30)->change();
        });

        // Asal baris slip: basic | fixed | manual | attendance | overtime | receipt | loan | tax | bpjs
        Schema::table('payslip_items', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('statutory_rule_versions', function (Blueprint $table) {
            $table->enum('type', ['ptkp', 'pph21_ter', 'pasal17'])->change();
        });

        Schema::table('payslip_items', function (Blueprint $table) {
            $table->enum('source', ['basic', 'fixed', 'manual', 'attendance', 'overtime', 'receipt', 'loan', 'tax'])
                ->default('manual')->change();
        });
    }
};
