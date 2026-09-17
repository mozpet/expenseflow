<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('allow_attendance')->default(true)->after('attendance_enabled');
            $table->boolean('allow_wfh')->default(true)->after('wfh_enabled');
            $table->boolean('allow_radius')->default(true)->after('radius_enabled');
        });

        // Sinkronkan data existing: salin status yang sudah ada ke kolom izin master
        DB::table('users')->update([
            'allow_attendance' => DB::raw('attendance_enabled'),
            'allow_wfh'        => DB::raw('wfh_enabled'),
            'allow_radius'     => DB::raw('radius_enabled'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['allow_attendance', 'allow_wfh', 'allow_radius']);
        });
    }
};
