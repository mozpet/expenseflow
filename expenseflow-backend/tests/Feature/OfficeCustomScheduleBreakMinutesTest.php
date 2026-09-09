<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use App\Http\Controllers\API\ShiftController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfficeCustomScheduleBreakMinutesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PT Istirahat Fleksibel', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Cabang',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 150,
            'work_start_time'        => '08:45:00',
            'work_end_time'          => '17:00:00',
            'break_minutes'          => 60,
            'work_days'              => [1, 2, 3, 4, 5],
            'late_tolerance_minutes' => 15,
            'custom_schedules'       => [
                '5' => [ // Jumat: setengah hari / istirahat 30 menit
                    'start'         => '08:00',
                    'end'           => '13:00',
                    'break_minutes' => 30,
                ],
            ],
        ]);

        $this->admin = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'admin',
            'attendance_setting_id' => $this->office->id,
        ]);

        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => false,
            'is_active'             => true,
            'attendance_setting_id' => $this->office->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('test')->plainTextToken];
    }

    public function test_can_save_custom_schedule_with_break_minutes(): void
    {
        $payload = [
            'office_name'       => 'Kantor Cabang Diperbarui',
            'office_latitude'   => -6.200000,
            'office_longitude'  => 106.816667,
            'radius_meters'     => 150,
            'work_start_time'   => '08:45',
            'work_end_time'     => '17:00',
            'break_minutes'     => 60,
            'work_days'         => [1, 2, 3, 4, 5],
            'confirm_dangerous' => 'SIMPAN',
            'custom_schedules'  => [
                '5' => [
                    'start'         => '08:00',
                    'end'           => '13:00',
                    'break_minutes' => 0,
                ],
                '1' => [
                    'start'         => '08:45',
                    'end'           => '17:00',
                    'break_minutes' => 45,
                ],
            ],
        ];

        $res = $this->putJson("/api/v1/dashboard/attendance/settings/{$this->office->id}", $payload, $this->token($this->admin));
        $res->assertOk();

        $this->office->refresh();
        $this->assertEquals(0, $this->office->custom_schedules[5]['break_minutes']);
        $this->assertEquals(45, $this->office->custom_schedules[1]['break_minutes']);
    }

    public function test_custom_schedule_break_minutes_validation(): void
    {
        // break_minutes negatif
        $resNeg = $this->putJson("/api/v1/dashboard/attendance/settings/{$this->office->id}", [
            'confirm_dangerous' => 'SIMPAN',
            'custom_schedules'  => [
                '5' => [
                    'start'         => '08:00',
                    'end'           => '13:00',
                    'break_minutes' => -10,
                ],
            ],
        ], $this->token($this->admin));
        $resNeg->assertStatus(422);

        // break_minutes melebihi 240
        $resMax = $this->putJson("/api/v1/dashboard/attendance/settings/{$this->office->id}", [
            'confirm_dangerous' => 'SIMPAN',
            'custom_schedules'  => [
                '5' => [
                    'start'         => '08:00',
                    'end'           => '13:00',
                    'break_minutes' => 300,
                ],
            ],
        ], $this->token($this->admin));
        $resMax->assertStatus(422);
    }

    public function test_resolve_schedule_uses_custom_break_minutes(): void
    {
        // 2026-09-11 adalah hari Jumat (day_of_week = 5)
        $friday = '2026-09-11';
        $schedFriday = ShiftController::resolveSchedule($this->employee, $friday);
        $this->assertEquals(30, $schedFriday['break_minutes']);
        $this->assertEquals('08:00', $schedFriday['work_start_time']);
        $this->assertEquals('13:00', $schedFriday['work_end_time']);

        // 2026-09-07 adalah hari Senin (day_of_week = 1) -> tidak ada custom break -> fallback ke office default 60
        $monday = '2026-09-07';
        $schedMonday = ShiftController::resolveSchedule($this->employee, $monday);
        $this->assertEquals(60, $schedMonday['break_minutes']);
        $this->assertEquals('08:45:00', $schedMonday['work_start_time']);
    }

    public function test_resolve_schedules_bulk_uses_custom_break_minutes(): void
    {
        $friday = '2026-09-11';
        $bulk = ShiftController::resolveSchedulesBulk(collect([$this->employee]), $friday);
        $this->assertEquals(30, $bulk[$this->employee->id]['break_minutes']);
    }

    public function test_my_schedule_returns_custom_break_minutes(): void
    {
        $res = $this->getJson('/api/v1/employee/my-schedule', $this->token($this->employee));
        $res->assertOk();

        $schedules = $res->json('schedules');
        $fridaySched = collect($schedules)->firstWhere('day_of_week', 5);
        $this->assertEquals(30, $fridaySched['break_minutes']);

        $mondaySched = collect($schedules)->firstWhere('day_of_week', 1);
        $this->assertEquals(60, $mondaySched['break_minutes']);
    }

    public function test_attendance_snapshot_schedule_uses_custom_break_minutes(): void
    {
        $friday = '2026-09-11';
        $attendance = Attendance::create([
            'user_id'              => $this->employee->id,
            'company_id'           => $this->company->id,
            'date'                 => $friday,
            'check_in_time'        => '2026-09-11 08:00:00',
            'check_in_lat'         => -6.200000,
            'check_in_lng'         => 106.816667,
            'check_in_type'        => 'wfh',
            'status'               => 'present',
            'snap_source'          => 'office',
            'snap_office_id'       => $this->office->id,
            'snap_work_start_time' => '08:00:00',
            'snap_work_end_time'   => '13:00:00',
        ]);

        $snapSched = $attendance->snapshotSchedule();
        $this->assertNotNull($snapSched);
        $this->assertEquals(30, $snapSched['break_minutes']);
    }

    public function test_checkout_deducts_custom_break_minutes_when_working_over_5_hours(): void
    {
        // Jumat 2026-09-11
        $friday = '2026-09-11';
        $attendance = Attendance::create([
            'user_id'              => $this->employee->id,
            'company_id'           => $this->company->id,
            'date'                 => $friday,
            'check_in_time'        => '2026-09-11 08:00:00',
            'check_in_lat'         => -6.200000,
            'check_in_lng'         => 106.816667,
            'check_in_type'        => 'wfh',
            'status'               => 'present',
            'snap_source'          => 'office',
            'snap_office_id'       => $this->office->id,
            'snap_work_start_time' => '08:00:00',
            'snap_work_end_time'   => '17:00:00',
        ]);

        // Karyawan checkout jam 14:00 (6 jam = 360 menit kotor).
        // Break custom di Jumat = 30 menit.
        // Durasi kerja bersih = 360 - 30 = 330 menit (bukan 360 - 60 = 300 menit).
        Carbon::setTestNow(Carbon::parse('2026-09-11 14:00:00', 'Asia/Jakarta'));
        $res = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.200000,
            'longitude' => 106.816667,
        ], $this->token($this->employee));

        $res->assertOk();
        $attendance->refresh();
        $this->assertEquals(330, $attendance->work_minutes);
    }
}
