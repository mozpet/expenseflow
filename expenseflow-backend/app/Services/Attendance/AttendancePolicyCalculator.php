<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;

/**
 * KEBIJAKAN DENDA & BONUS TINGKAT PERIODE (2026-10-11).
 *
 * Tiga dari enam kebijakan baru tidak bisa dibekukan per baris presensi karena
 * baru punya arti setelah SELURUH periode payroll diketahui:
 *
 *   - Sanksi mangkir/alpha          → butuh jumlah hari alpha sebulan.
 *   - Potongan izin pribadi         → butuh jumlah hari/insiden izin sebulan.
 *   - Insentif kehadiran penuh      → "penuh" hanya terbukti di akhir periode.
 *
 * Plus satu turunan presensi yang sengaja ditunda ke payroll:
 *   - Pulang cepat mode 'prorata'   → butuh upah per jam (gaji pokok), yang
 *     TIDAK BOLEH dibaca di lapisan presensi.
 *
 * Murni fungsi: tidak membaca DB, tidak membaca jam sistem, tidak tahu Payroll.
 * Yang membekukan hasilnya adalah batch payroll (payslip approved/paid immutable).
 */
class AttendancePolicyCalculator
{
    // ── Mode sanksi mangkir/alpha ────────────────────────────────────────────
    /** Prorata harian saja — perilaku lama sebelum fitur ini ada. */
    public const ALPHA_PRORATA            = 'prorata';
    /** Prorata harian + denda disiplin flat per hari alpha. */
    public const ALPHA_PRORATA_PLUS_FINE  = 'prorata_plus_fine';
    /** Tanpa potongan gaji pokok sama sekali (hanya peringatan). */
    public const ALPHA_NONE               = 'none';

    // ── Mode potongan izin pribadi ───────────────────────────────────────────
    /** Bebas potongan — kompensasi penuh perusahaan (default). */
    public const UNPAID_NONE    = 'none';
    /** Nominal flat sekali per PENGAJUAN izin (bukan per hari). */
    public const UNPAID_FLAT    = 'flat';
    /** Prorata gaji pokok harian per HARI izin. */
    public const UNPAID_PRORATA = 'prorata';

    /** Jam kerja sehari untuk mengonversi upah harian → upah per jam. */
    public const HOURS_PER_DAY = 8;

    public static function normalizeAlphaMode(?string $mode): string
    {
        return in_array($mode, [self::ALPHA_PRORATA_PLUS_FINE, self::ALPHA_NONE], true)
            ? $mode
            : self::ALPHA_PRORATA;
    }

    public static function normalizeUnpaidLeaveMode(?string $mode): string
    {
        return in_array($mode, [self::UNPAID_FLAT, self::UNPAID_PRORATA], true)
            ? $mode
            : self::UNPAID_NONE;
    }

    /**
     * Seluruh konfigurasi kebijakan periode satu cabang.
     *
     * Kantor NULL (karyawan tanpa penempatan) jatuh ke default netral:
     * alpha prorata (perilaku lama), izin bebas potongan, tanpa bonus.
     *
     * @return array{
     *     alpha_mode: string, alpha_fine_flat: float, alpha_sp_threshold: int,
     *     unpaid_leave_mode: string, unpaid_leave_fine_flat: float,
     *     perfect_bonus_enabled: bool, perfect_bonus_amount: float, perfect_allow_leave: bool
     * }
     */
    public static function configFromOffice(?AttendanceSetting $office): array
    {
        return [
            'alpha_mode'             => self::normalizeAlphaMode($office?->alpha_penalty_mode),
            'alpha_fine_flat'        => (float) ($office?->alpha_fine_flat ?? 0),
            'alpha_sp_threshold'     => (int) ($office?->alpha_sp_threshold ?? 3),
            'unpaid_leave_mode'      => self::normalizeUnpaidLeaveMode($office?->unpaid_leave_penalty_mode),
            'unpaid_leave_fine_flat' => (float) ($office?->unpaid_leave_fine_flat ?? 0),
            'perfect_bonus_enabled'  => (bool) ($office?->perfect_attendance_bonus_enabled ?? false),
            'perfect_bonus_amount'   => (float) ($office?->perfect_attendance_bonus_amount ?? 0),
            'perfect_allow_leave'    => (bool) ($office?->perfect_attendance_allow_leave ?? true),
        ];
    }

    /**
     * Sanksi mangkir/alpha untuk satu periode.
     *
     * 'prorata' mengembalikan potongan prorata yang NILAINYA SAMA dengan
     * potongan ketidakhadiran lama — pemanggil wajib memilih satu baris slip,
     * jangan menjumlahkan keduanya.
     *
     * @param  float $dailyWage Upah sehari (gaji pokok / divisor hari kerja).
     * @return array{mode: string, prorata: float, fine: float, total: float, needs_warning_letter: bool}
     */
    public static function alphaPenalty(int $alphaDays, float $dailyWage, array $config): array
    {
        $mode  = self::normalizeAlphaMode($config['alpha_mode'] ?? null);
        $days  = max(0, $alphaDays);
        $none  = ['mode' => $mode, 'prorata' => 0.0, 'fine' => 0.0, 'total' => 0.0, 'needs_warning_letter' => false];

        if ($days === 0) {
            return $none;
        }

        $threshold = (int) ($config['alpha_sp_threshold'] ?? 3);
        $none['needs_warning_letter'] = $threshold > 0 && $days >= $threshold;

        if ($mode === self::ALPHA_NONE) {
            return $none;
        }

        $prorata = round(max(0.0, $dailyWage) * $days, 2);
        $fine    = $mode === self::ALPHA_PRORATA_PLUS_FINE
            ? round(max(0.0, (float) ($config['alpha_fine_flat'] ?? 0)) * $days, 2)
            : 0.0;

        return [
            'mode'                 => $mode,
            'prorata'              => $prorata,
            'fine'                 => $fine,
            'total'                => round($prorata + $fine, 2),
            'needs_warning_letter' => $none['needs_warning_letter'],
        ];
    }

