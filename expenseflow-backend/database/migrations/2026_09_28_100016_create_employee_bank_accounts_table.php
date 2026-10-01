<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proteksi perubahan rekening bank karyawan (Fase 3, spec §3.A.3 & §3.B).
     *
     * Setiap perubahan rekening = pengajuan baru berstatus pending_verification,
     * WAJIB diverifikasi orang berbeda (maker-checker). Nomor rekening disimpan
     * terenkripsi (cast `encrypted`) dan hanya ditampilkan termasking. Saat verify,
     * rekening aktif lama menjadi superseded dan kolom users.bank_* disinkronkan
     * (agar snapshot payslip di PayrollCalculator tetap berjalan tanpa perubahan).
     */
    public function up(): void
    {
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('bank_name', 100);
            $table->text('bank_account_no');          // terenkripsi (cast encrypted di model)
            $table->string('bank_account_holder', 150);
            $table->string('bank_branch', 100)->nullable();
            $table->string('swift_code', 20)->nullable();

            $table->enum('status', ['pending_verification', 'active', 'superseded', 'rejected'])
                ->default('pending_verification');
            $table->boolean('is_primary')->default(false);

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bank_accounts');
    }
};
