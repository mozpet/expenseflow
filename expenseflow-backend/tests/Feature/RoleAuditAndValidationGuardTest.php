<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleAuditAndValidationGuardTest extends TestCase
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

    public function test_deleting_custom_role_records_old_permissions_and_branches_in_audit_log(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Staff Gudang Audit',
            'slug'         => 'staff_gudang_audit',
            'platform'     => 'both',
            'branch_scope' => 'specific',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        $role->branches()->sync([$this->branchJakarta->id]);

        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_RECEIPT, 'access_level' => 'manage']);
        RolePermission::create(['role_id' => $role->id, 'module' => Role::MODULE_ATTENDANCE, 'access_level' => 'read']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$role->id}");

        $response->assertOk();

        $log = DB::table('activity_logs')
            ->where('action', 'ROLE_DELETED')
            ->where('entity_id', $role->id)
            ->first();

        $this->assertNotNull($log, 'Audit log ROLE_DELETED harus tercatat.');
        $this->assertNotNull($log->old_values, 'old_values tidak boleh null saat role dihapus.');

        $oldValues = json_decode($log->old_values, true);
        $this->assertEquals('Staff Gudang Audit', $oldValues['name']);

        $permissionLevels = collect($oldValues['permissions'])->pluck('access_level', 'module');
        $this->assertEquals('manage', $permissionLevels[Role::MODULE_RECEIPT]);
        $this->assertEquals('read', $permissionLevels[Role::MODULE_ATTENDANCE]);

        $branchIds = collect($oldValues['branches'])->pluck('id');
        $this->assertTrue($branchIds->contains($this->branchJakarta->id));
    }

    public function test_creating_role_with_unknown_permission_module_key_is_rejected(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', [
                'name'         => 'Role Typo Modul',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'permissions'  => [
                    Role::MODULE_RECEIPT => 'manage',
                    'attendence'         => 'manage', // typo: seharusnya 'attendance'
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions']);

        $this->assertDatabaseMissing('roles', ['name' => 'Role Typo Modul']);
    }

    public function test_updating_role_with_unknown_permission_module_key_is_rejected(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Role Update Typo',
            'slug'         => 'role_update_typo',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$role->id}", [
                'name'         => 'Role Update Typo',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'permissions'  => [
                    'unknown_module_xyz' => 'manage',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions']);
    }

    public function test_creating_role_with_valid_permission_keys_still_succeeds(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', [
                'name'         => 'Role Valid Modul',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'permissions'  => [
                    Role::MODULE_RECEIPT    => 'manage',
                    Role::MODULE_ATTENDANCE => 'read',
                ],
            ]);

        $response->assertCreated();
    }
}
