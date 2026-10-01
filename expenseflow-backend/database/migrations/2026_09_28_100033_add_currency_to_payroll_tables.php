<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penggajian Multi-Mata Uang (Fase 6) — kolom valuta pada gaji, batch & slip.
 *
 * KEPUTUSAN DESAIN (penting agar tidak merusak modul hilir):
 * seluruh kolom uang pada `payslips` TETAP dalam RUPIAH (mata uang dasar), karena
 * pajak (PPh 21/26), iuran BPJS, jurnal GL, 1721-A1 dan bukti potong wajib disajikan
 * dalam Rupiah. Nominal valuta asing disimpan sebagai kolom TAMBAHAN (`*_currency`)
 * beserta kurs yang dipakai, untuk tampilan slip & berkas transfer bank valas.
 *
 * - `employee_salaries.currency` : mata uang KONTRAK gaji pokok (default IDR).
 * - `payrolls.exchange_rates`    : snapshot kurs per batch `{"USD": 16000, ...}`
 *                                  (kurs KMK/tengah BI yang dipakai saat menghitung).
 * - `payslips.currency`/`exchange_rate`/`gross_currency`/`net_currency`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->string('currency', 3)->default('IDR')->after('basic_salary');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            // Peta kurs {kode mata uang → kurs ke IDR} yang dikunci saat batch dihitung.
            $table->json('exchange_rates')->nullable()->after('is_year_end');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->string('currency', 3)->default('IDR')->after('ptkp_status');
            $table->decimal('exchange_rate', 15, 6)->default(1)->after('currency');
            $table->decimal('gross_currency', 15, 2)->default(0)->after('exchange_rate');
            $table->decimal('net_currency', 15, 2)->default(0)->after('gross_currency');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate', 'gross_currency', 'net_currency']);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('exchange_rates');
        });

        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
