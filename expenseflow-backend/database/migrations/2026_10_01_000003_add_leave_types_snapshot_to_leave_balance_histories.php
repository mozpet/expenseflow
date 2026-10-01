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
        Schema::table('leave_balance_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_balance_histories', 'leave_types_snapshot')) {
                $table->json('leave_types_snapshot')->nullable()->after('izin_sakit_used')->comment('Snapshot JSON kuota/used/remaining jenis cuti tambahan');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_balance_histories', function (Blueprint $table) {
            if (Schema::hasColumn('leave_balance_histories', 'leave_types_snapshot')) {
                $table->dropColumn('leave_types_snapshot');
            }
        });
    }
};
