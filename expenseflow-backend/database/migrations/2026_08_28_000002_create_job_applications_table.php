<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration: buat tabel lamaran kerja.
     */
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained('job_postings')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('education')->nullable(); // Pendidikan terakhir
            $table->unsignedTinyInteger('experience_years')->nullable(); // Pengalaman kerja (tahun)
            $table->text('cover_letter')->nullable(); // Surat lamaran
            $table->string('resume_path')->nullable(); // Path file CV (PDF)
            $table->enum('status', ['new', 'reviewed', 'shortlisted', 'rejected', 'hired'])->default('new');
            $table->text('notes')->nullable(); // Catatan internal HRD
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Index untuk query umum
            $table->index(['job_posting_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index('email');
        });
    }

    /**
     * Balik migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
