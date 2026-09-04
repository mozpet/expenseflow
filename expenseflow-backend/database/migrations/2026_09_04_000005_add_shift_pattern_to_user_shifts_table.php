<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Menambahkan shift_pattern_id dan anchor_day_order ke tabel user_shifts.
     */
    public function up(): void
    {
        Schema::table('user_shifts', function (Blueprint $table) {
            $table->foreignId('shift_pattern_id')->nullable()->after('shift_id')->constrained('shift_patterns')->nullOnDelete();
            $table->unsignedSmallInteger('anchor_day_order')->default(1)->after('shift_pattern_id'); // 1 sampai cycle_days
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_shifts', function (Blueprint $table) {
            $table->dropForeign(['shift_pattern_id']);
            $table->dropColumn(['shift_pattern_id', 'anchor_day_order']);
        });
    }
};
