<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slip gaji per karyawan dalam satu batch payroll.
     * Kolom snapshot (nama, jabatan, PTKP, bank) dibekukan agar slip tetap
     * konsisten walau data master berubah setelah disetujui.
     */
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            // Milik batch: hapus batch draft ikut menghapus slip-nya.
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('period_month');
            $table->unsignedSmallInteger('period_year');

            // Snapshot identitas (immutable pasca-approve).
            $table->string('employee_name', 150);
            $table->string('employee_code', 50)->nullable();
            $table->string('position_name', 120)->nullable();
            $table->string('department_name', 120)->nullable();
            $table->string('npwp_masked', 30)->nullable();
            $table->string('ptkp_status', 8)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_account_no', 50)->nullable();
            $table->string('bank_account_holder', 150)->nullable();

            // Angka hasil kalkulasi.
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('total_earning', 15, 2)->default(0);
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('taxable_income', 15, 2)->default(0);
            $table->decimal('pph21', 15, 2)->default(0);
            $table->decimal('total_deduction', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);

            // Ringkasan kehadiran/lembur untuk transparansi slip.
            $table->unsignedSmallInteger('working_days')->nullable();
            $table->unsignedSmallInteger('present_days')->nullable();
            $table->unsignedSmallInteger('absent_days')->nullable();
            $table->decimal('overtime_hours', 8, 2)->nullable();

            $table->enum('status', ['draft', 'calculated', 'approved', 'paid'])->default('draft');
            $table->string('pdf_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'period_year', 'period_month']);
            $table->index('payroll_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
