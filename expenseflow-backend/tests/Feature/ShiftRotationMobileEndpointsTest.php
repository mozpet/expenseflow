<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\ShiftPatternItem;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftRotationMobileEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $employee;
    private Shift $morningShift;
    private ShiftPattern $pattern;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'PT Mobile Rotation Test',
            'code' => 'PMRT',
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Pusat Mobile',
            'office_latitude'  => -6.200000,
            'office_longitude' => 106.816666,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
            'work_days'        => [1, 2, 3, 4, 5],
            'late_tolerance_minutes' => 15,
            'break_minutes'    => 60,
        ]);

        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'department'            => 'Field Ops',
            'attendance_enabled'    => true,
        ]);

        // Shift template pagi
        $this->morningShift = Shift::create([
            'company_id' => $this->company->id,
            'name'       => 'Shift Pagi 07-15',
            'color'      => '#10b981',
            'is_active'  => true,
        ]);

        foreach (range(0, 6) as $dow) {
            ShiftSchedule::create([
                'shift_id'        => $this->morningShift->id,
                'day_of_week'     => $dow,
                'effective_date'  => '2026-01-01',
                'work_start_time' => '07:00:00',
                'work_end_time'   => '15:00:00',
                'is_off'          => false,
            ]);
        }

        // Buat pola rotasi 3 hari: H1 & H2 Masuk (Shift Pagi), H3 Libur (OFF)
        $this->pattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola 2-1 Ops',
            'cycle_days' => 3,
            'is_active'  => true,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $this->pattern->id,
            'day_order'        => 1,
            'shift_id'         => $this->morningShift->id,
            'work_start_time'  => '07:00:00',
            'work_end_time'    => '15:00:00',
            'is_off'           => false,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $this->pattern->id,
            'day_order'        => 2,
            'shift_id'         => $this->morningShift->id,
            'work_start_time'  => '07:00:00',
            'work_end_time'    => '15:00:00',
            'is_off'           => false,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $this->pattern->id,
            'day_order'        => 3,
            'shift_id'         => null,
            'is_off'           => true,
        ]);

        // Assign pola ini ke employee mulai 1 September 2026, anchor day 1
        UserShift::create([
            'company_id'       => $this->company->id,
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $this->pattern->id,
            'start_date'       => '2026-09-01',
            'anchor_day_order' => 1,
        ]);
    }

    public function test_my_schedule_calendar_reflects_rotation_pattern_for_mobile(): void
    {
        Sanctum::actingAs($this->employee);

        // Fetch kalender bulan September 2026 (endpoint mobile Flutter)
        $response = $this->getJson('/api/v1/attendance/my-schedule-calendar?month=9&year=2026');

        $response->assertOk();
        $days = $response->json('days');

        $this->assertNotEmpty($days);

        // 2026-09-01 -> H1 (Masuk)
        $this->assertEquals('shift', $days['2026-09-01']['source']);
        $this->assertFalse($days['2026-09-01']['is_off']);
        $this->assertEquals(1, $days['2026-09-01']['cycle_day']);
        $this->assertEquals('07:00', $days['2026-09-01']['work_start_time']);

        // 2026-09-02 -> H2 (Masuk)
        $this->assertFalse($days['2026-09-02']['is_off']);
        $this->assertEquals(2, $days['2026-09-02']['cycle_day']);

        // 2026-09-03 -> H3 (Libur Siklus)
        $this->assertTrue($days['2026-09-03']['is_off']);
        $this->assertNull($days['2026-09-03']['work_start_time']);
        $this->assertEquals(3, $days['2026-09-03']['cycle_day']);
        $this->assertStringContainsString('Libur Pola', $days['2026-09-03']['shift_name']);

        // 2026-09-04 -> Bergulir kembali ke H1 (Masuk)
        $this->assertFalse($days['2026-09-04']['is_off']);
        $this->assertEquals(1, $days['2026-09-04']['cycle_day']);
    }

    public function test_my_schedule_endpoint_returns_pattern_info_for_mobile(): void
    {
        Sanctum::actingAs($this->employee);

        // Endpoint GET /api/v1/employee/my-schedule
        $response = $this->getJson('/api/v1/employee/my-schedule');

        $response->assertOk();
        $this->assertEquals('shift', $response->json('source'));
        $this->assertEquals($this->pattern->id, $response->json('pattern_id'));
        $this->assertEquals($this->pattern->name, $response->json('pattern_name'));
        $this->assertCount(3, $response->json('schedules'));
    }

    public function test_leave_preview_skips_pattern_off_days(): void
    {
        Sanctum::actingAs($this->employee);

        // Employee mengambil cuti 3 hari dari 2026-09-01 s/d 2026-09-03
        // 01 Sep = H1 (Kerja)
        // 02 Sep = H2 (Kerja)
        // 03 Sep = H3 (Libur Pola) -> harus di-skip dari pemotongan kuota cuti!
        $response = $this->getJson('/api/v1/attendance/leave-preview?start_date=2026-09-01&end_date=2026-09-03&leave_type=cuti');

        $response->assertOk();
        // total_days seharusnya hanya 2 hari kerja, bukan 3 hari
        $this->assertEquals(2, $response->json('total_days'));
    }
}
