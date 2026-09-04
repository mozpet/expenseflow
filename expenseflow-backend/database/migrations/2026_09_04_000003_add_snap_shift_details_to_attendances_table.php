<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Menambahkan snap_shift_id dan snap_shift_name ke tabel attendances
     * agar saat check-in, detail master shift yang dipakai terkunci aman di record presensi.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('snap_shift_id')->nullable()->after('snap_source');
            $table->string('snap_shift_name', 100)->nullable()->after('snap_shift_id');

            $table->index('snap_shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['snap_shift_id']);
            $table->dropColumn(['snap_shift_id', 'snap_shift_name']);
        });
    }
};
