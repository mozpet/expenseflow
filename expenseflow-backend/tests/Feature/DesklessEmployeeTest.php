<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesklessEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Test Deskless', 'is_active' => true]);

        // Seed roles if necessary
        Role::firstOrCreate(['slug' => 'employee'], [
            'name' => 'Employee',
            'platform' => 'mobile_only',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
            'is_active'  => true,
            'can_login'  => true,
        ]);
    }

    public function test_can_create_deskless_employee_without_email_and_password(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/users', [
            'name'          => 'Pak Joko (Satpam)',
            'can_login'     => false,
            'role'          => 'employee',
            'employee_code' => 'SEC-001',
            'identity_number' => '3578012345678901',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'name'               => 'Pak Joko (Satpam)',
            'employee_code'      => 'SEC-001',
            'can_login'          => false,
            'attendance_enabled' => false,
        ]);

        $createdUser = User::where('employee_code', 'SEC-001')->first();
        $this->assertNotNull($createdUser);
        $this->assertStringContainsString('deskless_', $createdUser->email);
    }

    public function test_deskless_employee_cannot_login(): void
    {
        $deskless = User::factory()->create([
            'company_id'         => $this->company->id,
            'name'               => 'Siti (OB)',
            'email'              => 'siti@test.com',
            'password'           => 'password123',
            'role'               => 'employee',
            'is_active'          => true,
            'can_login'          => false,
            'attendance_enabled' => false,
        ]);

        // Mobile login attempt
        $resMobile = $this->postJson('/api/v1/login', [
            'email'     => 'siti@test.com',
            'password'  => 'password123',
            'device_id' => 'mobile-device-1',
        ], ['X-Platform' => 'mobile']);

        $resMobile->assertStatus(403)
            ->assertJsonPath('message', 'Akun ini terdaftar sebagai karyawan non-sistem (tanpa akses login aplikasi/web).');

        // Web login attempt
        $resWeb = $this->postJson('/api/v1/login', [
            'email'    => 'siti@test.com',
            'password' => 'password123',
        ], ['X-Platform' => 'web']);

        $resWeb->assertStatus(403)
            ->assertJsonPath('message', 'Akun ini terdaftar sebagai karyawan non-sistem (tanpa akses login aplikasi/web).');
    }

    public function test_updating_employee_to_can_login_false_disables_attendance(): void
    {
        $emp = User::factory()->create([
            'company_id'         => $this->company->id,
            'name'               => 'Budi Digital',
            'email'              => 'budi@test.com',
            'role'               => 'employee',
            'is_active'          => true,
            'can_login'          => true,
            'attendance_enabled' => true,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$emp->id}", [
            'can_login' => false,
        ]);

        $response->assertOk();

        $emp->refresh();
        $this->assertFalse($emp->can_login);
        $this->assertFalse($emp->attendance_enabled);
    }
}
