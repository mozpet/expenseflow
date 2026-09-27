<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Division;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeApproval;
use App\Models\Position;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleVsPositionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Batas Tegas Nusantara',
            'email'     => 'admin@batastegas.com',
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

    private function token(User $user): array
    {
        \Laravel\Sanctum\Sanctum::actingAs($user);

        return [
            'Authorization' => 'Bearer ' . $user->createToken('test', ['*'])->plainTextToken,
            'Accept'        => 'application/json',
        ];
    }

    private function makeUser(string $role, ?int $roleId = null, ?int $divisionId = null, ?int $positionId = null, ?int $managerId = null): User
    {
        static $counter = 1;
        $counter++;

        return User::create([
            'company_id'            => $this->company->id,
            'name'                  => "Karyawan {$counter}",
            'email'                 => "user{$counter}@batastegas.com",
            'password'              => bcrypt('secret123'),
            'role'                  => $role,
            'role_id'               => $roleId,
            'division_id'           => $divisionId,
            'position_id'           => $positionId,
            'manager_id'            => $managerId,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'overtime_enabled'      => true,
            'is_active'             => true,
        ]);
    }

    /**
     * Skenario A:
     * User memiliki custom Role bernama "Supervisor Gudang" (slug: "spv_gudang"),
     * TETAPI posisinya bukan supervisor (is_supervisor = false), tidak punya bawahan,
     * dan tidak memiliki permission role "spv".
     *
     * EXPECTATION: DITOLAK 403 SPV_REQUIRED saat mencoba approve lembur & cuti Step 1.
     * Tidak ada lagi celah 'str_contains' yang meloloskannya hanya karena namanya mengandung 'spv'.
     */
    public function test_custom_role_with_spv_name_cannot_approve_without_actual_supervisor_privilege(): void
    {
        $fakeSpvRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Supervisor Operasional Gudang',
            'slug'         => 'spv_ops_gudang',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);

        $nonSpvPosition = Position::create([
            'company_id'    => $this->company->id,
            'name'          => 'Staf Gudang Pelaksana',
            'is_supervisor' => false,
            'is_active'     => true,
        ]);

        $fakeSpvUser = $this->makeUser('employee', $fakeSpvRole->id, null, $nonSpvPosition->id);
        $targetEmployee = $this->makeUser('employee');

        // Pastikan model isSupervisor() mengembalikan FALSE
        $this->assertFalse($fakeSpvUser->isSupervisor());

        // 1. Coba approve lembur Step 1
        $att = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $targetEmployee->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(10),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 90,
        ]);

        $overtime = OvertimeApproval::create([
            'company_id'       => $this->company->id,
            'attendance_id'    => $att->id,
            'user_id'          => $targetEmployee->id,
            'overtime_minutes' => 90,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$overtime->id}/approve", [
            'notes' => 'Mencoba approve lembur dengan nama role spv palsu',
        ], $this->token($fakeSpvUser))
            ->assertStatus(403)
            ->assertJsonPath('code', 'SPV_REQUIRED');

        // 2. Coba approve cuti Step 1
        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $targetEmployee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(3)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(4)->toDateString(),
            'total_days'   => 2,
            'reason'       => 'Keperluan pribadi',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        $this->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", [
            'notes' => 'Mencoba approve cuti dengan nama role spv palsu',
        ], $this->token($fakeSpvUser))
            ->assertStatus(403)
            ->assertJsonPath('code', 'SPV_REQUIRED');
    }

    /**
     * Skenario B:
     * User memiliki Role 'employee' biasa (tanpa embel-embel spv pada role),
     * TETAPI memiliki Jabatan dengan is_supervisor = true.
     *
     * EXPECTATION: BERHASIL menyetujui lembur & cuti Step 1 untuk bawahannya.
     */
    public function test_plain_employee_role_with_supervisor_position_can_approve_subordinate(): void
    {
        $div = Division::create(['company_id' => $this->company->id, 'name' => 'Produksi']);

        $spvPos = Position::create([
            'company_id'    => $this->company->id,
            'division_id'   => $div->id,
            'name'          => 'Koordinator Shift',
            'is_supervisor' => true,
            'is_active'     => true,
        ]);

        $staffPos = Position::create([
            'company_id'    => $this->company->id,
            'division_id'   => $div->id,
            'name'          => 'Operator Mesin',
            'is_supervisor' => false,
            'is_active'     => true,
        ]);

        $supervisor = $this->makeUser('employee', null, $div->id, $spvPos->id);
        $subordinate = $this->makeUser('employee', null, $div->id, $staffPos->id, $supervisor->id);

        $this->assertTrue($supervisor->isSupervisor());

        // 1. Approve Lembur Step 1 -> Berhasil
        $att = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $subordinate->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(10),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 60,
        ]);

        $overtime = OvertimeApproval::create([
            'company_id'       => $this->company->id,
            'attendance_id'    => $att->id,
            'user_id'          => $subordinate->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$overtime->id}/approve", [
            'notes' => 'Lembur diverifikasi oleh atasan langsung posisi supervisor',
        ], $this->token($supervisor))
            ->assertOk()
            ->assertJsonPath('approval.current_step', 'hrd')
            ->assertJsonPath('approval.spv_id', $supervisor->id);

        // 2. Approve Cuti Step 1 -> Berhasil
        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $subordinate->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(3)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(4)->toDateString(),
            'total_days'   => 2,
            'reason'       => 'Urusan keluarga',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        $this->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", [
            'notes' => 'Izin disetujui atasan langsung posisi supervisor',
        ], $this->token($supervisor))
            ->assertOk()
            ->assertJsonPath('leave.current_step', 'hrd')
            ->assertJsonPath('leave.spv_id', $supervisor->id);
    }

    /**
     * Skenario C:
     * User memegang Role 'employee' dan posisi biasa (is_supervisor = false),
     * TETAPI secara hierarki organisasi ditunjuk sebagai manager_id (Atasan Langsung) dari subordinate.
     *
     * EXPECTATION: BERHASIL approve Step 1 subordinate karena merupakan direct manager sah.
     */
    public function test_direct_manager_can_approve_step1_regardless_of_position_flag(): void
    {
        $directManager = $this->makeUser('employee');
        $subordinate   = $this->makeUser('employee', managerId: $directManager->id);

        $this->assertTrue($directManager->subordinates()->exists());
        $this->assertTrue($directManager->isSupervisor());

        $att = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $subordinate->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(9),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 45,
        ]);

        $overtime = OvertimeApproval::create([
            'company_id'       => $this->company->id,
            'attendance_id'    => $att->id,
            'user_id'          => $subordinate->id,
            'overtime_minutes' => 45,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$overtime->id}/approve", [
            'notes' => 'Disetujui oleh atasan langsung hierarki',
        ], $this->token($directManager))
            ->assertOk()
            ->assertJsonPath('approval.current_step', 'hrd')
            ->assertJsonPath('approval.spv_id', $directManager->id);
    }

    /**
     * Skenario D:
     * Staf Finance memiliki Role dengan permission "receipt: manage",
     * TETAPI memiliki Jabatan dengan is_supervisor = false dan bukan atasan siapapun.
     *
     * EXPECTATION: DITOLAK 422 SPV_FINANCE_REQUIRED saat mencoba approve Receipt Tier 3 Step 2.
     * Privilege creep dicegah: wewenang 'manage' tidak otomatis menganugerahkan status kepemimpinan struktural.
     */
    public function test_staff_with_manage_permission_cannot_approve_receipt_tier_3_step2_without_supervisor_position(): void
    {
        $employee = $this->makeUser('employee');
        $financeStaff1 = $this->makeUser('finance');

        $financeRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staf Keuangan Pusat',
            'slug'         => 'staf_finance_pusat',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);
        RolePermission::create([
            'role_id'      => $financeRole->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'manage',
        ]);

        $staffPos = Position::create([
            'company_id'    => $this->company->id,
            'name'          => 'Staf Verifikator Kuitansi',
            'is_supervisor' => false,
            'is_active'     => true,
        ]);

        $regularFinance = $this->makeUser('finance', $financeRole->id, null, $staffPos->id);

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TIER3-ROLE-VS-POS',
            'sha256_hash'    => hash('sha256', 'tier3-test'),
            'image_path'     => 'receipts/test.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 2500000,
            'claimed_amount' => 2500000,
            'status'             => 'submitted',
            'ocr_status'         => 'completed',
            'category'           => 'Operasional',
            'approval_tier'      => 'Tier 3 (> Rp 1.000.000)',
            'required_approvals' => 2,
            'current_approvals'  => 0,
        ]);

        // Step 1: Disetujui financeStaff1
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Step 1 verifikasi staf',
        ], $this->token($financeStaff1))->assertOk();

        // Step 2: regularFinance mencoba approve -> Ditolak 422
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Mencoba bypass step 2 tanpa jabatan SPV',
        ], $this->token($regularFinance))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SPV_FINANCE_REQUIRED');
    }

    /**
     * Skenario E:
     * Supervisor Divisi A (is_supervisor = true) mencoba menyetujui lembur karyawan Divisi B
     * yang sudah memiliki Atasan Langsung sendiri.
     *
     * EXPECTATION: DITOLAK 403 DIRECT_SUPERVISOR_REQUIRED.
     * Jabatan supervisor tidak boleh melompati batas divisi/hierarki karyawan lain.
     */
    public function test_supervisor_from_other_division_cannot_approve_employee_with_assigned_manager(): void
    {
        $divA = Division::create(['company_id' => $this->company->id, 'name' => 'Divisi HR']);
        $divB = Division::create(['company_id' => $this->company->id, 'name' => 'Divisi Operasional']);

        $posSpvA = Position::create(['company_id' => $this->company->id, 'division_id' => $divA->id, 'name' => 'SPV HR', 'is_supervisor' => true]);
        $posSpvB = Position::create(['company_id' => $this->company->id, 'division_id' => $divB->id, 'name' => 'SPV Operasional', 'is_supervisor' => true]);

        $spvA = $this->makeUser('employee', null, $divA->id, $posSpvA->id);
        $spvB = $this->makeUser('employee', null, $divB->id, $posSpvB->id);
        $staffB = $this->makeUser('employee', null, $divB->id, null, $spvB->id);

        $att = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $staffB->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(9),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 60,
        ]);

        $overtime = OvertimeApproval::create([
            'company_id'       => $this->company->id,
            'attendance_id'    => $att->id,
            'user_id'          => $staffB->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        // SPV A mencoba approve staf B -> Ditolak 403
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$overtime->id}/approve", [
            'notes' => 'Mencoba approve bawahan divisi lain',
        ], $this->token($spvA))
            ->assertStatus(403)
            ->assertJsonPath('code', 'DIRECT_SUPERVISOR_REQUIRED');
    }
}
