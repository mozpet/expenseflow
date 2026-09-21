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
        // 1. Kolom multi-approval pada receipts
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('approval_tier', 50)->nullable()->after('currency');
            $table->unsignedInteger('required_approvals')->default(1)->after('approval_tier');
            $table->unsignedInteger('current_approvals')->default(0)->after('required_approvals');
        });

        // 2. Kolom approval_level pada receipt_approvals
        Schema::table('receipt_approvals', function (Blueprint $table) {
            $table->unsignedInteger('approval_level')->default(1)->after('status');
        });

        // 3. Kolom 2-step approval pada overtime_approvals
        Schema::table('overtime_approvals', function (Blueprint $table) {
            $table->enum('current_step', ['spv', 'hrd'])->default('spv')->after('status');
            $table->foreignId('spv_id')->nullable()->after('current_step')->constrained('users')->nullOnDelete();
            $table->timestamp('spv_approved_at')->nullable()->after('spv_id');
            $table->text('spv_notes')->nullable()->after('spv_approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('overtime_approvals', function (Blueprint $table) {
            $table->dropForeign(['spv_id']);
            $table->dropColumn(['current_step', 'spv_id', 'spv_approved_at', 'spv_notes']);
        });

        Schema::table('receipt_approvals', function (Blueprint $table) {
            $table->dropColumn('approval_level');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['approval_tier', 'required_approvals', 'current_approvals']);
        });
    }
};
