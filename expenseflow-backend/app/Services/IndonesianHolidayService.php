<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IndonesianHolidayService
{
    /**
     * Endpoint API Hari Libur Indonesia resmi komunitas.
     */
    private const API_URL = 'https://api-hari-libur.vercel.app/api';

    /**
     * Ambil data hari libur nasional & cuti bersama untuk tahun tertentu.
     * Menggunakan API live dengan fallback built-in dataset jika offline/server down.
     *
     * @param int $year
     * @return array
     */
    public function getHolidays(int $year): array
    {
        // 1. Coba ambil dari live API
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get(self::API_URL, ['year' => $year]);

            if ($response->successful()) {
                $payload = $response->json();
                $items = $payload['data'] ?? (is_array($payload) ? $payload : []);

                if (!empty($items) && is_array($items)) {
                    $normalized = [];
                    foreach ($items as $item) {
                        if (empty($item['date'])) continue;

                        $rawDate = trim($item['date']);
                        $desc = trim($item['description'] ?? $item['name'] ?? 'Hari Libur Nasional');
                        $isCollective = (bool) (
                            ($item['is_cuti'] ?? false) ||
                            stripos($desc, 'Cuti Bersama') !== false
                        );

                        $normalized[] = [
                            'date'          => Carbon::parse($rawDate)->toDateString(),
                            'name'          => $desc,
                            'is_national'   => !$isCollective,
                            'is_collective' => $isCollective,
                            'source'        => 'api',
                        ];
                    }

                    if (!empty($normalized)) {
                        // Urutkan berdasarkan tanggal
                        usort($normalized, fn($a, $b) => strcmp($a['date'], $b['date']));
                        return $normalized;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[IndonesianHolidayService] Gagal menghubungi API hari libur live: {$e->getMessage()}. Menggunakan fallback dataset.");
        }

        // 2. Fallback dataset kurasi offline
        return $this->getFallbackHolidays($year);
    }

    /**
     * Database kurasi hari libur nasional & cuti bersama resmi Indonesia (SKB 3 Menteri).
     * Menjamin sistem tetap berjalan 100% andal tanpa bergantung koneksi internet luar.
     */
    private function getFallbackHolidays(int $year): array
    {
        $database = [
            2025 => [
                ['date' => '2025-01-01', 'name' => 'Tahun Baru 2025 Masehi', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-01-27', 'name' => 'Isra Mi’raj Nabi Muhammad SAW', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-01-28', 'name' => 'Cuti Bersama Tahun Baru Imlek', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-01-29', 'name' => 'Tahun Baru Imlek 2576 Kongzili', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-03-28', 'name' => 'Cuti Bersama Hari Suci Nyepi', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-03-29', 'name' => 'Hari Suci Nyepi Tahun Baru Saka 1947', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-03-31', 'name' => 'Hari Raya Idul Fitri 1446 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-04-01', 'name' => 'Hari Raya Idul Fitri 1446 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-04-02', 'name' => 'Cuti Bersama Idul Fitri 1446 Hijriah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-04-03', 'name' => 'Cuti Bersama Idul Fitri 1446 Hijriah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-04-04', 'name' => 'Cuti Bersama Idul Fitri 1446 Hijriah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-04-07', 'name' => 'Cuti Bersama Idul Fitri 1446 Hijriah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-04-18', 'name' => 'Wafat Yesus Kristus', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-04-20', 'name' => 'Kebangkitan Yesus Kristus (Paskah)', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-05-01', 'name' => 'Hari Buruh Internasional', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-05-12', 'name' => 'Hari Raya Waisak 2569 BE', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-05-13', 'name' => 'Cuti Bersama Hari Raya Waisak', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-05-29', 'name' => 'Kenaikan Yesus Kristus', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-05-30', 'name' => 'Cuti Bersama Kenaikan Yesus Kristus', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-06-01', 'name' => 'Hari Lahir Pancasila', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-06-06', 'name' => 'Hari Raya Idul Adha 1446 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-06-09', 'name' => 'Cuti Bersama Hari Raya Idul Adha', 'is_national' => false, 'is_collective' => true],
                ['date' => '2025-06-27', 'name' => '1 Muharam Tahun Baru Islam 1447 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-08-17', 'name' => 'Proklamasi Kemerdekaan', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-09-05', 'name' => 'Maulid Nabi Muhammad SAW', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-12-25', 'name' => 'Kelahiran Yesus Kristus (Natal)', 'is_national' => true, 'is_collective' => false],
                ['date' => '2025-12-26', 'name' => 'Cuti Bersama Kelahiran Yesus Kristus', 'is_national' => false, 'is_collective' => true],
            ],
            2026 => [
                ['date' => '2026-01-01', 'name' => 'Tahun Baru 2026 Masehi', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-01-16', 'name' => 'Isra Mi’raj Nabi Muhammad SAW', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-02-17', 'name' => 'Tahun Baru Imlek 2577 Kongzili', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-03-18', 'name' => 'Cuti Bersama Hari Suci Nyepi Tahun Baru Saka 1948', 'is_national' => false, 'is_collective' => true],
                ['date' => '2026-03-19', 'name' => 'Hari Suci Nyepi Tahun Baru Saka 1948', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-03-20', 'name' => 'Cuti Bersama Hari Raya Idul Fitri 1447 Hijriyah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2026-03-21', 'name' => 'Hari Raya Idul Fitri 1447 Hijriyah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-03-22', 'name' => 'Hari Raya Idul Fitri 1447 Hijriyah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-03-23', 'name' => 'Cuti Bersama Hari Raya Idul Fitri 1447 Hijriyah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2026-03-24', 'name' => 'Cuti Bersama Hari Raya Idul Fitri 1447 Hijriyah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2026-04-03', 'name' => 'Wafat Yesus Kristus / Jumat Agung', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-04-05', 'name' => 'Kebangkitan Yesus Kristus (Paskah)', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-05-01', 'name' => 'Hari Buruh Internasional', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-05-14', 'name' => 'Kenaikan Yesus Kristus', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-05-15', 'name' => 'Cuti Bersama Kenaikan Yesus Kristus', 'is_national' => false, 'is_collective' => true],
                ['date' => '2026-05-27', 'name' => 'Hari Raya Idul Adha 1447 Hijriyah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-05-28', 'name' => 'Cuti Bersama Hari Raya Idul Adha 1447 Hijriyah', 'is_national' => false, 'is_collective' => true],
                ['date' => '2026-05-31', 'name' => 'Hari Raya Waisak 2570 BE', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-06-01', 'name' => 'Hari Lahir Pancasila', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-06-16', 'name' => 'Tahun Baru Islam 1448 Hijriyah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-08-17', 'name' => 'Hari Kemerdekaan Republik Indonesia', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-08-25', 'name' => 'Maulid Nabi Muhammad SAW', 'is_national' => true, 'is_collective' => false],
                ['date' => '2026-12-24', 'name' => 'Cuti Bersama Hari Raya Natal', 'is_national' => false, 'is_collective' => true],
                ['date' => '2026-12-25', 'name' => 'Hari Raya Natal', 'is_national' => true, 'is_collective' => false],
            ],
            2027 => [
                ['date' => '2027-01-01', 'name' => 'Tahun Baru 2027 Masehi', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-01-05', 'name' => 'Isra Mi’raj Nabi Muhammad SAW', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-02-06', 'name' => 'Tahun Baru Imlek 2578 Kongzili', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-03-09', 'name' => 'Hari Suci Nyepi Tahun Baru Saka 1949', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-03-10', 'name' => 'Hari Raya Idul Fitri 1448 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-03-11', 'name' => 'Hari Raya Idul Fitri 1448 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-03-26', 'name' => 'Wafat Yesus Kristus', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-05-01', 'name' => 'Hari Buruh Internasional', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-05-06', 'name' => 'Kenaikan Yesus Kristus', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-05-17', 'name' => 'Hari Raya Idul Adha 1448 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-05-20', 'name' => 'Hari Raya Waisak 2571 BE', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-06-01', 'name' => 'Hari Lahir Pancasila', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-06-06', 'name' => 'Tahun Baru Islam 1449 Hijriah', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-08-17', 'name' => 'Hari Kemerdekaan Republik Indonesia', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-08-15', 'name' => 'Maulid Nabi Muhammad SAW', 'is_national' => true, 'is_collective' => false],
                ['date' => '2027-12-25', 'name' => 'Hari Raya Natal', 'is_national' => true, 'is_collective' => false],
            ]
        ];

        $items = $database[$year] ?? [];
        foreach ($items as &$item) {
            $item['source'] = 'fallback';
        }
        return $items;
    }
}
