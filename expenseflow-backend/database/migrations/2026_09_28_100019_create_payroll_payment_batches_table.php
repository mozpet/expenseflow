<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch pembayaran gaji (disbursement) — Fase 4.
 *
 * Satu baris = satu berkas transfer massal (bulk transfer) per bank untuk sebuah
 * payroll run. Siklus status batch:
 *   prepared → file_generated → uploaded → partially_settled → settled/reconciled
 *
 * Berkas transfer (berisi NOMOR REKENING PENUH) disimpan di disk PRIVAT
 * (storage/app/private/payroll/payment-batches) + checksum SHA-256 untuk deteksi
 * perubahan. Kolom di tabel HANYA menyimpan nomor rekening TERMASKING (di item).
 *
 * CATATAN DESAIN (penting): lapisan disbursement ini SENGAJA dipisah dari mesin
 * status payroll & pelunasan kasbon. Rekonsiliasi hanya mengubah status item/batch
 * dan mengirim notifikasi — TIDAK mengubah payslip/payroll & TIDAK memajukan kasbon
 * (itu tetap tanggung jawab aksi mark-paid pada PayrollController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('batch_reference', 60)->unique();
            $table->enum('bank_format', [
                'bca_klikbisnis', 'mandiri_mcm', 'bri_cms', 'bni_direct', 'generic_csv',
            ])->default('generic_csv');
            $table->integer('total_records')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('file_path', 255)->nullable();
            $table->string('file_checksum', 64)->nullable(); // SHA-256 hex dari isi berkas
            $table->enum('status', [
                'prepared', 'file_generated', 'uploaded', 'partially_settled', 'settled', 'reconciled',
            ])->default('prepared');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'payroll_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_batches');
    }
};
