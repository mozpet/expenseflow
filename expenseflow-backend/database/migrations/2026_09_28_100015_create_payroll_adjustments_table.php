<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penyesuaian gaji & koreksi retroaktif (Fase 3, spec §6.C & §10.F).
     *
     * Model konsumsi: klaim-saat-calculate (payroll_id di-set + status=applied) dan
     * release-saat-recalc/hapus. `payroll_id` null = masih antre / belum masuk batch.
     * `retroactive_payroll_id` = periode lama yang dikoreksi (opsional, untuk jejak).
     * Alur status maker-checker: pending → approved → applied (atau voided).
     */
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Batch tempat penyesuaian diterapkan (null = belum dikonsumsi batch mana pun).
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();
            // Periode lama yang dikoreksi (koreksi retroaktif), hanya sebagai referensi.
            $table->foreignId('retroactive_payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();

            $table->enum('type', ['earning', 'deduction']);
            $table->string('name', 150);
            $table->decimal('amount', 15, 2);
            // Hanya relevan untuk earning: apakah menambah dasar pajak (PPh21).
            $table->boolean('is_taxable')->default(true);
            $table->text('reason')->nullable();
            $table->string('source_document_path', 255)->nullable();

            $table->enum('status', ['pending', 'approved', 'applied', 'voided'])->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('payroll_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};
