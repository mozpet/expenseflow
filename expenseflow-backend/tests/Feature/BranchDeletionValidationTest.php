<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchDeletionValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private AttendanceSetting $branchA;
    private AttendanceSetting $branchB;
    private User $employee1;
    private User $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::create([
            'name'      => 'PT Maju Terus Pantang Mundur',
            'is_active' => true,
        ]);

        $adminRole = Role::whereNull('company_id')->where('slug', 'admin')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->branchA = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Cabang Jakarta Selatan',
            'office_latitude'        => -6.261493,
            'office_longitude'       => 106.810600,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $this->branchB = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Cabang Bandung',
            'office_latitude'        => -6.917464,
            'office_longitude'       => 107.619123,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $this->admin = User::create([
            'company_id'         => $this->company->id,
            'role_id'            => $adminRole->id,
            'name'               => 'Admin Utama',
            'email'              => 'admin@majuterus.com',
            'password'           => bcrypt('password'),
            'role'               => 'admin',
            'is_active'          => true,
            'attendance_enabled' => true,
        ]);

        $this->employee1 = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $employeeRole->id,
            'name'                  => 'Karyawan Satu',
            'email'                 => 'karyawan1@majuterus.com',
            'password'              => bcrypt('password'),
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->branchA->id,
        ]);

        $this->employee2 = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $employeeRole->id,
            'name'                  => 'Karyawan Dua',
            'email'                 => 'karyawan2@majuterus.com',
            'password'              => bcrypt('password'),
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->branchA->id,
        ]);
    }

    public function test_cannot_delete_branch_when_employees_are_assigned(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/dashboard/attendance/settings/{$this->branchA->id}");

        $response->assertStatus(422)
            ->assertJsonPath('code', 'BRANCH_HAS_ASSIGNED_EMPLOYEES')
            ->assertJsonPath('assigned_employees_count', 2)
            ->assertJsonPath('office_id', $this->branchA->id)
            ->assertJsonPath('office_name', 'Cabang Jakarta Selatan');

        $this->assertStringContainsString('Tidak dapat menghapus kantor cabang', $response->json('message'));
        $this->assertStringContainsString('Cabang Jakarta Selatan', $response->json('message'));
        $this->assertStringContainsString('2 karyawan yang terikat', $response->json('message'));

        // Pastikan kantor tidak terhapus dari database
        $this->assertDatabaseHas('attendance_settings', [
            'id' => $this->branchA->id,
        ]);
    }

    public function test_can_delete_branch_after_all_employees_are_reassigned(): void
    {
        // Pindahkan seluruh karyawan ke Cabang B
        $this->employee1->update(['attendance_setting_id' => $this->branchB->id]);
        $this->employee2->update(['attendance_setting_id' => $this->branchB->id]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/dashboard/attendance/settings/{$this->branchA->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Pengaturan kantor berhasil dihapus.');

        // Pastikan kantor berhasil dihapus
        $this->assertDatabaseMissing('attendance_settings', [
            'id' => $this->branchA->id,
        ]);

        // Pastikan tercatat di activity log
        $this->assertDatabaseHas('activity_logs', [
            'company_id'  => $this->company->id,
            'user_id'     => $this->admin->id,
            'action'      => 'attendance_setting_deleted',
            'entity_type' => 'attendance_setting',
            'entity_id'   => $this->branchA->id,
        ]);
    }

    public function test_cannot_delete_branch_from_another_company(): void
    {
        $otherCompany = Company::create([
            'name'      => 'PT Lain',
            'is_active' => true,
        ]);

        $otherBranch = AttendanceSetting::create([
            'company_id'             => $otherCompany->id,
            'office_name'            => 'Cabang PT Lain',
            'office_latitude'        => -6.2,
            'office_longitude'       => 106.8,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/dashboard/attendance/settings/{$otherBranch->id}");

        $response->assertStatus(403);
    }

    public function test_list_settings_includes_users_count(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/attendance/settings');

        $response->assertOk();

        $settings = collect($response->json('settings'));
        $settingA = $settings->firstWhere('id', $this->branchA->id);
        $settingB = $settings->firstWhere('id', $this->branchB->id);

        $this->assertNotNull($settingA);
        $this->assertEquals(2, $settingA['users_count']);

        $this->assertNotNull($settingB);
        $this->assertEquals(0, $settingB['users_count']);
    }
}
