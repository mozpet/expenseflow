<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomRoleEmployeeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_custom_role_to_employee_and_persist(): void
    {
        $company = Company::create(['name' => 'PT Maju Bersama', 'is_active' => true]);

        // Create Admin user
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'role'       => 'admin',
        ]);

        // Create Custom Role
        $customRole = Role::create([
            'company_id'   => $company->id,
            'name'         => 'Kepala Cabang',
            'slug'         => 'kepala_cabang',
            'description'  => 'Manajer operasional kantor cabang',
            'platform'     => 'both',
            'branch_scope' => 'self',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        // Target employee
        $employee = User::factory()->create([
            'company_id' => $company->id,
            'role'       => 'employee',
        ]);

        $this->actingAs($admin, 'sanctum');

        // 1. Update employee with custom role using role_id and role slug
        $response = $this->putJson("/api/v1/admin/users/{$employee->id}", [
            'name'    => $employee->name,
            'role'    => $customRole->slug,
            'role_id' => $customRole->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.role', 'kepala_cabang');
        $response->assertJsonPath('user.role_id', $customRole->id);
        $response->assertJsonPath('user.role_relation.name', 'Kepala Cabang');

        $employee->refresh();
        $this->assertEquals('kepala_cabang', $employee->role);
        $this->assertEquals($customRole->id, $employee->role_id);

        // 2. Listing users returns role_id and roleRelation
        $listResponse = $this->getJson('/api/v1/admin/users');
        $listResponse->assertStatus(200);
        $usersData = $listResponse->json('data');
        $updatedUserInList = collect($usersData)->firstWhere('id', $employee->id);
        $this->assertNotNull($updatedUserInList);
        $this->assertEquals('kepala_cabang', $updatedUserInList['role']);
        $this->assertEquals($customRole->id, $updatedUserInList['role_id']);
        $this->assertEquals('Kepala Cabang', $updatedUserInList['role_relation']['name']);
    }

    public function test_admin_can_assign_custom_role_using_slug_only(): void
    {
        $company = Company::create(['name' => 'PT Maju Bersama', 'is_active' => true]);

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'role'       => 'admin',
        ]);

        $customRole = Role::create([
            'company_id'   => $company->id,
            'name'         => 'Supervisor Lapangan',
            'slug'         => 'supervisor_lapangan',
            'description'  => 'Supervisor tim lapangan',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        $employee = User::factory()->create([
            'company_id' => $company->id,
            'role'       => 'employee',
        ]);

        $this->actingAs($admin, 'sanctum');

        $response = $this->putJson("/api/v1/admin/users/{$employee->id}", [
            'name' => $employee->name,
            'role' => $customRole->slug,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.role', 'supervisor_lapangan');
        $response->assertJsonPath('user.role_id', $customRole->id);

        $employee->refresh();
        $this->assertEquals('supervisor_lapangan', $employee->role);
        $this->assertEquals($customRole->id, $employee->role_id);
    }
}
