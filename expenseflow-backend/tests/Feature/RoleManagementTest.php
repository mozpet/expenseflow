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

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $superAdmin;
    private User $admin;
    private AttendanceSetting $branchJakarta;
    private AttendanceSetting $branchSurabaya;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::create([
            'name'      => 'PT Maju Bersama',
            'email'     => 'info@majubersama.co.id',
            'phone'     => '021-12345678',
            'address'   => 'Jl. Sudirman No. 1',
            'is_active' => true,
        ]);

        $this->branchJakarta = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Jakarta',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $this->branchSurabaya = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Surabaya',
            'office_latitude'        => -7.257500,
            'office_longitude'       => 112.752100,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $superRole = Role::whereNull('company_id')->where('slug', 'super_admin')->first();
        $adminRole = Role::whereNull('company_id')->where('slug', 'admin')->first();

        $this->superAdmin = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $superRole->id,
            'name'                  => 'Super Admin',
            'email'                 => 'super@majubersama.co.id',
            'password'              => bcrypt('password'),
            'role'                  => 'super_admin',
            'is_active'             => true,
            'attendance_setting_id' => $this->branchJakarta->id,
        ]);

        $this->admin = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $adminRole->id,
            'name'                  => 'Administrator',
            'email'                 => 'admin@majubersama.co.id',
            'password'              => bcrypt('password'),
            'role'                  => 'admin',
            'is_active'             => true,
            'attendance_setting_id' => $this->branchJakarta->id,
        ]);
    }

    public function test_can_list_builtin_roles_and_modules(): void
    {
        // 1. Endpoint modules
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/roles/modules');

        $response->assertOk()
            ->assertJsonStructure(['modules', 'access_levels', 'platforms', 'branch_scopes']);

        // 2. Endpoint roles index
        $resRoles = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/roles');

        $resRoles->assertOk();
        $data = $resRoles->json('data');
        $this->assertCount(5, $data); // 5 built-in roles
    }

    public function test_can_create_custom_role_with_platform_and_branch_scope(): void
    {
        $payload = [
            'name'         => 'Supervisor Cabang Jakarta',
            'description'  => 'Hanya mengelola presensi dan lembur di Jakarta',
            'platform'     => 'both',
            'branch_scope' => 'specific',
            'branch_ids'   => [$this->branchJakarta->id],
            'permissions'  => [
                Role::MODULE_ATTENDANCE => 'manage',
                Role::MODULE_OVERTIME   => 'manage',
                Role::MODULE_RECEIPT    => 'none',
            ],
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Supervisor Cabang Jakarta')
            ->assertJsonPath('data.platform', 'both')
            ->assertJsonPath('data.branch_scope', 'specific');

        $roleId = $response->json('data.id');
        $this->assertDatabaseHas('roles', [
            'id'           => $roleId,
            'company_id'   => $this->company->id,
            'is_builtin'   => false,
            'branch_scope' => 'specific',
        ]);

        $this->assertDatabaseHas('role_branches', [
            'role_id'               => $roleId,
            'attendance_setting_id' => $this->branchJakarta->id,
        ]);

        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_ATTENDANCE,
            'access_level' => 'manage',
        ]);
    }

    public function test_cannot_delete_or_modify_builtin_role(): void
    {
        $builtInRole = Role::where('slug', 'finance')->first();

        // Update should be rejected
        $updateRes = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$builtInRole->id}", [
                'name'         => 'Hacked Finance',
                'platform'     => 'both',
                'branch_scope' => 'all',
            ]);

        $updateRes->assertStatus(422);

        // Delete should be rejected
        $deleteRes = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$builtInRole->id}");

        $deleteRes->assertStatus(422);
    }

    public function test_cannot_delete_custom_role_if_still_assigned_to_user(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staff Gudang',
            'slug'         => 'staff_gudang',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        $user = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => 'Joko Susanto',
            'email'                 => 'joko@majubersama.co.id',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branchJakarta->id,
        ]);

        $deleteRes = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$role->id}");

        $deleteRes->assertStatus(422)
            ->assertJsonFragment(['message' => "Role 'Staff Gudang' tidak dapat dihapus karena masih digunakan oleh 1 karyawan. Alihkan karyawan ke role lain terlebih dahulu."]);

        // Reassign user
        $user->update(['role_id' => Role::where('slug', 'employee')->first()->id]);

        // Now delete should succeed
        $deleteRes2 = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$role->id}");

        $deleteRes2->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_user_permission_and_branch_scope_helper_methods(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Manager Surabaya',
            'slug'         => 'manager_surabaya',
            'platform'     => 'both',
            'branch_scope' => 'specific',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        $role->branches()->sync([$this->branchSurabaya->id]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => Role::MODULE_ATTENDANCE,
            'access_level' => 'read',
        ]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'manage',
        ]);

        $staff = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => 'Rudi Surabaya',
            'email'                 => 'rudi@majubersama.co.id',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branchSurabaya->id,
        ]);

        // Check permissions
        $this->assertTrue($staff->hasPermission(Role::MODULE_ATTENDANCE, 'read'));
        $this->assertFalse($staff->hasPermission(Role::MODULE_ATTENDANCE, 'manage'));
        $this->assertTrue($staff->hasPermission(Role::MODULE_RECEIPT, 'manage'));
        $this->assertFalse($staff->hasPermission(Role::MODULE_USER, 'read'));

        // Check branch scopes
        $this->assertTrue($staff->allowsBranch($this->branchSurabaya->id));
        $this->assertFalse($staff->allowsBranch($this->branchJakarta->id));

        // Superadmin bypasses everything
        $this->assertTrue($this->superAdmin->hasPermission(Role::MODULE_USER, 'manage'));
        $this->assertTrue($this->superAdmin->allowsBranch($this->branchJakarta->id));
        $this->assertTrue($this->superAdmin->allowsBranch($this->branchSurabaya->id));
    }

    public function test_can_assign_custom_role_to_new_and_existing_user(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staff Pajak',
            'slug'         => 'staff_pajak',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        // 1. Create user with custom role_id
        $createRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name'                  => 'Doni Pajak',
                'email'                 => 'doni@majubersama.co.id',
                'password'              => 'password123',
                'role_id'               => $role->id,
                'attendance_setting_id' => $this->branchJakarta->id,
            ]);

        $createRes->assertCreated();
        $this->assertDatabaseHas('users', [
            'email'   => 'doni@majubersama.co.id',
            'role_id' => $role->id,
            'role'    => 'staff_pajak',
        ]);

        $doni = User::where('email', 'doni@majubersama.co.id')->first();

        // 2. Update user to built-in role finance
        $financeRole = Role::where('slug', 'finance')->first();
        $updateRes = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$doni->id}", [
                'role_id' => $financeRole->id,
            ]);

        $updateRes->assertOk();
        $doni->refresh();
        $this->assertEquals($financeRole->id, $doni->role_id);
        $this->assertEquals('finance', $doni->role);
    }

    public function test_can_create_custom_role_with_array_of_objects_permissions(): void
    {
        $payload = [
            'name'         => 'Staff Gudang Logistik',
            'description'  => 'Izin format array of objects',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'permissions'  => [
                ['module' => Role::MODULE_ATTENDANCE, 'access_level' => 'manage'],
                ['module' => Role::MODULE_OVERTIME, 'access_level' => 'read'],
                ['module' => Role::MODULE_RECEIPT, 'access_level' => 'none'],
            ],
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Staff Gudang Logistik')
            ->assertJsonPath('data.platform', 'mobile_only')
            ->assertJsonPath('data.branch_scope', 'self');

        $roleId = $response->json('data.id');
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_ATTENDANCE,
            'access_level' => 'manage',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'read',
        ]);
    }
}

