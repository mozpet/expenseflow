<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('attendance_enabled')->default(true)->change();
        });

        // Aktifkan default presensi mobile untuk seluruh karyawan aktif
        DB::table('users')->where('is_active', true)->update([
            'attendance_enabled' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('attendance_enabled')->default(false)->change();
        });
    }
};
