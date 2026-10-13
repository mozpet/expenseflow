<?php

namespace Tests\Unit;

use App\Services\Attendance\AttendancePolicyCalculator as Policy;
use App\Services\Attendance\EarlyLeaveFineCalculator as EarlyLeave;
use App\Services\Attendance\MissedCheckoutFineCalculator as MissedCheckout;
use App\Services\Attendance\NightShiftBonusCalculator as NightShift;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Uji kalkulator murni "Peraturan Denda & Bonus Kantor" (2026-10-11).
 *
 * Keempat kelas ini tidak membaca DB maupun jam sistem, jadi seluruh perilakunya
 * bisa dibuktikan di sini — termasuk hal yang paling rawan: definisi "pulang
 * cepat" harus memakai TANGGAL JADWAL, bukan tanggal check-out, agar identik
 * dengan AttendanceRecalculator::checkEarlyLeave().
 */
class PenaltyBonusCalculatorTest extends TestCase
{
    /** @return array{mode: string, amount: ?float, max: ?float, tolerance: ?int} */
    private function elConfig(string $mode, ?float $amount, ?float $max = null, ?int $tol = 30): array
    {
        return ['mode' => $mode, 'amount' => $amount, 'max' => $max, 'tolerance' => $tol];
    }

    // ───────────────── Pulang cepat: menit ─────────────────

    public function test_menit_dihitung_dari_batas_toleransi_bukan_jam_pulang(): void
    {
        // Jam pulang 17:00, toleransi 30 menit → batas 16:30. Pulang 16:00 = 30 menit.
        $res = EarlyLeave::calculate(
            Carbon::parse('2026-06-02 16:00:00', 'Asia/Jakarta'),
            '17:00', '2026-06-02', 'Asia/Jakarta',
            $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 1000),
        );

