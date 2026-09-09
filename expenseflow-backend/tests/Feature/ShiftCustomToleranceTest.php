<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use App\Http\Controllers\API\ShiftController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftCustomToleranceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PT Toleransi Maju', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Pusat',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 150,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
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

    private function validSchedules(): array
    {
        $scheds = [];
        for ($d = 0; $d <= 6; $d++) {
            $isOff = ($d === 0); // Minggu libur
            $scheds[] = [
                'day_of_week'     => $d,
                'is_off'          => $isOff,
                'work_start_time' => $isOff ? null : '08:00',
                'work_end_time'   => $isOff ? null : '17:00',
                'break_minutes'   => $isOff ? 0 : 60,
            ];
        }
        return $scheds;
    }

    public function test_can_create_shift_with_custom_late_tolerance(): void
    {
        $payload = [
            'name'                   => 'Shift Khusus Toleransi 30 Menit',
            'attendance_setting_id'  => $this->office->id,
            'late_tolerance_minutes' => 30,
            'schedules'              => $this->validSchedules(),
        ];

        $res = $this->postJson('/api/v1/dashboard/attendance/shifts', $payload, $this->token($this->admin));
        $res->assertCreated();
        $res->assertJsonPath('data.late_tolerance_minutes', 30);

        $this->assertDatabaseHas('shifts', [
            'name'                   => 'Shift Khusus Toleransi 30 Menit',
            'late_tolerance_minutes' => 30,
        ]);
    }

    public function test_can_create_shift_with_null_tolerance_fallback_to_office(): void
    {
        $payload = [
            'name'                   => 'Shift Ikut Toleransi Kantor',
            'attendance_setting_id'  => $this->office->id,
            'late_tolerance_minutes' => null,
            'schedules'              => $this->validSchedules(),
        ];

        $res = $this->postJson('/api/v1/dashboard/attendance/shifts', $payload, $this->token($this->admin));
        $res->assertCreated();
        $res->assertJsonPath('data.late_tolerance_minutes', null);

        $this->assertDatabaseHas('shifts', [
            'name'                   => 'Shift Ikut Toleransi Kantor',
            'late_tolerance_minutes' => null,
        ]);
    }

    public function test_late_tolerance_validation_boundaries(): void
    {
        // Negatif
        $resNegative = $this->postJson('/api/v1/dashboard/attendance/shifts', [
            'name'                   => 'Shift Negatif',
            'attendance_setting_id'  => $this->office->id,
            'late_tolerance_minutes' => -5,
            'schedules'              => $this->validSchedules(),
        ], $this->token($this->admin));
        $resNegative->assertStatus(422);

        // Melebihi 300 menit (5 jam)
        $resMax = $this->postJson('/api/v1/dashboard/attendance/shifts', [
            'name'                   => 'Shift Terlalu Besar',
            'attendance_setting_id'  => $this->office->id,
            'late_tolerance_minutes' => 301,
            'schedules'              => $this->validSchedules(),
        ], $this->token($this->admin));
        $resMax->assertStatus(422);
    }

    public function test_can_update_shift_late_tolerance_on_and_off(): void
    {
        $shift = Shift::create([
            'company_id'             => $this->company->id,
            'attendance_setting_id'  => $this->office->id,
            'name'                   => 'Shift Fleksibel',
            'late_tolerance_minutes' => null,
        ]);

        // Nyalakan checkbox (set ke 45 menit)
        $res1 = $this->putJson("/api/v1/dashboard/attendance/shifts/{$shift->id}", [
            'late_tolerance_minutes' => 45,
        ], $this->token($this->admin));
        $res1->assertOk();
        $res1->assertJsonPath('data.late_tolerance_minutes', 45);
        $this->assertEquals(45, $shift->fresh()->late_tolerance_minutes);

        // Matikan checkbox (revert ke null)
        $res2 = $this->putJson("/api/v1/dashboard/attendance/shifts/{$shift->id}", [
            'late_tolerance_minutes' => null,
        ], $this->token($this->admin));
        $res2->assertOk();
        $res2->assertJsonPath('data.late_tolerance_minutes', null);
        $this->assertNull($shift->fresh()->late_tolerance_minutes);
    }

    public function test_resolve_schedule_uses_custom_tolerance_when_present(): void
    {
        $shift = Shift::create([
            'company_id'             => $this->company->id,
            'attendance_setting_id'  => $this->office->id,
            'name'                   => 'Shift Toleransi 30',
            'late_tolerance_minutes' => 30,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $d,
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'is_off'          => false,
            ]);
        }

        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-09-01',
        ]);

        $sched = ShiftController::resolveSchedule($this->employee, '2026-09-07');
        $this->assertEquals(30, $sched['late_tolerance_minutes']);

        // Jika shift toleransi null -> fallback ke office (15)
        $shift->update(['late_tolerance_minutes' => null]);
        $schedFallback = ShiftController::resolveSchedule($this->employee, '2026-09-07');
        $this->assertEquals(15, $schedFallback['late_tolerance_minutes']);
    }

    public function test_checkin_status_present_when_within_custom_shift_tolerance(): void
    {
        // Office toleransi: 15 menit (08:00 + 15 = 08:15).
        // Buat Shift dengan toleransi longgar 30 menit (08:00 + 30 = 08:30).
        $shiftLongTolerance = Shift::create([
            'company_id'             => $this->company->id,
            'attendance_setting_id'  => $this->office->id,
            'name'                   => 'Shift Long Tolerance',
            'late_tolerance_minutes' => 30,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shiftLongTolerance->id,
                'day_of_week'     => $d,
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'is_off'          => false,
            ]);
        }

        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shiftLongTolerance->id,
            'start_date' => '2026-09-01',
        ]);

        // Karyawan check-in pukul 08:20 WIB (telat 20 menit dari jadwal 08:00).
        // Pada aturan kantor default (15 menit), ini pasti 'late'.
        // Namun karena shift ini toleransinya 30 menit, status harus 'present'!
        Carbon::setTestNow(Carbon::parse('2026-09-07 08:20:00', 'Asia/Jakarta'));
        $res = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.200000,
            'longitude' => 106.816667,
        ], $this->token($this->employee));

        $res->assertCreated();
        $res->assertJsonPath('attendance.status', 'present');
    }

    public function test_checkin_status_late_when_exceeding_strict_custom_shift_tolerance(): void
    {
        // Office toleransi: 15 menit.
        // Buat shift dengan toleransi ketat 5 menit (08:00 + 5 = 08:05).
        $shiftStrict = Shift::create([
            'company_id'             => $this->company->id,
            'attendance_setting_id'  => $this->office->id,
            'name'                   => 'Shift Ketat',
            'late_tolerance_minutes' => 5,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shiftStrict->id,
                'day_of_week'     => $d,
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'is_off'          => false,
            ]);
        }

        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shiftStrict->id,
            'start_date' => '2026-09-01',
        ]);

        // Karyawan check-in pukul 08:10 WIB (telat 10 menit).
        // Di kantor default (15 menit) ini 'present', tapi karena shift toleransi 5 menit -> 'late'!
        Carbon::setTestNow(Carbon::parse('2026-09-07 08:10:00', 'Asia/Jakarta'));
        $resStrict = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.200000,
            'longitude' => 106.816667,
        ], $this->token($this->employee));

        $resStrict->assertCreated();
        $resStrict->assertJsonPath('attendance.status', 'late');
    }
}

