<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit tamper-evident payroll — hash-chain SHA-256 (Fase 3, spec §10.H & §15).
     *
     * Setiap event membentuk rantai per-perusahaan: record_hash dihitung dari
     * prev_hash + kanonik payload. Perubahan retroaktif satu baris memutus rantai
     * (verifyChain mendeteksi broken_at). before_state/after_state disimpan sebagai
     * TEXT berisi JSON kanonik (bukan kolom JSON) supaya byte yang di-hash identik
     * saat diverifikasi ulang (kolom JSON MySQL menormalkan/mengurutkan ulang kunci).
     * `payroll_id` nullable + SET NULL agar bisa memuat event non-batch (adjustment,
     * rekening bank) dan log tetap lestari bila draft dihapus.
     */
    public function up(): void
    {
        Schema::create('payroll_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 60);
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('before_state')->nullable();  // JSON kanonik (string)
            $table->text('after_state')->nullable();   // JSON kanonik (string)
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->string('prev_hash', 64)->nullable();
            $table->string('record_hash', 64);
            $table->unsignedBigInteger('sequence');
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'sequence']);
            $table->index(['payroll_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_logs');
    }
};
