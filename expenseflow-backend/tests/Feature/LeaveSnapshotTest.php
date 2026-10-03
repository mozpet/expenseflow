<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveSnapshotTest extends TestCase
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
            'work_start_time'              => '08:00:00',
            'work_end_time'                => '17:00:00',
            'default_leave_quota'          => 12,
            'leave_multi_approval_enabled' => false, // direct HRD approval for faster tests
            'leave_reset_date'             => Carbon::today('Asia/Jakarta')->format('m-d'),
        ]);

        $this->hrd = User::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'hrd',
            'name'                  => 'HRD Manager',
            'email'                 => 'hrd@nusantara.com',
            'password'              => bcrypt('secret123'),
            'gender'                => 'Laki-laki',
            'attendance_enabled'    => true,
            'is_active'             => true,
        ]);

        $this->employee = User::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'name'                  => 'Budi Santoso',
            'email'                 => 'budi@nusantara.com',
            'password'              => bcrypt('secret123'),
            'gender'                => 'Laki-laki',
            'attendance_enabled'    => true,
            'is_active'             => true,
            'allow_leave'           => true,
        ]);
    }

    public function test_leave_request_snapshots_employee_branch(): void
    {
        $year = Carbon::now('Asia/Jakarta')->year;
        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 0,
        ]);

        $futureDate = Carbon::now('Asia/Jakarta')->next(Carbon::TUESDAY)->toDateString();

        $res = $this->actingAs($this->employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'start_date' => $futureDate,
            'end_date'   => $futureDate,
            'reason'     => 'Keperluan keluarga',
        ]);

        $res->assertStatus(201);

        $leave = LeaveRequest::where('user_id', $this->employee->id)->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertEquals($this->office->id, $leave->attendance_setting_id);
    }

    public function test_leave_approval_snapshots_balance_before_after_and_policy(): void
    {
        $year = Carbon::now('Asia/Jakarta')->year;
        $futureDate = Carbon::now('Asia/Jakarta')->addDays(10)->toDateString();

        // Siapkan saldo cuti: kuota 12, terpakai 2 (sisa 10)
        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 2,
        ]);

        // Buat pengajuan 3 hari
        $startDate = Carbon::parse($futureDate);
        $endDate = $startDate->copy()->addDays(2); // 3 hari jika hari kerja

        $leave = LeaveRequest::create([
            'user_id'               => $this->employee->id,
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'leave_type'            => 'cuti',
            'start_date'            => $startDate->toDateString(),
            'end_date'              => $endDate->toDateString(),
            'total_days'            => 3,
            'reason'                => 'Liburan tahunan',
            'status'                => 'pending',
            'current_step'          => 'hrd',
        ]);

        // HRD menyetujui
        $approveRes = $this->actingAs($this->hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", [
            'notes' => 'Disetujui silakan berlibur',
        ]);

        $approveRes->assertStatus(200);

        $leave->refresh();
        $this->assertEquals('approved', $leave->status);
        $this->assertEquals(10, $leave->balance_before); // 12 - 2 = 10
        $this->assertEquals(7, $leave->balance_after);   // 10 - 3 = 7

        $this->assertNotNull($leave->leave_policy_snapshot);
        $this->assertEquals('cuti', $leave->leave_policy_snapshot['leave_type']);
        $this->assertEquals('Cuti Tahunan', $leave->leave_policy_snapshot['leave_type_label']);
        $this->assertEquals(12, $leave->leave_policy_snapshot['quota']);
        $this->assertEquals(5, $leave->leave_policy_snapshot['used_after']);
        $this->assertEquals(7, $leave->leave_policy_snapshot['remaining_after']);
        $this->assertEquals($this->office->office_name, $leave->leave_policy_snapshot['office_name']);
        $this->assertEquals('HRD Manager', $leave->leave_policy_snapshot['approved_by_name']);
    }

    public function test_hrd_cannot_reduce_quota_below_used_days(): void
    {
        $year = Carbon::now('Asia/Jakarta')->year;

        // Karyawan punya kuota 12, sudah terpakai 5
        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 5,
        ]);

        // HRD coba menurunkan kuota jadi 4 (di bawah 5 yang sudah terpakai)
        $res = $this->actingAs($this->hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'    => $this->employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 4,
            'reason'     => 'Koreksi sembarangan',
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment([
            'message' => "Kuota Cuti Tahunan untuk Budi Santoso tidak dapat diatur menjadi 4 hari karena sudah terpakai 5 hari pada tahun {$year}.",
        ]);

        // Pastikan kuota di database TIDAK berubah
        $bal = LeaveBalance::where('user_id', $this->employee->id)->where('year', $year)->first();
        $this->assertEquals(12, $bal->quota);
    }

    public function test_hrd_can_disable_leave_even_if_user_already_has_used_leaves(): void
    {
        $year = Carbon::now('Asia/Jakarta')->year;

        // Karyawan punya kuota 12, sudah terpakai 5
        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 5,
        ]);

        // HRD menonaktifkan hak cuti karyawan (allow_leave = false)
        $res = $this->actingAs($this->hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'     => $this->employee->id,
            'year'        => $year,
            'leave_type'  => 'cuti',
            'quota'       => 0, // atau kuota lama
            'allow_leave' => false,
        ]);

        $res->assertStatus(200);

        // Pastikan allow_leave karyawan menjadi false
        $this->employee->refresh();
        $this->assertFalse($this->employee->allow_leave);

        // Pastikan kuota dipertahankan (tidak minus)
        $bal = LeaveBalance::where('user_id', $this->employee->id)->where('year', $year)->first();
        $this->assertEquals(12, $bal->quota);
        $this->assertEquals(5, $bal->used);
        $this->assertEquals(7, $bal->remaining);
    }
}
