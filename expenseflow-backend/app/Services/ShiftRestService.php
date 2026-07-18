<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * ShiftRestService — validasi jeda istirahat antar shift (standar K3).
 *
 * Aturan:
 *   < 8 jam  → status 'error'   → penugasan DITOLAK
 *   8–11 jam → status 'warning' → penugasan BOLEH, HRD diberi peringatan
 *   ≥ 11 jam → status 'safe'    → aman, tanpa peringatan
 */
class ShiftRestService
{
    /** Jeda minimum wajib (jam). Di bawah ini penugasan ditolak. */
    public const MIN_REST_HOURS = 8;

    /** Jeda ideal yang direkomendasikan (jam). Di bawah ini muncul warning. */
    public const RECOMMENDED_REST_HOURS = 11;

    /**
     * Hitung jeda antara waktu selesai shift lama dan waktu mulai shift baru.
     *
     * @param  string $endTime   Waktu selesai shift lama  (format "H:i" atau "H:i:s")
     * @param  string $startTime Waktu mulai shift baru    (format "H:i" atau "H:i:s")
     * @param  bool   $endIsCrossDay  true jika shift lama cross-day (jam pulang esok hari)
     * @param  int    $daysBetween    Selisih hari kalender antara hari shift lama dengan hari shift baru
     *                                (0 = hari yang sama, 1 = hari berikutnya, dst.)
     * @return array{hours: float, minutes: int, status: string, valid: bool, message: string|null}
     */
    public function checkGap(
        string $endTime,
        string $startTime,
        bool $endIsCrossDay = false,
        int $daysBetween = 1
    ): array {
        // Bangun Carbon untuk waktu selesai shift lama (acuan: tanggal 0)
        $base   = Carbon::createFromFormat('Y-m-d', '2000-01-01');
        $endDt  = $base->copy()->setTimeFromTimeString($endTime);

        // Jika shift lama cross-day, jam pulangnya jatuh di hari berikutnya
        if ($endIsCrossDay) {
            $endDt->addDay();
        }

        // Waktu mulai shift baru = base + daysBetween hari
        $startDt = $base->copy()->addDays($daysBetween)->setTimeFromTimeString($startTime);

        $gapMinutes = $endDt->diffInMinutes($startDt, false);

        // Jika negatif artinya startDt < endDt (mustahil jika data valid) — anggap 0
        if ($gapMinutes < 0) {
            $gapMinutes = 0;
        }

        $gapHours = $gapMinutes / 60;

        return $this->buildResult((float) $gapHours, (int) $gapMinutes);
    }

    /**
     * Hitung jeda antara dua Carbon datetime secara langsung.
     * Dipakai saat membandingkan shift aktif karyawan dengan shift baru yang di-assign.
     *
     * @param  Carbon $shiftEndDatetime   Waktu selesai shift lama (tanggal + jam)
     * @param  Carbon $shiftStartDatetime Waktu mulai shift baru  (tanggal + jam)
     * @return array{hours: float, minutes: int, status: string, valid: bool, message: string|null}
     */
    public function checkGapBetweenDatetimes(Carbon $shiftEndDatetime, Carbon $shiftStartDatetime): array
    {
        $gapMinutes = $shiftEndDatetime->diffInMinutes($shiftStartDatetime, false);

        if ($gapMinutes < 0) {
            $gapMinutes = 0;
        }

        return $this->buildResult($gapMinutes / 60, (int) $gapMinutes);
    }

    /**
     * Validasi jeda antar hari berturutan dalam sebuah template shift (7 hari).
     * Dipakai saat HRD membuat/mengubah template.
     *
     * @param  array $schedules Array 7 ScheduleRow yang sudah di-sort by day_of_week.
     *                          Setiap item: ['day_of_week', 'is_off', 'work_start_time',
     *                                        'work_end_time', 'is_cross_day']
     * @return array{errors: string[], warnings: string[]}
     */
    public function validateTemplateGaps(array $schedules): array
    {
        // Index by day_of_week agar lookup O(1)
        $byDay = [];
        foreach ($schedules as $sch) {
            $byDay[(int) $sch['day_of_week']] = $sch;
        }

        $errors   = [];
        $warnings = [];

        // Periksa setiap pasangan hari berurutan (0→1, 1→2, ..., 6→0 untuk wrap seminggu)
        for ($d = 0; $d < 7; $d++) {
            $today    = $byDay[$d]      ?? null;
            $tomorrow = $byDay[($d + 1) % 7] ?? null;

            // Lewati jika salah satu hari libur atau tidak ada jam kerja
            if (! $today || $today['is_off'] || empty($today['work_end_time'])) {
                continue;
            }
            if (! $tomorrow || $tomorrow['is_off'] || empty($tomorrow['work_start_time'])) {
                continue;
            }

            // Jika shift hari ini cross-day, jam pulangnya jatuh di hari d+1.
            // Shift berikutnya (hari d+1) juga mulai di hari d+1 — daysBetween tetap 1.
            // checkGap sudah menangani cross-day dengan addDay() pada endDt,
            // sehingga perbandingan endDt (hari+1) vs startDt (hari+1) benar.
            $isCrossDay  = (bool) ($today['is_cross_day'] ?? false);
            $daysBetween = 1;

            $result = $this->checkGap(
                $today['work_end_time'],
                $tomorrow['work_start_time'],
                $isCrossDay,
                $daysBetween
            );

            if ($result['status'] === 'error') {
                $errors[] = sprintf(
                    '%s → %s: jeda hanya %.1f jam (minimum wajib %d jam K3).',
                    $this->dayName($d),
                    $this->dayName(($d + 1) % 7),
                    $result['hours'],
                    self::MIN_REST_HOURS
                );
            } elseif ($result['status'] === 'warning') {
                $warnings[] = sprintf(
                    '%s → %s: jeda %.1f jam (disarankan minimal %d jam untuk kesehatan karyawan).',
                    $this->dayName($d),
                    $this->dayName(($d + 1) % 7),
                    $result['hours'],
                    self::RECOMMENDED_REST_HOURS
                );
            }
        }

        return compact('errors', 'warnings');
    }

    // ─── Internal ──────────────────────────────────────────────────

    private function buildResult(float $gapHours, int $gapMinutes): array
    {
        if ($gapHours < self::MIN_REST_HOURS) {
            $status  = 'error';
            $valid   = false;
            $message = sprintf(
                'Jeda istirahat hanya %.1f jam. Minimum wajib %d jam (standar K3).',
                $gapHours,
                self::MIN_REST_HOURS
            );
        } elseif ($gapHours < self::RECOMMENDED_REST_HOURS) {
            $status  = 'warning';
            $valid   = true;
            $message = sprintf(
                'Jeda istirahat %.1f jam. Disarankan minimal %d jam untuk kesehatan karyawan.',
                $gapHours,
                self::RECOMMENDED_REST_HOURS
            );
        } else {
            $status  = 'safe';
            $valid   = true;
            $message = null;
        }

        return [
            'hours'   => round($gapHours, 2),
            'minutes' => $gapMinutes,
            'status'  => $status,
            'valid'   => $valid,
            'message' => $message,
        ];
    }

    private function dayName(int $dow): string
    {
        return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$dow] ?? "Hari-{$dow}";
    }
}
