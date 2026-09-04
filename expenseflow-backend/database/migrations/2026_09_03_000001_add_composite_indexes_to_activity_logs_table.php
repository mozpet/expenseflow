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
        Schema::table('activity_logs', function (Blueprint $table) {
            // Composite index untuk mempercepat query dashboard default:
            // WHERE company_id = ? ORDER BY created_at DESC
            $table->index(['company_id', 'created_at'], 'idx_act_logs_company_created');

            // Composite index untuk filter severity:
            // WHERE company_id = ? AND severity = ? ORDER BY created_at DESC
            $table->index(['company_id', 'severity', 'created_at'], 'idx_act_logs_company_sev_created');

            // Composite index untuk filter category:
            // WHERE company_id = ? AND category = ? ORDER BY created_at DESC
            $table->index(['company_id', 'category', 'created_at'], 'idx_act_logs_company_cat_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('idx_act_logs_company_created');
            $table->dropIndex('idx_act_logs_company_sev_created');
            $table->dropIndex('idx_act_logs_company_cat_created');
        });
    }
};
