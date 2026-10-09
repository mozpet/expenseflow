<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZONA WAKTU PER KANTOR CABANG (2026-10-08)
 *
 * Seluruh logika presensi sebelumnya dipatok ke 'Asia/Jakarta' secara hardcode,
 * sehingga cabang di Makassar dengan jam masuk 08:00 dinilai terhadap 08:00 WIB
 * (= 09:00 WITA lokal) dan karyawan yang dinas di Papua dibandingkan terhadap
 * jam dinding yang salah.
 *
 * Kolom ini menjadi SUMBER OTORITATIF zona bisnis cabang: jadwal kerja, status
 * telat/hadir, dan attendances.date dihitung di zona ini. Zona lokasi fisik
 * karyawan (dari GPS) hanya untuk ditampilkan & diaudit.
 *
 * Nullable + backfill ke 'Asia/Jakarta': baris existing tetap berperilaku
 * identik, dan NULL di kode tetap jatuh ke zona penyimpanan (WIB).
 *
 * CATATAN: ini BUKAN zona penyimpanan database. Kolom datetime tetap menyimpan
 * jam dinding WIB (lihat DB_TIMEZONE=+07:00); APP_TIMEZONE tidak boleh diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('office_longitude');
        });

        // Backfill agar dropdown HRD punya nilai nyata, bukan placeholder kosong.
        DB::table('attendance_settings')->whereNull('timezone')->update([
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
