<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian pembayaran per karyawan dalam satu batch disbursement — Fase 4.
 *
 * Nomor rekening di tabel ini SELALU termasking (bank_account_no_masked) — nomor
 * penuh hanya ada di dalam berkas transfer privat. Scoping perusahaan mengikuti
 * batch induk (tidak ada company_id di sini, sesuai spec §10.G).
 *
 * Status item: pending → success | failed | rejected_by_bank (diisi saat rekonsiliasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_batch_id')->constrained('payroll_payment_batches')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('payslips')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('bank_name', 50)->nullable();
            $table->string('bank_account_no_masked', 30)->nullable();
            $table->string('bank_account_holder', 150)->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'success', 'failed', 'rejected_by_bank'])->default('pending');
            $table->string('bank_reference_no', 100)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['payment_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_items');
    }
};
