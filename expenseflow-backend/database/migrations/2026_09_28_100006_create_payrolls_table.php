<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch payroll per periode (bulan/tahun) & opsional per cabang.
     * Alur status maker-checker: draft → calculated → submitted → approved → paid.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // null = seluruh cabang; jika diisi = batch khusus satu kantor cabang.
            $table->foreignId('attendance_setting_id')->nullable()->constrained('attendance_settings')->nullOnDelete();
            $table->unsignedTinyInteger('period_month');   // 1-12
            $table->unsignedSmallInteger('period_year');
            $table->string('period_label', 40)->nullable();
            // Periode akhir tahun (Desember) → PPh21 disetahunkan dengan Pasal 17.
            $table->boolean('is_year_end')->default(false);
            $table->enum('status', ['draft', 'calculated', 'submitted', 'approved', 'paid', 'rejected'])->default('draft');

            // Jejak maker-checker (self-approval dilarang di controller).
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 255)->nullable();

            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_deduction', 18, 2)->default(0);
            $table->decimal('total_tax', 18, 2)->default(0);
            $table->decimal('total_net', 18, 2)->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'attendance_setting_id', 'period_year', 'period_month'], 'payrolls_period_branch_unique');
            $table->index(['company_id', 'period_year', 'period_month']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
