<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FlexitimeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $hrd;
    private User $flexUser;
    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Sinergi Teknologi Mandiri',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'                    => $this->company->id,
            'office_name'                   => 'Kantor Pusat Jakarta',
            'office_latitude'               => -6.2000000,
            'office_longitude'              => 106.8166670,
            'radius_meters'                 => 1000,
            'work_start_time'               => '08:00:00',
            'work_end_time'                 => '17:00:00',
            'break_minutes'                 => 60,
            'late_tolerance_minutes'        => 15,
            'early_leave_tolerance_minutes' => 15,
            'flex_arrival_start'            => '07:00:00',
            'flex_arrival_end'              => '10:00:00',
            'flex_core_start'               => '10:00:00',
            'flex_core_end'                 => '15:00:00',
            'flex_target_minutes'           => 480, // 8 jam
        ]);

        $this->hrd = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'hrd',
            'attendance_enabled'    => true,
        ]);

        // Karyawan dengan jam kerja fleksibel
        $this->flexUser = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => false,
            'flexitime_enabled'     => true,
        ]);

        // Karyawan dengan jam kerja kaku (fixed hours)
        $this->normalUser = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => false,
            'flexitime_enabled'     => false,
        ]);
    }

    public function test_flexitime_employee_checkin_within_arrival_window_is_present(): void
    {
        // Pukul 08:45 WIB: lewat dari jam 08:00 tapi masih di jendela kedatangan flex (07:00 - 10:00)
        Carbon::setTestNow('2026-09-16 08:45:00');

        $response = $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response->assertStatus(201);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        $this->assertNotNull($attendance);
        // Karyawan flex statusnya HARUS 'present' (tidak telat)
        $this->assertEquals('present', $attendance->status);
    }

    public function test_non_flexitime_employee_checkin_at_same_time_is_late(): void
    {
        // Pukul 08:45 WIB: karyawan non-flex terlambat karena jam masuk 08:00 (+15m toleransi = 08:15)
        Carbon::setTestNow('2026-09-16 08:45:00');

        $response = $this->actingAs($this->normalUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response->assertStatus(201);

        $attendance = Attendance::where('user_id', $this->normalUser->id)->first();
        $this->assertNotNull($attendance);
        // Karyawan non-flex statusnya HARUS 'late' (terlambat)
        $this->assertEquals('late', $attendance->status);
    }

    public function test_flexitime_employee_checkin_after_arrival_window_is_late(): void
    {
        // Pukul 10:20 WIB: lewat batas akhir arrival 10:00 (+15m toleransi = 10:15)
        Carbon::setTestNow('2026-09-16 10:20:00');

        $response = $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response->assertStatus(201);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        $this->assertNotNull($attendance);
        // Melewati jendela fleksibel -> 'late'
        $this->assertEquals('late', $attendance->status);
    }

    public function test_flexitime_employee_checkout_after_core_and_duration_is_present(): void
    {
        // Masuk jam 08:30 WIB
        $checkIn = Carbon::parse('2026-09-16 08:30:00', 'Asia/Jakarta');
        Carbon::setTestNow($checkIn);

        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        // Pulang jam 17:35 WIB (9 jam 5 menit di kantor - 1 jam break = 8 jam 5 menit durasi kerja bersih)
        // Sudah lewat core hours (15:00) dan target 8 jam terpenuhi
        Carbon::setTestNow('2026-09-16 17:35:00');

        $response = $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response->assertStatus(200);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        $this->assertEquals('present', $attendance->status);
        $this->assertGreaterThanOrEqual(480, $attendance->work_minutes);
    }

    public function test_flexitime_employee_checkout_before_core_hours_is_early_leave(): void
    {
        // Masuk sangat pagi jam 07:00 WIB
        Carbon::setTestNow('2026-09-16 07:00:00');

        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        // Pulang jam 14:30 WIB (sebelum jam inti berakhir 15:00)
        Carbon::setTestNow('2026-09-16 14:30:00');

        $response = $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response->assertStatus(200);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        // Melanggar jam inti -> status 'early_leave'
        $this->assertEquals('early_leave', $attendance->status);
    }

    public function test_flexitime_employee_checkout_with_insufficient_duration_is_early_leave(): void
    {
        // Masuk jam 09:30 WIB
        Carbon::setTestNow('2026-09-16 09:30:00');

        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        // Pulang jam 16:00 WIB (sudah lewat jam inti 15:00, tetapi baru 6.5 jam kotor - 1 jam istirahat = 5.5 jam)
        // Kurang dari target 8 jam (480 menit)
        Carbon::setTestNow('2026-09-16 16:00:00');

        $response = $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response->assertStatus(200);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        // Durasi tidak mencapai target -> status 'early_leave'
        $this->assertEquals('early_leave', $attendance->status);
    }

    public function test_hrd_can_toggle_flexitime_via_api(): void
    {
        // Normal user awalnya flexitime_enabled = false
        $this->assertFalse((bool) $this->normalUser->flexitime_enabled);

        // HRD mengaktifkan flexitime untuk normalUser
        $response = $this->actingAs($this->hrd)->postJson("/api/v1/dashboard/attendance/users/{$this->normalUser->id}/toggle-flexitime");

        $response->assertStatus(200)
            ->assertJson([
                'user' => [
                    'id'                => $this->normalUser->id,
                    'flexitime_enabled' => true,
                ],
            ]);

        $this->normalUser->refresh();
        $this->assertTrue((bool) $this->normalUser->flexitime_enabled);

        // HRD menonaktifkan kembali
        $response2 = $this->actingAs($this->hrd)->postJson("/api/v1/dashboard/attendance/users/{$this->normalUser->id}/toggle-flexitime");
        $response2->assertStatus(200)
            ->assertJson([
                'user' => [
                    'id'                => $this->normalUser->id,
                    'flexitime_enabled' => false,
                ],
            ]);

        $this->normalUser->refresh();
        $this->assertFalse((bool) $this->normalUser->flexitime_enabled);
    }

    public function test_attendance_status_api_returns_flexitime_config_and_dynamic_target(): void
    {
        // Check in jam 08:30 WIB
        Carbon::setTestNow('2026-09-16 08:30:00');

        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response = $this->actingAs($this->flexUser)->getJson('/api/v1/attendance/status');

        $response->assertStatus(200)
            ->assertJson([
                'checked_in'        => true,
                'flexitime_enabled' => true,
                'flexitime_config'  => [
                    'arrival_start'        => '07:00',
                    'arrival_end'          => '10:00',
                    'core_start'           => '10:00',
                    'core_end'             => '15:00',
                    'target_minutes'       => 480,
                    'target_checkout_time' => '17:30', // 08:30 + 8h (480m) + 1h break (60m) = 17:30
                ],
            ]);
    }

    public function test_hrd_can_update_office_flexitime_settings(): void
    {
        $response = $this->actingAs($this->hrd)->putJson("/api/v1/dashboard/attendance/settings/{$this->office->id}", [
            'office_name'         => 'Kantor Pusat Jakarta (Updated)',
            'office_latitude'     => -6.2000000,
            'office_longitude'    => 106.8166670,
            'radius_meters'       => 1000,
            'work_start_time'     => '08:00',
            'work_end_time'       => '17:00',
            'work_days'           => [1, 2, 3, 4, 5],
            'flex_arrival_start'  => '06:30',
            'flex_arrival_end'    => '09:30',
            'flex_core_start'     => '09:30',
            'flex_core_end'       => '14:30',
            'flex_target_minutes' => 450,
            'confirm_dangerous'   => 'SIMPAN',
        ]);

        $response->assertStatus(200);

        $this->office->refresh();
        $this->assertEquals('06:30', substr($this->office->flex_arrival_start, 0, 5));
        $this->assertEquals('09:30', substr($this->office->flex_arrival_end, 0, 5));
        $this->assertEquals('09:30', substr($this->office->flex_core_start, 0, 5));
        $this->assertEquals('14:30', substr($this->office->flex_core_end, 0, 5));
        $this->assertEquals(450, $this->office->flex_target_minutes);
    }

    public function test_flexitime_employee_overtime_is_calculated_above_target_minutes(): void
    {
        // Masuk jam 08:00
        Carbon::setTestNow('2026-09-16 08:00:00');
        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        // Pulang jam 18:30 (total 10.5 jam = 630 mnt. Istirahat 60 mnt -> 570 mnt kerja. Target 480 mnt -> Lembur 90 mnt)
        Carbon::setTestNow('2026-09-16 18:30:00');
        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        $this->assertNotNull($attendance);
        $this->assertEquals(90, $attendance->overtime_minutes);
    }

    public function test_assign_shift_automatically_disables_flexitime_for_user(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Pagi Operasional',
            'is_active'             => true,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'effective_date'  => '2026-01-01',
                'day_of_week'     => $d,
                'work_start_time' => '07:00:00',
                'work_end_time'   => '15:00:00',
                'is_off'          => $d === 0,
                'break_minutes'   => 60,
            ]);
        }

        $this->assertTrue((bool) $this->flexUser->flexitime_enabled);

        $response = $this->actingAs($this->hrd)->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $this->flexUser->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-09-17',
        ]);

        $response->assertStatus(201);
        $this->flexUser->refresh();
        $this->assertFalse((bool) $this->flexUser->flexitime_enabled);
    }

    public function test_bulk_assign_automatically_disables_flexitime_for_users(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Siang',
            'is_active'             => true,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'effective_date'  => '2026-01-01',
                'day_of_week'     => $d,
                'work_start_time' => '14:00:00',
                'work_end_time'   => '22:00:00',
                'is_off'          => false,
                'break_minutes'   => 60,
            ]);
        }

        $this->assertTrue((bool) $this->flexUser->flexitime_enabled);

        $response = $this->actingAs($this->hrd)->postJson('/api/v1/dashboard/attendance/bulk-assign', [
            'user_ids'   => [$this->flexUser->id],
            'shift_id'   => $shift->id,
            'start_date' => '2026-09-17',
        ]);

        $response->assertStatus(201);
        $this->flexUser->refresh();
        $this->assertFalse((bool) $this->flexUser->flexitime_enabled);
    }

    public function test_toggle_flexitime_is_blocked_when_user_has_active_shift(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam',
            'is_active'             => true,
        ]);

        UserShift::create([
            'user_id'    => $this->normalUser->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-09-16',
            'end_date'   => null,
        ]);

        $this->assertFalse((bool) $this->normalUser->flexitime_enabled);

        // HRD mencoba mengaktifkan flexitime untuk normalUser yang sedang shift aktif
        $response = $this->actingAs($this->hrd)->postJson("/api/v1/dashboard/attendance/users/{$this->normalUser->id}/toggle-flexitime");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => "Karyawan '{$this->normalUser->name}' sedang memiliki penugasan Shift Malam. Jam kerja fleksibel (flexitime) tidak dapat diaktifkan untuk karyawan berjadwal shift.",
            ]);

        $this->normalUser->refresh();
        $this->assertFalse((bool) $this->normalUser->flexitime_enabled);
    }

    public function test_list_users_includes_has_active_shift_flag_and_name(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Khusus Roster',
            'is_active'             => true,
        ]);

        UserShift::create([
            'user_id'    => $this->normalUser->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-09-16',
            'end_date'   => null,
        ]);

        $response = $this->actingAs($this->hrd)->getJson('/api/v1/dashboard/attendance/users');

        $response->assertStatus(200);
        $data = $response->json('data');

        $normalUserItem = collect($data)->firstWhere('id', $this->normalUser->id);
        $flexUserItem   = collect($data)->firstWhere('id', $this->flexUser->id);

        $this->assertNotNull($normalUserItem);
        $this->assertTrue($normalUserItem['has_active_shift']);
        $this->assertEquals('Shift Khusus Roster', $normalUserItem['active_shift_name']);

        $this->assertNotNull($flexUserItem);
        $this->assertFalse($flexUserItem['has_active_shift']);
        $this->assertNull($flexUserItem['active_shift_name']);
    }

    public function test_checkin_stores_flexitime_snapshot_in_attendances_table(): void
    {
        Carbon::setTestNow('2026-09-16 08:30:00');
        $response = $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $response->assertStatus(201);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        $this->assertNotNull($attendance);
        $this->assertTrue((bool) $attendance->snap_flexitime_enabled);
        $this->assertEquals('07:00:00', $attendance->snap_flex_arrival_start);
        $this->assertEquals('10:00:00', $attendance->snap_flex_arrival_end);
        $this->assertEquals('10:00:00', $attendance->snap_flex_core_start);
        $this->assertEquals('15:00:00', $attendance->snap_flex_core_end);
        $this->assertEquals(480, $attendance->snap_flex_target_minutes);
    }

    public function test_midday_hrd_change_of_office_flex_target_minutes_does_not_affect_active_session(): void
    {
        // 1. Karyawan flexitime check-in pada pukul 08:00 saat target kantor masih 480 menit (8 jam)
        Carbon::setTestNow('2026-09-16 08:00:00');
        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        $this->assertNotNull($attendance);
        $this->assertEquals(480, $attendance->snap_flex_target_minutes);

        // 2. Di siang hari (12:00), HRD mengubah setting kantor: target dinaikkan menjadi 600 menit (10 jam)
        Carbon::setTestNow('2026-09-16 12:00:00');
        $this->office->update([
            'flex_target_minutes' => 600,
        ]);
        $this->office->refresh();
        $this->assertEquals(600, $this->office->flex_target_minutes);

        // 3. Status API pada sesi aktif tetap membaca target snapshot (480 menit)
        $statusResp = $this->actingAs($this->flexUser)->getJson('/api/v1/attendance/status');
        $statusResp->assertStatus(200)
            ->assertJsonPath('flexitime_enabled', true)
            ->assertJsonPath('flexitime_config.target_minutes', 480);

        // 4. Karyawan checkout pada 18:30
        // Total rentang 08:00 - 18:30 = 630 menit.
        // Potong istirahat 60 menit -> menit kerja bersih = 570 menit.
        // Karena snapshot dibekukan pada 480 menit:
        // Lembur = 570 - 480 = 90 menit.
        // (Jika terpengaruh perubahan siang hari ke 600 menit, lembur akan 0 menit).
        Carbon::setTestNow('2026-09-16 18:30:00');
        $checkOutResp = $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $checkOutResp->assertStatus(200);

        $attendance->refresh();
        $this->assertEquals(570, $attendance->work_minutes);
        $this->assertEquals(90, $attendance->overtime_minutes);
    }

    public function test_midday_hrd_toggle_off_user_flexitime_preserves_active_session(): void
    {
        // 1. Karyawan flexitime check-in pukul 08:30
        Carbon::setTestNow('2026-09-16 08:30:00');
        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        // 2. Di siang hari (13:00), HRD mematikan flexitime user
        Carbon::setTestNow('2026-09-16 13:00:00');
        $this->flexUser->update(['flexitime_enabled' => false]);
        $this->flexUser->refresh();
        $this->assertFalse((bool) $this->flexUser->flexitime_enabled);

        // 3. Status API pada sesi aktif tetap menganggap ini sesi flexitime berkat snapshot
        $statusResp = $this->actingAs($this->flexUser)->getJson('/api/v1/attendance/status');
        $statusResp->assertStatus(200)
            ->assertJsonPath('flexitime_enabled', true)
            ->assertJsonPath('flexitime_config.target_minutes', 480);

        // 4. Karyawan checkout pukul 17:30
        // Sesi flexitime: 08:30 ke 17:30 = 9 jam = 540 mnt - 60 mnt break = 480 mnt (target terpenuhi).
        // Checkout setelah core_end (15:00) -> status bukan early_leave.
        Carbon::setTestNow('2026-09-16 17:30:00');
        $this->actingAs($this->flexUser)->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.2000000,
            'longitude' => 106.8166670,
        ]);

        $attendance = Attendance::where('user_id', $this->flexUser->id)->first();
        $this->assertNotNull($attendance);
        $this->assertEquals('present', $attendance->status);
        $this->assertEquals(480, $attendance->work_minutes);
    }
}

