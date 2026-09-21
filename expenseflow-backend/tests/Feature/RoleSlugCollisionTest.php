<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSlugCollisionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Test Slug',
            'is_active' => true,
        ]);

        // Buat admin yang punya wewenang kelola role
        $adminRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Admin Perusahaan',
            'slug'         => 'admin_perusahaan',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create([
            'role_id'      => $adminRole->id,
            'module'       => 'role_management',
            'access_level' => 'manage',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => $adminRole->slug,
            'role_id'    => $adminRole->id,
            'is_active'  => true,
        ]);
    }

    private function postRole(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/roles', $data);
    }

    // ─── FIX #3: Reserved slug collision prevention ──────────────────

    public function test_membuat_role_dengan_nama_admin_menghasilkan_slug_aman(): void
    {
        $response = $this->postRole([
            'name'         => 'Admin',
            'platform'     => 'both',
            'branch_scope' => 'all',
        ]);

        $response->assertStatus(201);

        $slug = $response->json('data.slug');

        // Slug TIDAK boleh sama persis dengan 'admin' (built-in)
        $this->assertNotEquals('admin', $slug);
        // Harus mengandung prefix dari nama + suffix _custom
        $this->assertStringContainsString('admin_custom', $slug);
    }

    public function test_membuat_role_dengan_nama_super_admin_menghasilkan_slug_aman(): void
    {
        $response = $this->postRole([
            'name'         => 'Super Admin',
            'platform'     => 'both',
            'branch_scope' => 'all',
        ]);

        $response->assertStatus(201);

        $slug = $response->json('data.slug');
        $this->assertNotEquals('super_admin', $slug);
        $this->assertStringContainsString('super_admin_custom', $slug);
    }

    public function test_membuat_role_dengan_nama_employee_menghasilkan_slug_aman(): void
    {
        $response = $this->postRole([
            'name'         => 'Employee',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
        ]);

        $response->assertStatus(201);

        $slug = $response->json('data.slug');
        $this->assertNotEquals('employee', $slug);
        $this->assertStringContainsString('employee_custom', $slug);
    }

    public function test_membuat_role_dengan_nama_hrd_menghasilkan_slug_aman(): void
    {
        $response = $this->postRole([
            'name'         => 'HRD',
            'platform'     => 'both',
            'branch_scope' => 'all',
        ]);

        $response->assertStatus(201);

        $slug = $response->json('data.slug');
        $this->assertNotEquals('hrd', $slug);
        $this->assertStringContainsString('hrd_custom', $slug);
    }

    public function test_membuat_role_dengan_nama_finance_menghasilkan_slug_aman(): void
    {
        $response = $this->postRole([
            'name'         => 'Finance',
            'platform'     => 'both',
            'branch_scope' => 'all',
        ]);

        $response->assertStatus(201);

        $slug = $response->json('data.slug');
        $this->assertNotEquals('finance', $slug);
        $this->assertStringContainsString('finance_custom', $slug);
    }

    public function test_slug_unik_jika_dua_role_bernama_mirip(): void
    {
        // Buat pertama: "SPV Lapangan" → slug "spv_lapangan"
        $res1 = $this->postRole([
            'name'         => 'SPV Lapangan',
            'platform'     => 'both',
            'branch_scope' => 'all',
        ]);
        $res1->assertStatus(201);
        $slug1 = $res1->json('data.slug');
        $this->assertEquals('spv_lapangan', $slug1);

        // Buat kedua dengan nama sama → slug harus berbeda (auto-increment)
        $res2 = $this->postRole([
            'name'         => 'SPV Lapangan',
            'platform'     => 'both',
            'branch_scope' => 'all',
        ]);
        $res2->assertStatus(201);
        $slug2 = $res2->json('data.slug');
        $this->assertNotEquals($slug1, $slug2);
        $this->assertStringStartsWith('spv_lapangan_', $slug2);
    }

    public function test_nama_non_reserved_tidak_terkena_suffix_custom(): void
    {
        $response = $this->postRole([
            'name'         => 'Koordinator Lapangan',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
        ]);

        $response->assertStatus(201);

        $slug = $response->json('data.slug');
        // Nama biasa TIDAK boleh ditambahkan _custom
        $this->assertEquals('koordinator_lapangan', $slug);
        $this->assertStringNotContainsString('_custom', $slug);
    }
}
