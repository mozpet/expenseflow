<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penempatan kantor karyawan.
 * Menghubungkan user ke kantor (attendance_settings) tempat ia bekerja.
 * Nullable → karyawan lama / belum ditentukan tetap valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('attendance_setting_id')
                ->nullable()
                ->after('department')
                ->constrained('attendance_settings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_setting_id');
        });
    }
};
