<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konsolidasi masa pajak PPh21 per karyawan per bulan (Fase 2 — roadmap §10.E).
     *
     * Menyimpan akumulasi bruto kena pajak & PPh21 terpotong tiap masa, sebagai
     * sumber tunggal untuk rekonsiliasi tahunan (Desember / masa terakhir) &
     * pembuatan Bukti Potong 1721-A1 (Fase 4). Di-upsert setiap kali batch
     * dihitung (idempoten per user+tahun+bulan).
     */
    public function up(): void
    {
        Schema::create('employee_tax_period_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Data pajak karyawan → restrict agar riwayat tidak hilang.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->unsignedTinyInteger('tax_month');

            // Bruto teratur (gaji rutin) vs tidak teratur (THR/bonus) vs natura kena pajak.
            $table->decimal('regular_gross', 15, 2)->default(0);
            $table->decimal('irregular_gross', 15, 2)->default(0);       // THR / Bonus (Fase 6)
            $table->decimal('taxable_benefit_gross', 15, 2)->default(0); // Natura PMK 66 (Fase 6)
            $table->decimal('total_taxable_gross', 15, 2)->default(0);
            $table->decimal('total_pph21_withheld', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'tax_year', 'tax_month'], 'tax_period_user_unique');
            $table->index(['company_id', 'tax_year', 'tax_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tax_period_totals');
    }
};
