<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\JobLevel;
use App\Models\Role;
use App\Models\SalaryGrade;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6 Item 2 - Struktur & Skala Upah (Permenaker 1/2017).
 *
 * Membuktikan:
 *  (i)   Job level & golongan upah tersimpan per-perusahaan dengan rentang valid
 *        (maks >= min, titik tengah di antaranya) - selain itu 422.
 *  (ii)  Penetapan gaji pokok DIVALIDASI terhadap rentang golongan: di luar
 *        rentang -> 422 (pencegahan di muka, bukan saat batch payroll berjalan).
 *  (iii) Job level terisi otomatis dari golongan bila tidak dikirim.
 *  (iv)  Scoping multi-tenant: golongan perusahaan lain tidak terlihat & tidak
 *        dapat dipakai (404 / 422).
 *  (v)   Golongan yang melekat pada riwayat gaji tidak dapat dihapus (FK restrict).
 *  (vi)  Tulis = izin `manage`.
 */
class SalaryGradeStructureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $finance;
    private User $employee;
    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::create([
            'name' => 'PT Maju Bersama', 'email' => 'info@mb.co.id',
            'phone' => '021-1', 'address' => 'Jakarta', 'is_active' => true,
        ]);
        $this->other = Company::create([
            'name' => 'PT Lain', 'email' => 'info@lain.co.id',
            'phone' => '021-9', 'address' => 'Bandung', 'is_active' => true,
        ]);

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser($this->company->id, 'Andi', 'andi@mb.co.id', 'finance', $financeRole->id);
        $this->employee = $this->makeUser($this->company->id, 'Eko', 'eko@mb.co.id', 'employee', $employeeRole->id);
        $this->budi     = $this->makeUser($this->company->id, 'Budi', 'budi@mb.co.id', 'employee', $employeeRole->id);
    }

    private function makeUser(int $companyId, string $name, string $email, string $role, int $roleId): User
    {
        return User::create([
            'company_id' => $companyId, 'role_id' => $roleId, 'name' => $name,
            'email' => $email, 'password' => bcrypt('password'), 'role' => $role,
            'department' => 'Umum', 'joined_date' => '2022-01-01', 'is_active' => true,
        ]);
    }

    private function postLevel(array $payload)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/job-levels', $payload);
    }

    private function postGrade(array $payload)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/salary-grades', $payload);
    }

    private function assignSalary(int $userId, array $payload)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/salaries/{$userId}", $payload);
    }

    // -- (i) Pembuatan struktur & validasi rentang ---------------------------

    public function test_job_level_and_grade_are_created_scoped_to_company(): void
    {
        $levelId = (int) $this->postLevel([
            'name' => 'Staf', 'code' => 'ST', 'rank' => 2,
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('job_levels', [
            'id' => $levelId, 'company_id' => $this->company->id, 'name' => 'Staf', 'rank' => 2,
        ]);

        $gradeId = (int) $this->postGrade([
            'name' => 'Golongan II', 'code' => 'g2', 'job_level_id' => $levelId,
            'min_salary' => 6000000, 'mid_salary' => 7500000, 'max_salary' => 9000000,
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('salary_grades', [
            'id' => $gradeId, 'company_id' => $this->company->id,
            'code' => 'G2', // dinormalkan huruf besar
            'job_level_id' => $levelId,
        ]);
    }

    public function test_invalid_range_is_rejected(): void
    {
        // Maksimum < minimum.
        $this->postGrade([
            'name' => 'Golongan Salah', 'min_salary' => 9000000, 'max_salary' => 6000000,
        ])->assertStatus(422)->assertJsonValidationErrors('max_salary');

        // Titik tengah di luar rentang.
        $this->postGrade([
            'name' => 'Golongan Mid Salah', 'min_salary' => 6000000,
            'mid_salary' => 12000000, 'max_salary' => 9000000,
        ])->assertStatus(422)->assertJsonValidationErrors('mid_salary');

        $this->assertDatabaseCount('salary_grades', 0);
    }

    // -- (ii)+(iii) Penetapan gaji divalidasi terhadap rentang ---------------

    public function test_salary_outside_grade_range_is_rejected(): void
    {
        $levelId = (int) $this->postLevel(['name' => 'Staf', 'rank' => 2])
            ->assertStatus(201)->json('data.id');
        $gradeId = (int) $this->postGrade([
            'name' => 'Golongan II', 'job_level_id' => $levelId,
            'min_salary' => 6000000, 'max_salary' => 9000000,
        ])->assertStatus(201)->json('data.id');

        // Di bawah batas bawah.
        $this->assignSalary($this->budi->id, [
            'basic_salary' => 5000000, 'effective_date' => '2026-01-01',
            'salary_grade_id' => $gradeId,
        ])->assertStatus(422)->assertJsonValidationErrors('basic_salary');

        // Di atas batas atas.
        $this->assignSalary($this->budi->id, [
            'basic_salary' => 12000000, 'effective_date' => '2026-01-01',
            'salary_grade_id' => $gradeId,
        ])->assertStatus(422)->assertJsonValidationErrors('basic_salary');

        $this->assertDatabaseCount('employee_salaries', 0);

        // Dalam rentang -> diterima, dan job level terisi otomatis dari golongan.
        $this->assignSalary($this->budi->id, [
            'basic_salary' => 7500000, 'effective_date' => '2026-01-01',
            'salary_grade_id' => $gradeId,
        ])->assertStatus(201);

        $this->assertDatabaseHas('employee_salaries', [
            'user_id'         => $this->budi->id,
            'salary_grade_id' => $gradeId,
            'job_level_id'    => $levelId, // diturunkan dari golongan
            'currency'        => 'IDR',
        ]);
    }

    public function test_salary_without_grade_is_not_range_checked(): void
    {
        // Tanpa golongan, penetapan bebas (perusahaan belum menyusun struktur upah).
        $this->assignSalary($this->budi->id, [
            'basic_salary' => 3000000, 'effective_date' => '2026-01-01',
        ])->assertStatus(201);

        $this->assertDatabaseHas('employee_salaries', [
            'user_id' => $this->budi->id, 'salary_grade_id' => null,
        ]);
    }

    // -- (iv) Scoping multi-tenant -------------------------------------------

    public function test_grade_from_other_company_is_invisible_and_unusable(): void
    {
        $alienLevel = JobLevel::create([
            'company_id' => $this->other->id, 'name' => 'Manajer Lain', 'rank' => 5, 'is_active' => true,
        ]);
        $alienGrade = SalaryGrade::create([
            'company_id' => $this->other->id, 'job_level_id' => $alienLevel->id,
            'name' => 'Golongan Lain', 'min_salary' => 1000000, 'max_salary' => 99000000,
            'currency' => 'IDR', 'is_active' => true,
        ]);

        // Tidak muncul pada daftar milik perusahaan sendiri.
        $listed = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/salary-grades')
            ->assertOk()->json('data');
        $this->assertNotContains($alienGrade->id, array_column($listed, 'id'));

        $levels = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/job-levels')
            ->assertOk()->json('data');
        $this->assertNotContains($alienLevel->id, array_column($levels, 'id'));

        // Tidak dapat diubah/dihapus. Pertahanan berlapis: CompanyMiddleware menolak
        // route-model binding lintas-company lebih dulu (403); bila lolos, guard
        // scoping di controller menutup dengan 404. Keduanya = tidak ada kebocoran.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/salary-grades/{$alienGrade->id}", [
                'name' => 'Dibajak', 'min_salary' => 1, 'max_salary' => 2,
            ])->assertForbidden();

        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/salary-grades/{$alienGrade->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('salary_grades', [
            'id' => $alienGrade->id, 'name' => 'Golongan Lain',
        ]);

        // Tidak dapat dipasang pada karyawan sendiri (validasi exists ber-scope).
        $this->assignSalary($this->budi->id, [
            'basic_salary' => 7000000, 'effective_date' => '2026-01-01',
            'salary_grade_id' => $alienGrade->id,
        ])->assertStatus(422)->assertJsonValidationErrors('salary_grade_id');
    }

    // -- (v) FK finansial restrictOnDelete -----------------------------------

    public function test_grade_attached_to_salary_history_cannot_be_deleted(): void
    {
        $gradeId = (int) $this->postGrade([
            'name' => 'Golongan II', 'min_salary' => 6000000, 'max_salary' => 9000000,
        ])->assertStatus(201)->json('data.id');

        $this->assignSalary($this->budi->id, [
            'basic_salary' => 7000000, 'effective_date' => '2026-01-01',
            'salary_grade_id' => $gradeId,
        ])->assertStatus(201);

        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/salary-grades/{$gradeId}")
            ->assertStatus(422);

        $this->assertDatabaseHas('salary_grades', ['id' => $gradeId]);
    }

    // -- (vi) Gate izin -------------------------------------------------------

    public function test_structure_write_requires_manage_permission(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/job-levels', ['name' => 'Palsu', 'rank' => 1])
            ->assertStatus(403);

        $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/salary-grades', [
                'name' => 'Palsu', 'min_salary' => 1000000, 'max_salary' => 2000000,
            ])->assertStatus(403);

        $this->assertDatabaseCount('job_levels', 0);
        $this->assertDatabaseCount('salary_grades', 0);
    }
}
