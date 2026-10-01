<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versi aturan statutory (effective-dated) untuk perhitungan pajak.
     * company_id NULL = aturan global bawaan (di-seed): TER 2024, PTKP, Pasal 17.
     * Perusahaan boleh meng-override dengan versi sendiri bila perlu.
     */
    public function up(): void
    {
        Schema::create('statutory_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->enum('type', ['ptkp', 'pph21_ter', 'pasal17']);
            $table->string('name', 120);
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            // payload: bracket / nominal aturan dalam bentuk JSON (dibaca kalkulator, tanpa eval).
            $table->json('payload');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_rule_versions');
    }
};
