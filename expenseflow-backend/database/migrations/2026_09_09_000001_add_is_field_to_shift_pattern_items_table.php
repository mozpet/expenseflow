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
        Schema::table('shift_pattern_items', function (Blueprint $table) {
            $table->boolean('is_field')->default(false)->after('is_wfh');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_pattern_items', function (Blueprint $table) {
            $table->dropColumn('is_field');
        });
    }
};
