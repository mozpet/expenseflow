<?php

namespace Tests\Feature;

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
        $this->makeUser('employee');

        $this->postJson('/api/v1/login', [
            'email'    => User::first()->email,
            'password' => 'password',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200)
            ->assertJsonPath('user.can_access_receipts', true)
            ->assertJsonPath('user.role', 'employee');
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
        // Perilaku BARU: non-employee kini boleh login via mobile (untuk presensi).
        $this->makeUser('finance');

        $this->postJson('/api/v1/login', [
            'email'    => User::first()->email,
            'password' => 'password',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200)
            ->assertJsonPath('user.can_access_receipts', false);
    }

    public function test_flag_kapabilitas_disertakan_di_response_login(): void
    {
        $this->makeUser('finance', attendance: true);

        $this->postJson('/api/v1/login', [
            'email'    => User::first()->email,
            'password' => 'password',
        ], ['X-Platform' => 'mobile'])
            ->assertStatus(200)
            ->assertJsonPath('user.attendance_enabled', true)
            ->assertJsonPath('user.can_access_receipts', false)
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
}
