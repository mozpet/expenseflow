<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_pattern_day_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_pattern_id')
                  ->constrained('shift_patterns')
                  ->cascadeOnDelete();
            $table->tinyInteger('day_of_week'); // 0=Minggu … 6=Sabtu
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->unsignedSmallInteger('late_tolerance_minutes')->nullable();
            $table->timestamps();

            $table->unique(['shift_pattern_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_pattern_day_overrides');
    }
};
