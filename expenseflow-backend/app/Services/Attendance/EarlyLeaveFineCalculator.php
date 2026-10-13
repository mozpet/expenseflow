<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Support\Timezone;
use Illuminate\Support\Carbon;

/**
 * Menit & denda PULANG CEPAT per presensi (2026-10-11).
 *
 * Kembaran LateFineCalculator untuk sisi pulang. Satu-satunya tempat rumus ini
 * hidup — dipakai checkOut(), AutoCheckoutCommand, dan correctAttendance().
 * Murni fungsi: tidak membaca DB, tidak membaca jam sistem.
 *
 * Definisi "pulang cepat" WAJIB identik dengan
 * AttendanceRecalculator::checkEarlyLeave() jalur non-flexitime:
 * check-out < (jam pulang jadwal − toleransi), dengan tanggal acuan = TANGGAL
 * JADWAL (+1 hari bila shift lintas hari), bukan tanggal check-out. Kalau kedua
 * definisi berbeda, akan ada baris berstatus 'early_leave' tanpa menit — atau
 * sebaliknya — dan payroll ikut salah.
 *
 * MENIT dihitung dari BATAS TOLERANSI, bukan dari jam pulang. Alasannya sama
 * dengan mode after_tolerance pada denda telat: menit yang masih dimaafkan
 * kantor tidak boleh ikut didenda. Pulang 16:45 dengan jam pulang 17:00 dan
 * toleransi 30 menit sama sekali bukan pulang cepat → 0 menit, 0 denda.
 *
 * MODE DENDA (early_leave_fine_mode):
 *   none       : hanya status peringatan — menit tetap dicatat, denda 0.
 *   per_minute : denda = menit × nominal, dibatasi early_leave_fine_max.
 *   flat       : denda = nominal sekali per kejadian (tidak dikali menit),
 *                tetap dibatasi early_leave_fine_max bila diisi.
 *   prorata    : denda presensi 0 — pemotongan dilakukan PayrollCalculator
 *                dari upah per jam karyawan (butuh gaji pokok, yang sengaja
 *                tidak pernah dibaca di lapisan presensi).
 *
 * Menit dibulatkan ke bawah, minimal 1 menit bila statusnya pulang cepat.
 */
class EarlyLeaveFineCalculator
{
    public const MODE_NONE       = 'none';
    public const MODE_PER_MINUTE = 'per_minute';
    public const MODE_FLAT       = 'flat';
    public const MODE_PRORATA    = 'prorata';

    public static function normalizeMode(?string $mode): string
    {
        return in_array($mode, [self::MODE_PER_MINUTE, self::MODE_FLAT, self::MODE_PRORATA], true)
            ? $mode
            : self::MODE_NONE;
    }

    /**
     * Konfigurasi denda pulang cepat yang akan dibekukan ke snapshot presensi.
     *
     * @param int|null $effectiveTolerance Toleransi pulang awal efektif (menit).
     *        NULL pada kantor yang MEMATIKAN fitur early leave
     *        (early_leave_tolerance_minutes NULL) — lihat checkEarlyLeave().
     *
     * @return array{mode: string, amount: ?float, max: ?float, tolerance: ?int}
     */
    public static function configFromOffice(?AttendanceSetting $office, ?int $effectiveTolerance = null): array
    {
        return [
            'mode'      => self::normalizeMode($office?->early_leave_fine_mode),
            'amount'    => $office?->early_leave_fine_amount !== null ? (float) $office->early_leave_fine_amount : null,
            'max'       => $office?->early_leave_fine_max !== null ? (float) $office->early_leave_fine_max : null,
            'tolerance' => $effectiveTolerance ?? (
                $office?->early_leave_tolerance_minutes !== null
                    ? (int) $office->early_leave_tolerance_minutes
                    : null
            ),
        ];
    }

    /**
     * Terapkan mode denda pada sejumlah menit pulang cepat yang sudah diketahui.
     *
     * Dipisah dari calculate() karena jalur FLEXITIME menurunkan menitnya dengan
     * cara lain (mendahului jam inti / kurang dari target harian), tetapi wajib
     * memakai rumus rupiah yang sama persis.
     *
     * @param array{mode: string, amount: ?float, max: ?float, tolerance: ?int} $config
     */
    public static function fineFromMinutes(int $minutes, array $config): float
    {
        if ($minutes <= 0) {
            return 0.0;
        }

        $mode   = self::normalizeMode($config['mode'] ?? null);
        $amount = (float) ($config['amount'] ?? 0);

        $fine = match ($mode) {
            self::MODE_PER_MINUTE => $amount > 0 ? $minutes * $amount : 0.0,
            self::MODE_FLAT       => max(0.0, $amount),
            // 'prorata' dipotong di payroll (butuh gaji pokok); 'none' tanpa denda.
            default               => 0.0,
        };

        $max = $config['max'] ?? null;
        if ($fine > 0 && $max !== null && (float) $max > 0) {
            $fine = min($fine, (float) $max);
        }

        return round($fine, 2);
    }

