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

class RoleMobileOnlyPermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private AttendanceSetting $branchJakarta;

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

        $adminRole = Role::whereNull('company_id')->where('slug', 'admin')->first();

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

    public function test_creating_mobile_only_role_forces_disallowed_modules_to_none(): void
    {
        $payload = [
            'name'         => 'Staff Lapangan Mobile',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'permissions'  => [
                Role::MODULE_RECEIPT         => 'manage',
                Role::MODULE_USER            => 'manage',
                Role::MODULE_ATTENDANCE      => 'manage',
                Role::MODULE_PAYROLL         => 'manage',
                Role::MODULE_SETTINGS        => 'manage',
                Role::MODULE_ROLE_MANAGEMENT => 'manage',
                Role::MODULE_LEAVE           => 'manage',
                Role::MODULE_OVERTIME        => 'spv',
            ],
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', $payload);

        $response->assertCreated();
        $roleId = $response->json('data.id');

        // Modul di luar allow-list (leave, overtime) harus dipaksa 'none'.
        foreach ([
            Role::MODULE_RECEIPT,
            Role::MODULE_USER,
            Role::MODULE_ATTENDANCE,
            Role::MODULE_PAYROLL,
            Role::MODULE_SETTINGS,
            Role::MODULE_ROLE_MANAGEMENT,
        ] as $mod) {
            $this->assertDatabaseHas('role_permissions', [
                'role_id'      => $roleId,
                'module'       => $mod,
                'access_level' => 'none',
            ]);
        }

        // leave & overtime tetap sesuai yang dikirim (tidak di-override selain default overtime).
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_LEAVE,
            'access_level' => 'manage',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'spv',
        ]);
    }

    public function test_creating_mobile_only_role_defaults_overtime_to_manage_when_omitted(): void
    {
        $payload = [
            'name'         => 'Staff Lapangan Tanpa Lembur',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'permissions'  => [
                Role::MODULE_LEAVE => 'read',
                // overtime tidak dikirim sama sekali -> harus default ke 'manage'.
            ],
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', $payload);

        $response->assertCreated();
        $roleId = $response->json('data.id');

        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'manage',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_LEAVE,
            'access_level' => 'read',
        ]);
    }

    public function test_platform_both_role_is_unaffected_by_mobile_only_guard(): void
    {
        $payload = [
            'name'         => 'Staff Kantor Lengkap',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'permissions'  => [
                Role::MODULE_RECEIPT => 'manage',
                Role::MODULE_USER    => 'read',
                Role::MODULE_OVERTIME => 'none',
            ],
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', $payload);

        $response->assertCreated();
        $roleId = $response->json('data.id');

        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'manage',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_USER,
            'access_level' => 'read',
        ]);
        // overtime tetap 'none' karena platform bukan mobile_only, jadi tidak di-default.
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $roleId,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'none',
        ]);
    }

    public function test_updating_role_to_mobile_only_sanitizes_stored_permissions_even_without_resending_permissions(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staff Kantor Jadi Mobile',
            'slug'         => 'staff_kantor_jadi_mobile',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_RECEIPT, 'access_level' => 'manage']);
        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_USER, 'access_level' => 'manage']);
        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_LEAVE, 'access_level' => 'read']);
        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_OVERTIME, 'access_level' => 'hrd']);

        // Update hanya mengubah platform ke mobile_only, TANPA mengirim ulang 'permissions'.
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$role->id}", [
                'name'         => $role->name,
                'platform'     => 'mobile_only',
                'branch_scope' => 'all',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $role->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'none',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $role->id,
            'module'       => Role::MODULE_USER,
            'access_level' => 'none',
        ]);
        // leave & overtime yang sudah tersimpan tetap dipertahankan (bukan ditimpa/kosong).
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $role->id,
            'module'       => Role::MODULE_LEAVE,
            'access_level' => 'read',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $role->id,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'hrd',
        ]);
    }

    public function test_updating_mobile_only_role_with_new_permissions_payload_still_sanitizes(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staff Mobile Diperbarui',
            'slug'         => 'staff_mobile_diperbarui',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_LEAVE, 'access_level' => 'manage']);
        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_OVERTIME, 'access_level' => 'manage']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$role->id}", [
                'name'         => $role->name,
                'platform'     => 'mobile_only',
                'branch_scope' => 'self',
                'permissions'  => [
                    Role::MODULE_LEAVE    => 'manage',
                    Role::MODULE_OVERTIME => 'spv',
                    Role::MODULE_SETTINGS => 'manage',
                    Role::MODULE_ROLE_MANAGEMENT => 'manage',
                ],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $role->id,
            'module'       => Role::MODULE_SETTINGS,
            'access_level' => 'none',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $role->id,
            'module'       => Role::MODULE_ROLE_MANAGEMENT,
            'access_level' => 'none',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id'      => $role->id,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'spv',
        ]);
    }
}
