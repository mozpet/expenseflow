<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('current_step', 20)->nullable()->default(null)->change();
        });

        // Cuti bersama (holiday_id != null) tidak memiliki approval bertingkat (tidak ada Lv1 SPV maupun Lv2 HRD).
        // Keputusan sepenuhnya di tangan staf apakah ikut atau tidak.
        DB::table('leave_requests')
            ->whereNotNull('holiday_id')
            ->update(['current_step' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('leave_requests')
            ->whereNull('current_step')
            ->update(['current_step' => 'spv']);

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->enum('current_step', ['spv', 'hrd'])->default('spv')->change();
        });
    }
};