    /**
     * Potongan izin pribadi (ketidakhadiran berizin non-kuota cuti).
     *
     * @param  int   $days       Jumlah HARI izin dalam periode (untuk mode prorata).
     * @param  int   $incidents  Jumlah PENGAJUAN izin dalam periode (untuk mode flat).
     * @param  float $dailyWage  Upah sehari (gaji pokok / divisor hari kerja).
     * @return array{mode: string, amount: float, unit_count: int}
     */
    public static function unpaidLeavePenalty(int $days, int $incidents, float $dailyWage, array $config): array
    {
        $mode = self::normalizeUnpaidLeaveMode($config['unpaid_leave_mode'] ?? null);
        $days = max(0, $days);
        $inc  = max(0, $incidents);

        if ($mode === self::UNPAID_NONE) {
            return ['mode' => $mode, 'amount' => 0.0, 'unit_count' => 0];
        }

        if ($mode === self::UNPAID_PRORATA) {
            return [
                'mode'       => $mode,
                'amount'     => round(max(0.0, $dailyWage) * $days, 2),
                'unit_count' => $days,
            ];
        }

        $flat = max(0.0, (float) ($config['unpaid_leave_fine_flat'] ?? 0));

        return [
            'mode'       => $mode,
            'amount'     => round($flat * $inc, 2),
            'unit_count' => $inc,
        ];
    }

    /**
     * Insentif kehadiran penuh / uang kerajinan.
     *
     * KRITERIA (sama dengan yang ditampilkan form kantor):
     *   0 hari telat · 0 hari pulang cepat · 0 hari alpha · 0 hari izin pribadi.
     * Cuti tahunan resmi & sakit berizin tidak menggugurkan bonus bila
     * perfect_allow_leave = true (default). Bila false, cuti pun menggugurkan.
     *
     * Karyawan tanpa satu pun hari hadir di periode ini TIDAK dapat bonus —
     * "kehadiran penuh" tanpa kehadiran bukan prestasi.
     *
     * @return array{eligible: bool, amount: float, reasons: array<int, string>}
     */
    public static function perfectAttendanceBonus(
        int $presentDays,
        int $lateDays,
        int $earlyLeaveDays,
        int $alphaDays,
        int $unpaidLeaveDays,
        int $paidLeaveDays,
        array $config,
    ): array {
        if (! ($config['perfect_bonus_enabled'] ?? false)) {
            return ['eligible' => false, 'amount' => 0.0, 'reasons' => ['bonus tidak diaktifkan']];
        }

        $amount = round(max(0.0, (float) ($config['perfect_bonus_amount'] ?? 0)), 2);
        if ($amount <= 0) {
            return ['eligible' => false, 'amount' => 0.0, 'reasons' => ['nominal bonus belum diisi']];
        }

        $reasons = [];
        if ($presentDays <= 0) {
            $reasons[] = 'tidak ada hari hadir';
        }
        if ($lateDays > 0) {
            $reasons[] = $lateDays . ' hari telat';
        }
        if ($earlyLeaveDays > 0) {
            $reasons[] = $earlyLeaveDays . ' hari pulang cepat';
        }
        if ($alphaDays > 0) {
            $reasons[] = $alphaDays . ' hari alpha';
        }
        if ($unpaidLeaveDays > 0) {
            $reasons[] = $unpaidLeaveDays . ' hari izin pribadi';
        }
        if ($paidLeaveDays > 0 && ! ($config['perfect_allow_leave'] ?? true)) {
            $reasons[] = $paidLeaveDays . ' hari cuti/sakit';
        }

        return [
            'eligible' => $reasons === [],
            'amount'   => $reasons === [] ? $amount : 0.0,
            'reasons'  => $reasons,
        ];
    }

    /**
     * Potongan prorata pulang cepat (mode early_leave_fine_mode = 'prorata').
     *
     * Upah per jam = upah sehari / HOURS_PER_DAY, dikali jam yang tidak dijalani.
     * Dipakai HANYA untuk mode 'prorata'; mode per_minute/flat sudah dibekukan
     * sebagai rupiah di baris presensi oleh EarlyLeaveFineCalculator.
     */
    public static function earlyLeaveProrata(int $earlyLeaveMinutes, float $dailyWage): float
    {
        $minutes = max(0, $earlyLeaveMinutes);
        if ($minutes === 0 || $dailyWage <= 0) {
            return 0.0;
        }

        $hourlyWage = $dailyWage / self::HOURS_PER_DAY;

        return round($hourlyWage * ($minutes / 60), 2);
    }
}
