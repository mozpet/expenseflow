<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel template shift jadwal kerja per perusahaan.
     * Contoh: 'Shift Pagi', 'Shift Sabtu', 'Shift Malam'.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);          // nama shift, contoh: 'Shift Sabtu'
            $table->text('description')->nullable(); // keterangan opsional
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