    /**
     * @param Carbon      $checkOutTime Instan check-out (zona apa pun).
     * @param string|null $workEndTime  Jam pulang lokal cabang 'H:i' efektif hari itu.
     *                                  NULL → tidak bisa dinilai.
     * @param string      $scheduleDate Tanggal JADWAL 'Y-m-d' (tanggal baris presensi),
     *                                  bukan tanggal check-out. Shift lintas hari
     *                                  yang berakhir esok pagi tetap memakai tanggal ini.
     * @param string      $tz           Zona bisnis cabang.
     * @param array{mode: string, amount: ?float, max: ?float, tolerance: ?int} $config
     * @param bool        $isCrossDay   Shift lintas hari: jam pulang ada di hari berikutnya.
     *
     * @return array{early_leave_minutes: int, early_leave_fine_amount: float}
     */
    public static function calculate(
        Carbon $checkOutTime,
        ?string $workEndTime,
        string $scheduleDate,
        string $tz,
        array $config,
        bool $isCrossDay = false,
    ): array {
        $none = ['early_leave_minutes' => 0, 'early_leave_fine_amount' => 0.0];

        // Toleransi NULL = fitur pulang cepat dimatikan di kantor ini.
        if (! $workEndTime || ($config['tolerance'] ?? null) === null) {
            return $none;
        }

        $tz        = Timezone::normalize($tz);
        $local     = $checkOutTime->copy()->setTimezone($tz);
        $tolerance = max(0, (int) $config['tolerance']);

        // Tanggal jam pulang: sama dengan checkEarlyLeave() — tanggal jadwal,
        // ditambah satu hari bila shift bertanda lintas hari.
        $endDate = $isCrossDay
            ? Carbon::parse($scheduleDate, $tz)->addDay()->toDateString()
            : $scheduleDate;

        $end   = Carbon::parse($endDate . ' ' . substr($workEndTime, 0, 5), $tz);
        $limit = $end->copy()->subMinutes($tolerance);

        if (! $local->lessThan($limit)) {
            return $none;
        }

        $minutes = max(1, (int) floor($local->diffInSeconds($limit, true) / 60));

        return [
            'early_leave_minutes'     => $minutes,
            'early_leave_fine_amount' => self::fineFromMinutes($minutes, $config),
        ];
    }

    /**
     * Menit pulang cepat untuk sesi FLEXITIME, mengikuti dua syarat
     * checkEarlyLeave(): mendahului jam inti, atau kurang dari target harian.
     * Diambil yang terbesar supaya satu kejadian tidak didenda dua kali.
     *
     * @param Carbon      $checkOutTime  Instan check-out.
     * @param string|null $coreEndTime   flex_core_end 'H:i' (NULL → '15:00').
     * @param string      $scheduleDate  Tanggal jadwal 'Y-m-d'.
     * @param int|null    $workMinutes   Menit kerja efektif hasil recalculate().
     * @param int         $targetMinutes flex_target_minutes kantor.
     * @param int         $tolerance     early_leave_tolerance_minutes kantor.
     * @param array{mode: string, amount: ?float, max: ?float, tolerance: ?int} $config
     *
     * @return array{early_leave_minutes: int, early_leave_fine_amount: float}
     */
    public static function calculateFlexitime(
        Carbon $checkOutTime,
        ?string $coreEndTime,
        string $scheduleDate,
        string $tz,
        ?int $workMinutes,
        int $targetMinutes,
        int $tolerance,
        array $config,
    ): array {
        $tz      = Timezone::normalize($tz);
        $local   = $checkOutTime->copy()->setTimezone($tz);
        $coreEnd = Carbon::parse(
            $scheduleDate . ' ' . substr((string) ($coreEndTime ?: '15:00'), 0, 5),
            $tz
        );

        $beforeCore = $local->lessThan($coreEnd)
            ? max(1, (int) floor($local->diffInSeconds($coreEnd, true) / 60))
            : 0;

        $minRequired = max(0, $targetMinutes - max(0, $tolerance));
        $shortfall   = $workMinutes !== null && $workMinutes < $minRequired
            ? $minRequired - $workMinutes
            : 0;

        $minutes = max($beforeCore, $shortfall);

        return [
            'early_leave_minutes'     => $minutes,
            'early_leave_fine_amount' => self::fineFromMinutes($minutes, $config),
        ];
    }
}
