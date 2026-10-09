<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalfDayAttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $hrd;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Nusantara Cuti Bersama',
            'email'     => 'admin@nusantara.com',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'                   => $this->company->id,
            'office_name'                  => 'Kantor Pusat Jakarta',
            'office_latitude'              => -6.2088,
            'office_longitude'             => 106.8456,
            'radius_meters'                => 100,
            'work_start_time'              => '08:00:00',
            'work_end_time'                => '17:00:00',
            'late_tolerance_minutes'       => 15,
            'late_checkin_cutoff_minutes'  => 120, // cutoff 2 jam (10:00 jika masuk 08:00)
            'early_leave_tolerance_minutes' => 15,
            'default_leave_quota'          => 12,
        ]);

        $this->hrd = User::create([
            'company_id'            => $this->company->id,
            'name'                  => 'HRD Admin',
            'email'                 => 'hrd@nusantara.com',
            'password'              => bcrypt('secret123'),
            'role'                  => 'hrd',
            'gender'                => 'Laki-laki',
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
        ]);

        $this->employee = User::create([
            'company_id'            => $this->company->id,
            'name'                  => 'Budi Santoso',
            'email'                 => 'budi@nusantara.com',
            'password'              => bcrypt('secret123'),
            'role'                  => 'employee',
            'gender'                => 'Laki-laki',
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
            'allow_wfh'             => true,
            'wfh_enabled'           => true,
        ]);
    }

    public function test_half_day_leave_remains_in_not_checked_in_list_today(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        LeaveRequest::create([
            'company_id'       => $this->company->id,
            'user_id'          => $this->employee->id,
            'leave_type'       => 'cuti_setengah_hari',
            'half_day_session' => 'morning',
            'start_date'       => $today,
            'end_date'         => $today,
            'total_days'       => 1,
            'status'           => 'approved',
            'reason'           => 'Keperluan keluarga pagi hari',
        ]);

        $response = $this->actingAs($this->hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/today');

        $response->assertOk();
        $notCheckedIn = collect($response->json('not_checked_in'));
        $onLeave = collect($response->json('on_leave'));

        // Harus masuk ke not_checked_in
        $empItem = $notCheckedIn->firstWhere('user_id', $this->employee->id);
        $this->assertNotNull($empItem);
        $this->assertTrue($empItem['is_half_day']);
        $this->assertEquals('morning', $empItem['half_day_session']);
        $this->assertEquals('Cuti ½ Hari (Sesi 1)', $empItem['half_day_label']);
        // Jam masuk harus tergeser ke midpoint 12:30
        $this->assertEquals('12:30', $empItem['work_start_time']);

        // Tidak boleh ada di on_leave
        $leaveItem = $onLeave->firstWhere('user_id', $this->employee->id);
        $this->assertNull($leaveItem);
    }

    public function test_half_day_morning_session_can_check_in_at_midday_without_cutoff_error(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        LeaveRequest::create([
            'company_id'       => $this->company->id,
            'user_id'          => $this->employee->id,
            'leave_type'       => 'cuti_setengah_hari',
            'half_day_session' => 'morning',
            'start_date'       => $today,
            'end_date'         => $today,
            'total_days'       => 1,
            'status'           => 'approved',
            'reason'           => 'Cuti pagi',
        ]);

        // Simulasikan waktu jam 12:35 WIB (setelah midpoint 12:30 tapi sebelum cutoff 14:30)
        Carbon::setTestNow(Carbon::parse("{$today} 12:35:00", 'Asia/Jakarta'));

        $response = $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'       => -6.2088,
                'longitude'      => 106.8456,
                'check_in_type'  => 'wfh',
            ]);

        $response->assertCreated();
        $this->assertEquals('present', $response->json('attendance.status'));

        Carbon::setTestNow();
    }

    public function test_half_day_afternoon_session_leaves_at_midday_not_marked_early_leave(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        LeaveRequest::create([
            'company_id'       => $this->company->id,
            'user_id'          => $this->employee->id,
            'leave_type'       => 'cuti_setengah_hari',
            'half_day_session' => 'afternoon',
            'start_date'       => $today,
            'end_date'         => $today,
            'total_days'       => 1,
            'status'           => 'approved',
            'reason'           => 'Cuti paruh kedua / siang',
        ]);

        // Check-in pagi jam 08:00 WIB
        Carbon::setTestNow(Carbon::parse("{$today} 08:00:00", 'Asia/Jakarta'));
        $checkInRes = $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'       => -6.2088,
                'longitude'      => 106.8456,
                'check_in_type'  => 'wfh',
            ]);
        $checkInRes->assertCreated();

        // Check-out siang jam 12:30 WIB (sesuai midpoint)
        Carbon::setTestNow(Carbon::parse("{$today} 12:30:00", 'Asia/Jakarta'));
        $checkOutRes = $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/attendance/check-out', [
                'latitude'  => -6.2088,
                'longitude' => 106.8456,
            ]);
        $checkOutRes->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $today)->first();
        $this->assertNotNull($att->check_out_time);
        // Status TIDAK boleh 'early_leave'
        $this->assertNotEquals('early_leave', $att->status);
        $this->assertEquals('present', $att->status);

        Carbon::setTestNow();
    }

    public function test_half_day_afternoon_session_attempting_check_in_at_midday_is_rejected_due_to_morning_cutoff(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        LeaveRequest::create([
            'company_id'       => $this->company->id,
            'user_id'          => $this->employee->id,
            'leave_type'       => 'cuti_setengah_hari',
            'half_day_session' => 'afternoon', // Cuti siang -> Wajib kerja pagi (08:00 - 12:30)
            'start_date'       => $today,
            'end_date'         => $today,
            'total_days'       => 1,
            'status'           => 'approved',
            'reason'           => 'Cuti paruh kedua / siang',
        ]);

        // Karyawan baru mencoba check-in jam 12:35 WIB (padahal jam kerjanya pagi dan sudah lewat cutoff 10:00)
        Carbon::setTestNow(Carbon::parse("{$today} 12:35:00", 'Asia/Jakarta'));

        $response = $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'       => -6.2088,
                'longitude'      => 106.8456,
                'check_in_type'  => 'wfh',
            ]);

        // Ditolak 403 karena jadwal kerjanya adalah pagi (batas 10:00 WIB sudah lewat)
        $response->assertStatus(403);
        $this->assertStringContainsString('Presensi sudah ditutup', $response->json('message'));

        Carbon::setTestNow();
    }
}
