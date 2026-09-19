<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftNoticeAssignedEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PT Uji Shift Notice', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Pusat',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 150,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
            'shift_notice_days'      => 3,
        ]);

        $this->admin = User::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Admin HRD',
            'email'                 => 'hrd@ujishift.com',
            'password'              => bcrypt('password'),
            'role'                  => 'hrd',
            'is_active'             => true,
            'attendance_enabled'    => true,
        ]);

        $this->employee = User::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Karyawan 1',
            'email'                 => 'karyawan1@ujishift.com',
            'password'              => bcrypt('password'),
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_enabled'    => true,
        ]);
    }

    private function createShift(string $name, string $start = '08:00', string $end = '17:00'): Shift
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => $name,
            'color'                 => '#6366f1',
            'is_active'             => true,
        ]);

        for ($day = 0; $day < 7; $day++) {
            $isOff = in_array($day, [0, 6]);
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $day,
                'effective_date'  => $today,
                'work_start_time' => $isOff ? null : "{$start}:00",
                'work_end_time'   => $isOff ? null : "{$end}:00",
                'break_minutes'   => $isOff ? 0 : 60,
                'is_off'          => $isOff,
                'is_cross_day'    => false,
            ]);
        }

        return $shift;
    }

    public function test_index_includes_assigned_count_for_shifts(): void
    {
        $shiftUnassigned = $this->createShift('Shift Kosong');
        $shiftAssigned   = $this->createShift('Shift Terisi');

        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shiftAssigned->id,
            'start_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        $res = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/attendance/shifts');

        $res->assertOk();
        $data = collect($res->json('data'));

        $itemUnassigned = $data->firstWhere('id', $shiftUnassigned->id);
        $this->assertNotNull($itemUnassigned);
        $this->assertEquals(0, $itemUnassigned['assigned_count']);

        $itemAssigned = $data->firstWhere('id', $shiftAssigned->id);
        $this->assertNotNull($itemAssigned);
        $this->assertEquals(1, $itemAssigned['assigned_count']);
    }

    public function test_update_shift_without_assigned_employees_applies_immediately_today(): void
    {
        $shift = $this->createShift('Shift Bebas Atur');
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $schedulesPayload = [];
        for ($d = 0; $d < 7; $d++) {
            $isOff = in_array($d, [0, 6]);
            $schedulesPayload[] = [
                'day_of_week'     => $d,
                'is_off'          => $isOff,
                'work_start_time' => $isOff ? null : '09:00',
                'work_end_time'   => $isOff ? null : '18:00',
                'break_minutes'   => $isOff ? 0 : 60,
            ];
        }

        $res = $this->actingAs($this->admin)->putJson("/api/v1/dashboard/attendance/shifts/{$shift->id}", [
            'name'      => 'Shift Bebas Atur Updated',
            'schedules' => $schedulesPayload,
        ]);

        $res->assertOk();
        $this->assertEquals($today, $res->json('effective_date'));
        $this->assertEquals(0, $res->json('notified_users'));
        $this->assertEquals('Shift berhasil diperbarui dan langsung berlaku.', $res->json('message'));

        // Cek bahwa versi jadwal hari ini sudah ter-update ke 09:00:00
        $schedSenin = ShiftSchedule::where('shift_id', $shift->id)
            ->where('day_of_week', 1)
            ->where('effective_date', $today)
            ->first();

        $this->assertNotNull($schedSenin);
        $this->assertEquals('09:00', substr($schedSenin->work_start_time, 0, 5));
    }

    public function test_update_shift_with_assigned_employees_applies_notice_delay(): void
    {
        $shift = $this->createShift('Shift Karyawan Terpasang');
        $today = Carbon::now('Asia/Jakarta')->startOfDay();
        $expectedEffective = $today->copy()->addDays(3)->toDateString(); // notice_days = 3

        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shift->id,
            'start_date' => $today->toDateString(),
        ]);

        $schedulesPayload = [];
        for ($d = 0; $d < 7; $d++) {
            $isOff = in_array($d, [0, 6]);
            $schedulesPayload[] = [
                'day_of_week'     => $d,
                'is_off'          => $isOff,
                'work_start_time' => $isOff ? null : '07:30',
                'work_end_time'   => $isOff ? null : '16:30',
                'break_minutes'   => $isOff ? 0 : 60,
            ];
        }

        $res = $this->actingAs($this->admin)->putJson("/api/v1/dashboard/attendance/shifts/{$shift->id}", [
            'name'      => 'Shift Karyawan Terpasang Updated',
            'schedules' => $schedulesPayload,
        ]);

        $res->assertOk();
        $this->assertEquals($expectedEffective, $res->json('effective_date'));
        $this->assertEquals(1, $res->json('notified_users'));
        $this->assertStringContainsString('Jam kerja baru berlaku mulai', $res->json('message'));

        // Jadwal hari ini masih tetap versi lama (08:00:00 / 08:00)
        $schedHariIni = ShiftSchedule::where('shift_id', $shift->id)
            ->where('day_of_week', 1)
            ->where('effective_date', $today->toDateString())
            ->first();
        $this->assertEquals('08:00', substr($schedHariIni->work_start_time, 0, 5));

        // Jadwal versi masa depan sudah tersimpan dengan jam baru (07:30:00 / 07:30)
        $schedMasaDepan = ShiftSchedule::where('shift_id', $shift->id)
            ->where('day_of_week', 1)
            ->where('effective_date', $expectedEffective)
            ->first();
        $this->assertNotNull($schedMasaDepan);
        $this->assertEquals('07:30', substr($schedMasaDepan->work_start_time, 0, 5));
    }
}
