<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RENTANG CUT-OFF PRESENSI & RENCANA TANGGAL GAJIAN PER BATCH (2026-10-12)
 *
 * Sampai sekarang `payrolls` hanya menyimpan period_month + period_year, dan
 * PayrollCalculator menurunkan rentang tanggalnya sendiri dengan
 * Carbon::create($year,$month,1) → endOfMonth(). Akibatnya jendela presensi
 * tidak pernah bisa menyeberang batas bulan.
 *
 * Tiga kolom ini membuat rentang itu menjadi DATA, bukan turunan: nilai awal
 * diisi otomatis dari pengaturan grup payroll (cutoff_day/payment_day/
 * payment_month_offset, migrasi 2026_10_12_000001), lalu boleh ditimpa manual
 * oleh HRD lewat PayrollController::store()/update().
 *
 * PEMISAHAN YANG WAJIB DIJAGA:
 * Rentang ini HANYA mengatur agregat presensi (hari hadir, alpha, denda telat,
 * pulang cepat, lembur, cuti, reimbursement, hari kerja efektif). Penanggalan
 * efektif & statutori TETAP memakai akhir bulan kalender period_month/
 * period_year: activeSalaryOn, kurs valas, komponen tetap & formula, BPJS,
 * PPh 21 TER, employee_tax_period_totals.tax_month, cicilan kasbon, is_year_end,
 * period_label, dan jurnal GL. Nama kolom period_start_date/period_end_date
 * mengikuti preseden leave_balance_histories.period_start/period_end.
 *
 * BACKFILL: baris lama diisi dengan bulan kalendernya (tanggal 1 s/d akhir
 * bulan) — murni turunan dari period_month/period_year yang sudah ada, bukan
 * tebakan, sehingga perilaku batch lama identik dengan sebelum migrasi. Tanpa
 * backfill, guard tumpang-tindih dan PayrollPeriodLock harus ber-COALESCE di
 * SQL untuk setiap kueri. Dikerjakan di PHP (bukan LAST_DAY()) agar portabel
 * MySQL maupun SQLite yang dipakai test suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->date('period_start_date')->nullable()->after('period_year');
            $table->date('period_end_date')->nullable()->after('period_start_date');
            $table->date('planned_payment_date')->nullable()->after('period_end_date');

            $table->index(
                ['company_id', 'period_start_date', 'period_end_date'],
                'payrolls_period_range_index',
            );
        });

        // Backfill bulan kalender untuk seluruh batch yang sudah ada.
        // chunkById (bukan chunk): update di dalam loop mengisi kolom yang
        // justru menjadi filter whereNull, sehingga paginasi berbasis OFFSET
        // akan melewatkan baris. chunkById memakai "id > terakhir" → aman.
        DB::table('payrolls')
            ->whereNull('period_start_date')
            ->select('id', 'period_month', 'period_year')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $start = Carbon::create((int) $row->period_year, (int) $row->period_month, 1);
                    if (! $start) {
                        continue; // Data periode tidak valid — jangan tebak, biarkan NULL.
                    }

                    DB::table('payrolls')->where('id', $row->id)->update([
                        'period_start_date' => $start->toDateString(),
                        'period_end_date'   => $start->copy()->endOfMonth()->toDateString(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex('payrolls_period_range_index');
            $table->dropColumn(['period_start_date', 'period_end_date', 'planned_payment_date']);
        });
    }
};
