<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveSameDayCheckInRuleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $setting;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'PT Uji Cuti Hari H',
            'code' => 'PTUCH',
        ]);

        $this->setting = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Pusat',
            'office_latitude'  => -6.200000,
            'office_longitude' => 106.816666,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
            'work_days'        => [1, 2, 3, 4, 5, 6, 7], // semua hari kerja agar tidak diskip
            'is_active'        => true,
        ]);

        $this->employee = User::create([
            'name'                  => 'Budi Santoso',
            'email'                 => 'budi@example.com',
            'password'              => bcrypt('secret'),
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->setting->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'allow_leave'           => true,
        ]);

        // Beri saldo cuti tahunan
        LeaveBalance::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'year'       => now('Asia/Jakarta')->year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 0,
        ]);
    }


    public function test_user_can_apply_leave_on_same_day_before_checkin(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        // Belum ada check-in hari ini -> harus berhasil 201
        $response = $this->actingAs($this->employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'start_date' => $today,
            'end_date'   => $today,
            'reason'     => 'Keperluan mendadak pagi ini sebelum kerja',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leave_requests', [
            'user_id'    => $this->employee->id,
            'leave_type' => 'cuti',
            'start_date' => $today,
            'status'     => 'pending',
        ]);
    }

    public function test_user_cannot_apply_leave_on_same_day_after_checkin(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        // Simulasikan user sudah check-in hari ini
        Attendance::create([
            'user_id'       => $this->employee->id,
            'company_id'    => $this->company->id,
            'date'          => $today,
            'check_in_time' => now('Asia/Jakarta')->subHours(2),
            'status'        => 'present',
        ]);

        // Coba ajukan cuti hari ini -> harus ditolak 422
        $response = $this->actingAs($this->employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'start_date' => $today,
            'end_date'   => $today,
            'reason'     => 'Mau pulang izin',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('sudah melakukan presensi masuk (check-in) hari ini', $response->json('message'));
    }

    public function test_user_cannot_apply_leave_for_past_dates(): void
    {
        $yesterday = now('Asia/Jakarta')->subDay()->toDateString();

        $response = $this->actingAs($this->employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'start_date' => $yesterday,
            'end_date'   => $yesterday,
            'reason'     => 'Kemarin lupa izin',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('tidak dapat dilakukan untuk tanggal yang sudah lewat', $response->json('message'));
    }
}
