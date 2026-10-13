<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNAPSHOT KEPUTUSAN CARRY-OVER & PENCAIRAN PER PERIODE (2026-10-13)
 *
 * leave_balance_histories sudah mengarsipkan SALDO periode yang ditutup
 * (cuti_quota / cuti_used / cuti_remaining). Yang belum terarsip adalah
 * KEPUTUSAN atas sisa itu — dan justru keputusan inilah yang berdampak rupiah
 * dan paling sering dipersoalkan karyawan setahun kemudian.
 *
 * Desain snapshot: satu baris riwayat = satu peristiwa reset untuk satu
 * karyawan, dan baris itu harus bisa menjelaskan SELURUH takdir sisa cutinya
 * tanpa perlu melihat konfigurasi kantor yang berlaku SEKARANG. Karena itu
 * tarif, batas, dan status kedua checkbox ikut DIBEKUKAN di sini, bukan dibaca
 * ulang dari attendance_settings saat laporan dibuka. HRD yang mengubah tarif
 * pencairan bulan depan tidak boleh mengubah angka historis.
 *
 * Invarian yang dijaga oleh baris ini (diverifikasi test):
 *
 *     cuti_remaining = carried_over_days + encashed_days + forfeited_days
 *
 * Tiga angka itu habis membagi sisa cuti: dibawa, dicairkan, atau hangus.
 * Tidak ada kemungkinan keempat. Kolom policy_snapshot menyimpan konteks
 * kebijakannya (checkbox mana yang aktif, batas berapa, tarif berapa) sebagai
 * JSON agar penambahan parameter kebijakan di masa depan tidak menuntut migrasi
 * kolom baru untuk setiap angka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balance_histories', function (Blueprint $table) {
            // Hari yang dibawa ke periode baru (0 bila checkbox carry-over OFF).
            $table->unsignedSmallInteger('carried_over_days')->default(0)->after('cuti_remaining');
            // Tanggal kedaluwarsa hari bawaan tsb (NULL = ikut siklus anniversary).
            $table->date('carry_over_expired_at')->nullable()->after('carried_over_days');
            // Hari yang dikonversi menjadi uang (0 bila checkbox pencairan OFF).
            $table->unsignedSmallInteger('encashed_days')->default(0)->after('carry_over_expired_at');
            // Tarif per hari YANG BERLAKU SAAT ITU — dibekukan, bukan dibaca ulang.
            $table->decimal('encashment_rate_per_day', 12, 2)->nullable()->after('encashed_days');
            // encashed_days × encashment_rate_per_day. Disimpan sebagai angka jadi
            // agar laporan & rekonsiliasi payroll tidak menghitung ulang (dan tidak
            // bisa menyimpang dari nominal yang benar-benar diterbitkan).
            $table->decimal('encashment_amount', 15, 2)->nullable()->after('encashment_rate_per_day');
            // PayrollAdjustment yang menjadi kendaraan pencairan ke slip gaji.
            // SET NULL bila penyesuaian dibatalkan — riwayat cuti tidak boleh ikut
            // terhapus hanya karena jalur pembayarannya dibatalkan.
            $table->foreignId('encashment_adjustment_id')->nullable()
                ->after('encashment_amount')
                ->constrained('payroll_adjustments')->nullOnDelete();
            // Hari yang hangus (tidak dibawa & tidak dicairkan).
            $table->unsignedSmallInteger('forfeited_days')->default(0)->after('encashment_adjustment_id');
            // Konteks kebijakan yang berlaku saat keputusan dibuat.
            $table->json('policy_snapshot')->nullable()->after('forfeited_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balance_histories', function (Blueprint $table) {
            $table->dropForeign(['encashment_adjustment_id']);
            $table->dropColumn([
                'carried_over_days',
                'carry_over_expired_at',
                'encashed_days',
                'encashment_rate_per_day',
                'encashment_amount',
                'encashment_adjustment_id',
                'forfeited_days',
                'policy_snapshot',
            ]);
        });
    }
};
