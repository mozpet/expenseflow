<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JEJAK CARRY-OVER PADA SALDO CUTI BERJALAN (2026-10-13)
 *
 * Dua kolom yang diminta doc/kekurangnAttendance.md §8. Keduanya menjawab satu
 * pertanyaan yang tidak bisa dijawab oleh `quota` saja: dari 18 hari kuota
 * karyawan ini, BERAPA yang merupakan bawaan periode lalu, dan KAPAN bawaan itu
 * hangus?
 *
 * INVARIAN PENTING — carried_over_quota adalah BAGIAN DARI quota, bukan tambahan
 * di sebelahnya:
 *
 *     quota = default_leave_quota kantor + carried_over_quota
 *     remaining = quota - used          (tidak berubah, tetap satu rumus)
 *
 * Dipilih begitu supaya SELURUH jalur cuti yang sudah ada (requestLeave,
 * approveLeave, effectiveLeaveDays, saldo mobile, cuti bersama) terus membaca
 * `quota - used` tanpa satu pun perlu tahu soal carry-over. Kalau carry-over
 * disimpan sebagai saldo terpisah, setiap pembaca saldo harus diubah dan yang
 * terlewat akan menolak cuti yang sebenarnya masih ada haknya.
 *
 * Konsekuensi yang HARUS diikuti: clamp "kuota karyawan tidak boleh melebihi
 * standar kantor" di listLeaveBalances() / myLeaveBalance() / setLeaveBalance()
 * wajib membandingkan terhadap (default_leave_quota + carried_over_quota),
 * bukan default_leave_quota saja — jika tidak, clamp itu akan menghapus hari
 * bawaan beberapa milidetik setelah reset menuliskannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            // Bagian dari `quota` yang berasal dari periode sebelumnya.
            // 0 = tidak ada bawaan (default seluruh baris lama → perilaku identik).
            $table->unsignedSmallInteger('carried_over_quota')->default(0)->after('quota');
            // Tanggal hari bawaan hangus bila belum dipakai. NULL = tidak
            // kedaluwarsa sendiri (ikut hangus pada anniversary berikutnya).
            // Diproses ResetLeaveBalancesCommand yang berjalan tiap jam.
            $table->date('carry_over_expired_at')->nullable()->after('carried_over_quota');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn(['carried_over_quota', 'carry_over_expired_at']);
        });
    }
};
