<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penggajian Multi-Mata Uang (Fase 6) — master KURS bertanggal-efektif.
 *
 * Kurs dipilih dengan pola "berlaku pada tanggal" (seperti StatutoryRuleVersion):
 * baris dengan `effective_date` terbesar yang <= tanggal periode. Sumber kurs
 * (`source`) dicatat untuk audit — untuk PPh 21/26 seharusnya Kurs Menteri
 * Keuangan (KMK) yang berlaku pada saat pemotongan pajak.
 *
 * `company_id` NULL = kurs global (berlaku untuk semua perusahaan); baris
 * ber-company menang atas baris global pada tanggal yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('currency', 3);                 // ISO 4217, mis. USD
            $table->decimal('rate_to_idr', 15, 6);         // 1 unit valuta = N Rupiah
            $table->date('effective_date');
            $table->string('source', 60)->nullable();      // mis. "KMK", "BI Tengah"
            $table->timestamps();

            $table->unique(['company_id', 'currency', 'effective_date'], 'currency_rates_scope_unique');
            $table->index(['currency', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
