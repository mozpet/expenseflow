<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;

/**
 * Premi shift malam per sesi presensi (2026-10-11).
 *
 * Murni fungsi: tidak membaca DB, tidak membaca jam sistem.
 *
 * KRITERIA SHIFT MALAM (sesuai keterangan di form kantor):
 *   a) jadwal bertanda lintas hari (is_cross_day) — mis. 22:00–06:00; ATAU
 *   b) jam MULAI jadwal >= 22:00 (NIGHT_START_HOUR).
 *
 * Kriteria dipasang pada JADWAL, bukan pada jam check-in aktual, supaya
 * karyawan shift malam yang datang 21:55 (lebih awal) tetap dapat premi, dan
 * karyawan shift siang yang lembur sampai lewat tengah malam TIDAK mendapat
 * premi shift malam (lemburnya sudah dibayar lewat tunjangan lembur).
 *
 * Hari libur jadwal (is_off) tidak mendapat premi: tidak ada shift yang dijalani.
 */
class NightShiftBonusCalculator
{
    /** Jam mulai (lokal cabang) yang sudah dianggap shift malam. */
    public const NIGHT_START_HOUR = 22;

    /**
     * Konfigurasi premi yang akan dibekukan ke snapshot presensi.
     *
     * @return array{enabled: bool, amount: ?float}
     */
    public static function configFromOffice(?AttendanceSetting $office): array
    {
        return [
            'enabled' => (bool) ($office?->night_shift_bonus_enabled ?? false),
            'amount'  => $office?->night_shift_bonus_amount !== null
                ? (float) $office->night_shift_bonus_amount
                : null,
        ];
    }

    /**
     * Apakah jadwal hari itu tergolong shift malam?
     *
     * @param string|null $workStartTime Jam masuk lokal 'H:i' jadwal efektif.
     */
    public static function isNightShift(?string $workStartTime, bool $isCrossDay): bool
    {
        if ($isCrossDay) {
            return true;
        }

        if (! $workStartTime) {
            return false;
        }

        return (int) substr($workStartTime, 0, 2) >= self::NIGHT_START_HOUR;
    }

    /**
     * Nominal premi untuk satu sesi presensi (0 bila tidak memenuhi syarat).
     *
     * @param array{enabled: bool, amount: ?float} $config
     */
    public static function calculate(
        ?string $workStartTime,
        bool $isCrossDay,
        bool $isOff,
        array $config,
    ): float {
        if ($isOff || ! ($config['enabled'] ?? false)) {
            return 0.0;
        }

        if (! self::isNightShift($workStartTime, $isCrossDay)) {
            return 0.0;
        }

        $amount = (float) ($config['amount'] ?? 0);

        return $amount > 0 ? round($amount, 2) : 0.0;
    }
}
