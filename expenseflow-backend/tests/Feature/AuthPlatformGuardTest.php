<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthPlatformGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, bool $attendance = false): User
    {
        return User::factory()->create([
            'role'               => $role,
            'attendance_enabled' => $attendance,
            'is_active'          => true,
        ]);
    }

    public function test_employee_diblokir_login_di_web(): void
    {
        $this->makeUser('employee');

        $this->postJson('/api/v1/login', [
            'email'    => User::first()->email,
            'password' => 'password',
        ], ['X-Platform' => 'web'])
            ->assertStatus(403)
            ->assertJson(['message' => 'Karyawan hanya bisa login di aplikasi mobile.']);
    }

    public function test_employee_boleh_login_di_mobile(): void
    {
        $this->makeUser('employee', attendance: true);

        $this->postJson('/api/v1/login', [
            'email'     => User::first()->email,
            'password'  => 'password',
            'device_id' => 'dev-test-1',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200)
            ->assertJsonPath('user.can_access_receipts', true)
            ->assertJsonPath('user.role', 'employee');
    }

    public function test_user_diblokir_login_di_mobile_jika_presensi_mobile_nonaktif(): void
    {
        $this->makeUser('employee', attendance: false);

        $this->postJson('/api/v1/login', [
            'email'     => User::first()->email,
            'password'  => 'password',
            'device_id' => 'dev-test-blocked',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(403)
            ->assertJson(['message' => 'Akses presensi mobile belum diaktifkan atau telah dinonaktifkan oleh HRD.']);
    }

    public function test_finance_boleh_login_di_web(): void
    {
        $this->makeUser('finance');

        $this->postJson('/api/v1/login', [
            'email'    => User::first()->email,
            'password' => 'password',
        ], ['X-Platform' => 'web'])
            ->assertStatus(200);
    }

    public function test_finance_sekarang_boleh_login_di_mobile(): void
    {
        // Non-employee boleh login via mobile jika presensi mobile aktif.
        $this->makeUser('finance', attendance: true);

        $this->postJson('/api/v1/login', [
            'email'     => User::first()->email,
            'password'  => 'password',
            'device_id' => 'dev-test-2',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200)
            ->assertJsonPath('user.can_access_receipts', true);
    }

    public function test_flag_kapabilitas_disertakan_di_response_login(): void
    {
        $this->makeUser('finance', attendance: true);

        $this->postJson('/api/v1/login', [
            'email'     => User::first()->email,
            'password'  => 'password',
            'device_id' => 'dev-test-3',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200)
            ->assertJsonPath('user.attendance_enabled', true)
            ->assertJsonPath('user.can_access_receipts', true)
            ->assertJsonPath('user.can_access_attendance', true);
    }

    public function test_email_tidak_dikenal_tidak_error_500(): void
    {
        // Email tidak ada → user_id null saat dicatat ke login_attempts.
        // Harus tetap tercatat & balas JSON validasi (422), BUKAN 500.
        $this->postJson('/api/v1/login', [
            'email'    => 'tidakada@example.com',
            'password' => 'password',
        ], ['X-Platform' => 'web'])
            ->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => null,
            'status'  => 'failed',
        ]);
    }

    public function test_me_juga_mengembalikan_flag_kapabilitas(): void
    {
        $user = $this->makeUser('employee', attendance: false);
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200)
            ->assertJsonPath('user.can_access_receipts', true)
            ->assertJsonPath('user.can_access_attendance', false)
            ->assertJsonPath('user.attendance_enabled', false);
    }

    public function test_me_mencabut_token_mobile_jika_presensi_mobile_dinonaktifkan(): void
    {
        $user = $this->makeUser('employee', attendance: false);
        $token = $user->createToken('auth-token-mobile')->plainTextToken;

        $this->getJson('/api/v1/me', [
            'Authorization' => "Bearer {$token}",
            'X-Platform'    => 'mobile',
        ])
            ->assertStatus(403)
            ->assertJson(['message' => 'Akses presensi mobile Anda telah dinonaktifkan oleh HRD.']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => 'auth-token-mobile',
        ]);
    }

    // ─── FIX #1: Custom Role mobile_only ditolak login web ─────────────────

    public function test_custom_role_mobile_only_diblokir_login_web(): void
    {
        $company = Company::create([
            'name'      => 'PT Test Fix1',
            'is_active' => true,
        ]);

        // Buat custom role dengan platform mobile_only
        $role = Role::create([
            'company_id'   => $company->id,
            'name'         => 'Staff Gudang',
            'slug'         => 'staff_gudang',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => 'attendance',
            'access_level' => 'read',
        ]);

        $user = User::factory()->create([
            'company_id'         => $company->id,
            'role'               => $role->slug,
            'role_id'            => $role->id,
            'attendance_enabled' => true,
            'is_active'          => true,
        ]);

        // Login via web → harus ditolak 403
        $this->postJson('/api/v1/login', [
            'email'    => $user->email,
            'password' => 'password',
        ], ['X-Platform' => 'web'])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Role Anda (Staff Gudang) hanya dapat mengakses aplikasi mobile.']);
    }

    public function test_custom_role_both_boleh_login_web(): void
    {
        $company = Company::create([
            'name'      => 'PT Test Fix1b',
            'is_active' => true,
        ]);

        // Buat custom role dengan platform both
        $role = Role::create([
            'company_id'   => $company->id,
            'name'         => 'SPV Finance',
            'slug'         => 'spv_finance',
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => 'receipt',
            'access_level' => 'manage',
        ]);

        $user = User::factory()->create([
            'company_id'         => $company->id,
            'role'               => $role->slug,
            'role_id'            => $role->id,
            'attendance_enabled' => true,
            'is_active'          => true,
        ]);

        // Login via web → harus dibolehkan
        $this->postJson('/api/v1/login', [
            'email'    => $user->email,
            'password' => 'password',
        ], ['X-Platform' => 'web'])
            ->assertStatus(200)
            ->assertJsonPath('user.role', 'spv_finance');
    }

    public function test_custom_role_mobile_only_boleh_login_mobile(): void
    {
        $company = Company::create([
            'name'      => 'PT Test Fix1c',
            'is_active' => true,
        ]);

        $role = Role::create([
            'company_id'   => $company->id,
            'name'         => 'Kurir Lapangan',
            'slug'         => 'kurir_lapangan',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => 'attendance',
            'access_level' => 'read',
        ]);

        $user = User::factory()->create([
            'company_id'         => $company->id,
            'role'               => $role->slug,
            'role_id'            => $role->id,
            'attendance_enabled' => true,
            'is_active'          => true,
        ]);

        // Login via mobile → harus dibolehkan
        $this->postJson('/api/v1/login', [
            'email'     => $user->email,
            'password'  => 'password',
            'device_id' => 'device-kurir-001',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200)
            ->assertJsonPath('user.role', 'kurir_lapangan');
    }

    // ─── FIX #2: Device Binding berlaku untuk semua role di mobile ──────────

    public function test_custom_role_mobile_mendapatkan_device_binding(): void
    {
        // Pastikan device binding aktif (di .env mungkin false)
        config()->set('app.device_binding_enabled', true);

        $company = Company::create([
            'name'      => 'PT Test Fix2',
            'is_active' => true,
        ]);

        $role = Role::create([
            'company_id'   => $company->id,
            'name'         => 'Operator Mesin',
            'slug'         => 'operator_mesin',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => 'attendance',
            'access_level' => 'read',
        ]);

        $user = User::factory()->create([
            'company_id'         => $company->id,
            'role'               => $role->slug,
            'role_id'            => $role->id,
            'attendance_enabled' => true,
            'is_active'          => true,
            'device_id'          => null, // belum pernah bind
        ]);

        // Login pertama dari device A → auto-bind (trust-on-first-use)
        $this->postJson('/api/v1/login', [
            'email'     => $user->email,
            'password'  => 'password',
            'device_id' => 'device-A',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200);

        $user->refresh();
        $this->assertEquals('device-A', $user->device_id);

        // Login dari device B → DITOLAK (device mismatch)
        $this->postJson('/api/v1/login', [
            'email'     => $user->email,
            'password'  => 'password',
            'device_id' => 'device-B',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(403)
            ->assertJsonPath('device_mismatch', true);
    }

    public function test_custom_role_mobile_tanpa_device_id_ditolak(): void
    {
        // Pastikan device binding aktif (di .env mungkin false)
        config()->set('app.device_binding_enabled', true);

        $company = Company::create([
            'name'      => 'PT Test Fix2b',
            'is_active' => true,
        ]);

        $role = Role::create([
            'company_id'   => $company->id,
            'name'         => 'Teknisi Lapangan',
            'slug'         => 'teknisi_lapangan',
            'platform'     => 'mobile_only',
            'branch_scope' => 'self',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => 'attendance',
            'access_level' => 'read',
        ]);

        $user = User::factory()->create([
            'company_id'         => $company->id,
            'role'               => $role->slug,
            'role_id'            => $role->id,
            'attendance_enabled' => true,
            'is_active'          => true,
        ]);

        // Login mobile tanpa device_id → harus ditolak 422
        $this->postJson('/api/v1/login', [
            'email'    => $user->email,
            'password' => 'password',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Identitas perangkat tidak terdeteksi. Perbarui aplikasi Anda.']);
    }
}
