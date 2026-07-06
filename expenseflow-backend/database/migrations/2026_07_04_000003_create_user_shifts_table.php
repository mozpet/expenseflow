<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mapping karyawan ke shift tertentu dengan masa berlaku.
     *
     * Logika "shift aktif":
     *   Ambil baris dengan start_date terbaru yang sudah <= tanggal hari ini.
     *   shift_id = NULL → kembali ke default kantor (attendance_settings).
     *
     * Unique(user_id, start_date): satu karyawan tidak boleh punya dua assignment
     * yang mulai di tanggal yang sama, sehingga tidak ada ambiguitas.
     */
    public function up(): void
    {
        Schema::create('user_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // null = "hapus shift khusus, kembali ke jadwal default kantor"
            $table->foreignId('shift_id')->nullable()->nullOnDelete();

            $table->date('start_date'); // kapan shift ini mulai efektif
            $table->text('notes')->nullable(); // catatan HRD, opsional

            $table->timestamps();

            // Cegah dua assignment di tanggal mulai yang sama untuk karyawan yang sama
            $table->unique(['user_id', 'start_date']);
            $table->index(['user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_shifts');
    }
};
