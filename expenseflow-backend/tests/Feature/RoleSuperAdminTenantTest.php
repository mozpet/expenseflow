<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSuperAdminTenantTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User $superAdmin;
    private User $adminA;
    private User $adminB;
    private AttendanceSetting $branchA;
    private AttendanceSetting $branchB;
    private Role $customRoleA;
    private Role $customRoleB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Buat Company A & B
        $this->companyA = Company::create(['name' => 'PT Maju Terus', 'is_active' => true]);
        $this->companyB = Company::create(['name' => 'PT Berkah Selalu', 'is_active' => true]);

        // Branch untuk masing-masing company
        $this->branchA = AttendanceSetting::create([
            'company_id'             => $this->companyA->id,
            'office_name'            => 'Cabang Jakarta (A)',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $this->branchB = AttendanceSetting::create([
            'company_id'             => $this->companyB->id,
            'office_name'            => 'Cabang Surabaya (B)',
            'office_latitude'        => -7.250445,
            'office_longitude'       => 112.768845,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $superRole = Role::whereNull('company_id')->where('slug', 'super_admin')->first();
        $adminRole = Role::whereNull('company_id')->where('slug', 'admin')->first();

        // Super Admin dengan company_id = NULL (Global Super Admin)
        $this->superAdmin = User::create([
            'company_id'            => null,
            'role_id'               => $superRole->id,
            'name'                  => 'Super Administrator',
            'email'                 => 'superadmin@expenseflow.test',
            'password'              => bcrypt('password'),
            'role'                  => 'super_admin',
            'is_active'             => true,
            'attendance_setting_id' => null,
        ]);

        // Admin Company A
        $this->adminA = User::create([
            'company_id'            => $this->companyA->id,
            'role_id'               => $adminRole->id,
            'name'                  => 'Admin Company A',
            'email'                 => 'admin@majuterus.test',
            'password'              => bcrypt('password'),
            'role'                  => 'admin',
            'is_active'             => true,
            'attendance_setting_id' => $this->branchA->id,
        ]);

        // Admin Company B
        $this->adminB = User::create([
            'company_id'            => $this->companyB->id,
            'role_id'               => $adminRole->id,
            'name'                  => 'Admin Company B',
            'email'                 => 'admin@berkahselalu.test',
            'password'              => bcrypt('password'),
            'role'                  => 'admin',
            'is_active'             => true,
            'attendance_setting_id' => $this->branchB->id,
        ]);

        // Custom Role untuk Company A
        $this->customRoleA = Role::create([
            'company_id'   => $this->companyA->id,
            'name'         => 'Supervisor Operasional A',
            'slug'         => 'spv_ops_a_' . uniqid(),
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);
        RolePermission::create([
            'role_id'      => $this->customRoleA->id,
            'module'       => Role::MODULE_ATTENDANCE,
            'access_level' => 'manage',
        ]);

        // Custom Role untuk Company B
        $this->customRoleB = Role::create([
            'company_id'   => $this->companyB->id,
            'name'         => 'Supervisor Operasional B',
            'slug'         => 'spv_ops_b_' . uniqid(),
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);
        RolePermission::create([
            'role_id'      => $this->customRoleB->id,
            'module'       => Role::MODULE_ATTENDANCE,
            'access_level' => 'manage',
        ]);
    }

    /**
     * Super Admin tanpa query filter (?company_id) melihat built-in roles + SEMUA custom roles across tenant.
     */
    public function test_super_admin_unfiltered_sees_all_roles_with_company_relation(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/roles');

        $response->assertOk();
        $roles = $response->json('data');

        // Total 5 built-in + 2 custom roles (A & B) = 7 roles
        $this->assertCount(7, $roles);

        $roleNames = collect($roles)->pluck('name')->toArray();
        $this->assertContains('Supervisor Operasional A', $roleNames);
        $this->assertContains('Supervisor Operasional B', $roleNames);

        // Periksa eager loading company:id,name
        $roleAData = collect($roles)->firstWhere('id', $this->customRoleA->id);
        $this->assertNotNull($roleAData['company']);
        $this->assertEquals($this->companyA->id, $roleAData['company']['id']);
        $this->assertEquals('PT Maju Terus', $roleAData['company']['name']);

        $roleBData = collect($roles)->firstWhere('id', $this->customRoleB->id);
        $this->assertNotNull($roleBData['company']);
        $this->assertEquals($this->companyB->id, $roleBData['company']['id']);
        $this->assertEquals('PT Berkah Selalu', $roleBData['company']['name']);
    }

    /**
     * Super Admin dengan parameter ?company_id=X hanya melihat built-in roles + custom roles milik Company X.
     */
    public function test_super_admin_filtered_by_company_sees_only_target_company_custom_roles(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/roles?company_id={$this->companyA->id}");

        $response->assertOk();
        $roles = $response->json('data');

        // 5 built-in + 1 custom role A = 6 roles
        $this->assertCount(6, $roles);

        $roleNames = collect($roles)->pluck('name')->toArray();
        $this->assertContains('Supervisor Operasional A', $roleNames);
        $this->assertNotContains('Supervisor Operasional B', $roleNames);
    }

    /**
     * Tenant Admin (Company A) hanya melihat built-in roles + custom roles Company A.
     * Tidak dapat melihat custom role Company B bahkan jika mencoba mengirim ?company_id=B.
     */
    public function test_tenant_admin_cannot_access_other_company_roles_via_query_param(): void
    {
        // 1. Tanpa query param
        $resNormal = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/v1/admin/roles');

        $resNormal->assertOk();
        $rolesNormal = $resNormal->json('data');
        $this->assertCount(6, $rolesNormal);
        $this->assertContains('Supervisor Operasional A', collect($rolesNormal)->pluck('name')->toArray());
        $this->assertNotContains('Supervisor Operasional B', collect($rolesNormal)->pluck('name')->toArray());

        // 2. Mencoba manipulasi via ?company_id={companyB->id}
        $resExploit = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/v1/admin/roles?company_id={$this->companyB->id}");

        $resExploit->assertOk();
        $rolesExploit = $resExploit->json('data');

        // Tetap hanya 6 roles (built-in + company A), company B tetap tidak terlihat
        $this->assertCount(6, $rolesExploit);
        $this->assertContains('Supervisor Operasional A', collect($rolesExploit)->pluck('name')->toArray());
        $this->assertNotContains('Supervisor Operasional B', collect($rolesExploit)->pluck('name')->toArray());
    }

    /**
     * Tenant Admin Company A dilarang mengakses detail, mengedit, atau menghapus role Company B (403 Forbidden).
     */
    public function test_tenant_admin_cannot_mutate_or_view_other_company_role(): void
    {
        // 1. Show role Company B oleh Admin A -> 403
        $showRes = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/v1/admin/roles/{$this->customRoleB->id}");
        $showRes->assertStatus(403);

        // 2. Update role Company B oleh Admin A -> 403
        $updateRes = $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->customRoleB->id}", [
                'name'         => 'Hacked Name',
                'platform'     => 'both',
                'branch_scope' => 'all',
            ]);
        $updateRes->assertStatus(403);

        // 3. Delete role Company B oleh Admin A -> 403
        $deleteRes = $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$this->customRoleB->id}");
        $deleteRes->assertStatus(403);
    }

    /**
     * Super Admin dapat membuat Custom Role untuk company tertentu dengan menyertakan company_id.
     */
    public function test_super_admin_can_create_custom_role_for_specific_company(): void
    {
        $payload = [
            'company_id'   => $this->companyB->id,
            'name'         => 'Manajer Logistik B',
            'description'  => 'Role logistik khusus Company B',
            'platform'     => 'both',
            'branch_scope' => 'specific',
            'branch_ids'   => [$this->branchB->id],
            'permissions'  => [
                Role::MODULE_RECEIPT => 'manage',
            ],
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/roles', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Manajer Logistik B')
            ->assertJsonPath('data.company_id', $this->companyB->id);

        $this->assertDatabaseHas('roles', [
            'name'       => 'Manajer Logistik B',
            'company_id' => $this->companyB->id,
        ]);
    }

    /**
     * Super Admin dapat melihat detail, memperbarui, dan menghapus custom role dari perusahaan manapun.
     */
    public function test_super_admin_can_view_update_and_delete_any_company_role(): void
    {
        // 1. Show custom role B oleh Super Admin
        $showRes = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/roles/{$this->customRoleB->id}");

        $showRes->assertOk()
            ->assertJsonPath('data.id', $this->customRoleB->id)
            ->assertJsonPath('data.company.id', $this->companyB->id);

        // 2. Update custom role B oleh Super Admin
        $updateRes = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->customRoleB->id}", [
                'name'         => 'Supervisor Operasional B Updated',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'permissions'  => [
                    Role::MODULE_ATTENDANCE => 'read',
                ],
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.name', 'Supervisor Operasional B Updated');

        $this->assertDatabaseHas('roles', [
            'id'   => $this->customRoleB->id,
            'name' => 'Supervisor Operasional B Updated',
        ]);

        // 3. Delete custom role B oleh Super Admin
        $deleteRes = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$this->customRoleB->id}");

        $deleteRes->assertOk()
            ->assertJsonFragment(['message' => "Custom Role 'Supervisor Operasional B Updated' berhasil dihapus."]);

        $this->assertDatabaseMissing('roles', [
            'id' => $this->customRoleB->id,
        ]);
    }
}
