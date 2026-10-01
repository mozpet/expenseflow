<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kasbon / pinjaman karyawan dengan cicilan otomatis dipotong tiap payroll.
     * (Melengkapi gap roadmap: employee_loans dirujuk engine namun belum didefinisikan.)
     */
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 120)->nullable();
            $table->decimal('principal', 15, 2)->default(0);
            $table->decimal('installment_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('tenor_months')->default(1);
            $table->unsignedSmallInteger('installments_paid')->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            // Periode mulai potong cicilan.
            $table->unsignedTinyInteger('start_period_month');
            $table->unsignedSmallInteger('start_period_year');
            $table->enum('status', ['pending', 'active', 'paid', 'cancelled'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loans');
    }
};
