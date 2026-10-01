<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pisahkan PPh21 terpotong menjadi komponen TERATUR & TIDAK TERATUR (Item lanjutan — Run THR).
     *
     * Tabel `employee_tax_period_totals` sudah memisah BRUTO teratur/irregular
     * (regular_gross / irregular_gross), namun PPh21-nya masih menyatu di
     * `total_pph21_withheld`. Untuk mendukung Run THR terpisah tanpa saling
     * menimpa (hazard clobber), kita bedah PPh21 per masa menjadi:
     *   - regular_pph21   : dimiliki run reguler (gaji bulanan)
     *   - irregular_pph21 : dimiliki run THR/bonus
     * dan `total_pph21_withheld` = regular_pph21 + irregular_pph21 (dihitung ulang
     * oleh PayrollCalculator, masing-masing run hanya "memiliki" satu kolomnya).
     *
     * Backfill: seluruh baris historis hanya berisi PPh21 reguler (Run THR belum
     * pernah ada), maka regular_pph21 = total_pph21_withheld; irregular_pph21 = 0.
     */
    public function up(): void
    {
        Schema::table('employee_tax_period_totals', function (Blueprint $table) {
            $table->decimal('regular_pph21', 15, 2)->default(0)->after('total_pph21_withheld');
            $table->decimal('irregular_pph21', 15, 2)->default(0)->after('regular_pph21');
        });

        // Baris lama: seluruh PPh21 terpotong berasal dari gaji reguler.
        DB::table('employee_tax_period_totals')->update([
            'regular_pph21' => DB::raw('total_pph21_withheld'),
        ]);
    }

    public function down(): void
    {
        Schema::table('employee_tax_period_totals', function (Blueprint $table) {
            $table->dropColumn(['regular_pph21', 'irregular_pph21']);
        });
    }
};
