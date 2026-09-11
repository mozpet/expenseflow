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
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('expense_report_id')
                ->nullable()
                ->after('attendance_setting_id')
                ->constrained('expense_reports')
                ->nullOnDelete();

            $table->index('expense_report_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['expense_report_id']);
            $table->dropColumn('expense_report_id');
        });
    }
};
