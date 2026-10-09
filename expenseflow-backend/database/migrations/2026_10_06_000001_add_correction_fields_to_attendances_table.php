<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JEJAK KOREKSI PRESENSI MANUAL OLEH HRD (2026-10-06)
 *
 * Sebelum ini, baris `attendances` yang salah (GPS drift, HP mati, karyawan lupa
 * check-in) tidak bisa diperbaiki siapa pun → laporan presensi permanen salah,
 * dan karena PayrollCalculator membaca attendances.status / is_holiday, kesalahan
 * presensi langsung menjadi kesalahan gaji.
 *
 * Empat kolom di bawah adalah syarat audit untuk membuka jalur koreksi manual:
 * - corrected_by       : HRD/Admin yang melakukan koreksi
 * - corrected_at       : kapan dikoreksi (WIB)
 * - correction_reason  : alasan koreksi (WAJIB di layer validasi, min 10 karakter)
 * - is_manual_entry    : true bila baris dibuat HRD (backfill absen hilang),
 *                        bukan hasil check-in karyawan sendiri
 *
 * Tidak ada ALTER ENUM di sini, sehingga migrasi aman dijalankan di SQLite
 * (suite test memakai :memory:) tanpa guard driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('corrected_by')->nullable()->after('offline_recorded_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable()->after('corrected_by');
            $table->text('correction_reason')->nullable()->after('corrected_at');
            $table->boolean('is_manual_entry')->default(false)->after('correction_reason');

            // Filter "hanya baris yang pernah dikoreksi" di laporan & audit
            $table->index('corrected_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['corrected_at']);
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn(['corrected_at', 'correction_reason', 'is_manual_entry']);
        });
    }
};
