<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KEBIJAKAN CARRY-OVER & PENCAIRAN SISA CUTI TAHUNAN (2026-10-13)
 *
 * Melengkapi kebijakan saldo cuti per kantor (default_leave_quota /
 * leave_reset_date) dengan dua keputusan yang sebelumnya tidak ada sama sekali:
 * saat anniversary tiba, sisa cuti karyawan HANGUS tanpa opsi. Lihat
 * doc/kekurangnAttendance.md §8 (Carry-Forward Expiry).
 *
 * Dua kebijakan ini masing-masing DIGERBANGI SATU CHECKBOX, dan urutannya
 * berarti: carry-over lebih dulu, pencairan mengambil SISANYA.
 *
 * 1. leave_carry_over_*  — sisa cuti periode lama dibawa ke periode baru,
 *    maksimal N hari, dan (opsional) wajib dihabiskan dalam M bulan sebelum
 *    hangus otomatis. Checkbox OFF = tidak ada regulasi carry-over sama sekali.
 *
 * 2. leave_encashment_* — sisa cuti yang TIDAK dibawa dikonversi menjadi uang
 *    dengan tarif per hari, diterbitkan sebagai PayrollAdjustment approved
 *    sehingga cair di batch payroll berikutnya pada tanggal gajian grupnya.
 *    Checkbox OFF = seluruh sisa cuti hangus (perilaku lama, persis).
 *
 * MATRIKS KEDUA CHECKBOX (satu-satunya sumber kebenaran: LeaveCarryOverCalculator):
 *   carry OFF, cair OFF → seluruh sisa HANGUS (perilaku sebelum fitur ini ada)
 *   carry ON , cair OFF → maks N hari dibawa, selebihnya HANGUS
 *   carry OFF, cair ON  → seluruh sisa DICAIRKAN jadi uang
 *   carry ON , cair ON  → maks N hari dibawa, SISA YANG TIDAK DIBAWA dicairkan
 *
 * Seluruh kolom berdefault netral (enabled=false, nominal NULL) sehingga kantor
 * yang sudah ada berperilaku PERSIS seperti sebelum migrasi ini dijalankan:
 * tidak ada hari cuti yang berpindah dan tidak ada rupiah yang bergerak tanpa
 * HRD mencentang kotaknya dan mengisi nominalnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // ─── 1) Carry-over sisa cuti ke periode berikutnya ───────────────
            // Checkbox utama. false = tidak ada regulasi carry-over.
            $table->boolean('leave_carry_over_enabled')->default(false);
            // Batas hari yang boleh dibawa. WAJIB > 0 bila checkbox dicentang
            // (dijaga validateLeavePolicyAmounts): carry-over aktif dengan batas
            // 0 hari adalah kebijakan yang terlihat ON tapi tidak membawa apa pun.
            $table->unsignedSmallInteger('leave_carry_over_max_days')->nullable();
            // Masa kedaluwarsa hari bawaan, dihitung dari tanggal reset.
            // NULL = tidak kedaluwarsa sendiri (hangus saat anniversary berikutnya,
            // mengikuti siklus normal). Mis. 3 = praktik umum "wajib dihabiskan
            // paling lambat 31 Maret" untuk kantor yang reset 1 Januari.
            $table->unsignedTinyInteger('leave_carry_over_expiry_months')->nullable();

            // ─── 2) Pencairan sisa cuti menjadi uang ─────────────────────────
            // Checkbox kedua. false = sisa cuti yang tidak dibawa HANGUS.
            $table->boolean('leave_encashment_enabled')->default(false);
            // Nominal rupiah per hari cuti yang dicairkan. WAJIB > 0 bila
            // checkbox dicentang. Nilai ini DIBEKUKAN ke leave_balance_histories
            // saat reset, sehingga HRD yang mengubah tarif setelahnya tidak
            // mengubah uang yang sudah diterbitkan untuk periode lampau.
            $table->decimal('leave_encashment_rate_per_day', 12, 2)->nullable();
            // Pagu hari yang boleh dicairkan per karyawan per periode.
            // NULL = tanpa pagu (seluruh sisa yang tidak dibawa dicairkan).
            // Ada karena pencairan adalah SATU-SATUNYA kebijakan presensi yang
            // menerbitkan rupiah berdasarkan angka yang bisa menumpuk setahun:
            // karyawan dengan sisa 24 hari langsung menjadi tagihan 24 hari.
            $table->unsignedSmallInteger('leave_encashment_max_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'leave_carry_over_enabled',
                'leave_carry_over_max_days',
                'leave_carry_over_expiry_months',
                'leave_encashment_enabled',
                'leave_encashment_rate_per_day',
                'leave_encashment_max_days',
            ]);
        });
    }
};
