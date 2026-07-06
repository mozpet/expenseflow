<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detail jadwal harian per shift.
     * Setiap shift memiliki 7 baris (satu per hari: 0=Minggu s.d. 6=Sabtu).
     * is_off = true → hari libur dalam shift ini (karyawan tidak masuk).
     */
    public function up(): void
    {
        Schema::create('shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();

            // 0=Minggu, 1=Senin, 2=Selasa, 3=Rabu, 4=Kamis, 5=Jumat, 6=Sabtu
            $table->unsignedTinyInteger('day_of_week');

            // Null saat is_off = true (tidak perlu jam masuk/pulang di hari libur shift)
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();

            // true → hari libur dalam shift ini; jam kerja diabaikan
            $table->boolean('is_off')->default(false);

            $table->timestamps();

            // Satu shift hanya boleh punya satu entri per hari
            $table->unique(['shift_id', 'day_of_week']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_schedules');
    }
};
