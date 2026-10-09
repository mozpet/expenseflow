<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Katalog Zona Waktu Indonesia (WIB / WITA / WIT)
    |--------------------------------------------------------------------------
    |
    | Dipakai App\Support\Timezone. Mengikuti pola config/leave_types.php:
    | master katalog di config, nilai per-kantor di kolom attendance_settings.timezone.
    |
    | 'storage' adalah JANGKAR PENYIMPANAN: semua kolom datetime di database
    | menyimpan jam dinding WIB (lihat DB_TIMEZONE=+07:00 di config/database.php).
    | Jangan pernah mengubah nilai ini — mengubahnya akan membuat SELURUH baris
    | presensi historis terbaca bergeser.
    |
    | Ketiga zona Indonesia TIDAK punya DST, sehingga offset-nya tetap
    | sepanjang tahun (+7 / +8 / +9).
    |
    */

    'storage' => 'Asia/Jakarta',

    'catalog' => [
        'Asia/Jakarta' => [
            'label'          => 'WIB',
            'name'           => 'Waktu Indonesia Barat',
            'offset_minutes' => 420,
            'regions'        => 'Sumatra, Jawa, Kalimantan Barat & Kalimantan Tengah',
        ],
        'Asia/Makassar' => [
            'label'          => 'WITA',
            'name'           => 'Waktu Indonesia Tengah',
            'offset_minutes' => 480,
            'regions'        => 'Bali, NTB, NTT, Sulawesi, Kalimantan Selatan/Timur/Utara',
        ],
        'Asia/Jayapura' => [
            'label'          => 'WIT',
            'name'           => 'Waktu Indonesia Timur',
            'offset_minutes' => 540,
            'regions'        => 'Maluku, Maluku Utara, Papua',
        ],
    ],

    'detection' => [
        /*
        | Kotak pengecualian — diperiksa BERURUTAN dan MENANG atas ambang
        | longitude di bawah. Diperlukan karena batas zona waktu Indonesia
        | mengikuti batas administratif provinsi, bukan garis bujur:
        |   - Bali (~115°E) seharusnya WIB menurut bujur, kenyataannya WITA.
        |   - Maluku (~128°E) seharusnya WITA menurut bujur, kenyataannya WIT.
        |   - Kalimantan Selatan WITA, sedangkan Kalimantan Tengah (bujur
        |     hampir sama) WIB.
        */
        'boxes' => [
            // Bali, NTB, NTT
            ['timezone' => 'Asia/Makassar', 'lat' => [-11.0, -8.0], 'lng' => [114.4, 125.2]],
            // Maluku & Maluku Utara (WIT walau bujurnya < 135)
            ['timezone' => 'Asia/Jayapura', 'lat' => [-8.5, 3.0], 'lng' => [125.0, 135.5]],
            // Sulawesi (seluruh pulau)
            ['timezone' => 'Asia/Makassar', 'lat' => [-6.5, 2.5], 'lng' => [118.5, 125.5]],
            // Kalimantan Selatan (WITA; Kalteng di bujur serupa tetap WIB)
            ['timezone' => 'Asia/Makassar', 'lat' => [-4.8, -1.0], 'lng' => [113.9, 117.0]],
            // Kalimantan Timur & Kalimantan Utara
            ['timezone' => 'Asia/Makassar', 'lat' => [-2.5, 4.5], 'lng' => [115.0, 119.2]],
        ],

        /*
        | Fallback: ambang bujur sederhana untuk koordinat di luar semua kotak.
        | Dievaluasi berurutan, yang pertama cocok (lng <= max) dipakai.
        */
        'longitude_thresholds' => [
            ['max' => 120.0, 'timezone' => 'Asia/Jakarta'],
            ['max' => 135.0, 'timezone' => 'Asia/Makassar'],
            ['max' => 180.0, 'timezone' => 'Asia/Jayapura'],
        ],
    ],
];
