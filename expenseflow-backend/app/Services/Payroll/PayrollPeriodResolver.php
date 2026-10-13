<?php

namespace App\Services\Payroll;

use App\Models\PayrollGroup;
use Illuminate\Support\Carbon;

/**
 * PENERJEMAH "HARI" → "TANGGAL" UNTUK PERIODE PAYROLL (2026-10-12)
 *
 * Satu-satunya tempat aturan cut-off presensi & tanggal gajian diterjemahkan
 * dari pengaturan grup payroll (cutoff_day / payment_day /
 * payment_month_offset) menjadi tanggal konkret. Dipakai bersama oleh
 * PayrollController::store(), update(), dan periodPreview() — endpoint
 * pratinjau ada justru agar frontend TIDAK menduplikasi aturan ini di
 * TypeScript lalu menyimpang dari backend.
 *
 * Kelas ini murni: tidak menyentuh DB, tidak membaca jam sistem, sehingga
 * seluruh perilakunya bisa dibuktikan di tests/Unit/PayrollPeriodResolverTest.
 */
class PayrollPeriodResolver
{
    /** Nilai hari khusus: "akhir bulan" (tanggal terakhir, 28/29/30/31). */
    public const DAY_END_OF_MONTH = 0;

    /** Batas panjang rentang cut-off yang dianggap wajar (hari). */
    public const MIN_RANGE_DAYS = 1;

    public const MAX_RANGE_DAYS = 62;

    /**
     * Rentang jendela presensi untuk satu periode payroll.
     *
     * - $cutoffDay NULL atau 0 → tanggal 1 s/d akhir bulan (perilaku lama).
     * - $cutoffDay = d         → (d+1) bulan sebelumnya s/d d bulan ini.
     *   Mis. cut-off 20 untuk periode Oktober 2026 → 21 Sep s/d 20 Okt 2026.
     *
     * Hari SELALU di-clamp ke panjang bulan masing-masing ujung, jadi cut-off
     * 31 pada periode Februari berakhir 28/29 Feb — tidak pernah melimpah ke
     * bulan berikutnya. Inilah sebabnya validasi boleh menerima 1-31 apa adanya.
     *
     * @return array{0: Carbon, 1: Carbon} [mulai 00:00, selesai 00:00]
     */
    public function resolveRange(int $year, int $month, ?int $cutoffDay): array
    {
        $base = Carbon::create($year, $month, 1)->startOfDay();

        // Belum diatur / akhir bulan → bulan kalender penuh.
        if ($cutoffDay === null || $cutoffDay === self::DAY_END_OF_MONTH) {
            return [$base, $base->copy()->endOfMonth()->startOfDay()];
        }

        $end = $this->dayInMonth($base, $cutoffDay);

        // Mulai = sehari setelah cut-off bulan sebelumnya. Memakai addDay() dari
        // tanggal cut-off bulan lalu (bukan $cutoffDay + 1) supaya clamping bulan
        // pendek tetap benar: cut-off 31 pada periode Maret → mulai 1 Maret
        // (28 Feb + 1), bukan 32 Februari.
        $prevMonth = $base->copy()->subMonthNoOverflow();
        $start = $this->dayInMonth($prevMonth, $cutoffDay)->addDay();

        return [$start->startOfDay(), $end->startOfDay()];
    }

    /**
     * Rencana tanggal gajian, atau NULL bila grup belum mengatur payment_day.
     *
     * $offset 0 = dibayarkan di bulan periode itu sendiri, 1 = bulan berikutnya.
     */
    public function resolvePaymentDate(int $year, int $month, ?int $paymentDay, int $offset = 0): ?Carbon
    {
        if ($paymentDay === null) {
            return null;
        }

        // subMonthNoOverflow/addMonthNoOverflow: dari tanggal 1 keduanya aman,
        // tetapi dipakai konsisten agar tidak ada jalur overflow tersembunyi.
        $base = Carbon::create($year, $month, 1)->startOfDay();
        if ($offset > 0) {
            $base = $base->addMonthsNoOverflow($offset);
        }

        if ($paymentDay === self::DAY_END_OF_MONTH) {
            return $base->endOfMonth()->startOfDay();
        }

        return $this->dayInMonth($base, $paymentDay)->startOfDay();
    }

    /**
     * Rentang + rencana gajian sekaligus, langsung dari sebuah grup payroll.
     * Grup NULL → bulan kalender penuh tanpa rencana gajian (keputusan produk:
     * batch tanpa grup berperilaku persis seperti sebelum fitur ini ada).
     *
     * @return array{
     *     period_start_date: string,
     *     period_end_date: string,
     *     planned_payment_date: string|null,
     *     source: string
     * }
     */
    public function resolveForGroup(int $year, int $month, ?PayrollGroup $group): array
    {
        $cutoffDay  = $group?->cutoff_day;
        $paymentDay = $group?->payment_day;
        $offset     = (int) ($group?->payment_month_offset ?? 0);

        [$start, $end] = $this->resolveRange($year, $month, $cutoffDay);
        $payment = $this->resolvePaymentDate($year, $month, $paymentDay, $offset);

        return [
            'period_start_date'    => $start->toDateString(),
            'period_end_date'      => $end->toDateString(),
            'planned_payment_date' => $payment?->toDateString(),
            // 'group' hanya bila grup benar-benar punya pengaturan tanggal;
            // grup tanpa pengaturan tetap 'calendar' agar frontend bisa
            // menjelaskan ke HRD dari mana angka itu berasal.
            'source' => ($cutoffDay !== null || $paymentDay !== null) ? 'group' : 'calendar',
        ];
    }

    /**
     * Tanggal ke-$day pada bulan $month, di-clamp ke hari terakhir bulan itu.
     * Inti pengaman overflow seluruh kelas ini.
     */
    private function dayInMonth(Carbon $month, int $day): Carbon
    {
        $firstOfMonth = $month->copy()->startOfMonth();
        $clamped = max(1, min($day, $firstOfMonth->daysInMonth));

        return $firstOfMonth->setDay($clamped);
    }
}
