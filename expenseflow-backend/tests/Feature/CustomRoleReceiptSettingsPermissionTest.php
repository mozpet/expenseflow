<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomRoleReceiptSettingsPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Maju Terus',
            'is_active' => true,
        ]);

        $this->branch = AttendanceSetting::create([
            'company_id'      => $this->company->id,
            'office_name'     => 'Cabang Jakarta',
            'office_latitude' => -6.2088,
            'office_longitude'=> 106.8456,
            'radius_meters'   => 100,
        ]);
    }

    public function test_custom_role_with_receipt_manage_can_read_and_update_settings_and_branch_limits(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Finance Officer Custom',
            'slug'         => 'finance_officer_custom',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        // Beri izin receipt = 'manage' (Wewenang Penuh Struk Reimbursement)
        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'manage',
        ]);

        $user = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => $role->slug,
            'role_id'               => $role->id,
            'attendance_setting_id' => $this->branch->id,
            'is_active'             => true,
        ]);

        // 1. Test GET /settings (Membaca pengaturan klaim & variansi)
        $resGet = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/settings');

        $resGet->assertStatus(200)
            ->assertJsonStructure(['settings', 'receipt_approval_rules', 'branch_settings']);

        // 2. Test PUT /settings (Mengubah aturan global limit & threshold)
        $resPutGlobal = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dashboard/settings', [
                'variance_limit'          => 15,
                'max_claim_limit'         => 3500000,
                'threshold_single'        => '< Rp 10.000.000',
                'threshold_two'           => 'Rp 10 jt — Rp 50 jt',
                'threshold_three'         => '> Rp 50.000.000',
                'receipt_tier1_threshold' => 750000,
                'receipt_tier2_threshold' => 1500000,
                'receipt_tier2_mode'      => 'two_finance',
            ]);

        $resPutGlobal->assertStatus(200);

        // 3. Test PUT /settings/branches/{id} (Mengubah batas limit cabang)
        $resPutBranch = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/dashboard/settings/branches/{$this->branch->id}", [
                'variance_limit'  => 12,
                'max_claim_limit' => 4000000,
            ]);

        $resPutBranch->assertStatus(200)
            ->assertJsonPath('branch.variance_limit', 12)
            ->assertJsonPath('branch.max_claim_limit', 4000000);
    }

    public function test_custom_role_with_receipt_read_only_can_read_but_cannot_update_settings(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Finance Auditor Custom',
            'slug'         => 'finance_auditor_custom',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        // Beri izin receipt = 'read'
        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'read',
        ]);

        $user = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => $role->slug,
            'role_id'               => $role->id,
            'attendance_setting_id' => $this->branch->id,
            'is_active'             => true,
        ]);

        // GET /settings harus sukses
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/settings')
            ->assertStatus(200);

        // PUT /settings harus ditolak 403 Forbidden
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dashboard/settings', [
                'variance_limit'          => 15,
                'max_claim_limit'         => 3500000,
                'threshold_single'        => '< Rp 10.000.000',
                'threshold_two'           => 'Rp 10 jt — Rp 50 jt',
                'threshold_three'         => '> Rp 50.000.000',
                'receipt_tier1_threshold' => 750000,
                'receipt_tier2_threshold' => 1500000,
                'receipt_tier2_mode'      => 'two_finance',
            ])
            ->assertStatus(403);

        // PUT /settings/branches/{id} harus ditolak 403 Forbidden
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/dashboard/settings/branches/{$this->branch->id}", [
                'variance_limit'  => 12,
                'max_claim_limit' => 4000000,
            ])
            ->assertStatus(403);
    }
}
