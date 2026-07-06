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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');

            // Check-in
            $table->timestamp('check_in_time')->nullable();
            $table->decimal('check_in_lat', 10, 8)->nullable();
            $table->decimal('check_in_lng', 11, 8)->nullable();
            $table->integer('check_in_distance_meters')->nullable();
            $table->enum('check_in_type', ['onsite', 'wfh', 'field'])->nullable();
            $table->string('check_in_photo')->nullable();

            // Check-out
            $table->timestamp('check_out_time')->nullable();
            $table->decimal('check_out_lat', 10, 8)->nullable();
            $table->decimal('check_out_lng', 11, 8)->nullable();
            $table->enum('check_out_type', ['onsite', 'wfh', 'field'])->nullable();

            $table->enum('status', ['present', 'late', 'absent', 'wfh'])->default('absent');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Satu record presensi per user per hari
            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
