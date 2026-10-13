<?php

namespace Tests\Unit;

use App\Models\PayrollGroup;
use App\Services\Payroll\PayrollPeriodResolver;
use PHPUnit\Framework\TestCase;

/**
 * Uji unit penerjemah "hari" → "tanggal" periode payroll (2026-10-12).
 *
 * PayrollPeriodResolver sengaja dibuat murni (tanpa DB & tanpa jam sistem),
 * jadi seluruh aturan cut-off presensi + tanggal gajian bisa dibuktikan di
 * sini tanpa RefreshDatabase. Objek PayrollGroup pada test resolveForGroup()
 * TIDAK disimpan ke database — hanya dipakai sebagai pembawa atribut.
 */
class PayrollPeriodResolverTest extends TestCase
{
    private PayrollPeriodResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PayrollPeriodResolver();
    }

    /** @return array{0: string, 1: string} */
    private function range(int $year, int $month, ?int $cutoffDay): array
    {
        [$start, $end] = $this->resolver->resolveRange($year, $month, $cutoffDay);

        return [$start->toDateString(), $end->toDateString()];
    }

    /** cutoff_day NULL = belum diatur → bulan kalender penuh (perilaku lama). */
    public function test_cutoff_null_falls_back_to_full_calendar_month(): void
    {
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->range(2026, 10, null));
    }

    /** cutoff_day 0 = "akhir bulan" → hasilnya sama dengan bulan kalender. */
    public function test_cutoff_zero_means_end_of_month(): void
    {
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->range(2026, 10, 0));
        $this->assertSame(['2026-02-01', '2026-02-28'], $this->range(2026, 2, 0));
    }

    /** Kasus utama permintaan HRD: tutup buku tanggal 20. */
    public function test_cutoff_twenty_spans_previous_month(): void
    {
        $this->assertSame(['2026-09-21', '2026-10-20'], $this->range(2026, 10, 20));
    }

    /** Menyeberang tahun: periode Januari menarik presensi Desember tahun lalu. */
    public function test_cutoff_in_january_reaches_previous_year(): void
    {
        $this->assertSame(['2025-12-21', '2026-01-20'], $this->range(2026, 1, 20));
    }

    /**
     * Clamping ujung AKHIR: cut-off 31 pada Februari tidak boleh melimpah
     * ke Maret. Inilah sebabnya validasi boleh menerima 1-31 apa adanya.
     */
    public function test_cutoff_thirty_one_is_clamped_in_february(): void
    {
        $this->assertSame(['2026-02-01', '2026-02-28'], $this->range(2026, 2, 31));
    }

    /**
     * Clamping ujung MULAI: cut-off 31 pada Maret harus mulai 1 Maret
     * (28 Feb + 1 hari), bukan "32 Februari".
     */
    public function test_cutoff_thirty_one_start_is_day_after_short_previous_month(): void
    {
        $this->assertSame(['2026-03-01', '2026-03-31'], $this->range(2026, 3, 31));
    }

    /** Tahun kabisat: 29 Februari ikut terhitung. */
    public function test_leap_year_february_has_twenty_nine_days(): void
    {
        $this->assertSame(['2024-02-01', '2024-02-29'], $this->range(2024, 2, 0));
        $this->assertSame(['2024-02-01', '2024-02-29'], $this->range(2024, 2, 31));
    }

    /**
     * Cut-off 1 tetap jendela penuh satu bulan — 2 Sep s/d 1 Okt, BUKAN satu
     * hari. Aturannya konsisten: "(d+1) bulan lalu s/d d bulan ini" berlaku
     * untuk semua d, jadi setiap tanggal presensi masuk tepat satu periode.
     */
    public function test_cutoff_one_still_covers_a_full_month_window(): void
    {
        $this->assertSame(['2026-09-02', '2026-10-01'], $this->range(2026, 10, 1));
    }

    /**
     * Konsekuensi penting dari aturan itu: periode berurutan harus BERSAMBUNG
     * tanpa celah dan tanpa tumpang-tindih, sehingga tidak ada hari presensi
     * yang terbayar dua kali atau terlewat.
     */
    public function test_consecutive_periods_are_contiguous(): void
    {
        foreach ([1, 15, 20, 31] as $day) {
            [, $prevEnd] = $this->resolver->resolveRange(2026, 9, $day);
            [$thisStart] = $this->resolver->resolveRange(2026, 10, $day);

            $this->assertSame(
                $prevEnd->copy()->addDay()->toDateString(),
                $thisStart->toDateString(),
                "cutoff {$day}: periode Sep & Okt tidak bersambung"
            );
        }
    }

    /** Rentang hasil resolver selalu di dalam batas MIN/MAX yang dijaga controller. */
    public function test_resolved_range_always_within_allowed_length(): void
    {
        foreach ([null, 0, 1, 15, 20, 28, 31] as $day) {
            foreach ([1, 2, 3, 6, 12] as $month) {
                [$start, $end] = $this->resolver->resolveRange(2026, $month, $day);
                $days = $start->diffInDays($end) + 1;

                $this->assertGreaterThanOrEqual(
                    PayrollPeriodResolver::MIN_RANGE_DAYS,
                    $days,
                    "cutoff {$day} bulan {$month} terlalu pendek"
                );
                $this->assertLessThanOrEqual(
                    PayrollPeriodResolver::MAX_RANGE_DAYS,
                    $days,
                    "cutoff {$day} bulan {$month} terlalu panjang"
                );
            }
        }
    }

    /** payment_day NULL = belum diatur → tidak ada rencana tanggal gajian. */
    public function test_payment_day_null_returns_null(): void
    {
        $this->assertNull($this->resolver->resolvePaymentDate(2026, 10, null));
        $this->assertNull($this->resolver->resolvePaymentDate(2026, 10, null, 1));
    }

    /** Gajian tanggal 25 di bulan periode itu sendiri (offset 0). */
    public function test_payment_day_same_month(): void
    {
        $this->assertSame(
            '2026-10-25',
            $this->resolver->resolvePaymentDate(2026, 10, 25, 0)?->toDateString()
        );
    }

    /** Gajian tanggal 25 di bulan berikutnya (offset 1), termasuk lintas tahun. */
    public function test_payment_day_next_month(): void
    {
        $this->assertSame(
            '2026-11-25',
            $this->resolver->resolvePaymentDate(2026, 10, 25, 1)?->toDateString()
        );
        $this->assertSame(
            '2027-01-25',
            $this->resolver->resolvePaymentDate(2026, 12, 25, 1)?->toDateString()
        );
    }

    /** payment_day 0 = akhir bulan, ikut panjang bulan sasaran. */
    public function test_payment_day_zero_is_end_of_target_month(): void
    {
        $this->assertSame(
            '2026-10-31',
            $this->resolver->resolvePaymentDate(2026, 10, 0, 0)?->toDateString()
        );
        $this->assertSame(
            '2026-02-28',
            $this->resolver->resolvePaymentDate(2026, 1, 0, 1)?->toDateString()
        );
    }

    /** Clamping tanggal gajian: 31 pada bulan pendek jatuh ke hari terakhir. */
    public function test_payment_day_is_clamped_to_target_month_length(): void
    {
        $this->assertSame(
            '2026-02-28',
            $this->resolver->resolvePaymentDate(2026, 2, 31, 0)?->toDateString()
        );
        $this->assertSame(
            '2026-04-30',
            $this->resolver->resolvePaymentDate(2026, 3, 31, 1)?->toDateString()
        );
    }

    /** Tanpa grup payroll → bulan kalender penuh, tanpa rencana gajian. */
    public function test_resolve_for_group_without_group_is_calendar_month(): void
    {
        $this->assertSame([
            'period_start_date'    => '2026-10-01',
            'period_end_date'      => '2026-10-31',
            'planned_payment_date' => null,
            'source'               => 'calendar',
        ], $this->resolver->resolveForGroup(2026, 10, null));
    }

    /** Grup dengan pengaturan lengkap → rentang cut-off + rencana gajian. */
    public function test_resolve_for_group_with_cutoff_and_payment(): void
    {
        $group = new PayrollGroup([
            'cutoff_day'           => 20,
            'payment_day'          => 25,
            'payment_month_offset' => 0,
        ]);

        $this->assertSame([
            'period_start_date'    => '2026-09-21',
            'period_end_date'      => '2026-10-20',
            'planned_payment_date' => '2026-10-25',
            'source'               => 'group',
        ], $this->resolver->resolveForGroup(2026, 10, $group));
    }

    /**
     * Grup yang ADA tapi BELUM mengatur tanggal tetap dilaporkan 'calendar',
     * supaya modal bisa menjelaskan ke HRD bahwa angka itu bukan dari grup.
     */
    public function test_resolve_for_group_without_dates_reports_calendar_source(): void
    {
        $group = new PayrollGroup(['cutoff_day' => null, 'payment_day' => null]);

        $resolved = $this->resolver->resolveForGroup(2026, 10, $group);

        $this->assertSame('calendar', $resolved['source']);
        $this->assertSame('2026-10-01', $resolved['period_start_date']);
        $this->assertSame('2026-10-31', $resolved['period_end_date']);
        $this->assertNull($resolved['planned_payment_date']);
    }

    /** Grup dengan payment_day saja (cut-off tetap bulan kalender) → 'group'. */
    public function test_resolve_for_group_with_payment_day_only(): void
    {
        $group = new PayrollGroup([
            'cutoff_day'           => null,
            'payment_day'          => 5,
            'payment_month_offset' => 1,
        ]);

        $this->assertSame([
            'period_start_date'    => '2026-10-01',
            'period_end_date'      => '2026-10-31',
            'planned_payment_date' => '2026-11-05',
            'source'               => 'group',
        ], $this->resolver->resolveForGroup(2026, 10, $group));
    }
}
