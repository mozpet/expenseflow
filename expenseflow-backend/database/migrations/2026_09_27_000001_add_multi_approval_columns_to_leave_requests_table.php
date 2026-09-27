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
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->enum('current_step', ['spv', 'hrd'])->default('spv')->after('status');
            $table->foreignId('spv_id')->nullable()->after('current_step')->constrained('users')->nullOnDelete();
            $table->timestamp('spv_approved_at')->nullable()->after('spv_id');
            $table->text('spv_notes')->nullable()->after('spv_approved_at');
            $table->text('notes')->nullable()->after('approved_at');

            $table->index(['company_id', 'current_step']);
            $table->index(['company_id', 'spv_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['spv_id']);
            $table->dropIndex(['company_id', 'current_step']);
            $table->dropIndex(['company_id', 'spv_id']);
            $table->dropColumn(['current_step', 'spv_id', 'spv_approved_at', 'spv_notes', 'notes']);
        });
    }
};
