<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;

/**
 * Denda LUPA CHECK-OUT (missed punch) per presensi (2026-10-11).
 *
 * Dipicu HANYA oleh AutoCheckoutCommand: sistem menutup sesi presensi yang
 * dibiarkan terbuka melewati jam pulang + grace. Karyawan yang check-out sendiri
 * — walau sangat terlambat — tidak pernah terkena denda ini.
 *
 * Murni fungsi: tidak membaca DB, tidak membaca jam sistem.
 *
 * MODE (missed_checkout_penalty_mode):
 *   none            : tidak ada konsekuensi (default; perilaku lama).
 *   flat_fine       : denda administrasi flat sekali per kejadian.
 *   mark_early_leave: tanpa denda rupiah, tetapi presensi ditandai 'early_leave'
 *                     sehingga menggugurkan insentif kehadiran penuh. Ini SATU-SATUNYA
 *                     jalan di mana AutoCheckoutCommand boleh menulis status
 *                     'early_leave' — di luar mode ini perintah tersebut sengaja
 *                     tidak pernah menyentuh kolom status.
 */
class MissedCheckoutFineCalculator
{
    public const MODE_NONE             = 'none';
    public const MODE_FLAT_FINE        = 'flat_fine';
    public const MODE_MARK_EARLY_LEAVE = 'mark_early_leave';

    public static function normalizeMode(?string $mode): string
    {
        return in_array($mode, [self::MODE_FLAT_FINE, self::MODE_MARK_EARLY_LEAVE], true)
            ? $mode
            : self::MODE_NONE;
    }

    /**
     * Konfigurasi denda yang akan dibekukan ke snapshot presensi.
     *
     * @return array{mode: string, amount: ?float}
     */
    public static function configFromOffice(?AttendanceSetting $office): array
    {
        return [
            'mode'   => self::normalizeMode($office?->missed_checkout_penalty_mode),
            'amount' => $office?->missed_checkout_fine_amount !== null
                ? (float) $office->missed_checkout_fine_amount
                : null,
        ];
    }

    /**
     * Konsekuensi auto-checkout untuk satu baris presensi.
     *
     * @param array{mode: string, amount: ?float} $config
     *
     * @return array{fine_amount: float, mark_early_leave: bool}
     */
    public static function calculate(array $config): array
    {
        $mode = self::normalizeMode($config['mode'] ?? null);

        if ($mode === self::MODE_MARK_EARLY_LEAVE) {
            return ['fine_amount' => 0.0, 'mark_early_leave' => true];
        }

        if ($mode === self::MODE_FLAT_FINE) {
            $amount = (float) ($config['amount'] ?? 0);

            return [
                'fine_amount'      => $amount > 0 ? round($amount, 2) : 0.0,
                'mark_early_leave' => false,
            ];
        }

        return ['fine_amount' => 0.0, 'mark_early_leave' => false];
    }
}
