<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Test', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // reset frozen time
        parent::tearDown();
    }

    /**
     * Buat user dengan flag attendance/wfh/radius yang bisa dikontrol.
     * attendance_enabled & wfh_enabled sinkron via toggleWfh() di produksi,
     * tapi di test kita bisa set independen untuk menguji tiap lapisan check.
     */
    private function user(string $role, bool $attendance = true, bool $wfh = true, bool $radius = false): User
    {
        return User::factory()->create([
            'company_id'         => $this->company->id,
            'role'               => $role,
            'attendance_enabled' => $attendance,
            'wfh_enabled'        => $wfh,
            'radius_enabled'     => $radius,
            'is_active'          => true,
        ]);
    }

    private function token(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('t')->plainTextToken];
    }

    private function office(float $lat = -6.20, float $lng = 106.81666700, int $radius = 100): void
    {
        AttendanceSetting::create([
            'company_id'            => $this->company->id,
            'office_name'           => 'HQ',
            'office_latitude'       => $lat,
            'office_longitude'      => $lng,
            'radius_meters'         => $radius,
            'work_start_time'       => '08:00:00',
            'late_tolerance_minutes' => 15,
        ]);
    }

    // ── 1. Check-in dalam radius (mode lapangan) → berhasil ─────────
    public function test_checkin_dalam_radius_berhasil(): void
    {
        // Freeze jam 08:00 WIB (sebelum batas telat 08:15 WIB)
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', 'Asia/Jakarta'));
        $this->office();

        // wfh=true, radius=true → mode lapangan, wajib dalam radius
        $emp = $this->user('employee', wfh: true, radius: true);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81666700, // tepat di kantor → jarak 0m
        ], $this->token($emp))
        ->assertCreated()
        ->assertJsonPath('attendance.check_in_type', 'field')
        ->assertJsonPath('attendance.status', 'present');
    }

    // ── 2. Check-in di luar radius → 403 ────────────────────────────
    public function test_checkin_di_luar_radius_ditolak(): void
    {
        $this->office();
        $emp = $this->user('employee', wfh: true, radius: true);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.30, // ~11 km dari kantor
            'longitude' => 106.81666700,
        ], $this->token($emp))
        ->assertStatus(403)
        ->assertJsonStructure(['message', 'distance_meters', 'radius_meters', 'office_name']);
    }

    // ── 3. Check-in WFH (dengan izin HRD: wfh_enabled=true) → berhasil
    public function test_checkin_wfh_dengan_izin_hrd_berhasil(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', 'Asia/Jakarta'));

        // wfh=true, radius=false → mode WFH bebas, tanpa cek lokasi
        $emp = $this->user('employee', wfh: true, radius: false);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -7.00, // lokasi jauh — tidak divalidasi saat WFH
            'longitude' => 110.00,
        ], $this->token($emp))
        ->assertCreated()
        ->assertJsonPath('attendance.check_in_type', 'wfh')
        ->assertJsonPath('attendance.status', 'present');

        $this->assertDatabaseHas('activity_logs', ['action' => 'attendance_check_in']);
    }

    // ── 4. Check-in WFH tanpa izin HRD (wfh_enabled=false) → 403 ───
    public function test_checkin_wfh_tanpa_izin_hrd_ditolak(): void
    {
        // attendance=true supaya lolos middleware, wfh=false supaya ditolak di checkIn()
        $emp = $this->user('employee', attendance: true, wfh: false);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))
        ->assertStatus(403);
    }

    // ── 5. attendance_enabled=false → 403 via AttendanceAccessMiddleware
    public function test_attendance_disabled_diblokir_middleware(): void
    {
        $emp = $this->user('employee', attendance: false, wfh: false);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))
        ->assertStatus(403)
        ->assertJsonPath('message', 'Fitur presensi belum diaktifkan oleh HRD.');
    }

    // ── 6. Finance tidak bisa akses struk di mobile ──────────────────
    public function test_finance_tidak_bisa_akses_struk_di_mobile(): void
    {
        // attendance diaktifkan agar bisa akses presensi, tapi receipt diblokir
        $finance = $this->user('finance', wfh: true);

        $this->getJson('/api/v1/employee/receipts', $this->token($finance))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akses ditolak. Role yang diizinkan: employee.');
    }

    // ── Extra: check-out tanpa check-in → 403 ───────────────────────
    public function test_checkout_tanpa_checkin_ditolak(): void
    {
        $emp = $this->user('employee', wfh: true);

        $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))
        ->assertStatus(403)
        ->assertJsonPath('message', 'Anda belum check-in hari ini.');
    }

    // ── Extra: check-in telat → status late ─────────────────────────
    public function test_checkin_setelah_batas_toleransi_status_late(): void
    {
        // Work start 08:00 WIB, toleransi 15 menit → batas 08:15 WIB
        // Freeze waktu di 08:20 WIB → telat
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:20:00', 'Asia/Jakarta'));
        $this->office();

        $emp = $this->user('employee', wfh: true, radius: false);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'late');
    }

    // ── Extra: double check-in ditolak ──────────────────────────────
    public function test_double_checkin_ditolak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', 'Asia/Jakarta'));
        $emp = $this->user('employee', wfh: true, radius: false);

        // Check-in pertama → berhasil
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))->assertCreated();

        // Check-in kedua → 409
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))->assertStatus(409);
    }

    // ── Extra: HRD toggle WFH → attendance_enabled sinkron ──────────
    public function test_hrd_toggle_wfh_sinkronkan_attendance_enabled(): void
    {
        $hrd = $this->user('hrd', wfh: true);
        $emp = $this->user('employee', attendance: false, wfh: false);

        // Toggle WFH ON
        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-wfh",
            [],
            $this->token($hrd)
        )
        ->assertOk()
        ->assertJsonPath('user.wfh_enabled', true)
        ->assertJsonPath('user.attendance_enabled', true);

        $emp->refresh();
        $this->assertTrue($emp->wfh_enabled);
        $this->assertTrue($emp->attendance_enabled);
        $this->assertDatabaseHas('activity_logs', ['action' => 'wfh_toggled']);

        // Toggle WFH OFF
        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-wfh",
            [],
            $this->token($hrd)
        )
        ->assertOk()
        ->assertJsonPath('user.wfh_enabled', false)
        ->assertJsonPath('user.attendance_enabled', false);

        $emp->refresh();
        $this->assertFalse($emp->wfh_enabled);
        $this->assertFalse($emp->attendance_enabled);
    }

    // ── Extra: HRD toggle radius ─────────────────────────────────────
    public function test_hrd_toggle_radius(): void
    {
        $hrd = $this->user('hrd', wfh: true);
        $emp = $this->user('employee', wfh: true, radius: false);

        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-radius",
            [],
            $this->token($hrd)
        )
        ->assertOk()
        ->assertJsonPath('user.radius_enabled', true);

        $this->assertTrue($emp->fresh()->radius_enabled);
        $this->assertDatabaseHas('activity_logs', ['action' => 'radius_toggled']);
    }

    // ── Extra: employee tidak bisa akses dashboard HRD ───────────────
    public function test_employee_tidak_bisa_akses_dashboard_attendance(): void
    {
        $emp = $this->user('employee', wfh: true);

        $this->getJson('/api/v1/dashboard/attendance/users', $this->token($emp))
            ->assertStatus(403);
    }
}
