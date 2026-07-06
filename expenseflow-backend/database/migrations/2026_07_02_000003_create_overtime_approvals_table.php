<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel overtime_approvals menyimpan permintaan persetujuan lembur.
     *
     * Alur:
     * 1. Karyawan checkout (manual/auto) → sistem hitung overtime_minutes
     * 2. Jika ada lembur, dibuat record di sini dengan status 'pending'
     * 3. HRD approve → overtime_minutes dikonfirmasi (berlaku ke payroll)
     * 4. HRD reject → overtime_minutes di attendances di-set 0
     */
    public function up(): void
    {
        Schema::create('overtime_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();     // karyawan
            $table->unsignedBigInteger('company_id');
            // Durasi lembur yang diusulkan (menit), diambil dari attendances.overtime_minutes
            $table->integer('overtime_minutes');
            // Status persetujuan
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            // Siapa HRD/admin yang mereview
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Catatan HRD (opsional)
            $table->string('notes', 1000)->nullable();
            // Checkout jenis apa yang memicu lembur ini?
            $table->boolean('is_auto_checkout')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_approvals');
    }
};
