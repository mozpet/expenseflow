<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Test', 'is_active' => true]);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => $role,
            'is_active'  => $active,
        ]);
    }

    private function payload(User $u, string $password = 'password'): array
    {
        return ['email' => $u->email, 'password' => $password];
    }

    // ── 1. Employee login mobile → berhasil ─────────────────────────
    public function test_employee_login_mobile_berhasil(): void
    {
        $emp = $this->user('employee');

        $this->postJson('/api/v1/login', $this->payload($emp), ['X-Platform' => 'mobile'])
            ->assertOk()
            ->assertJsonPath('message', 'Login berhasil.')
            ->assertJsonStructure(['token', 'user' => ['id', 'role', 'can_access_receipts']]);
    }

    // ── 2. Employee login web → 403 ─────────────────────────────────
    public function test_employee_login_web_ditolak(): void
    {
        $emp = $this->user('employee');

        $this->postJson('/api/v1/login', $this->payload($emp), ['X-Platform' => 'web'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Karyawan hanya bisa login di aplikasi mobile.');
    }

    // ── 3. Finance login mobile → berhasil ──────────────────────────
    public function test_finance_login_mobile_berhasil(): void
    {
        $fin = $this->user('finance');

        $this->postJson('/api/v1/login', $this->payload($fin), ['X-Platform' => 'mobile'])
            ->assertOk()
            ->assertJsonPath('message', 'Login berhasil.');
    }

    // ── 4. Finance login web → berhasil ─────────────────────────────
    public function test_finance_login_web_berhasil(): void
    {
        $fin = $this->user('finance');

        $this->postJson('/api/v1/login', $this->payload($fin), ['X-Platform' => 'web'])
            ->assertOk()
            ->assertJsonPath('message', 'Login berhasil.');
    }

    // ── 5. Password salah → 422 ──────────────────────────────────────
    public function test_password_salah_ditolak(): void
    {
        $emp = $this->user('employee');

        $this->postJson('/api/v1/login',
            ['email' => $emp->email, 'password' => 'salahsekali'],
            ['X-Platform' => 'mobile']
        )
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
    }

    // ── 6. User nonaktif → 403 ───────────────────────────────────────
    public function test_user_nonaktif_ditolak(): void
    {
        $emp = $this->user('employee', active: false);

        $this->postJson('/api/v1/login', $this->payload($emp), ['X-Platform' => 'mobile'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun telah dinonaktifkan.');
    }

    // ── 7a. Login sukses → login_attempts (status: success) ─────────
    public function test_login_sukses_tercatat_di_login_attempts(): void
    {
        $emp = $this->user('employee');

        $this->postJson('/api/v1/login', $this->payload($emp), ['X-Platform' => 'mobile'])
            ->assertOk();

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $emp->id,
            'status'  => 'success',
        ]);
    }

    // ── 7b. Password salah → login_attempts (status: failed) ────────
    public function test_password_salah_tercatat_di_login_attempts(): void
    {
        $emp = $this->user('employee');

        $this->postJson('/api/v1/login',
            ['email' => $emp->email, 'password' => 'salahsekali'],
            ['X-Platform' => 'mobile']
        )->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $emp->id,
            'status'  => 'failed',
        ]);
    }

    // ── 7c. Email tidak dikenal → login_attempts (user_id: null) ────
    public function test_email_tidak_dikenal_tercatat_di_login_attempts(): void
    {
        $this->postJson('/api/v1/login',
            ['email' => 'hantu@test.com', 'password' => 'password'],
            ['X-Platform' => 'mobile']
        )->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => null,
            'status'  => 'failed',
        ]);
    }

    // ── 7d. Employee login via web → login_attempts (status: failed) ─
    public function test_employee_login_web_tercatat_sebagai_failed(): void
    {
        $emp = $this->user('employee');

        $this->postJson('/api/v1/login', $this->payload($emp), ['X-Platform' => 'web'])
            ->assertStatus(403);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $emp->id,
            'status'  => 'failed',
        ]);
    }

    // ── 7e. User nonaktif → login_attempts (status: failed) ─────────
    public function test_user_nonaktif_tercatat_sebagai_failed(): void
    {
        $emp = $this->user('employee', active: false);

        $this->postJson('/api/v1/login', $this->payload($emp), ['X-Platform' => 'mobile'])
            ->assertStatus(403);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $emp->id,
            'status'  => 'failed',
        ]);
    }
}
