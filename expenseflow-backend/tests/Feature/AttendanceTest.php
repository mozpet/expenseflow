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
    // ── Extra: HRD toggle WFH tidak mematikan attendance_enabled ──────────
    public function test_hrd_toggle_wfh_sinkronkan_attendance_enabled(): void
    {
        $hrd = $this->user('hrd', wfh: true);
        $emp = $this->user('employee', attendance: true, wfh: true);

        // 1. Toggle WFH OFF dari tabel
        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-wfh",
            [],
            $this->token($hrd)
        )
        ->assertOk()
        ->assertJsonPath('user.wfh_enabled', false)
        ->assertJsonPath('user.attendance_enabled', true); // Presensi mobile onsite tetap aktif

        $emp->refresh();
        $this->assertFalse($emp->wfh_enabled);
        $this->assertTrue($emp->attendance_enabled);
        $this->assertDatabaseHas('activity_logs', ['action' => 'wfh_toggled']);

        // 2. HRD bisa toggle WFH kembali ON dari tabel karena allow_wfh = true
        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-wfh",
            [],
            $this->token($hrd)
        )
        ->assertOk()
        ->assertJsonPath('user.wfh_enabled', true);

        // 3. Jika allow_wfh di profil dimatikan, barulah toggle-wfh terlock dan ditolak 422
        $emp->allow_wfh = false;
        $emp->save();

        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-wfh",
            [],
            $this->token($hrd)
        )
        ->assertStatus(422)
        ->assertJsonPath('message', "Izinkan Presensi WFH untuk '{$emp->name}' sedang dinonaktifkan di Edit Profil Karyawan. Switch WFH terkunci dan harus diaktifkan kembali melalui menu Edit Profil Karyawan.");
    }

    // ── Extra: HRD toggle radius ─────────────────────────────────────
    public function test_hrd_toggle_radius(): void
    {
        $hrd = $this->user('hrd', wfh: true);
        $emp = $this->user('employee', attendance: true, wfh: true, radius: true);

        // 1. Toggle radius OFF saat sedang aktif -> berhasil 200
        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-radius",
            [],
            $this->token($hrd)
        )
        ->assertOk()
        ->assertJsonPath('user.radius_enabled', false);

        $this->assertFalse($emp->fresh()->radius_enabled);
        $this->assertDatabaseHas('activity_logs', ['action' => 'radius_toggled']);

        // 2. Toggle radius ON kembali saat diizinkan -> berhasil 200
        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-radius",
            [],
            $this->token($hrd)
        )
        ->assertOk()
        ->assertJsonPath('user.radius_enabled', true);

        // 3. Saat allow_radius di profil dimatikan, switch lapangan terlock off -> ditolak 422
        $emp->allow_radius = false;
        $emp->save();

        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-radius",
            [],
            $this->token($hrd)
        )
        ->assertStatus(422)
        ->assertJsonPath('message', "Validasi Radius Geofence untuk '{$emp->name}' sedang dinonaktifkan di Edit Profil Karyawan. Switch Lapangan terkunci dan harus diaktifkan kembali melalui menu Edit Profil Karyawan.");
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

    public function test_unclosed_yesterday_daytime_shift_is_not_treated_as_active_cross_day_today(): void
    {
        $this->office();
        $hrd = $this->user('hrd');
        $emp = $this->user('employee');

        // Freeze time to today at 09:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Jakarta'));

        // Employee checked in yesterday morning for daytime shift (08:00 - 17:00), no checkout (e.g. server was off)
        $yesterday = '2026-09-09';
        $attYesterday = \App\Models\Attendance::create([
            'user_id' => $emp->id,
            'company_id' => $this->company->id,
            'date' => $yesterday,
            'check_in_time' => '2026-09-09 08:15:00',
            'check_in_type' => 'wfh',
            'status' => 'present',
            'snap_source' => 'office',
            'snap_work_start_time' => '08:00:00',
            'snap_work_end_time' => '17:00:00',
            'snap_is_cross_day' => false,
            'snap_is_off' => false,
            'snap_grace_minutes' => 60,
        ]);

        // Clear catchup throttle cache
        \Illuminate\Support\Facades\Cache::forget('auto_checkout_catchup_throttle');

        // HRD calls today() dashboard
        $response = $this->getJson('/api/v1/dashboard/attendance/today', $this->token($hrd))
            ->assertOk();

        $checkedInIds = collect($response->json('checked_in'))->pluck('user_id')->all();
        $notCheckedInIds = collect($response->json('not_checked_in'))->pluck('user_id')->all();

        // Employee should NOT be in checked_in as an active cross-day shift
        $this->assertNotContains($emp->id, $checkedInIds);
        // Employee should be in not_checked_in for today
        $this->assertContains($emp->id, $notCheckedInIds);

        // And the yesterday attendance should be auto-checked out by the catchup
        $attYesterday->refresh();
        $this->assertNotNull($attYesterday->check_out_time);
        $this->assertTrue((bool) $attYesterday->is_auto_checkout);
    }

    public function test_toggle_attendance_off_otomatis_mematikan_wfh_dan_radius(): void
    {
        $hrd = $this->user('hrd', wfh: true);
        $emp = $this->user('employee', attendance: true, wfh: true);
        $emp->radius_enabled = true;
        $emp->save();

        $this->assertTrue($emp->canWfh());
        $this->assertTrue($emp->hasRadiusEnabled());

        // Matikan Presensi Mobile
        $this->postJson("/api/v1/dashboard/attendance/users/{$emp->id}/toggle-attendance", [], $this->token($hrd))
            ->assertOk()
            ->assertJsonPath('user.attendance_enabled', false)
            ->assertJsonPath('user.wfh_enabled', false)
            ->assertJsonPath('user.radius_enabled', false);

        $emp->refresh();
        $this->assertFalse($emp->attendance_enabled);
        $this->assertFalse($emp->wfh_enabled);
        $this->assertFalse($emp->radius_enabled);
        $this->assertFalse($emp->canAccessAttendance());
        $this->assertFalse($emp->canWfh());
        $this->assertFalse($emp->hasRadiusEnabled());

        // Cek status mobile juga mengembalikan false
        $statusRes = $this->actingAs($emp, 'sanctum')
            ->getJson('/api/v1/attendance/status')
            ->assertOk();
        $this->assertFalse($statusRes->json('attendance_enabled'));
        $this->assertFalse($statusRes->json('wfh_enabled'));
        $this->assertFalse($statusRes->json('radius_enabled'));
    }

    public function test_update_mobile_policy_cascade(): void
    {
        $hrd = $this->user('hrd', wfh: true);
        $emp = $this->user('employee', attendance: true, wfh: true);

        // Jika attendance_enabled false, wfh dan radius dipaksa false
        $this->putJson("/api/v1/dashboard/attendance/users/{$emp->id}/mobile-policy", [
            'attendance_enabled' => false,
            'wfh_enabled'        => true,
            'radius_enabled'     => true,
        ], $this->token($hrd))
            ->assertOk()
            ->assertJsonPath('user.attendance_enabled', false)
            ->assertJsonPath('user.wfh_enabled', false)
            ->assertJsonPath('user.radius_enabled', false);

        $emp->refresh();
        $this->assertFalse($emp->attendance_enabled);
        $this->assertFalse($emp->wfh_enabled);
        $this->assertFalse($emp->radius_enabled);
    }

    public function test_user_controller_store_and_update_mobile_policy_cascade(): void
    {
        $admin = $this->user('admin', attendance: true, wfh: true);

        // 1. Store dengan attendance_enabled false -> wfh dan radius otomatis false
        $resStore = $this->postJson('/api/v1/admin/users', [
            'name'               => 'Budi Mobile Off',
            'email'              => 'budi.off@example.com',
            'password'           => 'password123',
            'role'               => 'employee',
            'attendance_enabled' => false,
            'wfh_enabled'        => true,
            'radius_enabled'     => true,
        ], $this->token($admin))->assertCreated();

        $this->assertFalse($resStore->json('user.attendance_enabled'));
        $this->assertFalse($resStore->json('user.wfh_enabled'));
        $this->assertFalse($resStore->json('user.radius_enabled'));

        $newUser = User::find($resStore->json('user.id'));
        $this->assertFalse($newUser->attendance_enabled);
        $this->assertFalse($newUser->wfh_enabled);
        $this->assertFalse($newUser->radius_enabled);

        // 2. Update user: hidupkan attendance_enabled dan wfh_enabled
        $resUpdate = $this->putJson("/api/v1/admin/users/{$newUser->id}", [
            'attendance_enabled' => true,
            'wfh_enabled'        => true,
            'radius_enabled'     => false,
        ], $this->token($admin))->assertOk();

        $this->assertTrue($resUpdate->json('user.attendance_enabled'));
        $this->assertTrue($resUpdate->json('user.wfh_enabled'));
        $this->assertFalse($resUpdate->json('user.radius_enabled'));

        // 3. Update lagi: matikan attendance_enabled -> wfh_enabled otomatis false
        $resUpdateOff = $this->putJson("/api/v1/admin/users/{$newUser->id}", [
            'attendance_enabled' => false,
            'wfh_enabled'        => true,
            'radius_enabled'     => true,
        ], $this->token($admin))->assertOk();

        $this->assertFalse($resUpdateOff->json('user.attendance_enabled'));
        $this->assertFalse($resUpdateOff->json('user.wfh_enabled'));
        $this->assertFalse($resUpdateOff->json('user.radius_enabled'));

        // 4. Pastikan index GET /api/v1/admin/users juga mengembalikan field attendance_enabled
        $resIndex = $this->getJson('/api/v1/admin/users', $this->token($admin))->assertOk();
        $targetInIndex = collect($resIndex->json('data'))->firstWhere('id', $newUser->id);
        $this->assertNotNull($targetInIndex);
        $this->assertFalse($targetInIndex['attendance_enabled']);
        $this->assertFalse($targetInIndex['wfh_enabled']);
        $this->assertFalse($targetInIndex['radius_enabled']);

        // 5. Pastikan token mobile dicabut saat attendance_enabled dimatikan via mobile-policy
        $newUser->createToken('auth-token-mobile');
        $this->assertEquals(1, $newUser->tokens()->where('name', 'auth-token-mobile')->count());

        $this->putJson("/api/v1/dashboard/attendance/users/{$newUser->id}/mobile-policy", [
            'attendance_enabled' => false,
            'wfh_enabled'        => false,
            'radius_enabled'     => false,
        ], $this->token($admin))->assertOk();

        $this->assertEquals(0, $newUser->tokens()->where('name', 'auth-token-mobile')->count());
    }

    public function test_toggle_attendance_off_cascades_all_switches_off_and_guards_activation(): void
    {
        $admin = $this->user('admin');
        $user = $this->user('employee', attendance: true, wfh: true, radius: true);
        $user->dinas_luar_enabled = true;
        $user->flexitime_enabled = true;
        $user->save();

        // 1. Toggle mobile attendance OFF
        $res = $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-attendance", [], $this->token($admin))
            ->assertOk();

        $this->assertFalse($res->json('user.attendance_enabled'));
        $this->assertFalse($res->json('user.wfh_enabled'));
        $this->assertFalse($res->json('user.radius_enabled'));
        $this->assertFalse($res->json('user.dinas_luar_enabled'));
        // Flexitime tetap aktif (independen dari presensi mobile)
        $this->assertTrue($res->json('user.flexitime_enabled'));

        $user->refresh();
        $this->assertFalse((bool) $user->attendance_enabled);
        $this->assertFalse((bool) $user->wfh_enabled);
        $this->assertFalse((bool) $user->radius_enabled);
        $this->assertFalse((bool) $user->dinas_luar_enabled);
        $this->assertTrue((bool) $user->flexitime_enabled);

        // 2. Coba toggle Dinas Luar saat Presensi Mobile mati -> ditolak 422
        $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-dinas-luar", [], $this->token($admin))
            ->assertStatus(422);

        // 3. Toggle Flexitime saat Presensi Mobile mati -> BERHASIL (karena flexitime independen)
        $toggleFlexRes = $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-flexitime", [], $this->token($admin))
            ->assertOk();
        $this->assertFalse($toggleFlexRes->json('user.flexitime_enabled'));

        // 4. Toggle Presensi Mobile saat mati harian tapi diizinkan di profil -> BERHASIL ON kembali
        $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-attendance", [], $this->token($admin))
            ->assertOk()
            ->assertJsonPath('user.attendance_enabled', true);

        // 5. Update lewat Edit Profil Karyawan (PUT /api/v1/admin/users/{id}) dengan allow_attendance: false
        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'allow_attendance'   => false,
            'flexitime_enabled'  => true,
        ], $this->token($admin))->assertOk();

        $user->refresh();
        $this->assertFalse((bool) $user->allow_attendance);
        $this->assertFalse((bool) $user->attendance_enabled);
        $this->assertTrue((bool) $user->flexitime_enabled);

        // 5b. Saat allow_attendance mati di profil, barulah switch terkunci dan tolak 422
        $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-attendance", [], $this->token($admin))
            ->assertStatus(422)
            ->assertJsonPath('message', "Akses Presensi Mobile untuk '{$user->name}' sedang dinonaktifkan di Edit Profil Karyawan. Switch terkunci dan harus diaktifkan kembali melalui menu Edit Profil Karyawan.");

        // 6. Aktifkan kembali Presensi Mobile lewat Edit Profil Karyawan
        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'allow_attendance' => true,
        ], $this->token($admin))->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->attendance_enabled);

        // 7. Jika Izinkan Presensi WFH di-uncheck lewat Edit Profil Karyawan, radius_enabled otomatis false
        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'wfh_enabled' => false,
        ], $this->token($admin))->assertOk();

        $user->refresh();
        $this->assertFalse((bool) $user->wfh_enabled);
        $this->assertFalse((bool) $user->radius_enabled);

        // Switch Lapangan terkunci off saat WFH mati di profil -> tolak 422
        $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-radius", [], $this->token($admin))
            ->assertStatus(422)
            ->assertJsonPath('message', "Izinkan Presensi WFH untuk '{$user->name}' sedang dinonaktifkan di Edit Profil Karyawan. Switch Lapangan terkunci dan harus diaktifkan kembali melalui menu Edit Profil Karyawan.");

        // 8. Jika Validasi Radius Geofence di-uncheck lewat Edit Profil Karyawan, switch Lapangan terkunci off
        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'wfh_enabled'    => true,
            'radius_enabled' => false,
        ], $this->token($admin))->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->wfh_enabled);
        $this->assertFalse((bool) $user->radius_enabled);

        // Switch Lapangan terkunci off saat radius mati di profil -> tolak 422
        $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-radius", [], $this->token($admin))
            ->assertStatus(422)
            ->assertJsonPath('message', "Validasi Radius Geofence untuk '{$user->name}' sedang dinonaktifkan di Edit Profil Karyawan. Switch Lapangan terkunci dan harus diaktifkan kembali melalui menu Edit Profil Karyawan.");

        // 9. Aktifkan kembali Validasi Radius Geofence lewat Edit Profil Karyawan -> radius_enabled true
        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'wfh_enabled'    => true,
            'radius_enabled' => true,
        ], $this->token($admin))->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->wfh_enabled);
        $this->assertTrue((bool) $user->radius_enabled);

        // Sekarang toggle-radius bisa mematikan radius sementara (karena sedang aktif)
        $this->postJson("/api/v1/dashboard/attendance/users/{$user->id}/toggle-radius", [], $this->token($admin))
            ->assertOk()
            ->assertJsonPath('user.radius_enabled', false);
    }

    public function test_check_in_accepts_office_alias_and_normalizes_to_onsite(): void
    {
        $emp = $this->user('employee', attendance: true, wfh: true);
        $emp->radius_enabled = false;
        $emp->save();

        $res = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'      => -6.2088,
            'longitude'     => 106.8456,
            'check_in_type' => 'office',
        ], $this->token($emp))->assertCreated();

        $this->assertDatabaseHas('attendances', [
            'user_id'       => $emp->id,
            'check_in_type' => 'wfh', // Karena radius_enabled = false & wfh_enabled = true
        ]);
    }
}



