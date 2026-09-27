<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Division;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveMultiApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Maju Makmur Bersama',
            'email'     => 'admin@majumakmur.com',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Pusat',
            'office_latitude'  => -6.2088,
            'office_longitude' => 106.8456,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
            'overtime_enabled' => true,
        ]);
    }

    private function makeUser(string $role, ?int $roleId = null, ?int $divisionId = null, ?int $positionId = null, ?int $managerId = null): User
    {
        static $counter = 1;
        $counter++;

        return User::create([
            'company_id'            => $this->company->id,
            'name'                  => "Karyawan {$counter}",
            'email'                 => "user{$counter}@majumakmur.com",
            'password'              => bcrypt('secret123'),
            'role'                  => $role,
            'role_id'               => $roleId,
            'division_id'           => $divisionId,
            'position_id'           => $positionId,
            'manager_id'            => $managerId,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
        ]);
    }

    public function test_leave_request_initializes_with_spv_step(): void
    {
        $spv = $this->makeUser('employee');
        $employee = $this->makeUser('employee', managerId: $spv->id);

        $startDate = Carbon::now('Asia/Jakarta')->addDays(3)->toDateString();
        $endDate   = Carbon::now('Asia/Jakarta')->addDays(4)->toDateString();

        $res = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'izin',
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'reason'     => 'Urusan keluarga mendadak',
        ]);

        $res->assertStatus(201);
        $this->assertEquals('spv', $res->json('leave.current_step'));
        $this->assertEquals('pending', $res->json('leave.status'));

        $this->assertDatabaseHas('leave_requests', [
            'id'           => $res->json('leave.id'),
            'user_id'      => $employee->id,
            'current_step' => 'spv',
            'status'       => 'pending',
        ]);
    }

    public function test_direct_manager_can_approve_step_1_spv_and_advances_to_hrd(): void
    {
        $spv = $this->makeUser('employee');
        $employee = $this->makeUser('employee', managerId: $spv->id);
        $hrd = $this->makeUser('hrd');

        $year = Carbon::now('Asia/Jakarta')->year;
        $balance = LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 0,
        ]);

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'cuti',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(6)->toDateString(),
            'total_days'   => 2,
            'reason'       => 'Cuti tahunan',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        // Step 1: Direct Manager Approves
        $res = $this->actingAs($spv, 'sanctum')->postJson("/api/v1/attendance/spv/leave-approvals/{$leave->id}/approve", [
            'notes' => 'Disetujui atasan, pekerjaan sudah didelegasikan',
        ]);

        $res->assertStatus(200);
        $res->assertJsonFragment(['current_step' => 'hrd']);

        $leave->refresh();
        $this->assertEquals('pending', $leave->status);
        $this->assertEquals('hrd', $leave->current_step);
        $this->assertEquals($spv->id, $leave->spv_id);
        $this->assertNotNull($leave->spv_approved_at);
        $this->assertEquals('Disetujui atasan, pekerjaan sudah didelegasikan', $leave->spv_notes);

        // Kuota belum boleh berkurang di tahap SPV
        $balance->refresh();
        $this->assertEquals(0, $balance->used);

        // Step 2: HRD Approves Final
        $resHrd = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", [
            'notes' => 'Disetujui HRD, saldo dipotong 2 hari',
        ]);

        $resHrd->assertStatus(200);

        $leave->refresh();
        $this->assertEquals('approved', $leave->status);
        $this->assertEquals($hrd->id, $leave->approved_by);
        $this->assertNotNull($leave->approved_at);

        // Kuota terpotong atomik di tahap HRD
        $balance->refresh();
        $this->assertEquals(2, $balance->used);
    }

    public function test_foreign_supervisor_cannot_approve_step_1(): void
    {
        $spv1 = $this->makeUser('employee');
        $spv2 = $this->makeUser('employee');
        $employee = $this->makeUser('employee', managerId: $spv1->id);

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        // SPV2 (bukan direct manager) coba approve
        $res = $this->actingAs($spv2, 'sanctum')->postJson("/api/v1/attendance/spv/leave-approvals/{$leave->id}/approve", []);
        $res->assertStatus(403);
    }

    public function test_employee_cannot_approve_own_leave(): void
    {
        $employee = $this->makeUser('employee');

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        $res = $this->actingAs($employee, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", []);
        $res->assertStatus(403);
    }

    public function test_non_hrd_cannot_approve_step_2(): void
    {
        $spv = $this->makeUser('employee');
        $employee = $this->makeUser('employee', managerId: $spv->id);

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin',
            'status'       => 'pending',
            'current_step' => 'hrd', // sudah di-approve SPV
            'spv_id'       => $spv->id,
        ]);

        // SPV mencoba approve tahap HRD
        $res = $this->actingAs($spv, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", []);
        $res->assertStatus(403);
        $res->assertJsonFragment(['code' => 'HRD_REQUIRED']);
    }

    public function test_spv_can_reject_at_step_1(): void
    {
        $spv = $this->makeUser('employee');
        $employee = $this->makeUser('employee', managerId: $spv->id);

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin urusan',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        $res = $this->actingAs($spv, 'sanctum')->postJson("/api/v1/attendance/spv/leave-approvals/{$leave->id}/reject", [
            'notes' => 'Tidak diizinkan karena sedang ada deadline proyek penting',
        ]);

        $res->assertStatus(200);

        $leave->refresh();
        $this->assertEquals('rejected', $leave->status);
        $this->assertEquals('Tidak diizinkan karena sedang ada deadline proyek penting', $leave->rejection_reason);
    }

    public function test_hrd_can_reject_at_step_2(): void
    {
        $hrd = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin',
            'status'       => 'pending',
            'current_step' => 'hrd',
        ]);

        $res = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/reject", [
            'rejection_reason' => 'Dokumen pendukung tidak lengkap',
        ]);

        $res->assertStatus(200);

        $leave->refresh();
        $this->assertEquals('rejected', $leave->status);
        $this->assertEquals('Dokumen pendukung tidak lengkap', $leave->rejection_reason);
    }

    public function test_spv_list_and_count_endpoints(): void
    {
        $div = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS']);
        $spvPos = Position::create(['company_id' => $this->company->id, 'division_id' => $div->id, 'name' => 'SPV Ops', 'is_supervisor' => true]);

        $spv = $this->makeUser('employee', divisionId: $div->id, positionId: $spvPos->id);
        $employee1 = $this->makeUser('employee', divisionId: $div->id, managerId: $spv->id);
        $employee2 = $this->makeUser('employee', divisionId: $div->id, managerId: $spv->id);

        // Leave 1: step 'spv' (should be counted)
        LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee1->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin 1',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        // Leave 2: step 'hrd' (already approved by SPV, so pending_count for SPV should be 1)
        LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee2->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(6)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(6)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin 2',
            'status'       => 'pending',
            'current_step' => 'hrd',
            'spv_id'       => $spv->id,
        ]);

        // Count endpoint
        $resCount = $this->actingAs($spv, 'sanctum')->getJson('/api/v1/attendance/spv/leave-approvals/count');
        $resCount->assertStatus(200);
        $this->assertEquals(1, $resCount->json('pending_count'));

        // List endpoint
        $resList = $this->actingAs($spv, 'sanctum')->getJson('/api/v1/attendance/spv/leave-approvals');
        $resList->assertStatus(200);
        $this->assertCount(2, $resList->json('data'));
    }
}
