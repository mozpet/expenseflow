<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JEJAK ZONA WAKTU PADA BARIS PRESENSI (2026-10-08)
 *
 * snap_timezone — zona bisnis yang BERLAKU SAAT CHECK-IN, mengikuti doktrin
 *   snapshot yang sudah ada (2026_08_26_000001): perhitungan checkout/lembur/
 *   early-leave/auto-checkout/koreksi membaca snapshot, bukan attendance_settings
 *   live. Tanpa ini, HRD yang mengubah zona cabang siang hari akan menggeser
 *   penilaian karyawan yang sudah check-in pagi.
 *
 *   SENGAJA TANPA BACKFILL — sama seperti kolom snap_* lainnya. NULL berarti
 *   "baris sebelum fitur" dan dihitung memakai zona penyimpanan (WIB), sehingga
 *   SELURUH presensi historis tidak pernah diinterpretasi ulang.
 *
 * check_in_local_timezone / check_out_local_timezone — zona LOKASI FISIK karyawan,
 *   diturunkan SERVER dari check_in_lat/lng lewat App\Support\Timezone::fromCoordinates().
 *   Display-only: dipakai untuk menampilkan "08:20 WIB (10:20 WIT lokasi Anda)" dan
 *   tidak pernah memengaruhi perhitungan apa pun.
 *
 * device_timezone / device_utc_offset_minutes — apa yang DIKLAIM perangkat.
 *   AUDIT SAJA. Aturan Online-Only / Anti-Clock-Spoofing melarang mempercayai jam
 *   perangkat; justru SELISIH antara klaim perangkat dengan zona hasil GPS adalah
 *   sinyal kecurangan yang berguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Zona bisnis beku saat check-in (otoritatif untuk semua perhitungan).
            $table->string('snap_timezone', 64)->nullable()->after('snap_source');

            // Zona lokasi fisik karyawan dari GPS (display & audit).
            $table->string('check_in_local_timezone', 64)->nullable()->after('check_in_type');
            $table->string('check_out_local_timezone', 64)->nullable()->after('check_out_type');

            // Klaim perangkat (audit saja, tidak dipercaya).
            $table->string('device_timezone', 64)->nullable()->after('check_in_local_timezone');
            $table->smallInteger('device_utc_offset_minutes')->nullable()->after('device_timezone');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'snap_timezone',
                'check_in_local_timezone',
                'check_out_local_timezone',
                'device_timezone',
                'device_utc_offset_minutes',
            ]);
        });
    }
};
