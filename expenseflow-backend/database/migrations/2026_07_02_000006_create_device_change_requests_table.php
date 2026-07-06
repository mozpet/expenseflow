<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan pindah device dari karyawan → butuh approval HR.
 * Dibuat otomatis saat login mobile dari device yang tidak cocok
 * dengan device yang terikat pada akun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Device yang saat ini terikat (bisa null jika sebelumnya belum bind).
            $table->string('old_device_id', 255)->nullable();
            $table->string('old_device_name', 255)->nullable();

            // Device baru yang mengajukan.
            $table->string('new_device_id', 255);
            $table->string('new_device_name', 255)->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // HR yang memproses.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('notes', 1000)->nullable();

            $table->timestamps();

            // Percepat lookup permintaan pending per user & per perusahaan.
            $table->index(['user_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_change_requests');
    }
};
