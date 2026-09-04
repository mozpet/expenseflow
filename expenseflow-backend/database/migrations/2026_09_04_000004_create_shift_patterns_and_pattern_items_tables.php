<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Membuat tabel master pola rotasi shift (shift_patterns)
     * dan detail hari per siklus (shift_pattern_items).
     */
    public function up(): void
    {
        Schema::create('shift_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('cycle_days'); // Jumlah hari per siklus, misal 6 (pola 4-2), 8, 21, 28
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::create('shift_pattern_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_pattern_id')->constrained('shift_patterns')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_order'); // Hari ke-1 s/d cycle_days dalam siklus
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->boolean('is_off')->default(false); // true = Hari libur siklus
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->boolean('is_cross_day')->default(false);
            $table->timestamps();

            $table->unique(['shift_pattern_id', 'day_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_pattern_items');
        Schema::dropIfExists('shift_patterns');
    }
};
