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
            $table->string('education_level', 50)->nullable()->after('medical_conditions');
            $table->string('institution_name', 150)->nullable()->after('education_level');
            $table->string('major', 100)->nullable()->after('institution_name');
            $table->unsignedSmallInteger('graduation_year')->nullable()->after('major');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['education_level', 'institution_name', 'major', 'graduation_year']);
        });
    }
};
