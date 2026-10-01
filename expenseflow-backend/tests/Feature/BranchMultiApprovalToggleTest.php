<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeApproval;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchMultiApprovalToggleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Multi Cabang Indonesia',
            'email'     => 'admin@multicabang.com',
            'is_active' => true,
        ]);
    }

    private function makeOffice(string $name, bool $overtimeMulti = true, bool $leaveMulti = true): AttendanceSetting
    {
        return AttendanceSetting::create([
            'company_id'                      => $this->company->id,
            'office_name'                     => $name,
            'office_latitude'                 => -6.2088,
            'office_longitude'                => 106.8456,
            'work_start_time'                 => '08:00:00',
            'work_end_time'                   => '17:00:00',
            'overtime_enabled'                => true,
            'overtime_multi_approval_enabled' => $overtimeMulti,
            'leave_multi_approval_enabled'    => $leaveMulti,
        ]);
    }

    private function makeUser(string $role, AttendanceSetting $office, ?int $managerId = null): User
    {
        static $counter = 1;
        $counter++;

        return User::create([
            'company_id'            => $this->company->id,
            'name'                  => "Karyawan {$counter}",
            'email'                 => "user{$counter}@multicabang.com",
            'password'              => bcrypt('secret123'),
            'role'                  => $role,
            'manager_id'            => $managerId,
            'attendance_setting_id' => $office->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
        ]);
    }

    public function test_branch_with_leave_multi_approval_enabled_starts_at_spv_step(): void
    {
        $office = $this->makeOffice('Kantor Pusat (Hierarki Penuh)', leaveMulti: true);
        $spv = $this->makeUser('employee', $office);
        $employee = $this->makeUser('employee', $office, managerId: $spv->id);

        $startDate = Carbon::now('Asia/Jakarta')->next(Carbon::MONDAY)->toDateString();
        $endDate   = Carbon::parse($startDate)->addDay()->toDateString();

        $res = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'izin',
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'reason'     => 'Urusan keluarga di kantor pusat',
        ]);

        $res->assertStatus(201);
        $this->assertEquals('spv', $res->json('leave.current_step'));
        $this->assertEquals('pending', $res->json('leave.status'));

        $this->assertDatabaseHas('leave_requests', [
            'id'           => $res->json('leave.id'),
            'current_step' => 'spv',
            'status'       => 'pending',
        ]);
    }

    public function test_branch_with_leave_multi_approval_disabled_starts_at_hrd_step_and_allows_instant_hrd_approval(): void
    {
        $officeSmall = $this->makeOffice('Cabang Outlet Kecil', leaveMulti: false);
        $employee = $this->makeUser('employee', $officeSmall);
        $hrd = $this->makeUser('hrd', $officeSmall);

        // Berikan saldo cuti
        LeaveBalance::create([
            'user_id'    => $employee->id,
            'company_id' => $this->company->id,
            'year'       => Carbon::now()->year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 0,
        ]);

        $startDate = Carbon::now('Asia/Jakarta')->next(Carbon::MONDAY)->toDateString();
        $endDate   = Carbon::parse($startDate)->addDay()->toDateString();

        $res = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'reason'     => 'Cuti liburan dari cabang kecil',
        ]);

        $res->assertStatus(201);
        $leaveId = $res->json('leave.id');

        // Harus langsung berada di tahap HRD (skip SPV)
        $this->assertEquals('hrd', $res->json('leave.current_step'));
        $this->assertEquals('pending', $res->json('leave.status'));

        // HRD langsung menyetujui di Tahap 2 secara instan
        $approveRes = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$leaveId}/approve", [
            'notes' => 'Disetujui langsung oleh HRD pusat karena cabang tidak memiliki SPV',
        ]);

        $approveRes->assertStatus(200);
        $this->assertEquals('approved', $approveRes->json('leave.status'));
        $this->assertEquals($hrd->id, $approveRes->json('leave.approved_by'));

        // Pastikan kuota cuti langsung terpotong
        $balance = LeaveBalance::where('user_id', $employee->id)->where('leave_type', 'cuti')->first();
        $this->assertEquals(2, $balance->used);
    }

    public function test_branch_with_overtime_multi_approval_enabled_starts_at_spv_step(): void
    {
        $office = $this->makeOffice('Kantor Pusat Lembur', overtimeMulti: true);
        $spv = $this->makeUser('employee', $office);
        $employee = $this->makeUser('employee', $office, managerId: $spv->id);

        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $attendance = Attendance::create([
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'date'             => $today,
            'check_in_time'    => '08:00:00',
            'check_out_time'   => '19:00:00',
            'status'           => 'present',
            'overtime_minutes' => 120,
        ]);

        $res = $this->actingAs($employee, 'sanctum')->postJson("/api/v1/attendance/{$attendance->id}/claim-overtime", [
            'reason' => 'Menyelesaikan laporan audit kuartal',
        ]);

        $res->assertStatus(200);
        $this->assertEquals('spv', $res->json('approval.current_step'));
        $this->assertEquals('pending', $res->json('approval.status'));
    }

    public function test_branch_with_overtime_multi_approval_disabled_starts_at_hrd_step_and_allows_instant_hrd_approval(): void
    {
        $officeSmall = $this->makeOffice('Outlet Ritel Tanpa SPV', overtimeMulti: false);
        $employee = $this->makeUser('employee', $officeSmall);
        $hrd = $this->makeUser('hrd', $officeSmall);

        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $attendance = Attendance::create([
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'date'             => $today,
            'check_in_time'    => '08:00:00',
            'check_out_time'   => '20:00:00',
            'status'           => 'present',
            'overtime_minutes' => 180,
        ]);

        $res = $this->actingAs($employee, 'sanctum')->postJson("/api/v1/attendance/{$attendance->id}/claim-overtime", [
            'reason' => 'Stock opname akhir bulan',
        ]);

        $res->assertStatus(200);
        $approvalId = $res->json('approval.id');

        // Harus langsung berada di tahap HRD
        $this->assertEquals('hrd', $res->json('approval.current_step'));
        $this->assertEquals('pending', $res->json('approval.status'));

        // HRD langsung approve lembur
        $approveRes = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/approve", [
            'notes' => 'Disetujui langsung oleh HRD',
        ]);

        $approveRes->assertStatus(200);
        $this->assertEquals('approved', $approveRes->json('approval.status'));
    }

    public function test_update_office_settings_persists_multi_approval_toggles(): void
    {
        $office = $this->makeOffice('Cabang Dinamis', overtimeMulti: true, leaveMulti: true);
        $admin = $this->makeUser('admin', $office);

        $res = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/dashboard/attendance/settings/{$office->id}", [
            'office_name'                     => 'Cabang Dinamis Updated',
            'overtime_multi_approval_enabled' => false,
            'leave_multi_approval_enabled'    => false,
        ]);

        $res->assertStatus(200);

        $office->refresh();
        $this->assertFalse($office->overtime_multi_approval_enabled);
        $this->assertFalse($office->leave_multi_approval_enabled);
    }
}
