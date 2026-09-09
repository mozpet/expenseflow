<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kolom color dan late_tolerance_minutes ke shift_patterns.
     */
    public function up(): void
    {
        Schema::table('shift_patterns', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('description');
            $table->unsignedSmallInteger('late_tolerance_minutes')->nullable()->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_patterns', function (Blueprint $table) {
            $table->dropColumn(['color', 'late_tolerance_minutes']);
        });
    }
};
