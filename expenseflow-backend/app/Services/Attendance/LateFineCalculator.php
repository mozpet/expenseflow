<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Support\Timezone;
use Illuminate\Support\Carbon;

/**
 * Menit keterlambatan & denda per presensi (2026-10-09).
 *
 * Satu-satunya tempat rumus ini hidup — dipakai checkIn() dan correctAttendance().
 * Murni fungsi: tidak membaca DB, tidak membaca jam sistem.
 *
 * Definisi "telat" IDENTIK dengan AttendanceController::determineStatus():
 * check-in > jam acuan + toleransi (perbandingan sampai detik). Hanya cara
 * menghitung menitnya yang bergantung pada mode:
 *
 *   after_tolerance  : menit = selisih dari (jam acuan + toleransi)   08:16, tol 15 → 1
 *   after_work_start : menit = selisih dari jam acuan                  08:16, tol 15 → 16
 *                      (dalam toleransi: tidak telat sama sekali, tanpa denda)
 *
 * Menit dibulatkan ke bawah, minimal 1 menit bila statusnya telat.
 */
class LateFineCalculator
{
    public const MODE_AFTER_TOLERANCE  = 'after_tolerance';
    public const MODE_AFTER_WORK_START = 'after_work_start';

    public static function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_AFTER_WORK_START
            ? self::MODE_AFTER_WORK_START
            : self::MODE_AFTER_TOLERANCE;
    }

    /**
     * Konfigurasi denda yang akan dibekukan ke snapshot presensi.
     *
     * @return array{rate: ?float, mode: string, max: ?float, tolerance: int}
     */
    public static function configFromOffice(?AttendanceSetting $office, ?int $effectiveTolerance = null): array
    {
        return [
            'rate'      => $office?->late_fine_per_minute !== null ? (float) $office->late_fine_per_minute : null,
            'mode'      => self::normalizeMode($office?->late_count_mode),
            'max'       => $office?->late_fine_max_per_day !== null ? (float) $office->late_fine_max_per_day : null,
            'tolerance' => $effectiveTolerance ?? (int) ($office?->late_tolerance_minutes ?? 15),
        ];
    }

    /**
     * @param Carbon      $checkInTime Instan check-in (zona apa pun).
     * @param string|null $referenceTime Jam acuan lokal cabang 'H:i' (jam masuk; untuk
     *        flexitime: batas akhir jendela kedatangan). NULL → tidak bisa dinilai.
     * @param string      $tz  Zona bisnis cabang.
     * @param array{rate: ?float, mode: string, max: ?float, tolerance: int} $config
     *
     * @return array{late_minutes: int, late_fine_amount: float}
     */
    public static function calculate(Carbon $checkInTime, ?string $referenceTime, string $tz, array $config): array
    {
        $none = ['late_minutes' => 0, 'late_fine_amount' => 0.0];

        if (! $referenceTime) {
            return $none;
        }

        $tz        = Timezone::normalize($tz);
        $local     = $checkInTime->copy()->setTimezone($tz);
        $tolerance = max(0, (int) ($config['tolerance'] ?? 0));
        $start     = Carbon::parse($local->toDateString() . ' ' . substr($referenceTime, 0, 5), $tz);
        $limit     = $start->copy()->addMinutes($tolerance);

        if (! $local->greaterThan($limit)) {
            return $none;
        }

        $base    = self::normalizeMode($config['mode'] ?? null) === self::MODE_AFTER_WORK_START ? $start : $limit;
        $minutes = max(1, (int) floor($base->diffInSeconds($local, true) / 60));

        $rate = (float) ($config['rate'] ?? 0);
        $fine = $rate > 0 ? $minutes * $rate : 0.0;
        $max  = $config['max'] ?? null;
        if ($max !== null && (float) $max > 0) {
            $fine = min($fine, (float) $max);
        }

        return ['late_minutes' => $minutes, 'late_fine_amount' => round($fine, 2)];
    }
}
