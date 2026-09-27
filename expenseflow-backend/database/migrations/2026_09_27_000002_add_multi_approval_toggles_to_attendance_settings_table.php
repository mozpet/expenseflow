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
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->boolean('overtime_multi_approval_enabled')
                ->default(true)
                ->after('overtime_enabled');

            $table->boolean('leave_multi_approval_enabled')
                ->default(true)
                ->after('default_leave_quota');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'overtime_multi_approval_enabled',
                'leave_multi_approval_enabled',
            ]);
        });
    }
};
