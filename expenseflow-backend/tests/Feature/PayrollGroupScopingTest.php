<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\PayrollGroup;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Item 5 — Grup Payroll (grouping + scoping MVP).
 *
 * Membuktikan:
 *  (i)   CRUD grup ter-gate izin manage (read→403 utk karyawan; manage→sukses).
 *  (ii)  REGRESI: dua run reguler ruang-lingkup identik pada periode sama → 422.
 *  (iii) Run reguler + run ber-grup pada periode sama SAMA-SAMA boleh (tanpa 422).
 *  (iv)  Run ber-grup HANYA menghitung anggota grup itu (scoping eligibleUsers).
 *  (v)   Unique index periode di-REBUILD menjadi `payrolls_period_scope_unique`.
 */
class PayrollGroupScopingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $hrd;
    private User $employee;
    private User $budi;
    private User $siti;
    private User $joni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create([
            'name'      => 'PT Maju Bersama',
            'email'     => 'info@majubersama.co.id',
            'phone'     => '021-12345678',
            'address'   => 'Jl. Sudirman No. 1, Jakarta',
            'is_active' => true,
        ]);

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $hrdRole      = Role::whereNull('company_id')->where('slug', 'hrd')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id, null);
        $this->hrd      = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id, null);
        $this->employee = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id, 'EMP-000');

        $this->budi = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001');
        $this->siti = $this->makeUser('Siti Karyawan', 'siti@mb.co.id', 'employee', $employeeRole->id, 'EMP-002');
        $this->joni = $this->makeUser('Joni Karyawan', 'joni@mb.co.id', 'employee', $employeeRole->id, 'EMP-003');

        $this->setSalary($this->budi, 10_000_000);
        $this->setSalary($this->siti, 8_000_000);
        $this->setSalary($this->joni, 6_000_000);
    }

    private function makeUser(string $name, string $email, string $role, int $roleId, ?string $code): User
    {
        return User::create([
            'company_id'    => $this->company->id,
            'role_id'       => $roleId,
            'name'          => $name,
            'email'         => $email,
            'password'      => bcrypt('password'),
            'role'          => $role,
            'employee_code' => $code,
            'department'    => 'Umum',
            'is_active'     => true,
        ]);
    }

    private function setSalary(User $user, int $basic): void
    {
        EmployeeSalary::create([
            'company_id'     => $user->company_id,
            'user_id'        => $user->id,
            'basic_salary'   => $basic,
            'effective_date' => '2026-01-01',
            'is_active'      => true,
            'created_by'     => $this->finance->id,
        ]);

        EmployeeTaxProfile::create([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'npwp'        => null,
            'ptkp_status' => 'TK/0',
            'has_npwp'    => false,
            'tax_method'  => 'gross',
        ]);
    }

    /** POST /runs dgn payload penuh, kembalikan response test. */
    private function createRun(array $payload)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', array_merge(['period_month' => 6, 'period_year' => 2026], $payload));
    }

    public function test_group_crud_is_gated_by_manage_permission(): void
    {
        // Karyawan biasa (tanpa izin payroll) → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/groups', ['name' => 'Staf Bulanan'])
            ->assertStatus(403);

        // Finance (manage) → 201.
        $created = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/groups', ['name' => 'Staf Bulanan', 'code' => 'staf'])
            ->assertStatus(201)->json('data');
        $this->assertSame('Staf Bulanan', $created['name']);
        $this->assertSame('STAF', $created['code']); // kode dinormalisasi uppercase.
        $groupId = $created['id'];

        // Nama duplikat dalam company sama → 422.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/groups', ['name' => 'Staf Bulanan'])
            ->assertStatus(422);

        // GET index → memuat grup + jumlah anggota.
        $list = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/groups')
            ->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertArrayHasKey('users_count', $list[0]);

        // PUT update → 200.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/groups/{$groupId}", ['name' => 'Staf Tetap', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Staf Tetap');

        // DELETE grup kosong → 200.
        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/groups/{$groupId}")
            ->assertOk();
        $this->assertDatabaseMissing('payroll_groups', ['id' => $groupId]);

        $this->assertDatabaseHas('activity_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_GROUP_CREATED',
        ]);
    }

    public function test_group_with_members_or_history_cannot_be_deleted(): void
    {
        $group = PayrollGroup::create(['company_id' => $this->company->id, 'name' => 'Direksi']);
        $this->budi->update(['payroll_group_id' => $group->id]);

        // Masih beranggota → 422.
        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/groups/{$group->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('payroll_groups', ['id' => $group->id]);
    }

    public function test_duplicate_regular_run_same_scope_rejected(): void
    {
        // REGRESI: perilaku lama dipertahankan — dua run reguler seluruh-perusahaan identik → 422.
        $this->createRun([])->assertStatus(201);
        $this->createRun([])->assertStatus(422);
    }

    public function test_regular_and_grouped_run_same_period_both_allowed(): void
    {
        $group = PayrollGroup::create(['company_id' => $this->company->id, 'name' => 'Staf Bulanan']);

        // Run reguler (tanpa grup) → 201.
        $this->createRun([])->assertStatus(201);

        // Run ber-grup pada periode sama → 201 (ruang-lingkup berbeda, tak menabrak).
        $this->createRun(['payroll_group_id' => $group->id])->assertStatus(201);

        // Run ber-grup KEDUA untuk grup sama & periode sama → 422.
        $this->createRun(['payroll_group_id' => $group->id])->assertStatus(422);
    }

    public function test_grouped_run_only_calculates_group_members(): void
    {
        $group = PayrollGroup::create(['company_id' => $this->company->id, 'name' => 'Staf Bulanan']);
        // Hanya budi & siti anggota grup; joni di luar grup.
        $this->budi->update(['payroll_group_id' => $group->id]);
        $this->siti->update(['payroll_group_id' => $group->id]);

        $runId = $this->createRun(['payroll_group_id' => $group->id])->assertStatus(201)->json('data.id');
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate")->assertOk();

        $payroll = Payroll::findOrFail($runId);
        $userIds = $payroll->payslips()->pluck('user_id')->all();

        $this->assertEqualsCanonicalizing([$this->budi->id, $this->siti->id], $userIds, 'Run ber-grup hanya menghitung anggota grup.');
        $this->assertNotContains($this->joni->id, $userIds, 'Karyawan di luar grup tidak boleh ikut.');
        $this->assertSame(2, (int) $payroll->employee_count);
        $this->assertSame($group->id, (int) $payroll->payroll_group_id);
    }

    public function test_period_unique_index_is_rebuilt_to_scope_variant(): void
    {
        $names = array_map(
            fn ($idx) => $idx['name'],
            Schema::getIndexes('payrolls'),
        );

        $this->assertContains('payrolls_period_scope_unique', $names, 'Unique index ber-scope harus ada.');
        $this->assertNotContains('payrolls_period_branch_unique', $names, 'Unique index lama harus sudah dibuang.');
    }
}
