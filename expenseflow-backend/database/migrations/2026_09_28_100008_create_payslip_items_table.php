<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rincian baris slip gaji (earning / deduction).
     * Milik payslip → cascade saat slip dihapus (batch draft dibatalkan).
     */
    public function up(): void
    {
        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('code', 50)->nullable();
            $table->enum('type', ['earning', 'deduction']);
            $table->decimal('amount', 15, 2)->default(0);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_statutory')->default(false);
            // Asal data baris → transparansi & audit integrasi.
            $table->enum('source', ['basic', 'fixed', 'manual', 'attendance', 'overtime', 'receipt', 'loan', 'tax'])->default('manual');
            $table->foreignId('salary_component_id')->nullable()->constrained('salary_components')->nullOnDelete();
            $table->string('ref_type', 50)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_items');
    }
};
