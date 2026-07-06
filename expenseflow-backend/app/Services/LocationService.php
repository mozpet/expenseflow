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
}
