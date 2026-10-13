<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TANGGAL GAJIAN & TANGGAL TUTUP BUKU (CUT-OFF) PRESENSI PER GRUP PAYROLL (2026-10-12)
 *
 * Sebelum ini periode payroll dikunci keras tanggal 1 s/d akhir bulan
 * (PayrollCalculator::calculate()), sehingga kantor yang tutup buku presensi
 * tanggal 20 dan menggajikan tanggal 25 tidak terlayani. Tiga kolom di bawah
 * menjadi SUMBER DEFAULT rentang cut-off & rencana tanggal gajian; nilai final
 * per batch disimpan di payrolls.period_start_date/period_end_date/
 * planned_payment_date (migrasi 2026_10_12_000002) agar HRD tetap bisa
 * mengedit manual saat ada libur panjang atau gajian dimajukan.
 *
 * MENYIMPANG DARI ROADMAP SECARA SADAR:
 * doc/07-PAYROLL-ROADMAP.md:516-517 mendesain `cutoff_day TINYINT NOT NULL
 * DEFAULT 25`. Default NOT NULL akan DIAM-DIAM menggeser jendela presensi
 * SELURUH grup payroll yang sudah ada (dari 1–akhir bulan menjadi 26–25) tanpa
 * HRD pernah menyetujuinya — perubahan angka gaji tanpa jejak keputusan.
 * Karena itu kedua kolom tanggal dibuat NULLABLE dengan default NULL yang
 * berarti "belum diatur → pakai bulan kalender penuh (perilaku lama)". Grup
 * lama tidak bergeser sedikit pun sampai HRD mengisinya sendiri.
 *
 * Konvensi nilai hari (dipakai PayrollPeriodResolver):
 *  - NULL = belum diatur → bulan kalender penuh.
 *  - 0    = akhir bulan (tanggal terakhir, otomatis 28/29/30/31).
 *  - 1-31 = tanggal tetap; bila bulannya lebih pendek, di-clamp ke hari
 *           terakhir bulan itu (cut-off 31 di Februari → 28/29), TIDAK
 *           melimpah ke bulan berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_groups', function (Blueprint $table) {
            // Tanggal tutup buku presensi. Mis. 20 → jendela 21 bulan lalu s/d 20 bulan ini.
            $table->unsignedTinyInteger('cutoff_day')->nullable()->after('description');

            // Rencana tanggal gajian. Mis. 25 → dibayarkan tanggal 25.
            $table->unsignedTinyInteger('payment_day')->nullable()->after('cutoff_day');

            // Bulan gajian relatif terhadap bulan periode:
            //  0 = bulan periode itu sendiri (cut-off 20 Okt → gajian 25 Okt),
            //  1 = bulan berikutnya (cut-off akhir Okt → gajian 25 Nov).
            // NOT NULL default 0 aman: tanpa payment_day terisi, offset tidak
            // pernah dipakai sama sekali.
            $table->unsignedTinyInteger('payment_month_offset')->default(0)->after('payment_day');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_groups', function (Blueprint $table) {
            $table->dropColumn(['cutoff_day', 'payment_day', 'payment_month_offset']);
        });
    }
};
