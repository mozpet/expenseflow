<?php

namespace App\Support;

/**
 * Format durasi menit → teks Indonesia "Xj Ym" (2026-10-12).
 *
 * Satu-satunya tempat format ini hidup. Sebelumnya ditulis dua kali dengan
 * signature berbeda: AttendanceController::formatMinutes(?int) dan
 * AutoCheckoutCommand::formatMinutes(int) — isinya identik, jadi notifikasi
 * auto-checkout dan notifikasi approval lembur memformat angka yang sama
 * lewat dua jalur kode.
 */
class Duration
{
    /**
     * Nol / negatif / null → '0j'. Sisa menit 0 → hanya jam ("2j"),
     * selain itu "2j 15m". Bentuk ini sudah dipakai di payload API &
     * notifikasi push, jadi jangan diubah tanpa menyesuaikan klien.
     */
    public static function formatMinutes(?int $minutes): string
    {
        $m = (int) $minutes;
        if ($m <= 0) {
            return '0j';
        }

        $jam  = intdiv($m, 60);
        $sisa = $m % 60;

        return $sisa === 0 ? "{$jam}j" : "{$jam}j {$sisa}m";
    }
}
