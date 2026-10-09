<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * RESOLVER ZONA WAKTU PRESENSI MULTI-ZONA (2026-10-08)
 *
 * Helper statis tanpa state — tidak melakukan I/O apa pun (LocationService tetap
 * satu-satunya pemanggil jaringan untuk reverse-geocode).
 *
 * DUA KONSEP YANG TIDAK BOLEH DICAMPUR:
 *
 * 1. ZONA PENYIMPANAN (storage()) — 'Asia/Jakarta'. Semua kolom datetime di
 *    database menyimpan jam dinding WIB. Laravel menulis datetime dengan
 *    format() pada zona Carbon yang diberikan, dan membacanya kembali memakai
 *    date_default_timezone_get(). Artinya Carbon bernuansa Asia/Makassar yang
 *    di-assign ke kolom datetime akan tersimpan sebagai jam WITA lalu dibaca
 *    ulang sebagai WIB → bergeser 1 jam. Gunakan forStorage() sebelum menyimpan.
 *
 * 2. ZONA BISNIS — zona kantor cabang penempatan karyawan. Di sinilah SELURUH
 *    perbandingan dilakukan: jam masuk, status telat, tanggal presensi, lembur,
 *    cutoff, early-leave. Datang dari $schedule['timezone'],
 *    Attendance::effectiveTimezone(), atau User::effectiveTimezone().
 *
 * Zona lokasi fisik karyawan (hasil fromCoordinates() atas check_in_lat/lng)
 * bersifat DISPLAY-ONLY dan tidak pernah memengaruhi perhitungan.
 */
class Timezone
{
    /** Jangkar penyimpanan datetime database (selalu WIB). */
    public static function storage(): string
    {
        return config('timezones.storage', config('app.timezone', 'Asia/Jakarta'));
    }

    /** Katalog lengkap untuk dropdown HRD & validasi. */
    public static function catalog(): array
    {
        return config('timezones.catalog', []);
    }

    /** Daftar identifier IANA yang diizinkan ('Asia/Jakarta', ...). */
    public static function identifiers(): array
    {
        return array_keys(self::catalog());
    }

    public static function isValid(?string $tz): bool
    {
        return $tz !== null && array_key_exists($tz, self::catalog());
    }

    /**
     * Normalisasi: zona yang tidak dikenal (atau NULL) jatuh ke zona penyimpanan.
     * Dipakai di seluruh accessor agar tidak ada jalur yang bisa menghasilkan
     * identifier zona tidak valid ke Carbon.
     */
    public static function normalize(?string $tz): string
    {
        return self::isValid($tz) ? $tz : self::storage();
    }

    /** Label singkat untuk pengguna: 'WIB' | 'WITA' | 'WIT'. */
    public static function label(?string $tz): string
    {
        return self::catalog()[self::normalize($tz)]['label'] ?? 'WIB';
    }

    /** Nama panjang: 'Waktu Indonesia Barat'. */
    public static function name(?string $tz): string
    {
        return self::catalog()[self::normalize($tz)]['name'] ?? 'Waktu Indonesia Barat';
    }

    /** Offset dari UTC dalam menit: 420 | 480 | 540. */
    public static function offsetMinutes(?string $tz): int
    {
        return (int) (self::catalog()[self::normalize($tz)]['offset_minutes'] ?? 420);
    }

