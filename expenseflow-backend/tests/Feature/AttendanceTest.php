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
    private function user(string $role, bool $attendance = true, bool $wfh = true, bool $radius = false, bool $active = true): User
    {
        return User::factory()->create([
            'company_id'         => $this->company->id,
            'role'               => $role,
            'attendance_enabled' => $attendance,
            'wfh_enabled'        => $wfh,
            'radius_enabled'     => $radius,
            'is_active'          => $active,
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

    // ── 6. Inactive user tidak bisa akses struk di mobile ───────────────────
    public function test_inactive_user_tidak_bisa_akses_struk_di_mobile(): void
    {
        $inactive = $this->user('finance', wfh: true, active: false);

        $this->getJson('/api/v1/employee/receipts', $this->token($inactive))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Anda tidak memiliki akses ke fitur ini.');
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

    // ── Cutoff: Check-in sebelum batas cutoff berhasil (status late) ─
    public function test_checkin_sebelum_cutoff_berhasil(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:30:00', 'Asia/Jakarta'));
        AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'HQ',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.81666700,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'late_tolerance_minutes'      => 15,
            'late_checkin_cutoff_minutes' => 120, // batas jam 10:00 WIB
        ]);

        $emp = $this->user('employee', wfh: true, radius: false);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'late');
    }

    // ── Cutoff: Check-in setelah batas cutoff ditolak 403 ────────────
    public function test_checkin_setelah_cutoff_ditolak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 10:05:00', 'Asia/Jakarta'));
        AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'HQ',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.81666700,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'late_tolerance_minutes'      => 15,
            'late_checkin_cutoff_minutes' => 120, // batas jam 10:00 WIB
        ]);

        $emp = $this->user('employee', wfh: true, radius: false);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))
        ->assertStatus(403)
        ->assertJsonPath('cutoff_at', '10:00')
        ->assertJsonPath('cutoff_minutes', 120);
    }

    // ── Cutoff: Check-in tanpa batas cutoff (null) tetap bisa kapan saja ─
    public function test_checkin_tanpa_cutoff_tetap_bisa_kapan_saja(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 14:00:00', 'Asia/Jakarta'));
        AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'HQ',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.81666700,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'late_tolerance_minutes'      => 15,
            'late_checkin_cutoff_minutes' => null, // bebas
        ]);

        $emp = $this->user('employee', wfh: true, radius: false);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($emp))
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'late');
    }

    // ── Cutoff vs Toleransi: Toleransi telat > cutoff ditolak 422 ────
    public function test_toleransi_telat_lebih_besar_dari_cutoff_ditolak(): void
    {
        $hrd = $this->user('hrd', wfh: true);

        // Store: toleransi 60 menit, cutoff 30 menit → 422
        $this->postJson('/api/v1/dashboard/attendance/settings', [
            'office_name'                 => 'Cabang Baru',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.81,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00',
            'work_end_time'               => '17:00',
            'work_days'                   => [1, 2, 3, 4, 5],
            'late_tolerance_minutes'      => 60,
            'late_checkin_cutoff_minutes' => 30, // lebih kecil dari toleransi!
            'default_leave_quota'         => 12,
        ], $this->token($hrd))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Toleransi telat tidak boleh lebih besar dari batas waktu presensi telat (cutoff). Toleransi telat 60 mnt, batas waktu presensi 30 mnt.');
    }

    // ── Anti-Fake GPS: is_mocked=true ditolak saat check-in ─────────
    public function test_checkin_fake_gps_ditolak(): void
    {
        $emp = $this->user('employee', wfh: true);

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
            'is_mocked' => true,
        ], $this->token($emp))
        ->assertStatus(403)
        ->assertJsonPath('fake_gps_detected', true);
    }

    // ── Anti-Fake GPS: is_mocked=true ditolak saat check-out ────────
    public function test_checkout_fake_gps_ditolak(): void
    {
        $emp = $this->user('employee', wfh: true);

        // Check-in normal
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
            'is_mocked' => false,
        ], $this->token($emp))->assertCreated();

        // Check-out dengan fake GPS
        $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
            'is_mocked' => true,
        ], $this->token($emp))
        ->assertStatus(403)
        ->assertJsonPath('fake_gps_detected', true);
    }

    public function test_wfh_leave_skips_already_wfh_shift_and_holidays_and_existing_leaves(): void
    {
        $office = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'HQ',
            'office_latitude'  => -6.20,
            'office_longitude' => 106.81,
            'radius_meters'    => 100,
            'work_days'        => [1, 2, 3, 4, 5],
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
        ]);

        $emp = $this->user('employee', attendance: true, wfh: false, radius: true);
        $emp->update(['attendance_setting_id' => $office->id]);

        // Cari hari kerja minggu depan (Senin s.d. Jumat)
        $nextMonday = Carbon::now('Asia/Jakarta')->next(Carbon::MONDAY);
        if ($nextMonday->isToday()) {
            $nextMonday = $nextMonday->copy()->addWeek();
        }
        $mon = $nextMonday->toDateString();
        $tue = $nextMonday->copy()->addDays(1)->toDateString();
        $wed = $nextMonday->copy()->addDays(2)->toDateString();
        $thu = $nextMonday->copy()->addDays(3)->toDateString();
        $fri = $nextMonday->copy()->addDays(4)->toDateString();

        // 1. Shift: hari Selasa dibuat WFH (is_wfh = true)
        $shift = \App\Models\Shift::create([
            'company_id' => $this->company->id,
            'name'       => 'Shift Hybrid',
            'is_active'  => true,
        ]);
        // Senin, Rabu, Kamis, Jumat onsite; Selasa WFH
        foreach ([1, 2, 3, 4, 5] as $dow) {
            \App\Models\ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $dow,
                'effective_date'  => '2026-01-01',
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'is_off'          => false,
                'is_wfh'          => ($dow === 2), // Selasa WFH
                'is_field'        => false,
                'is_cross_day'    => false,
            ]);
        }
        \App\Models\UserShift::create([
            'user_id'    => $emp->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-01-01',
            'end_date'   => null,
        ]);

        // 2. Libur nasional: hari Rabu
        \App\Models\Holiday::create([
            'company_id'    => $this->company->id,
            'name'          => 'Libur Nasional Uji Coba',
            'date'          => $wed,
            'is_national'   => true,
            'is_collective' => false,
        ]);

        // 3. Izin yang sudah diajukan: hari Kamis
        \App\Models\LeaveRequest::create([
            'user_id'    => $emp->id,
            'company_id' => $this->company->id,
            'leave_type' => 'izin',
            'start_date' => $thu,
            'end_date'   => $thu,
            'total_days' => 1,
            'reason'     => 'Urusan keluarga',
            'status'     => 'approved',
        ]);

        // Cek leave preview dari Senin s.d. Jumat
        $preview = $this->getJson("/api/v1/attendance/leave-preview?leave_type=wfh&start_date={$mon}&end_date={$fri}", $this->token($emp))
            ->assertOk()
            ->json();

        // Hari efektif WFH hanya Senin ($mon) dan Jumat ($fri) = 2 hari
        $this->assertEquals(2, $preview['total_days']);
        $this->assertEquals([$mon, $fri], $preview['effective_dates']);

        // Pastikan skipped_dates memuat alasan yang tepat
        $skippedReasons = collect($preview['skipped_dates'])->keyBy('date');
        $this->assertEquals('already_wfh', $skippedReasons[$tue]['reason']);
        $this->assertEquals('holiday_or_off_day', $skippedReasons[$wed]['reason']);
        $this->assertEquals('already_requested', $skippedReasons[$thu]['reason']);

        // Submit pengajuan WFH
        $submitRes = $this->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'wfh',
            'start_date' => $mon,
            'end_date'   => $fri,
            'reason'     => 'Kebutuhan WFH',
        ], $this->token($emp))
        ->assertCreated();

        $this->assertEquals(2, $submitRes->json('leave.total_days'));
    }

    public function test_approved_wfh_allows_mobile_checkin_and_checkout(): void
    {
        $office = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Pusat',
            'office_latitude'  => -6.200000,
            'office_longitude' => 106.810000,
            'radius_meters'    => 50,
            'work_days'        => [0, 1, 2, 3, 4, 5, 6],
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
            'wfh_checkin_window_minutes' => null,
            'min_checkout_interval_minutes' => 0,
        ]);

        // Karyawan kantor biasa (wfh_enabled = false, radius_enabled = true)
        $emp = $this->user('employee', attendance: true, wfh: false, radius: true);
        $emp->update(['attendance_setting_id' => $office->id]);

        $today = Carbon::now('Asia/Jakarta')->toDateString();

        // 1. Sebelum WFH di-approve: presensi mobile dari rumah (-6.90, jauh dari kantor) ditolak
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.900000,
            'longitude' => 107.600000,
        ], $this->token($emp))
        ->assertStatus(403);

        // 2. Buat pengajuan WFH yang sudah di-approve oleh HRD
        $hrd = $this->user('hrd');
        \App\Models\LeaveRequest::create([
            'user_id'     => $emp->id,
            'company_id'  => $this->company->id,
            'leave_type'  => 'wfh',
            'start_date'  => $today,
            'end_date'    => $today,
            'total_days'  => 1,
            'reason'      => 'WFH disetujui HRD',
            'status'      => 'approved',
            'approved_by' => $hrd->id,
            'approved_at' => now(),
        ]);

        // 3. Status presensi hari ini otomatis mendeteksi wfh_enabled = true & is_wfh_approved = true
        $this->getJson('/api/v1/attendance/status', $this->token($emp))
            ->assertOk()
            ->assertJsonPath('wfh_enabled', true)
            ->assertJsonPath('is_wfh_approved', true);

        // 4. Karyawan check-in dari rumah (-6.90, 107.60) -> BERHASIL check-in sebagai WFH
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.900000,
            'longitude' => 107.600000,
        ], $this->token($emp))
        ->assertCreated()
        ->assertJsonPath('attendance.check_in_type', 'wfh');

        // 5. Karyawan check-out dari rumah -> BERHASIL check-out tanpa terkena blokir radius kantor
        $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.900000,
            'longitude' => 107.600000,
        ], $this->token($emp))
        ->assertOk()
        ->assertJsonPath('attendance.check_out_type', 'wfh');
    }
}

