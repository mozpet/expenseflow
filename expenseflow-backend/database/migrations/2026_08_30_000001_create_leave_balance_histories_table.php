<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_balance_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_setting_id')->nullable()->constrained('attendance_settings')->nullOnDelete();
            $table->string('period_label'); // contoh: "Periode 2025/2026" atau "Tahun 2025"
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('reset_date'); // tanggal reset dieksekusi
            $table->integer('cuti_quota')->default(0);
            $table->integer('cuti_used')->default(0);
            $table->integer('cuti_remaining')->default(0);
            $table->integer('izin_sakit_used')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'reset_date']);
            $table->index(['user_id', 'reset_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balance_histories');
    }
};
