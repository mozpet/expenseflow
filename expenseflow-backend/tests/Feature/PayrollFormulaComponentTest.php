<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeSalaryComponent;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji integrasi komponen gaji bertipe FORMULA (Fase 6 §6.A) — Stage 2:
 *  - Persistensi & validasi endpoint (rumus valid/invalid, variabel tak dikenal,
 *    kode terpesan, ketergantungan melingkar, formula wajib).
 *  - Endpoint alat bantu validate-formula.
 *  - Wiring ke PayrollCalculator: rumus dievaluasi menjadi baris slip + jejak,
 *    menghormati pajak, urutan ketergantungan, rujukan kode komponen tetap, dan
 *    diagnostik non-fatal saat evaluasi gagal.
 */
class PayrollFormulaComponentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $budi; // karyawan, gaji 10jt

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create([
            'name'      => 'PT Formula Sejahtera',
            'email'     => 'info@formula.co.id',
            'phone'     => '021-99887766',
            'address'   => 'Jl. Rumus No. 6, Jakarta',
            'is_active' => true,
        ]);

        AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Pusat',
            'office_latitude'        => -6.2,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance = $this->makeUser('Andi Finance', 'andi@formula.co.id', 'finance', $financeRole->id);
        $this->budi    = $this->makeUser('Budi Karyawan', 'budi@formula.co.id', 'employee', $employeeRole->id, 'EMP-001');

        $this->setSalary($this->budi, 10_000_000, 'TK/0');
    }

    private function makeUser(string $name, string $email, string $role, int $roleId, ?string $code = null): User
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

    private function setSalary(User $user, int $basic, string $ptkp): void
    {
        EmployeeSalary::create([
            'company_id'     => $this->company->id,
            'user_id'        => $user->id,
            'basic_salary'   => $basic,
            'effective_date' => '2026-01-01',
            'is_active'      => true,
            'created_by'     => $this->finance->id,
        ]);

        EmployeeTaxProfile::create([
            'company_id'  => $this->company->id,
            'user_id'     => $user->id,
            'ptkp_status' => $ptkp,
            'has_npwp'    => true,
            'tax_method'  => 'gross',
        ]);
    }

    /** Buat komponen master (fixed atau formula) langsung di DB. */
    private function makeComponent(string $code, string $type, string $calcType, ?string $formula = null): SalaryComponent
    {
        return SalaryComponent::create([
            'company_id'  => $this->company->id,
            'code'        => strtoupper($code),
            'name'        => ucwords(str_replace('_', ' ', strtolower($code))),
            'type'        => $type,
            'calc_type'   => $calcType,
            'formula_dsl' => $formula,
            'is_taxable'  => $type === SalaryComponent::TYPE_EARNING,
            'is_active'   => true,
        ]);
    }

    /** Pasang komponen ke karyawan (berlaku sejak awal 2026). */
    private function assign(User $user, SalaryComponent $component, float $amount = 0): void
    {
        EmployeeSalaryComponent::create([
            'company_id'          => $this->company->id,
            'user_id'             => $user->id,
            'salary_component_id' => $component->id,
            'amount'              => $amount,
            'effective_date'      => '2026-01-01',
            'is_active'           => true,
        ]);
    }

    private function createRun(): int
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', [
                'period_month' => 6,
                'period_year'  => 2026,
            ]);
        $res->assertStatus(201);

        return $res->json('data.id');
    }

    private function calculate(int $runId): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate")
            ->assertOk();
    }

    private function budiSlip(int $runId): Payslip
    {
        return Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();
    }

    // ─────────────── Persistensi & validasi endpoint ───────────────

    public function test_finance_dapat_membuat_komponen_formula(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components', [
                'code'        => 'tunj_kinerja',
                'name'        => 'Tunjangan Kinerja',
                'type'        => 'earning',
                'calc_type'   => 'formula',
                'formula_dsl' => 'BASIC_SALARY * 5%',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.calc_type', 'formula')
            ->assertJsonPath('data.formula_dsl', 'BASIC_SALARY * 5%');

        $this->assertDatabaseHas('salary_components', [
            'company_id'  => $this->company->id,
            'code'        => 'TUNJ_KINERJA',
            'calc_type'   => 'formula',
            'formula_dsl' => 'BASIC_SALARY * 5%',
        ]);
    }

    public function test_formula_wajib_diisi_untuk_tipe_formula(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components', [
                'code'      => 'kosong',
                'name'      => 'Kosong',
                'type'      => 'earning',
                'calc_type' => 'formula',
            ])
            ->assertStatus(422);
    }

    public function test_formula_sintaks_salah_ditolak(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components', [
                'code'        => 'ngawur',
                'name'        => 'Ngawur',
                'type'        => 'earning',
                'calc_type'   => 'formula',
                'formula_dsl' => 'BASIC_SALARY * ',
            ])
            ->assertStatus(422);
    }

    public function test_formula_variabel_tak_dikenal_ditolak(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components', [
                'code'        => 'misterius',
                'name'        => 'Misterius',
                'type'        => 'earning',
                'calc_type'   => 'formula',
                'formula_dsl' => 'BASIC_SALARY + BONUS_HANTU',
            ])
            ->assertStatus(422);
    }

    public function test_kode_terpesan_variabel_sistem_ditolak(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components', [
                'code'      => 'basic_salary', // == nama variabel sistem
                'name'      => 'Coba Pesan',
                'type'      => 'earning',
                'calc_type' => 'fixed',
            ])
            ->assertStatus(422);
    }

    public function test_ketergantungan_melingkar_ditolak(): void
    {
        // A merujuk B (B sudah ada sebagai komponen formula yang merujuk A) → siklus.
        $this->makeComponent('KOMP_B', 'earning', 'formula', 'KOMP_A + 1');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components', [
                'code'        => 'komp_a',
                'name'        => 'Komp A',
                'type'        => 'earning',
                'calc_type'   => 'formula',
                'formula_dsl' => 'KOMP_B + 1',
            ])
            ->assertStatus(422);
    }

    public function test_validate_formula_endpoint_melaporkan_valid(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components/validate-formula', [
                'formula' => 'BASIC_SALARY * 5% + PRESENT_DAYS * 25000',
                'sample'  => ['BASIC_SALARY' => 10_000_000, 'PRESENT_DAYS' => 20],
            ]);

        $res->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.sample_result', 1_000_000); // 500000 + 500000
        $this->assertContains('BASIC_SALARY', $res->json('data.variables'));
    }

    public function test_validate_formula_endpoint_menolak_input_berbahaya(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components/validate-formula', [
                'formula' => "system('id')",
            ])
            ->assertOk()
            ->assertJsonPath('data.ok', false);
    }

    // ─────────────── Wiring ke PayrollCalculator ───────────────

    public function test_komponen_formula_menghasilkan_baris_slip_dan_jejak(): void
    {
        $komp = $this->makeComponent('TUNJ_KINERJA', 'earning', 'formula', 'BASIC_SALARY * 5%');
        $this->assign($this->budi, $komp);

        $runId = $this->createRun();
        $this->calculate($runId);

        $slip = $this->budiSlip($runId);

        $item = PayslipItem::where('payslip_id', $slip->id)
            ->where('source', 'formula')
            ->where('salary_component_id', $komp->id)
            ->first();
        $this->assertNotNull($item, 'Baris slip formula harus ada.');
        $this->assertEqualsWithDelta(500_000, (float) $item->amount, 0.01);
        $this->assertSame('earning', $item->type);

        // Bruto kena pajak = gaji pokok + tunjangan formula kena pajak.
        $this->assertEqualsWithDelta(10_500_000, (float) $slip->taxable_income, 0.01);
        $this->assertEqualsWithDelta(10_500_000, (float) $slip->gross, 0.01);

        // Jejak perhitungan FORMULA tercatat.
        $step = PayslipCalculationStep::where('payslip_id', $slip->id)
            ->where('step_code', PayslipCalculationStep::STEP_FORMULA)
            ->first();
        $this->assertNotNull($step);
        $this->assertEqualsWithDelta(500_000, (float) $step->raw_result, 0.01);
    }

    public function test_komponen_formula_deduction_mengurangi_neto_tanpa_pajak(): void
    {
        $komp = $this->makeComponent('POT_KOPERASI', 'deduction', 'formula', 'BASIC_SALARY * 2%');
        $this->assign($this->budi, $komp);

        $runId = $this->createRun();
        $this->calculate($runId);

        $slip = $this->budiSlip($runId);

        $item = PayslipItem::where('payslip_id', $slip->id)
            ->where('source', 'formula')
            ->where('salary_component_id', $komp->id)
            ->first();
        $this->assertNotNull($item);
        $this->assertSame('deduction', $item->type);
        $this->assertEqualsWithDelta(200_000, (float) $item->amount, 0.01);

        // Potongan tidak memengaruhi bruto kena pajak (tetap gaji pokok saja).
        $this->assertEqualsWithDelta(10_000_000, (float) $slip->taxable_income, 0.01);
    }

    public function test_formula_merujuk_kode_komponen_tetap(): void
    {
        // Komponen tetap PENJUALAN 2jt, lalu komisi = 10% dari penjualan.
        $penjualan = $this->makeComponent('PENJUALAN', 'earning', 'fixed');
        $this->assign($this->budi, $penjualan, 2_000_000);

        $komisi = $this->makeComponent('KOMISI', 'earning', 'formula', 'PENJUALAN * 10%');
        $this->assign($this->budi, $komisi);

        $runId = $this->createRun();
        $this->calculate($runId);

        $slip = $this->budiSlip($runId);

        $item = PayslipItem::where('payslip_id', $slip->id)
            ->where('source', 'formula')
            ->where('salary_component_id', $komisi->id)
            ->first();
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(200_000, (float) $item->amount, 0.01);
    }

    public function test_formula_berantai_dievaluasi_urut_ketergantungan(): void
    {
        // TUNJ_B bergantung pada TUNJ_A; disimpan dengan urutan "terbalik" agar
        // memastikan wiring memakai dependencyOrder, bukan urutan sisip.
        $a = $this->makeComponent('TUNJ_A', 'earning', 'formula', 'BASIC_SALARY * 10%'); // 1.000.000
        $b = $this->makeComponent('TUNJ_B', 'earning', 'formula', 'TUNJ_A + 100000');    // 1.100.000
        $this->assign($this->budi, $b);
        $this->assign($this->budi, $a);

        $runId = $this->createRun();
        $this->calculate($runId);

        $slip = $this->budiSlip($runId);

        $itemA = PayslipItem::where('payslip_id', $slip->id)->where('salary_component_id', $a->id)->first();
        $itemB = PayslipItem::where('payslip_id', $slip->id)->where('salary_component_id', $b->id)->first();

        $this->assertNotNull($itemA);
        $this->assertNotNull($itemB);
        $this->assertEqualsWithDelta(1_000_000, (float) $itemA->amount, 0.01);
        $this->assertEqualsWithDelta(1_100_000, (float) $itemB->amount, 0.01);
    }

    public function test_evaluasi_gagal_saat_run_bersifat_non_fatal_dan_dicatat(): void
    {
        // HANTU adalah komponen tetap yang SAH (lolos validasi simpan sebagai variabel),
        // tapi TIDAK dipasang ke budi → saat run, variabel HANTU tak ada di konteks →
        // FormulaException → dicatat FORMULA_ERROR, slip lain tetap terhitung.
        $this->makeComponent('HANTU', 'earning', 'fixed');
        $ghost = $this->makeComponent('GHOST', 'earning', 'formula', 'HANTU + 1');
        $this->assign($this->budi, $ghost);

        $runId = $this->createRun();
        $this->calculate($runId);

        $slip = $this->budiSlip($runId);

        // Tidak ada baris slip untuk GHOST.
        $this->assertNull(
            PayslipItem::where('payslip_id', $slip->id)->where('salary_component_id', $ghost->id)->first()
        );

        // Ada jejak FORMULA_ERROR.
        $this->assertNotNull(
            PayslipCalculationStep::where('payslip_id', $slip->id)
                ->where('step_code', PayslipCalculationStep::STEP_FORMULA_ERROR)
                ->first()
        );

        // Slip tetap terbentuk normal (gaji pokok utuh).
        $this->assertEqualsWithDelta(10_000_000, (float) $slip->gross, 0.01);
    }
}