        $this->assertSame(30, $res['early_leave_minutes']);
        $this->assertEqualsWithDelta(30_000, $res['early_leave_fine_amount'], 0.01);
    }

    public function test_pulang_di_dalam_toleransi_bukan_pulang_cepat(): void
    {
        $res = EarlyLeave::calculate(
            Carbon::parse('2026-06-02 16:45:00', 'Asia/Jakarta'),
            '17:00', '2026-06-02', 'Asia/Jakarta',
            $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 1000),
        );

        $this->assertSame(0, $res['early_leave_minutes']);
        $this->assertSame(0.0, $res['early_leave_fine_amount']);
    }

    public function test_toleransi_null_mematikan_fitur(): void
    {
        $res = EarlyLeave::calculate(
            Carbon::parse('2026-06-02 10:00:00', 'Asia/Jakarta'),
            '17:00', '2026-06-02', 'Asia/Jakarta',
            $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 1000, null, null),
        );

        $this->assertSame(0, $res['early_leave_minutes']);
    }

    public function test_shift_lintas_hari_memakai_tanggal_jadwal_plus_satu(): void
    {
        // Jadwal 2 Juni 22:00 → 06:00 (lintas hari). Check-out 3 Juni 04:00
        // berarti pulang cepat 90 menit dari batas 05:30, BUKAN sehari penuh.
        $res = EarlyLeave::calculate(
            Carbon::parse('2026-06-03 04:00:00', 'Asia/Jakarta'),
            '06:00', '2026-06-02', 'Asia/Jakarta',
            $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 500),
            isCrossDay: true,
        );

        $this->assertSame(90, $res['early_leave_minutes']);
        $this->assertEqualsWithDelta(45_000, $res['early_leave_fine_amount'], 0.01);
    }

    public function test_shift_lintas_hari_yang_tuntas_tidak_didenda(): void
    {
        $res = EarlyLeave::calculate(
            Carbon::parse('2026-06-03 06:05:00', 'Asia/Jakarta'),
            '06:00', '2026-06-02', 'Asia/Jakarta',
            $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 500),
            isCrossDay: true,
        );

        $this->assertSame(0, $res['early_leave_minutes']);
    }

    public function test_zona_waktu_cabang_dihormati(): void
    {
        // Check-out ditulis dalam WIB, tetapi cabangnya WITA: 16:00 WIB = 17:00
        // WITA, yang berarti TIDAK pulang cepat di cabang itu.
        $res = EarlyLeave::calculate(
            Carbon::parse('2026-06-02 16:00:00', 'Asia/Jakarta'),
            '17:00', '2026-06-02', 'Asia/Makassar',
            $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 1000),
        );

        $this->assertSame(0, $res['early_leave_minutes']);
    }

    // ───────────────── Pulang cepat: mode rupiah ─────────────────

    public function test_mode_flat_tidak_dikali_menit(): void
    {
        $fine = EarlyLeave::fineFromMinutes(120, $this->elConfig(EarlyLeave::MODE_FLAT, 50_000));

        $this->assertEqualsWithDelta(50_000, $fine, 0.01);
    }

    public function test_mode_per_minute_dibatasi_nilai_maksimum(): void
    {
        $fine = EarlyLeave::fineFromMinutes(
            300,
            $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 1000, 100_000),
        );

        $this->assertEqualsWithDelta(100_000, $fine, 0.01);
    }

    public function test_mode_prorata_tidak_menghasilkan_denda_presensi(): void
    {
        // Rupiahnya dihitung payroll (butuh gaji pokok), bukan di sini.
        $fine = EarlyLeave::fineFromMinutes(120, $this->elConfig(EarlyLeave::MODE_PRORATA, 1000));

        $this->assertSame(0.0, $fine);
    }

    public function test_mode_none_mencatat_menit_tanpa_denda(): void
    {
        $res = EarlyLeave::calculate(
            Carbon::parse('2026-06-02 16:00:00', 'Asia/Jakarta'),
            '17:00', '2026-06-02', 'Asia/Jakarta',
            $this->elConfig(EarlyLeave::MODE_NONE, 1000),
        );

        $this->assertSame(30, $res['early_leave_minutes']);
        $this->assertSame(0.0, $res['early_leave_fine_amount']);
    }

    public function test_mode_tidak_dikenal_jatuh_ke_none(): void
    {
        $this->assertSame(EarlyLeave::MODE_NONE, EarlyLeave::normalizeMode('mode_karangan'));
        $this->assertSame(EarlyLeave::MODE_NONE, EarlyLeave::normalizeMode(null));
    }

    // ───────────────── Pulang cepat: flexitime ─────────────────

    public function test_flexitime_mendahului_jam_inti_dihitung_pulang_cepat(): void
    {
        // Jam inti berakhir 15:00, pulang 14:00 → 60 menit.
        $res = EarlyLeave::calculateFlexitime(
            Carbon::parse('2026-06-02 14:00:00', 'Asia/Jakarta'),
            '15:00', '2026-06-02', 'Asia/Jakarta',
            workMinutes: 480, targetMinutes: 480, tolerance: 0,
            config: $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 500),
        );

        $this->assertSame(60, $res['early_leave_minutes']);
        $this->assertEqualsWithDelta(30_000, $res['early_leave_fine_amount'], 0.01);
    }

    public function test_flexitime_kurang_dari_target_dihitung_pulang_cepat(): void
    {
        // Pulang setelah jam inti tetapi kerja hanya 400 dari target 480 menit.
        $res = EarlyLeave::calculateFlexitime(
            Carbon::parse('2026-06-02 16:00:00', 'Asia/Jakarta'),
            '15:00', '2026-06-02', 'Asia/Jakarta',
            workMinutes: 400, targetMinutes: 480, tolerance: 0,
            config: $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 500),
        );

        $this->assertSame(80, $res['early_leave_minutes']);
    }

    public function test_flexitime_mengambil_menit_terbesar_bukan_menjumlah(): void
    {
        // Dua kriteria terpenuhi sekaligus (pulang 14:00 = 60 menit sebelum jam
        // inti, kerja 400 menit = kurang 80). Harus 80, bukan 140.
        $res = EarlyLeave::calculateFlexitime(
            Carbon::parse('2026-06-02 14:00:00', 'Asia/Jakarta'),
            '15:00', '2026-06-02', 'Asia/Jakarta',
            workMinutes: 400, targetMinutes: 480, tolerance: 0,
            config: $this->elConfig(EarlyLeave::MODE_PER_MINUTE, 500),
        );

        $this->assertSame(80, $res['early_leave_minutes']);
    }

    // ───────────────── Sanksi mangkir (alpha) ─────────────────

    public function test_alpha_prorata_tanpa_denda_tambahan(): void
    {
        $res = Policy::alphaPenalty(2, 500_000, ['alpha_mode' => Policy::ALPHA_PRORATA]);

        $this->assertEqualsWithDelta(1_000_000, $res['prorata'], 0.01);
        $this->assertSame(0.0, $res['fine']);
        $this->assertEqualsWithDelta(1_000_000, $res['total'], 0.01);
    }

    public function test_alpha_none_tanpa_potongan_apa_pun(): void
    {
        $res = Policy::alphaPenalty(5, 500_000, ['alpha_mode' => Policy::ALPHA_NONE]);

        $this->assertSame(0.0, $res['prorata']);
        $this->assertSame(0.0, $res['total']);
    }

    public function test_alpha_prorata_plus_fine_menjumlah_denda_per_hari(): void
    {
        $res = Policy::alphaPenalty(3, 500_000, [
            'alpha_mode'      => Policy::ALPHA_PRORATA_PLUS_FINE,
            'alpha_fine_flat' => 100_000,
        ]);

        $this->assertEqualsWithDelta(1_500_000, $res['prorata'], 0.01);
        $this->assertEqualsWithDelta(300_000, $res['fine'], 0.01);
        $this->assertEqualsWithDelta(1_800_000, $res['total'], 0.01);
    }

    public function test_ambang_surat_peringatan_tetap_dilaporkan_pada_mode_none(): void
    {
        // Mode 'none' tidak memotong rupiah, tetapi HRD tetap perlu tahu
        // ambang SP sudah terlampaui.
        $res = Policy::alphaPenalty(3, 500_000, [
            'alpha_mode'         => Policy::ALPHA_NONE,
            'alpha_sp_threshold' => 3,
        ]);

        $this->assertTrue($res['needs_warning_letter']);
        $this->assertSame(0.0, $res['total']);
    }

    public function test_ambang_surat_peringatan_belum_terlampaui(): void
    {
        $res = Policy::alphaPenalty(2, 500_000, [
            'alpha_mode'         => Policy::ALPHA_PRORATA,
            'alpha_sp_threshold' => 3,
        ]);

        $this->assertFalse($res['needs_warning_letter']);
    }

    public function test_alpha_nol_hari_tidak_memotong(): void
    {
        $res = Policy::alphaPenalty(0, 500_000, [
            'alpha_mode'      => Policy::ALPHA_PRORATA_PLUS_FINE,
            'alpha_fine_flat' => 100_000,
        ]);

        $this->assertSame(0.0, $res['total']);
        $this->assertFalse($res['needs_warning_letter']);
    }

    public function test_alpha_mode_default_adalah_prorata_perilaku_lama(): void
    {
        $this->assertSame(Policy::ALPHA_PRORATA, Policy::normalizeAlphaMode(null));
        $this->assertSame(Policy::ALPHA_PRORATA, Policy::normalizeAlphaMode('mode_karangan'));
    }

    // ───────────────── Potongan izin pribadi ─────────────────

    public function test_izin_mode_flat_dihitung_per_pengajuan(): void
    {
        $res = Policy::unpaidLeavePenalty(
            days: 7, incidents: 2, dailyWage: 500_000,
            config: ['unpaid_leave_mode' => Policy::UNPAID_FLAT, 'unpaid_leave_fine_flat' => 150_000],
        );

        $this->assertEqualsWithDelta(300_000, $res['amount'], 0.01);
        $this->assertSame(2, $res['unit_count']);
    }

    public function test_izin_mode_prorata_dihitung_per_hari(): void
    {
        $res = Policy::unpaidLeavePenalty(
            days: 3, incidents: 1, dailyWage: 500_000,
            config: ['unpaid_leave_mode' => Policy::UNPAID_PRORATA],
        );

        $this->assertEqualsWithDelta(1_500_000, $res['amount'], 0.01);
        $this->assertSame(3, $res['unit_count']);
    }

    public function test_izin_mode_none_adalah_default(): void
    {
        $this->assertSame(Policy::UNPAID_NONE, Policy::normalizeUnpaidLeaveMode(null));

        $res = Policy::unpaidLeavePenalty(5, 2, 500_000, []);
        $this->assertSame(0.0, $res['amount']);
    }

    // ───────────────── Insentif kehadiran penuh ─────────────────

    /** @return array<string, mixed> */
    private function perfectConfig(bool $allowLeave = true): array
    {
        return [
            'perfect_bonus_enabled' => true,
            'perfect_bonus_amount'  => 500_000,
            'perfect_allow_leave'   => $allowLeave,
        ];
    }

    public function test_insentif_diberikan_bila_semua_kriteria_bersih(): void
    {
        $res = Policy::perfectAttendanceBonus(20, 0, 0, 0, 0, 0, $this->perfectConfig());

        $this->assertTrue($res['eligible']);
        $this->assertEqualsWithDelta(500_000, $res['amount'], 0.01);
        $this->assertSame([], $res['reasons']);
    }

    public function test_insentif_gugur_dan_alasannya_dicatat(): void
    {
        $res = Policy::perfectAttendanceBonus(20, 2, 1, 0, 0, 0, $this->perfectConfig());

        $this->assertFalse($res['eligible']);
        $this->assertSame(0.0, $res['amount']);
        $this->assertContains('2 hari telat', $res['reasons']);
        $this->assertContains('1 hari pulang cepat', $res['reasons']);
    }

    public function test_tanpa_hari_hadir_tidak_dapat_insentif(): void
    {
        $res = Policy::perfectAttendanceBonus(0, 0, 0, 0, 0, 0, $this->perfectConfig());

        $this->assertFalse($res['eligible']);
        $this->assertContains('tidak ada hari hadir', $res['reasons']);
    }

    public function test_cuti_resmi_tidak_menggugurkan_bila_allow_leave_true(): void
    {
        $res = Policy::perfectAttendanceBonus(18, 0, 0, 0, 0, 3, $this->perfectConfig(true));

        $this->assertTrue($res['eligible']);
    }

    public function test_cuti_resmi_menggugurkan_bila_allow_leave_false(): void
    {
        $res = Policy::perfectAttendanceBonus(18, 0, 0, 0, 0, 3, $this->perfectConfig(false));

        $this->assertFalse($res['eligible']);
        $this->assertContains('3 hari cuti/sakit', $res['reasons']);
    }

    public function test_insentif_nonaktif_atau_nominal_kosong_tidak_diberikan(): void
    {
        $off = Policy::perfectAttendanceBonus(20, 0, 0, 0, 0, 0, ['perfect_bonus_enabled' => false]);
        $this->assertFalse($off['eligible']);

        $zero = Policy::perfectAttendanceBonus(20, 0, 0, 0, 0, 0, [
            'perfect_bonus_enabled' => true, 'perfect_bonus_amount' => 0,
        ]);
        $this->assertFalse($zero['eligible']);
        $this->assertContains('nominal bonus belum diisi', $zero['reasons']);
    }

    // ───────────────── Prorata pulang cepat di payroll ─────────────────

    public function test_prorata_pulang_cepat_memakai_upah_per_jam(): void
    {
        // 500.000 sehari / 8 jam = 62.500 per jam; 120 menit = 2 jam = 125.000.
        $this->assertEqualsWithDelta(125_000, Policy::earlyLeaveProrata(120, 500_000), 0.01);
    }

    public function test_prorata_pulang_cepat_nol_bila_tanpa_menit_atau_tanpa_upah(): void
    {
        $this->assertSame(0.0, Policy::earlyLeaveProrata(0, 500_000));
        $this->assertSame(0.0, Policy::earlyLeaveProrata(120, 0));
    }

    // ───────────────── Denda lupa check-out ─────────────────

    public function test_lupa_checkout_mode_flat_fine(): void
    {
        $res = MissedCheckout::calculate([
            'mode' => MissedCheckout::MODE_FLAT_FINE, 'amount' => 25_000,
        ]);

        $this->assertEqualsWithDelta(25_000, $res['fine_amount'], 0.01);
        $this->assertFalse($res['mark_early_leave']);
    }

    public function test_lupa_checkout_mode_mark_early_leave_tanpa_rupiah(): void
    {
        // Sanksinya status, bukan uang — ini yang mencegah dobel potong dengan
        // langkah pulang cepat di payroll.
        $res = MissedCheckout::calculate([
            'mode' => MissedCheckout::MODE_MARK_EARLY_LEAVE, 'amount' => 25_000,
        ]);

        $this->assertSame(0.0, $res['fine_amount']);
        $this->assertTrue($res['mark_early_leave']);
    }

    public function test_lupa_checkout_mode_none_adalah_default(): void
    {
        $this->assertSame(MissedCheckout::MODE_NONE, MissedCheckout::normalizeMode(null));

        $res = MissedCheckout::calculate(['mode' => MissedCheckout::MODE_NONE, 'amount' => 25_000]);
        $this->assertSame(0.0, $res['fine_amount']);
        $this->assertFalse($res['mark_early_leave']);
    }

    // ───────────────── Premi shift malam ─────────────────

    public function test_shift_mulai_pukul_22_atau_lebih_adalah_shift_malam(): void
    {
        $this->assertTrue(NightShift::isNightShift('22:00', false));
        $this->assertTrue(NightShift::isNightShift('23:30', false));
    }

    public function test_shift_bertanda_lintas_hari_adalah_shift_malam(): void
    {
        $this->assertTrue(NightShift::isNightShift('20:00', true));
    }

    public function test_shift_siang_bukan_shift_malam(): void
    {
        $this->assertFalse(NightShift::isNightShift('08:00', false));
        $this->assertFalse(NightShift::isNightShift('21:59', false));
    }

    public function test_premi_diberikan_untuk_shift_malam_aktif(): void
    {
        $amount = NightShift::calculate('22:00', false, false, ['enabled' => true, 'amount' => 75_000]);

        $this->assertEqualsWithDelta(75_000, $amount, 0.01);
    }

    public function test_hari_libur_jadwal_tidak_mendapat_premi(): void
    {
        $amount = NightShift::calculate('22:00', true, true, ['enabled' => true, 'amount' => 75_000]);

        $this->assertSame(0.0, $amount);
    }

    public function test_premi_nonaktif_tidak_memberi_apa_pun(): void
    {
        $amount = NightShift::calculate('22:00', false, false, ['enabled' => false, 'amount' => 75_000]);

        $this->assertSame(0.0, $amount);
    }
}
