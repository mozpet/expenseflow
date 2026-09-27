<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\OvertimeApproval;
use App\Models\Position;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $branchA;
    private AttendanceSetting $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->company = Company::create(['name' => 'PT Multi Approval Solusi', 'is_active' => true]);

        $this->branchA = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Jakarta Pusat',
            'office_latitude'  => -6.1754,
            'office_longitude' => 106.8272,
            'radius_meters'    => 100,
        ]);

        $this->branchB = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Surabaya',
            'office_latitude'  => -7.2575,
            'office_longitude' => 112.7521,
            'radius_meters'    => 100,
        ]);
    }

    private function createUser(string $role, ?int $branchId = null, ?int $roleId = null, ?int $positionId = null): User
    {
        return User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => $role,
            'attendance_setting_id' => $branchId,
            'role_id'               => $roleId,
            'position_id'           => $positionId,
            'is_active'             => true,
        ]);
    }

    private function token(User $u): array
    {
        \Laravel\Sanctum\Sanctum::actingAs($u);

        return ['Authorization' => 'Bearer ' . $u->createToken('test')->plainTextToken];
    }

    // ─────────────────────────────────────────────────────────────
    // STRUK REIMBURSEMENT MULTI-APPROVAL TESTS
    // ─────────────────────────────────────────────────────────────

    public function test_receipt_tier_1_under_500k_requires_single_finance_approval(): void
    {
        $employee = $this->createUser('employee');
        $finance  = $this->createUser('finance');

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TEST-001',
            'sha256_hash'    => hash('sha256', 'tier1'),
            'image_path'     => 'receipts/test1.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 350000,
            'claimed_amount' => 350000,
            'status'         => 'draft',
            'ocr_status'     => 'completed',
            'category'       => 'Konsumsi',
        ]);

        // 1. Submit receipt via employee route
        $response = $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], $this->token($employee))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'submitted')
            ->assertJsonPath('receipt.approval_tier', 'Tier 1 (< Rp 500.000)')
            ->assertJsonPath('receipt.required_approvals', 1)
            ->assertJsonPath('receipt.current_approvals', 0);

        // 2. Finance approves
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Disetujui',
        ], $this->token($finance))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'approved')
            ->assertJsonPath('receipt.is_fully_approved', true)
            ->assertJsonPath('receipt.current_approvals', 1);

        $this->assertEquals('approved', $receipt->fresh()->status);
        $this->assertDatabaseHas('receipt_approvals', [
            'receipt_id'     => $receipt->id,
            'user_id'        => $finance->id,
            'approval_level' => 1,
            'status'         => 'approved',
        ]);
    }

    public function test_receipt_tier_2_between_500k_and_1m_requires_two_finance_approvals(): void
    {
        $employee = $this->createUser('employee');
        $finance1 = $this->createUser('finance');
        $finance2 = $this->createUser('finance');

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TEST-002',
            'sha256_hash'    => hash('sha256', 'tier2'),
            'image_path'     => 'receipts/test2.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 750000,
            'claimed_amount' => 750000,
            'status'         => 'draft',
            'ocr_status'     => 'completed',
            'category'       => 'Transport',
        ]);

        // Submit
        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], $this->token($employee))
            ->assertOk()
            ->assertJsonPath('receipt.approval_tier', 'Tier 2 (Rp 500.000 - Rp 1.000.000)')
            ->assertJsonPath('receipt.required_approvals', 2);

        // Approval 1: Finance 1 -> partially_approved
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Pemeriksaan tahap 1 selesai',
        ], $this->token($finance1))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'partially_approved')
            ->assertJsonPath('receipt.is_fully_approved', false)
            ->assertJsonPath('receipt.current_approvals', 1);

        $this->assertEquals('partially_approved', $receipt->fresh()->status);

        // Approval 2: Finance 2 -> fully approved
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Pemeriksaan tahap 2 final',
        ], $this->token($finance2))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'approved')
            ->assertJsonPath('receipt.is_fully_approved', true)
            ->assertJsonPath('receipt.current_approvals', 2);

        $this->assertEquals('approved', $receipt->fresh()->status);
        $this->assertEquals(2, $receipt->approvals()->count());
    }

    public function test_receipt_anti_double_approval_prevents_same_finance_user_approving_twice(): void
    {
        $employee = $this->createUser('employee');
        $finance  = $this->createUser('finance');

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TEST-003',
            'sha256_hash'    => hash('sha256', 'anti-double'),
            'image_path'     => 'receipts/test3.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 800000,
            'claimed_amount' => 800000,
            'status'         => 'draft',
            'ocr_status'     => 'completed',
            'category'       => 'Peralatan',
        ]);

        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], $this->token($employee))->assertOk();

        // Finance approves level 1
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Approve 1',
        ], $this->token($finance))->assertOk();

        // Finance tries to approve level 2 again on the same receipt
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Approve 2 lagi',
        ], $this->token($finance))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALREADY_APPROVED_BY_YOU');

        // Status remains partially_approved with current_approvals = 1
        $fresh = $receipt->fresh();
        $this->assertEquals('partially_approved', $fresh->status);
        $this->assertEquals(1, $fresh->current_approvals);
    }

    public function test_receipt_tier_3_over_1m_requires_step2_by_spv_or_manager(): void
    {
        $employee = $this->createUser('employee');
        $staff1   = $this->createUser('finance');
        $staff2   = $this->createUser('finance'); // Regular staff

        // Create Custom SPV Finance role
        $spvRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Kepala Keuangan',
            'slug'         => 'kepala_keuangan',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);
        RolePermission::create([
            'role_id'      => $spvRole->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'spv',
        ]);
        $spvFinance = $this->createUser('finance', null, $spvRole->id);

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TEST-004',
            'sha256_hash'    => hash('sha256', 'tier3'),
            'image_path'     => 'receipts/test4.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 1500000,
            'claimed_amount' => 1500000,
            'status'         => 'draft',
            'ocr_status'     => 'completed',
            'category'       => 'Hardware',
        ]);

        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], $this->token($employee))
            ->assertOk()
            ->assertJsonPath('receipt.approval_tier', 'Tier 3 (> Rp 1.000.000)')
            ->assertJsonPath('receipt.required_approvals', 2);

        // Step 1: Staff 1 approves
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Diperiksa oleh staff finance',
        ], $this->token($staff1))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'partially_approved');

        // Step 2: Staff 2 (regular finance, NOT SPV) attempts to approve -> Rejected 422
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Staff 2 mencoba approve step 2',
        ], $this->token($staff2))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SPV_FINANCE_REQUIRED');

        // Step 2: SPV Finance approves -> Success & approved
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Disetujui oleh SPV Finance',
        ], $this->token($spvFinance))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'approved')
            ->assertJsonPath('receipt.is_fully_approved', true);

        $this->assertEquals('approved', $receipt->fresh()->status);
    }

    public function test_receipt_tier_3_step2_authorized_by_supervisor_position_regardless_of_role_name(): void
    {
        $employee = $this->createUser('employee');
        $staff1   = $this->createUser('finance');

        // Role Staf Keuangan (sama sekali tidak mengandung kata spv/head/manager di nama atau slug)
        $financeRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staf Keuangan Lapangan',
            'slug'         => 'staf_keuangan_lapangan',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);
        RolePermission::create([
            'role_id'      => $financeRole->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'manage',
        ]);

        // Master Jabatan dengan is_supervisor = true
        $spvPosition = Position::create([
            'company_id'    => $this->company->id,
            'name'          => 'Supervisor Akuntansi',
            'is_supervisor' => true,
            'is_active'     => true,
        ]);

        // User memegang role Staf Keuangan TETAPI memiliki Jabatan Supervisor
        $spvByPosition = $this->createUser('finance', null, $financeRole->id, $spvPosition->id);

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TEST-POS-01',
            'sha256_hash'    => hash('sha256', 'pos-spv-ok'),
            'image_path'     => 'receipts/test-pos-ok.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 1500000,
            'claimed_amount' => 1500000,
            'status'         => 'draft',
            'ocr_status'     => 'completed',
            'category'       => 'Hardware',
        ]);

        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], $this->token($employee))->assertOk();

        // Step 1: Staff 1 approves
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Diperiksa oleh staff finance',
        ], $this->token($staff1))->assertOk();

        // Step 2: User dengan Jabatan is_supervisor = true dapat menyetujui tahap 2
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Disetujui oleh Supervisor via Master Jabatan',
        ], $this->token($spvByPosition))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'approved')
            ->assertJsonPath('receipt.is_fully_approved', true);

        $this->assertEquals('approved', $receipt->fresh()->status);
    }

    public function test_receipt_tier_3_step2_rejected_for_user_with_non_supervisor_position(): void
    {
        $employee = $this->createUser('employee');
        $staff1   = $this->createUser('finance');

        $financeRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staf Keuangan Lapangan',
            'slug'         => 'staf_keuangan_lapangan',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);
        RolePermission::create([
            'role_id'      => $financeRole->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'manage',
        ]);

        // Jabatan staf biasa (is_supervisor = false)
        $staffPosition = Position::create([
            'company_id'    => $this->company->id,
            'name'          => 'Staf Kasir',
            'is_supervisor' => false,
            'is_active'     => true,
        ]);

        $regularStaff = $this->createUser('finance', null, $financeRole->id, $staffPosition->id);

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TEST-POS-02',
            'sha256_hash'    => hash('sha256', 'pos-spv-fail'),
            'image_path'     => 'receipts/test-pos-fail.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 1500000,
            'claimed_amount' => 1500000,
            'status'         => 'draft',
            'ocr_status'     => 'completed',
            'category'       => 'Hardware',
        ]);

        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], $this->token($employee))->assertOk();

        // Step 1: Staff 1 approves
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Diperiksa oleh staff finance',
        ], $this->token($staff1))->assertOk();

        // Step 2: Regular staff tanpa jabatan supervisor DITOLAK 422
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Mencoba approve step 2',
        ], $this->token($regularStaff))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SPV_FINANCE_REQUIRED');
    }

    public function test_receipt_bulk_approve_enforces_branch_scope_anti_double_approval_and_staged_transition(): void
    {
        $employee = $this->createUser('employee');

        // Role khusus Cabang A
        $roleBranchA = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Finance Cabang Jakarta',
            'slug'         => 'finance_jkt',
            'branch_scope' => 'specific',
            'platform'     => 'both',
        ]);
        $roleBranchA->branches()->attach($this->branchA->id);
        RolePermission::create([
            'role_id'      => $roleBranchA->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'manage',
        ]);

        $financeA = $this->createUser('finance', $this->branchA->id, $roleBranchA->id);

        // Struk 1: Cabang A, Tier 1 (Rp 300.000, req 1)
        $rcp1 = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $employee->id,
            'attendance_setting_id' => $this->branchA->id,
            'receipt_number'        => 'RCP-BULK-01',
            'sha256_hash'           => hash('sha256', 'bulk-1'),
            'image_path'            => 'receipts/b1.jpg',
            'currency'              => 'IDR',
            'total_amount'          => 300000,
            'claimed_amount'        => 300000,
            'status'                => 'submitted',
            'ocr_status'            => 'completed',
            'approval_tier'         => 'Tier 1 (< Rp 500.000)',
            'required_approvals'    => 1,
            'current_approvals'     => 0,
        ]);

        // Struk 2: Cabang B, Tier 1 (Rp 250.000, req 1) - TIDAK BOLEH DIAKSES financeA
        $rcp2 = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $employee->id,
            'attendance_setting_id' => $this->branchB->id,
            'receipt_number'        => 'RCP-BULK-02',
            'sha256_hash'           => hash('sha256', 'bulk-2'),
            'image_path'            => 'receipts/b2.jpg',
            'currency'              => 'IDR',
            'total_amount'          => 250000,
            'claimed_amount'        => 250000,
            'status'                => 'submitted',
            'ocr_status'            => 'completed',
            'approval_tier'         => 'Tier 1 (< Rp 500.000)',
            'required_approvals'    => 1,
            'current_approvals'     => 0,
        ]);

        // Struk 3: Cabang A, Tier 2 (Rp 750.000, req 2) - HARUS jadi partially_approved
        $rcp3 = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $employee->id,
            'attendance_setting_id' => $this->branchA->id,
            'receipt_number'        => 'RCP-BULK-03',
            'sha256_hash'           => hash('sha256', 'bulk-3'),
            'image_path'            => 'receipts/b3.jpg',
            'currency'              => 'IDR',
            'total_amount'          => 750000,
            'claimed_amount'        => 750000,
            'status'                => 'submitted',
            'ocr_status'            => 'completed',
            'approval_tier'         => 'Tier 2 (Rp 500.000 - Rp 1.000.000)',
            'required_approvals'    => 2,
            'current_approvals'     => 0,
        ]);

        // Jalankan bulk approve
        $this->postJson('/api/v1/dashboard/receipts/bulk-approve', [
            'receipt_ids' => [$rcp1->id, $rcp2->id, $rcp3->id],
            'notes'       => 'Bulk approval batch',
        ], $this->token($financeA))->assertOk();

        // Struk 1 disetujui penuh (approved)
        $this->assertEquals('approved', $rcp1->fresh()->status);
        $this->assertEquals(1, $rcp1->fresh()->current_approvals);

        // Struk 2 dilewati karena beda cabang (masih submitted)
        $this->assertEquals('submitted', $rcp2->fresh()->status);
        $this->assertEquals(0, $rcp2->fresh()->current_approvals);

        // Struk 3 bertransisi ke partially_approved dengan current_approvals = 1 (bukan langsung approved!)
        $this->assertEquals('partially_approved', $rcp3->fresh()->status);
        $this->assertEquals(1, $rcp3->fresh()->current_approvals);

        // Coba bulk approve lagi oleh user yang sama (Anti double-approval)
        $this->postJson('/api/v1/dashboard/receipts/bulk-approve', [
            'receipt_ids' => [$rcp3->id],
        ], $this->token($financeA))->assertOk();

        // Struk 3 tetap partially_approved karena user yang sama dilewati
        $this->assertEquals('partially_approved', $rcp3->fresh()->status);
        $this->assertEquals(1, $rcp3->fresh()->current_approvals);
    }

    public function test_receipt_rejection_at_partially_approved_status(): void
    {
        $employee = $this->createUser('employee');
        $finance1 = $this->createUser('finance');
        $finance2 = $this->createUser('finance');

        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $employee->id,
            'receipt_number' => 'RCP-TEST-005',
            'sha256_hash'    => hash('sha256', 'reject-partial'),
            'image_path'     => 'receipts/test5.jpg',
            'currency'       => 'IDR',
            'total_amount'   => 700000,
            'claimed_amount' => 700000,
            'status'         => 'draft',
            'ocr_status'     => 'completed',
            'category'       => 'Operasional',
        ]);

        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], $this->token($employee))->assertOk();

        // Step 1: Finance 1 approves -> partially_approved
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Step 1 OK',
        ], $this->token($finance1))->assertOk();

        // Step 2: Finance 2 decides to reject
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/reject", [
            'notes' => 'Faktur pajak tidak valid, ditolak.',
        ], $this->token($finance2))
            ->assertOk()
            ->assertJsonPath('receipt.status', 'rejected');

        $this->assertEquals('rejected', $receipt->fresh()->status);
    }

    public function test_receipt_branch_scoping_prevents_finance_approving_other_branch_receipt(): void
    {
        // Finance scoped strictly to branch A
        $roleBranchA = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Finance Jakarta',
            'slug'         => 'fin_jkt',
            'branch_scope' => 'specific',
            'platform'     => 'both',
        ]);
        $roleBranchA->branches()->attach($this->branchA->id);

        $financeJakarta = $this->createUser('finance', $this->branchA->id, $roleBranchA->id);
        $empSurabaya    = $this->createUser('employee', $this->branchB->id);

        $receipt = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $empSurabaya->id,
            'attendance_setting_id' => $this->branchB->id,
            'receipt_number'        => 'RCP-TEST-SBY-001',
            'sha256_hash'           => hash('sha256', 'sby'),
            'image_path'            => 'receipts/sby.jpg',
            'currency'              => 'IDR',
            'total_amount'          => 200000,
            'claimed_amount'        => 200000,
            'status'                => 'submitted',
            'ocr_status'            => 'completed',
            'category'              => 'Logistik',
        ]);

        // Finance Jakarta cannot approve receipt from Surabaya
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve", [
            'notes' => 'Coba approve cabang lain',
        ], $this->token($financeJakarta))
            ->assertStatus(403);

        $this->assertEquals('submitted', $receipt->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────
    // OVERTIME (LEMBUR) 2-STEP APPROVAL TESTS (SPV -> HRD)
    // ─────────────────────────────────────────────────────────────

    public function test_overtime_2_step_approval_workflow(): void
    {
        $employee = $this->createUser('employee');

        // Create SPV user (e.g. role with 'spv' in name or slug)
        $spvRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Supervisor Operasional',
            'slug'         => 'spv_ops',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);
        RolePermission::create([
            'role_id'      => $spvRole->id,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'spv',
        ]);
        $spv = $this->createUser('employee', null, $spvRole->id);

        $hrd = $this->createUser('hrd');

        // Presensi dengan lembur 120 menit
        $attendance = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $employee->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(10),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 120,
        ]);

        // 1. Karyawan claim lembur
        $claimRes = $this->postJson("/api/v1/attendance/{$attendance->id}/claim-overtime", [
            'reason' => 'Menyelesaikan migrasi database client',
        ], $this->token($employee))
            ->assertOk()
            ->assertJsonPath('approval.status', 'pending')
            ->assertJsonPath('approval.current_step', 'spv');

        $approvalId = $claimRes->json('approval.id');

        // 2. SPV approves step 1
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/approve", [
            'notes' => 'Pekerjaan lembur terkonfirmasi oleh SPV',
        ], $this->token($spv))
            ->assertOk()
            ->assertJsonPath('approval.status', 'pending')
            ->assertJsonPath('approval.current_step', 'hrd')
            ->assertJsonPath('approval.spv_id', $spv->id);

        $approval = OvertimeApproval::find($approvalId);
        $this->assertEquals('pending', $approval->status);
        $this->assertEquals('hrd', $approval->current_step);
        $this->assertEquals($spv->id, $approval->spv_id);
        $this->assertNotNull($approval->spv_approved_at);

        // 3. Regular employee cannot approve step 2
        $otherEmp = $this->createUser('employee');
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/approve", [
            'notes' => 'Bukan HRD',
        ], $this->token($otherEmp))
            ->assertStatus(403);

        // 4. HRD approves step 2 (Final)
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/approve", [
            'notes' => 'Disetujui HRD untuk payroll',
        ], $this->token($hrd))
            ->assertOk()
            ->assertJsonPath('approval.status', 'approved')
            ->assertJsonPath('approval.reviewed_at', fn ($val) => !empty($val));

        $freshApproval = $approval->fresh();
        $this->assertEquals('approved', $freshApproval->status);
        $this->assertEquals($hrd->id, $freshApproval->reviewed_by);

        // Overtime minutes remains 120
        $this->assertEquals(120, $attendance->fresh()->overtime_minutes);
    }

    public function test_overtime_employee_cannot_approve_own_overtime(): void
    {
        // User who is SPV but also claiming overtime
        $spvRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Supervisor Finance',
            'slug'         => 'spv_fin',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);
        $spvUser = $this->createUser('employee', null, $spvRole->id);

        $attendance = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $spvUser->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(9),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 60,
        ]);

        $claimRes = $this->postJson("/api/v1/attendance/{$attendance->id}/claim-overtime", [
            'reason' => 'Lembur closing bulanan',
        ], $this->token($spvUser))->assertOk();

        $approvalId = $claimRes->json('approval.id');

        // SPV user tries to approve his own overtime
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/approve", [
            'notes' => 'Approve sendiri',
        ], $this->token($spvUser))
            ->assertStatus(403);
    }

    public function test_overtime_spv_rejection_zeroes_overtime_minutes(): void
    {
        $employee = $this->createUser('employee');
        $spv      = $this->createUser('admin'); // Admin acts as supervisor

        $attendance = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $employee->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(10),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 90,
        ]);

        $claimRes = $this->postJson("/api/v1/attendance/{$attendance->id}/claim-overtime", [
            'reason' => 'Bermain game setelah jam kantor',
        ], $this->token($employee))->assertOk();

        $approvalId = $claimRes->json('approval.id');

        // SPV rejects
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/reject", [
            'notes' => 'Aktivitas bukan urusan kantor, lembur ditolak.',
        ], $this->token($spv))
            ->assertOk()
            ->assertJsonPath('approval.status', 'rejected');

        $this->assertEquals('rejected', OvertimeApproval::find($approvalId)->status);
        // Overtime minutes in attendances is zeroed out
        $this->assertEquals(0, $attendance->fresh()->overtime_minutes);
    }

    public function test_overtime_hrd_rejection_at_step_2_zeroes_overtime_minutes(): void
    {
        $employee = $this->createUser('employee');
        $spv      = $this->createUser('admin');
        $hrd      = $this->createUser('hrd');

        $attendance = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $employee->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(11),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => 150,
        ]);

        $claimRes = $this->postJson("/api/v1/attendance/{$attendance->id}/claim-overtime", [
            'reason' => 'Perbaikan instalasi kabel',
        ], $this->token($employee))->assertOk();

        $approvalId = $claimRes->json('approval.id');

        // Step 1: SPV approves
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/approve", [
            'notes' => 'SPV approve',
        ], $this->token($spv))->assertOk();

        // Step 2: HRD rejects
        $this->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/reject", [
            'notes' => 'Tidak ada SPK resmi dari vendor, ditolak.',
        ], $this->token($hrd))
            ->assertOk()
            ->assertJsonPath('approval.status', 'rejected');

        $this->assertEquals('rejected', OvertimeApproval::find($approvalId)->status);
        $this->assertEquals(0, $attendance->fresh()->overtime_minutes);
    }

    public function test_overtime_list_filters_by_step_and_summary_counters(): void
    {
        $employee = $this->createUser('employee');
        $hrd      = $this->createUser('hrd');

        $att1 = Attendance::create([
            'company_id' => $this->company->id, 'user_id' => $employee->id,
            'date' => now()->toDateString(), 'overtime_minutes' => 60,
        ]);
        $att2 = Attendance::create([
            'company_id' => $this->company->id, 'user_id' => $employee->id,
            'date' => now()->subDay()->toDateString(), 'overtime_minutes' => 60,
        ]);

        // Overtime 1 at SPV step
        OvertimeApproval::create([
            'attendance_id'    => $att1->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        // Overtime 2 at HRD step
        OvertimeApproval::create([
            'attendance_id'    => $att2->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'hrd',
            'spv_id'           => $hrd->id,
            'spv_approved_at'  => now(),
        ]);

        // Query step=spv
        $resSpv = $this->getJson('/api/v1/dashboard/attendance/overtime-approvals?step=spv', $this->token($hrd))
            ->assertOk()
            ->assertJsonPath('summary.pending_spv', 1)
            ->assertJsonPath('summary.pending_hrd', 1);

        $this->assertCount(1, $resSpv->json('data'));
        $this->assertEquals('spv', $resSpv->json('data.0.current_step'));

        // Query step=hrd
        $resHrd = $this->getJson('/api/v1/dashboard/attendance/overtime-approvals?step=hrd', $this->token($hrd))
            ->assertOk();

        $this->assertCount(1, $resHrd->json('data'));
        $this->assertEquals('hrd', $resHrd->json('data.0.current_step'));
    }
}
