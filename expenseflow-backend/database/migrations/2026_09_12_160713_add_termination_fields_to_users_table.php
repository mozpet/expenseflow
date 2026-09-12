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
        Schema::table('users', function (Blueprint $table) {
            $table->date('exit_date')->nullable()->after('graduation_year');
            $table->string('exit_reason', 100)->nullable()->after('exit_date');
            $table->text('exit_notes')->nullable()->after('exit_reason');
            $table->string('severance_status', 50)->nullable()->after('exit_notes');
            $table->string('clearance_status', 50)->nullable()->after('severance_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['exit_date', 'exit_reason', 'exit_notes', 'severance_status', 'clearance_status']);
        });
    }
};
