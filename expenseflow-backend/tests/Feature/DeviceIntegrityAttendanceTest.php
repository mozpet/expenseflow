<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceIntegrityAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Test Integrity', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createEmployee(): User
    {
        $setting = AttendanceSetting::create([
            'company_id'            => $this->company->id,
            'office_name'           => 'HQ Integrity',
            'office_latitude'       => -6.2088,
            'office_longitude'      => 106.8456,
            'radius_meters'         => 500,
            'work_start_time'       => '08:00:00',
            'work_end_time'         => '17:00:00',
            'work_days'             => [1, 2, 3, 4, 5],
            'min_checkout_interval_minutes' => 0,
        ]);

        return User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $setting->id,
            'role'                  => 'employee',
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => false,
            'is_active'             => true,
        ]);
    }

    public function test_check_in_blocked_when_device_is_rooted(): void
    {
        $employee = $this->createEmployee();
        Sanctum::actingAs($employee);
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:15:00', 'Asia/Jakarta'));

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2088,
            'longitude' => 106.8456,
            'is_rooted' => true,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'rooted_device_detected' => true,
            ]);

        $this->assertDatabaseMissing('attendances', [
            'user_id' => $employee->id,
        ]);
    }

    public function test_check_in_blocked_when_device_is_emulator(): void
    {
        $employee = $this->createEmployee();
        Sanctum::actingAs($employee);
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:15:00', 'Asia/Jakarta'));

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'    => -6.2088,
            'longitude'   => 106.8456,
            'is_emulator' => true,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'emulator_detected' => true,
            ]);

        $this->assertDatabaseMissing('attendances', [
            'user_id' => $employee->id,
        ]);
    }

    public function test_check_out_blocked_when_device_is_rooted(): void
    {
        $employee = $this->createEmployee();
        Sanctum::actingAs($employee);
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'Asia/Jakarta'));

        // Normal check-in (201 Created)
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2088,
            'longitude' => 106.8456,
        ])->assertCreated();

        // Attempt check-out with rooted flag
        Carbon::setTestNow(Carbon::parse('2026-09-16 17:05:00', 'Asia/Jakarta'));
        $response = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.2088,
            'longitude' => 106.8456,
            'is_rooted' => true,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'rooted_device_detected' => true,
            ]);

        $attendance = Attendance::where('user_id', $employee->id)->first();
        $this->assertNotNull($attendance);
        $this->assertNull($attendance->check_out_time);
    }

    public function test_check_out_blocked_when_device_is_emulator(): void
    {
        $employee = $this->createEmployee();
        Sanctum::actingAs($employee);
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'Asia/Jakarta'));

        // Normal check-in (201 Created)
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.2088,
            'longitude' => 106.8456,
        ])->assertCreated();

        // Attempt check-out with emulator flag
        Carbon::setTestNow(Carbon::parse('2026-09-16 17:05:00', 'Asia/Jakarta'));
        $response = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'    => -6.2088,
            'longitude'   => 106.8456,
            'is_emulator' => true,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'emulator_detected' => true,
            ]);

        $attendance = Attendance::where('user_id', $employee->id)->first();
        $this->assertNotNull($attendance);
        $this->assertNull($attendance->check_out_time);
    }

    public function test_check_in_and_check_out_success_when_device_is_clean(): void
    {
        $employee = $this->createEmployee();
        Sanctum::actingAs($employee);
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'Asia/Jakarta'));

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'    => -6.2088,
            'longitude'   => 106.8456,
            'is_mocked'   => false,
            'is_rooted'   => false,
            'is_emulator' => false,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-09-16 17:00:00', 'Asia/Jakarta'));
        $this->postJson('/api/v1/attendance/check-out', [
            'latitude'    => -6.2088,
            'longitude'   => 106.8456,
            'is_mocked'   => false,
            'is_rooted'   => false,
            'is_emulator' => false,
        ])->assertOk();

        $attendance = Attendance::where('user_id', $employee->id)->first();
        $this->assertNotNull($attendance);
        $this->assertNotNull($attendance->check_out_time);
    }
}
