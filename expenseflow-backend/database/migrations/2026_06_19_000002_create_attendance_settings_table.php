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
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('office_name');
            // Koordinat kantor untuk validasi jarak (rumus Haversine)
            $table->decimal('office_latitude', 10, 8);
            $table->decimal('office_longitude', 11, 8);
            $table->integer('radius_meters')->default(100);
            $table->time('work_start_time')->default('08:00:00');
            $table->time('work_end_time')->default('17:00:00');
            $table->integer('late_tolerance_minutes')->default(15);
            $table->boolean('require_selfie')->default(false);
            $table->boolean('allow_wfh')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
