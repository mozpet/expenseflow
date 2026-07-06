<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            // NULL = libur nasional (berlaku untuk semua perusahaan).
            // Berisi company_id = libur khusus perusahaan tsb (mis. cuti bersama internal).
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('name');
            $table->boolean('is_national')->default(true);
            $table->timestamps();

            // Satu tanggal hanya boleh satu entri per scope (company / nasional).
            $table->unique(['company_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
