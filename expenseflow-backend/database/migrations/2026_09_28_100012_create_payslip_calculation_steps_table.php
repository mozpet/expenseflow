<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit langkah perhitungan matematis payslip (calculation trace).
     * Fase 2 — roadmap §10.D.
     *
     * Setiap tahapan (BPJS_KES, PPH21_TER, NETT, dll) dicatat beserta payload
     * input, hasil mentah, pembulatan, & referensi aturan → transparansi penuh
     * bagaimana angka slip terbentuk (untuk audit & sengketa).
     *
     * Milik payslip → cascade saat slip dihapus/dihitung ulang (idempoten),
     * mengikuti pola payslip_items.
     */
    public function up(): void
    {
        Schema::create('payslip_calculation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            // Kode tahap: PRORATE, OVERTIME, BPJS_KES, BPJS_JHT, PPH21_TER, PPH21_PASAL17, NETT, dll.
            $table->string('step_code', 50);
            $table->unsignedSmallInteger('step_sequence')->default(0);
            // Versi/nama aturan yang dipakai (mis. "TER Bulanan 2024 (PMK 168/2023)").
            $table->string('formula_version', 80)->nullable();
            // Input mentah tahap ini (basis upah, tarif, cap, dll) untuk audit.
            $table->json('input_payload')->nullable();
            $table->decimal('raw_result', 15, 2)->default(0);
            $table->decimal('rounding_diff', 8, 2)->default(0);
            $table->decimal('final_result', 15, 2)->default(0);
            // Referensi regulasi (mis. "PMK 168/2023", "PP 35/2021").
            $table->string('rule_reference', 100)->nullable();
            $table->timestamps();

            $table->index(['payslip_id', 'step_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_calculation_steps');
    }
};
