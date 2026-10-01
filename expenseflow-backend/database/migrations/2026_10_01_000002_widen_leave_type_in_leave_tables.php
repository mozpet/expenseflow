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
            $table->string('leave_type', 40)->change();
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->string('leave_type', 40)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('leave_type', 20)->change();
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->string('leave_type', 20)->change();
        });
    }
};
