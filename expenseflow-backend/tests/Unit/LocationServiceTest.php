<?php

namespace Tests\Unit;

use App\Services\LocationService;
use PHPUnit\Framework\TestCase;

class LocationServiceTest extends TestCase
{
    public function test_jarak_titik_sama_nol(): void
    {
        $svc = new LocationService();

        $this->assertSame(0.0, $svc->calculateDistance(-6.2, 106.816667, -6.2, 106.816667));
    }

    public function test_jarak_dua_kota_masuk_akal(): void
    {
        $svc = new LocationService();

        // Monas (Jakarta) → Gedung Sate (Bandung) ≈ 119 km
        $meters = $svc->calculateDistance(-6.175392, 106.827153, -6.902481, 107.618782);

        $this->assertGreaterThan(115_000, $meters);
        $this->assertLessThan(125_000, $meters);
    }

    public function test_jarak_radius_kecil(): void
    {
        $svc = new LocationService();

        // ~111 m per 0.001 derajat latitude di sekitar khatulistiwa
        $meters = $svc->calculateDistance(-6.200000, 106.800000, -6.201000, 106.800000);

        $this->assertGreaterThan(100, $meters);
        $this->assertLessThan(120, $meters);
    }
}
