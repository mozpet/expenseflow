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
        Schema::table('holidays', function (Blueprint $table) {
            $table->unique(['company_id', 'date', 'attendance_setting_id'], 'holidays_company_date_office_unique');
            $table->dropUnique('holidays_company_id_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->unique(['company_id', 'date'], 'holidays_company_id_date_unique');
            $table->dropUnique('holidays_company_date_office_unique');
        });
    }
};
