<?php

namespace App\Services;

/**
 * LocationService — utilitas perhitungan lokasi/GPS.
 *
 * Cara pakai:
 *   $meters = app(LocationService::class)->calculateDistance($lat1, $lng1, $lat2, $lng2);
 */
class LocationService
{
    /**
     * Hitung jarak dua koordinat dalam meter.
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->haversine($lat1, $lng1, $lat2, $lng2);
    }

    /**
     * Rumus Haversine — jarak lingkaran besar antara dua titik di bumi (meter).
     */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meter

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Reverse geocoding koordinat (lat, lng) ke alamat/nama jalan ringkas via OpenStreetMap Nominatim.
     * Menggunakan timeout singkat (3 detik) & fallback aman agar tidak menghambat proses presensi.
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'ExpenseFlow-Attendance/1.0 (contact@expenseflow.internal)',
                'Accept'     => 'application/json',
            ])->timeout(3)->get('https://nominatim.openstreetmap.org/reverse', [
                'lat'            => $lat,
                'lon'            => $lng,
                'format'         => 'json',
                'zoom'           => 18,
                'addressdetails' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $displayName = $data['display_name'] ?? null;
                if ($displayName) {
                    // Buat format alamat lebih ringkas jika ada komponen address
                    $addr = $data['address'] ?? [];
                    $parts = array_filter([
                        $addr['road'] ?? $addr['suburb'] ?? null,
                        $addr['city_district'] ?? $addr['city'] ?? $addr['county'] ?? null,
                        $addr['state'] ?? null,
                    ]);
                    return !empty($parts) ? implode(', ', $parts) : $displayName;
                }
            }
        } catch (\Throwable $e) {
            // Log silent / abaikan jika jaringan timeout
        }

        return null;
    }
}
