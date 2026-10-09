<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NotifyCollectiveLeaveH7Command → AttendanceController::dispatchH7CollectiveLeaveNotifications().
 * Bug lama: method ini belum ada sama sekali, command crash tiap dijalankan scheduler.
 */
class CollectiveLeaveH7NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Cuti Bersama', 'is_active' => true]);
        $this->office   = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Pusat',
            'office_latitude'        => -6.2,
            'office_longitude'       => 106.8166,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'work_days'              => [1, 2, 3, 4, 5],
            'late_tolerance_minutes' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): User
    {
        return User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
        ]);
    }

    /** Senin kerja terdekat agar tidak kena hari libur akhir pekan. */
    private function workDate(int $daysFromNow): string
    {
        $d = now('Asia/Jakarta')->copy()->addDays($daysFromNow);
        while ($d->isWeekend()) {
            $d->addDay();
        }

        return $d->toDateString();
    }

    public function test_command_tidak_crash_dan_mengirim_notifikasi_pending(): void
    {
        $emp = $this->employee();
        Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => $this->workDate(3), // H-3, dalam window H-7
            'name'          => 'Cuti Bersama Nyepi',
            'is_national'   => false,
            'is_collective' => true,
        ]);

        $this->artisan('attendance:notify-collective-leaves')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $emp->id,
            'type'    => 'collective_leave_h7_reminder',
        ]);
    }

    public function test_tidak_mengirim_jika_sudah_memilih(): void
    {
        $emp = $this->employee();
        $holiday = Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => $this->workDate(2),
            'name'          => 'Cuti Bersama Idul Fitri',
            'is_national'   => false,
            'is_collective' => true,
        ]);
        LeaveRequest::create([
            'user_id'           => $emp->id,
            'company_id'        => $this->company->id,
            'leave_type'        => 'cuti',
            'start_date'        => $holiday->date,
            'end_date'          => $holiday->date,
            'total_days'        => 1,
            'reason'            => 'Cuti bersama',
            'status'            => 'approved',
            'current_step'      => 'done',
            'holiday_id'        => $holiday->id,
            'collective_status' => 'accepted',
        ]);

        app(\App\Http\Controllers\API\AttendanceController::class)->dispatchH7CollectiveLeaveNotifications();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $emp->id,
            'type'    => 'collective_leave_h7_reminder',
        ]);
    }

    public function test_tidak_mengirim_jika_di_luar_window_h7(): void
    {
        $emp = $this->employee();
        Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => $this->workDate(20), // jauh di luar window
            'name'          => 'Cuti Bersama Jauh',
            'is_national'   => false,
            'is_collective' => true,
        ]);

        app(\App\Http\Controllers\API\AttendanceController::class)->dispatchH7CollectiveLeaveNotifications();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $emp->id,
            'type'    => 'collective_leave_h7_reminder',
        ]);
    }

    public function test_dedup_tidak_mengirim_dua_kali_pada_hari_yang_sama(): void
    {
        $emp = $this->employee();
        Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => $this->workDate(1),
            'name'          => 'Cuti Bersama Dedup',
            'is_national'   => false,
            'is_collective' => true,
        ]);

        $ctrl = app(\App\Http\Controllers\API\AttendanceController::class);
        $first  = $ctrl->dispatchH7CollectiveLeaveNotifications();
        $second = $ctrl->dispatchH7CollectiveLeaveNotifications();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $emp->id)
            ->where('type', 'collective_leave_h7_reminder')
            ->count());
    }

    public function test_karyawan_yang_dikecualikan_tidak_menerima_notifikasi(): void
    {
        $emp = $this->employee();
        $excluded = $this->employee();
        $holiday = Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => $this->workDate(4),
            'name'          => 'Cuti Bersama Exclusion',
            'is_national'   => false,
            'is_collective' => true,
        ]);
        $holiday->excludedUsers()->attach($excluded->id);

        app(\App\Http\Controllers\API\AttendanceController::class)->dispatchH7CollectiveLeaveNotifications();

        $this->assertDatabaseHas('notifications', ['user_id' => $emp->id, 'type' => 'collective_leave_h7_reminder']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $excluded->id, 'type' => 'collective_leave_h7_reminder']);
    }
}
