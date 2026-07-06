<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_approvals', function (Blueprint $table) {
            $table->unsignedTinyInteger('approval_level')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('approval_level');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_approvals', function (Blueprint $table) {
            $table->dropColumn(['approval_level', 'reviewed_at', 'rejection_reason']);
        });
    }
};