    /**
     * Tebak zona dari koordinat.
     *
     * Dipakai dua tempat: (a) prefill zona saat HRD membuat kantor cabang baru
     * (boleh di-override manual), (b) menurunkan zona LOKASI FISIK karyawan dari
     * check_in_lat/lng untuk ditampilkan & diaudit.
     *
     * SENGAJA memakai GPS, bukan jam perangkat: aturan Online-Only /
     * Anti-Clock-Spoofing melarang mempercayai perangkat klien.
     *
     * @return array{timezone:string,label:string,source:string}
     */
    public static function fromCoordinates(?float $latitude, ?float $longitude): array
    {
        if ($latitude === null || $longitude === null) {
            return [
                'timezone' => self::storage(),
                'label'    => self::label(self::storage()),
                'source'   => 'default',
            ];
        }

        // 1. Kotak pengecualian menang — batas zona Indonesia mengikuti batas
        //    provinsi, bukan garis bujur (Bali, Maluku, Kalsel vs Kalteng).
        foreach (config('timezones.detection.boxes', []) as $box) {
            [$latMin, $latMax] = $box['lat'];
            [$lngMin, $lngMax] = $box['lng'];

            if ($latitude >= $latMin && $latitude <= $latMax
                && $longitude >= $lngMin && $longitude <= $lngMax) {
                return [
                    'timezone' => $box['timezone'],
                    'label'    => self::label($box['timezone']),
                    'source'   => 'box',
                ];
            }
        }

        // 2. Fallback ambang bujur.
        foreach (config('timezones.detection.longitude_thresholds', []) as $threshold) {
            if ($longitude <= (float) $threshold['max']) {
                return [
                    'timezone' => $threshold['timezone'],
                    'label'    => self::label($threshold['timezone']),
                    'source'   => 'longitude',
                ];
            }
        }

        return [
            'timezone' => self::storage(),
            'label'    => self::label(self::storage()),
            'source'   => 'default',
        ];
    }

    /**
     * Konversi Carbon apa pun ke jam dinding zona penyimpanan SEBELUM disimpan
     * ke kolom datetime.
     *
     * Berbeda maksud dengan setTimezone($tz) untuk perbandingan — jangan
     * disamakan. Yang ini melindungi integritas penyimpanan, yang itu
     * menentukan hasil bisnis.
     */
    public static function forStorage(?Carbon $time): ?Carbon
    {
        return $time?->copy()->setTimezone(self::storage());
    }

    /**
     * Objek `timezone` standar untuk payload API (checkIn / checkOut / checkStatus).
     *
     * String `display` dibentuk di backend sesuai aturan proyek: seluruh teks
     * yang dilihat pengguna berasal dari payload API — web & Flutter tidak boleh
     * mengonversi zona sendiri.
     *
     * @param  string       $branchTz  Zona kantor cabang (otoritatif)
     * @param  string|null  $localTz   Zona lokasi fisik karyawan (display-only)
     * @param  Carbon       $at        Instan presensi
     */
    public static function describe(string $branchTz, ?string $localTz, Carbon $at, ?string $officeName = null): array
    {
        $branchTz = self::normalize($branchTz);
        $branchAt = $at->copy()->setTimezone($branchTz);

        $payload = [
            'branch' => [
                'id'             => $branchTz,
                'label'          => self::label($branchTz),
                'name'           => self::name($branchTz),
                'offset_minutes' => self::offsetMinutes($branchTz),
                'office_name'    => $officeName,
            ],
            'local'             => null,
            'is_cross_timezone' => false,
            'branch_time'       => $branchAt->format('H:i'),
            'local_time'        => null,
            'display'           => $branchAt->format('H:i') . ' ' . self::label($branchTz),
        ];

        if (! self::isValid($localTz)) {
            return $payload;
        }

        $localAt = $at->copy()->setTimezone($localTz);

        $payload['local'] = [
            'id'             => $localTz,
            'label'          => self::label($localTz),
            'name'           => self::name($localTz),
            'offset_minutes' => self::offsetMinutes($localTz),
        ];
        $payload['local_time']        = $localAt->format('H:i');
        $payload['is_cross_timezone'] = $localTz !== $branchTz;

        if ($payload['is_cross_timezone']) {
            $payload['display'] = sprintf(
                '%s %s (%s %s lokasi Anda)',
                $branchAt->format('H:i'),
                self::label($branchTz),
                $localAt->format('H:i'),
                self::label($localTz)
            );
        }

        return $payload;
    }
}
