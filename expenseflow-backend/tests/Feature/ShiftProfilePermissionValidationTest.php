<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftPattern;
use App\Models\ShiftPatternItem;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftProfilePermissionValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private Shift $wfhShift;
    private Shift $fieldShift;
    private Shift $regularShift;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-04 08:00:00'); // Friday (day 5)

        $this->company = Company::create(['name' => 'PT Uji Izin', 'is_active' => true]);
        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Pusat',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.816667,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'break_minutes'               => 60,
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);

        $this->admin = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'admin',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        // 1. Shift WFH
        $this->wfhShift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift WFH Jumat',
            'is_active'             => true,
            'color'                 => '#10b981',
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $this->wfhShift->id,
                'day_of_week'     => $d,
                'effective_date'  => '2026-09-01',
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'break_minutes'   => 60,
                'is_off'          => false,
                'is_wfh'          => $d === 5, // Jumat WFH
                'is_field'        => false,
            ]);
        }

        // 2. Shift Lapangan
        $this->fieldShift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Lapangan Sales',
            'is_active'             => true,
            'color'                 => '#f59e0b',
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $this->fieldShift->id,
                'day_of_week'     => $d,
                'effective_date'  => '2026-09-01',
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'break_minutes'   => 60,
                'is_off'          => false,
                'is_wfh'          => false,
                'is_field'        => true, // Lapangan
            ]);
        }

        // 3. Shift Onsite Reguler
        $this->regularShift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Onsite Reguler',
            'is_active'             => true,
            'color'                 => '#3b82f6',
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $this->regularShift->id,
                'day_of_week'     => $d,
                'effective_date'  => '2026-09-01',
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'break_minutes'   => 60,
                'is_off'          => false,
                'is_wfh'          => false,
                'is_field'        => false,
            ]);
        }
    }

    public function test_cannot_assign_wfh_shift_if_user_allow_wfh_is_false(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => false, // Izin WFH nonaktif
            'allow_radius'          => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $employee->id,
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-04',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => "Penugasan shift ditolak: Shift '{$this->wfhShift->name}' memiliki jadwal WFH pada hari (Jumat), sedangkan izin 'Izinkan Presensi WFH' untuk '{$employee->name}' sedang dinonaktifkan di Edit Profil Karyawan.",
        ]);
    }

    public function test_cannot_assign_field_shift_if_user_allow_radius_is_false(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => false, // Izin Radius Geofence nonaktif
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $employee->id,
            'shift_id'   => $this->fieldShift->id,
            'start_date' => '2026-09-04',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Validasi Radius Geofence', $response->json('message'));
    }

    public function test_cannot_assign_wfh_shift_if_user_allow_attendance_is_false(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => false, // Akses Mobile nonaktif
            'allow_wfh'             => true,
            'allow_radius'          => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $employee->id,
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-04',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Akses Mobile', $response->json('message'));
    }

    public function test_can_assign_regular_shift_even_if_user_allow_wfh_is_false(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => false,
            'allow_radius'          => false,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $employee->id,
            'shift_id'   => $this->regularShift->id,
            'start_date' => '2026-09-04',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('user_shifts', [
            'user_id'  => $employee->id,
            'shift_id' => $this->regularShift->id,
        ]);
    }

    public function test_bulk_assign_skips_incompatible_users_and_reports_them(): void
    {
        $empValid = User::factory()->create([
            'company_id'            => $this->company->id,
            'name'                  => 'Budi Valid',
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => true,
        ]);

        $empBlocked = User::factory()->create([
            'company_id'            => $this->company->id,
            'name'                  => 'Joko Non-WFH',
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => false, // Blocked
            'allow_radius'          => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/dashboard/attendance/bulk-assign', [
            'user_ids'   => [$empValid->id, $empBlocked->id],
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-04',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'total_success' => 1,
            'total_skipped' => 1,
        ]);

        $skipped = $response->json('skipped');
        $this->assertCount(1, $skipped);
        $this->assertEquals($empBlocked->id, $skipped[0]['user_id']);
        $this->assertEquals('Joko Non-WFH', $skipped[0]['name']);
        $this->assertStringContainsString('Izinkan Presensi WFH', $skipped[0]['reason']);

        // Pastikan empValid berhasil terdaftar, empBlocked tidak
        $this->assertDatabaseHas('user_shifts', ['user_id' => $empValid->id, 'shift_id' => $this->wfhShift->id]);
        $this->assertDatabaseMissing('user_shifts', ['user_id' => $empBlocked->id, 'shift_id' => $this->wfhShift->id]);
    }

    public function test_runtime_checkin_and_checkstatus_respect_profile_permission(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'name'                  => 'Siti Staff',
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => false, // Master WFH mati
            'wfh_enabled'           => false,
            'attendance_enabled'    => true,
        ]);

        // Asumsikan sebelumnya karyawan sempat di-assign ke shift WFH
        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-01',
            'is_active'  => true,
        ]);

        // Cek checkStatus (status)
        $statusResp = $this->actingAs($employee, 'sanctum')->getJson('/api/v1/attendance/status');
        $statusResp->assertStatus(200);
        // wfh_enabled harus false karena profil allow_wfh = false
        $this->assertFalse($statusResp->json('wfh_enabled'));

        // Coba check-in WFH via mobile
        $checkInResp = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude'       => -6.20,
            'longitude'      => 106.816667,
            'check_in_type'  => 'wfh',
        ], [
            'X-Platform' => 'mobile',
        ]);

        $checkInResp->assertStatus(403);
        $this->assertStringContainsString('Presensi aplikasi hanya untuk karyawan WFH atau lapangan', $checkInResp->json('message'));
    }

    public function test_cannot_assign_pattern_with_wfh_if_user_allow_wfh_is_false(): void
    {
        $pattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola Hybrid 4 Hari',
            'cycle_days' => 4,
            'is_active'  => true,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $pattern->id,
            'day_order'        => 1,
            'is_off'           => false,
            'is_wfh'           => true, // H1 WFH
            'is_field'         => false,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
        ]);
        for ($i = 2; $i <= 4; $i++) {
            ShiftPatternItem::create([
                'shift_pattern_id' => $pattern->id,
                'day_order'        => $i,
                'is_off'           => false,
                'is_wfh'           => false,
                'is_field'         => false,
                'work_start_time'  => '08:00:00',
                'work_end_time'    => '17:00:00',
            ]);
        }

        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => false, // Blocked WFH
            'allow_radius'          => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $employee->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-04',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Izinkan Presensi WFH', $response->json('message'));
    }

    public function test_cannot_assign_pattern_with_field_if_user_allow_radius_is_false(): void
    {
        $pattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola Lapangan 3 Hari',
            'cycle_days' => 3,
            'is_active'  => true,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $pattern->id,
            'day_order'        => 1,
            'is_off'           => false,
            'is_wfh'           => false,
            'is_field'         => true, // H1 Field
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
        ]);
        for ($i = 2; $i <= 3; $i++) {
            ShiftPatternItem::create([
                'shift_pattern_id' => $pattern->id,
                'day_order'        => $i,
                'is_off'           => false,
                'is_wfh'           => false,
                'is_field'         => false,
                'work_start_time'  => '08:00:00',
                'work_end_time'    => '17:00:00',
            ]);
        }

        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => false, // Blocked Radius
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $employee->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-04',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Validasi Radius Geofence', $response->json('message'));
    }

    public function test_user_index_includes_shift_locks_when_assigned(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => true,
        ]);

        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-01',
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/users');
        $response->assertStatus(200);

        $userData = collect($response->json('data'))->firstWhere('id', $employee->id);
        $this->assertNotNull($userData);
        $this->assertArrayHasKey('shift_locks', $userData);
        $this->assertTrue($userData['shift_locks']['has_active_shift']);
        $this->assertTrue($userData['shift_locks']['lock_attendance']);
        $this->assertTrue($userData['shift_locks']['lock_wfh']);
        $this->assertFalse($userData['shift_locks']['lock_radius']);
        $this->assertStringContainsString($this->wfhShift->name, $userData['shift_locks']['reason_wfh']);
    }

    public function test_cannot_uncheck_allow_wfh_in_user_update_if_assigned_to_wfh_shift(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => true,
        ]);

        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-01',
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/users/{$employee->id}", [
            'name'      => $employee->name,
            'allow_wfh' => false,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Shift WFH Jumat', $response->json('message'));
        $this->assertStringContainsString('memiliki jadwal WFH', $response->json('message'));

        $employee->refresh();
        $this->assertTrue($employee->allow_wfh);
    }

    public function test_cannot_uncheck_allow_radius_in_user_update_if_assigned_to_field_shift(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => true,
        ]);

        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $this->fieldShift->id,
            'start_date' => '2026-09-01',
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/users/{$employee->id}", [
            'name'         => $employee->name,
            'allow_radius' => false,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Shift Lapangan Sales', $response->json('message'));
        $this->assertStringContainsString('memiliki jadwal Lapangan', $response->json('message'));

        $employee->refresh();
        $this->assertTrue($employee->allow_radius);
    }

    public function test_cannot_uncheck_allow_attendance_in_user_update_if_assigned_to_mobile_shift(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => true,
        ]);

        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-01',
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/users/{$employee->id}", [
            'name'             => $employee->name,
            'allow_attendance' => false,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('memerlukan presensi mobile', $response->json('message'));

        $employee->refresh();
        $this->assertTrue($employee->allow_attendance);
    }

    public function test_cannot_toggle_off_in_attendance_controller_if_assigned_to_shift(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => true,
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => true,
        ]);

        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $this->wfhShift->id,
            'start_date' => '2026-09-01',
            'is_active'  => true,
        ]);

        // 1. Toggle Attendance off -> must be rejected
        $respAtt = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/dashboard/attendance/users/{$employee->id}/toggle-attendance");
        $respAtt->assertStatus(422);
        $this->assertStringContainsString('memerlukan presensi mobile', $respAtt->json('message'));

        // 2. Toggle WFH off -> must be rejected
        $respWfh = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/dashboard/attendance/users/{$employee->id}/toggle-wfh");
        $respWfh->assertStatus(422);
        $this->assertStringContainsString('Shift WFH Jumat', $respWfh->json('message'));

        // 3. Mobile policy with attendance_enabled = false -> must be rejected
        $respPol = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/dashboard/attendance/users/{$employee->id}/mobile-policy", [
            'attendance_enabled' => false,
            'wfh_enabled'        => false,
            'radius_enabled'     => false,
        ]);
        $respPol->assertStatus(422);
        $this->assertStringContainsString('memerlukan presensi mobile', $respPol->json('message'));
    }

    public function test_can_uncheck_permissions_if_shift_is_regular_onsite_or_ended(): void
    {
        $employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_setting_id' => $this->office->id,
            'allow_attendance'      => true,
            'allow_wfh'             => true,
            'allow_radius'          => true,
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => true,
        ]);

        // Assigned to onsite-only shift (no WFH, no field)
        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $this->regularShift->id,
            'start_date' => '2026-09-01',
            'is_active'  => true,
        ]);

        // Unchecking WFH should succeed!
        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/users/{$employee->id}", [
            'name'      => $employee->name,
            'allow_wfh' => false,
        ]);

        $response->assertStatus(200);
        $employee->refresh();
        $this->assertFalse($employee->allow_wfh);
    }
}

