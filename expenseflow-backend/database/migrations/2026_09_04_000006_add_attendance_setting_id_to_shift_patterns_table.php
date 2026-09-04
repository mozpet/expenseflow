<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pola rotasi shift dapat dikhususkan untuk CABANG (attendance_settings) tertentu,
     * atau NULL untuk berlaku di semua cabang (company-wide).
     */
    public function up(): void
    {
        Schema::table('shift_patterns', function (Blueprint $table) {
            $table->foreignId('attendance_setting_id')
                ->nullable()
                ->after('company_id')
                ->constrained('attendance_settings')
                ->nullOnDelete();

            $table->index('attendance_setting_id');
        });
    }

    public function down(): void
    {
        Schema::table('shift_patterns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_setting_id');
        });
    }
};
