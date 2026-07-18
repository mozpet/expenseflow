<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_approvals', function (Blueprint $table) {
            $table->string('overtime_reason', 255)->nullable()->after('is_auto_checkout');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_approvals', function (Blueprint $table) {
            $table->dropColumn('overtime_reason');
        });
    }
};
