<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device binding untuk mobile — cegah "titip absen" (buddy punching).
 * 1 akun karyawan terikat ke 1 device. Pindah device butuh approval HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identitas device yang saat ini terikat ke akun (null = belum pernah bind).
            $table->string('device_id', 255)->nullable()->after('fcm_token');
            // Nama device untuk ditampilkan ke HR (mis. "Samsung Galaxy A52").
            $table->string('device_name', 255)->nullable()->after('device_id');
            // Kapan device terikat (audit).
            $table->timestamp('device_bound_at')->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_name', 'device_bound_at']);
        });
    }
};
