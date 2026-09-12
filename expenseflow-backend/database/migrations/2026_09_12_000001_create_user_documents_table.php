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
        Schema::create('user_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('document_type', 50)->comment('ktp, kartu_keluarga, npwp, buku_tabungan, kontrak_kerja, ijazah, sertifikat, lainnya');
            $table->string('title', 150)->nullable()->comment('Judul/Label dokumen, misal: KTP Asli, SPK PKWT 2026');
            $table->string('file_path', 500)->comment('Path relatif pada storage disk privat');
            $table->string('file_name', 255)->comment('Nama asli berkas');
            $table->unsignedBigInteger('file_size')->default(0)->comment('Ukuran berkas dalam bytes');
            $table->string('mime_type', 100)->comment('MIME type berkas, misal application/pdf, image/jpeg');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable()->comment('Catatan tambahan atau keterangan dokumen');
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
            $table->index(['user_id', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_documents');
    }
};
