<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Division;
use App\Models\OvertimeApproval;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DivisionPositionSupervisorOvertimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Sinergi Maju',
            'email'     => 'sinergi@example.com',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Head Office',
            'office_latitude'  => -6.2088,
            'office_longitude' => 106.8456,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
            'overtime_enabled' => true,
        ]);
    }

    private function token(User $user): array
    {
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
            'name'                  => "User {$counter}",
            'email'                 => "user{$counter}@example.com",
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

    public function test_division_and_position_crud(): void
    {
        $admin = $this->makeUser('admin');

        // 1. Create Division
        $resDiv = $this->postJson('/api/v1/admin/divisions', [
            'name' => 'Information Technology',
            'code' => 'IT',
            'description' => 'Divisi Teknologi Informasi',
        ], $this->token($admin));

        $resDiv->assertStatus(201);
        $divId = $resDiv->json('division.id');
        $this->assertDatabaseHas('divisions', ['name' => 'Information Technology', 'code' => 'IT']);

        // 2. Create Position with is_supervisor = true
        $resPos = $this->postJson('/api/v1/admin/positions', [
            'name' => 'IT Supervisor',
            'division_id' => $divId,
            'is_supervisor' => true,
        ], $this->token($admin));

        $resPos->assertStatus(201);
        $posSpvId = $resPos->json('position.id');
        $this->assertDatabaseHas('positions', ['name' => 'IT Supervisor', 'is_supervisor' => true]);

        // 3. Create Staff Position
        $resPosStaff = $this->postJson('/api/v1/admin/positions', [
            'name' => 'Software Engineer',
            'division_id' => $divId,
            'is_supervisor' => false,
        ], $this->token($admin));
        $resPosStaff->assertStatus(201);

        // 4. Test List Supervisors endpoint
        $spvUser = $this->makeUser('employee', null, $divId, $posSpvId);
        $resSup = $this->getJson("/api/v1/admin/users/supervisors?division_id={$divId}", $this->token($admin));
        $resSup->assertStatus(200);
        $this->assertTrue(collect($resSup->json('supervisors'))->contains('id', $spvUser->id));
    }

    public function test_overtime_level1_requires_direct_supervisor(): void
    {
        // Setup Divisi IT
        $divIT = Division::create(['company_id' => $this->company->id, 'name' => 'IT']);
        $divOps = Division::create(['company_id' => $this->company->id, 'name' => 'Operations']);

        // Posisi SPV & Staff
        $posSpvIT = Position::create(['company_id' => $this->company->id, 'division_id' => $divIT->id, 'name' => 'SPV IT', 'is_supervisor' => true]);
        $posSpvOps = Position::create(['company_id' => $this->company->id, 'division_id' => $divOps->id, 'name' => 'SPV Ops', 'is_supervisor' => true]);
        $posStaff = Position::create(['company_id' => $this->company->id, 'division_id' => $divIT->id, 'name' => 'Developer', 'is_supervisor' => false]);

        // User
        $spvIT = $this->makeUser('employee', null, $divIT->id, $posSpvIT->id);
        $spvOps = $this->makeUser('employee', null, $divOps->id, $posSpvOps->id);
        $staff = $this->makeUser('employee', null, $divIT->id, $posStaff->id, $spvIT->id); // manager_id = $spvIT->id
        $hrd = $this->makeUser('hrd');

        // Presensi dengan lembur
        $att = Attendance::create([
            'user_id'          => $staff->id,
            'company_id'       => $this->company->id,
            'date'             => '2026-09-23',
            'check_in_time'    => '2026-09-23 08:00:00',
            'check_out_time'   => '2026-09-23 19:00:00',
            'work_minutes'     => 540,
            'overtime_minutes' => 120,
            'status'           => 'present',
        ]);

        $approval = OvertimeApproval::create([
            'attendance_id'    => $att->id,
            'user_id'          => $staff->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 120,
            'status'           => 'pending',
            'current_step'     => 'spv',
            'overtime_reason'  => 'Deploy fitur divisi',
        ]);

        // 1. SPV dari divisi lain (spvOps) mencoba approve -> DITOLAK HTTP 403
        $this->actingAs($spvOps, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approval->id}/approve", [
                'notes' => 'Coba approve lembur divisi lain',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DIRECT_SUPERVISOR_REQUIRED');

        $this->assertEquals('pending', $approval->fresh()->status);
        $this->assertEquals('spv', $approval->fresh()->current_step);

        // 2. Atasan Langsung yang sah (spvIT) melakukan approve -> BERHASIL HTTP 200, maju ke step hrd
        $res = $this->actingAs($spvIT, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approval->id}/approve", [
                'notes' => 'Pekerjaan deploy selesai dengan baik',
            ]);
        $res->assertStatus(200);

        $freshApproval = $approval->fresh();
        $this->assertEquals('pending', $freshApproval->status);
        $this->assertEquals('hrd', $freshApproval->current_step);
        $this->assertEquals($spvIT->id, $freshApproval->spv_id);

        // 3. HRD melakukan approve Level 2 final -> BERHASIL HTTP 200, status approved
        $this->actingAs($hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approval->id}/approve", [
                'notes' => 'Valid, disetujui masuk rekap payroll',
            ])
            ->assertStatus(200);

        $this->assertEquals('approved', $approval->fresh()->status);
    }

    public function test_circular_hierarchy_and_backward_compatibility(): void
    {
        $admin = $this->makeUser('admin');
        $div = Division::create(['company_id' => $this->company->id, 'name' => 'Marketing']);
        $posSpv = Position::create(['company_id' => $this->company->id, 'division_id' => $div->id, 'name' => 'Marketing Head', 'is_supervisor' => true]);

        // 1. Backward compatibility: saving division_id automatically syncs department
        $manager = $this->makeUser('employee', null, $div->id, $posSpv->id);
        $this->assertEquals('Marketing', $manager->fresh()->department);

        $subordinate = $this->makeUser('employee', null, $div->id, null, $manager->id);
        $this->assertEquals($manager->id, $subordinate->manager_id);

        // 2. Anti-sirkular di dropdown supervisor: saat edit manager, bawahan ($subordinate) tidak muncul sebagai pilihan
        $resSup = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/users/supervisors?division_id={$div->id}&exclude_user_id={$manager->id}");
        $resSup->assertStatus(200);
        $candidateIds = collect($resSup->json('supervisors'))->pluck('id')->toArray();
        $this->assertNotContains($subordinate->id, $candidateIds, 'Bawahan tidak boleh muncul di pilihan atasan');

        // 3. Anti-sirkular di API update user: manager tidak boleh memilih subordinate sebagai atasannya
        $resUpdate = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$manager->id}", [
                'manager_id' => $subordinate->id,
            ]);
        $resUpdate->assertStatus(422);
        $resUpdate->assertJsonValidationErrors(['manager_id']);

        // 4. Karyawan tidak boleh memilih dirinya sendiri
        $resSelf = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$manager->id}", [
                'manager_id' => $manager->id,
            ]);
        $resSelf->assertStatus(422);
        $resSelf->assertJsonValidationErrors(['manager_id']);
    }

    public function test_mobile_spv_overtime_approval_flow(): void
    {
        $div = Division::create(['company_id' => $this->company->id, 'name' => 'Logistik']);
        $posSpv = Position::create([
            'company_id' => $this->company->id,
            'division_id' => $div->id,
            'name' => 'SPV Logistik',
            'is_supervisor' => true,
        ]);
        $posStaff = Position::create([
            'company_id' => $this->company->id,
            'division_id' => $div->id,
            'name' => 'Staf Gudang',
            'is_supervisor' => false,
        ]);

        $spv = $this->makeUser('employee', null, $div->id, $posSpv->id);
        $staff = $this->makeUser('employee', null, $div->id, $posStaff->id, $spv->id);
        $otherStaff = $this->makeUser('employee', null, $div->id, $posStaff->id);

        $att = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $staff->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->setTime(8, 0),
            'check_out_time'   => now()->setTime(19, 30),
            'status'           => 'present',
            'overtime_minutes' => 90,
        ]);

        $approval = OvertimeApproval::create([
            'company_id'       => $this->company->id,
            'attendance_id'    => $att->id,
            'user_id'          => $staff->id,
            'overtime_minutes' => 90,
            'overtime_reason'  => 'Bongkar muat barang masuk akhir shift',
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        // 1. Non-SPV employee tidak boleh akses endpoint SPV (403)
        $resNonSpv = $this->actingAs($otherStaff, 'sanctum')
            ->getJson('/api/v1/attendance/spv/overtime-approvals');
        $resNonSpv->assertStatus(403);

        // 2. SPV cek pending count
        $resCount = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/attendance/spv/overtime-approvals/count');
        $resCount->assertStatus(200);
        $resCount->assertJson(['success' => true, 'pending_count' => 1]);

        // 3. SPV lihat daftar approval bawahan
        $resList = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/attendance/spv/overtime-approvals');
        $resList->assertStatus(200);
        $resList->assertJson(['success' => true]);
        $this->assertCount(1, $resList->json('approvals'));
        $this->assertEquals($staff->name, $resList->json('approvals.0.user_name'));
        $this->assertEquals('Bongkar muat barang masuk akhir shift', $resList->json('approvals.0.reason'));

        // 4. SPV approve lembur bawahan
        $resApprove = $this->actingAs($spv, 'sanctum')
            ->postJson("/api/v1/attendance/spv/overtime-approvals/{$approval->id}/approve", [
                'notes' => 'Pekerjaan sudah dicek, disetujui',
            ]);
        $resApprove->assertStatus(200);

        // 5. Cek status lembur di database
        $approval->refresh();
        $this->assertEquals($spv->id, $approval->spv_id);
        $this->assertEquals('hrd', $approval->current_step);
        $this->assertNotNull($approval->spv_approved_at);
    }

    public function test_division_code_unique_and_deletion_protection(): void
    {
        $admin = $this->makeUser('admin');

        // 1. Buat divisi pertama dengan kode IT
        $res1 = $this->postJson('/api/v1/admin/divisions', [
            'name' => 'Divisi Teknologi',
            'code' => 'IT_TEST',
        ], $this->token($admin));
        $res1->assertStatus(201);
        $divId = $res1->json('division.id');

        // 2. Buat divisi kedua dengan kode yang sama -> Harus ditolak 422
        $res2 = $this->postJson('/api/v1/admin/divisions', [
            'name' => 'Divisi IT Kedua',
            'code' => 'IT_TEST',
        ], $this->token($admin));
        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['code']);

        // 3. Pasang posisi pada divisi tersebut
        $pos = \App\Models\Position::create([
            'company_id'  => $this->company->id,
            'division_id' => $divId,
            'name'        => 'Lead Dev',
        ]);

        // 4. Hapus divisi yang memiliki posisi -> Harus ditolak 422
        $resDel = $this->deleteJson("/api/v1/admin/divisions/{$divId}", [], $this->token($admin));
        $resDel->assertStatus(422);
        $resDel->assertJsonPath('positions_count', 1);

        // 5. Hapus posisi terlebih dahulu, lalu hapus divisi -> Sukses 200
        $pos->delete();
        $resDelSuccess = $this->deleteJson("/api/v1/admin/divisions/{$divId}", [], $this->token($admin));
        $resDelSuccess->assertStatus(200);
        $this->assertDatabaseMissing('divisions', ['id' => $divId]);
    }
}

