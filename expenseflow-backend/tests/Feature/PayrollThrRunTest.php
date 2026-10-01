<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxPeriodTotal;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Payroll\Gl\PayrollGlComposer;
use App\Services\Payroll\Tax\AnnualTaxAggregator;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 6 — Run THR terpisah (Tunjangan Hari Raya, Permenaker 6/2016 + PMK 168/2023).
 *
 * Membuktikan:
 *  (i)   Slip THR hanya berisi 1 earning `source='thr'` (+ 1 potongan `source='tax'`
 *        bila PPh21 terutang), tanpa gaji pokok/BPJS/lembur; `bpjs_*_total = 0`;
 *        nominal penuh (masa kerja ≥12 bln) & pro-rata (masa kerja < 12 bln) benar.
 *  (ii)  Run reguler + run THR periode sama SAMA-SAMA persist (tanpa 422); THR kedua → 422.
 *  (iii) REGRESI hazard-2: regular→THR→recalc reguler TIDAK menol-kan kolom irregular;
 *        kedua total (bruto & PPh21) tetap konsisten dari 4 kontributor.
 *  (iv)  1721-A1 `bruto_irregular` mencerminkan THR (tanpa mencemari `bruto_regular`).
 *  (v)   Run Desember year-end: `prior_taxable_gross` rekonsiliasi Pasal-17 memuat
 *        bulan THR (payslip THR yang sudah approved ikut ke priorYearAccumulation).
 *  (vi)  Jurnal GL run THR seimbang & memakai pos `expense_thr` (tanpa beban BPJS).
 */
class PayrollThrRunTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $hrd;
    private User $budi; // masa kerja ≥ 12 bln (joined_date NULL) → THR penuh.
    private User $sari; // bergabung 2026-02-01 → pro-rata 4/12.

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

        $this->finance = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id, null, null);
        $this->hrd     = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id, null, null);

        // Budi: tanpa joined_date → masa kerja diasumsikan ≥ 12 bln → THR = 1× upah = 10jt.
        $this->budi = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001', null);
        $this->setSalary($this->budi, 10_000_000);

        // Sari: bergabung 2026-02-01 → masa kerja s.d. 2026-06-30 = 4 bln → pro-rata 4/12 × 12jt = 4jt.
        $this->sari = $this->makeUser('Sari Karyawan', 'sari@mb.co.id', 'employee', $employeeRole->id, 'EMP-002', '2026-02-01');
        $this->setSalary($this->sari, 12_000_000);
    }

    private function makeUser(string $name, string $email, string $role, int $roleId, ?string $code, ?string $joined): User
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
            'joined_date'   => $joined,
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

    /** POST /runs dgn default periode Juni 2026. */
    private function createRun(array $payload = [])
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', array_merge(['period_month' => 6, 'period_year' => 2026], $payload));
    }

    private function calculate(int $runId, array $options = [])
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate", $options);
    }

    private function submit(int $runId)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/submit");
    }

    /** Maker-checker: penyetuju (HRD) berbeda dari penyiap (Finance). */
    private function approve(int $runId)
    {
        return $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve");
    }

    private function taxTotal(User $user, int $month = 6): EmployeeTaxPeriodTotal
    {
        return EmployeeTaxPeriodTotal::where('user_id', $user->id)
            ->where('tax_year', 2026)
            ->where('tax_month', $month)
            ->firstOrFail();
    }

    public function test_thr_payslip_only_contains_thr_and_tax_lines(): void
    {
        $runId = $this->createRun(['run_type' => 'thr'])->assertStatus(201)->json('data.id');
        $this->calculate($runId)->assertOk();

        $payroll = Payroll::findOrFail($runId);
        $this->assertTrue($payroll->isThr(), 'Batch harus bertipe THR.');

        // ── Budi: THR penuh = 1× upah sebulan (10jt). ──────────────────────────────
        $slip = $payroll->payslips()->where('user_id', $this->budi->id)->firstOrFail();
        $this->assertEquals(10_000_000, (float) $slip->gross, 'THR penuh = 1× upah sebulan.');
        $this->assertEquals(10_000_000, (float) $slip->taxable_income);
        $this->assertEquals(0, (float) $slip->basic_salary, 'Slip THR tidak memuat gaji pokok.');
        $this->assertEquals(0, (float) $slip->bpjs_company_total, 'THR bukan basis iuran BPJS.');
        $this->assertEquals(0, (float) $slip->bpjs_employee_total);

        $items = $slip->items()->get();
        $earnings = $items->where('type', PayslipItem::TYPE_EARNING);
        $deductions = $items->where('type', PayslipItem::TYPE_DEDUCTION);

        // Tepat 1 baris pendapatan: THR.
        $this->assertCount(1, $earnings, 'Slip THR hanya boleh punya 1 baris pendapatan.');
        $this->assertSame('thr', $earnings->first()->source);
        $this->assertEquals(10_000_000, (float) $earnings->first()->amount);

        // Potongan (bila ada) HANYA PPh21 THR (source='tax').
        foreach ($deductions as $d) {
            $this->assertSame('tax', $d->source, 'Slip THR hanya boleh memuat potongan pajak.');
        }
        $this->assertGreaterThan(0, (float) $slip->pph21, 'PPh21 THR pada 10jt harus > 0 (TER marginal/thr-only).');
        $this->assertEquals((float) $slip->gross - (float) $slip->pph21, (float) $slip->net, 'net = THR − PPh21 THR.');

        // Tidak boleh ada komponen upah rutin apa pun.
        $forbidden = ['basic', 'fixed', 'overtime', 'receipt', 'attendance', 'loan', 'bpjs', 'adjustment'];
        $this->assertEmpty(
            $items->whereIn('source', $forbidden),
            'Slip THR tidak boleh memuat gaji pokok/tunjangan/lembur/BPJS/kasbon/absen/penyesuaian.'
        );

        // Jejak perhitungan memuat langkah THR & PPh21 THR.
        $steps = $slip->calculationSteps()->pluck('step_code')->all();
        $this->assertContains(PayslipCalculationStep::STEP_THR, $steps);
        $this->assertContains(PayslipCalculationStep::STEP_PPH21_THR, $steps);

        // ── Sari: THR pro-rata 4/12 × 12jt = 4jt. ──────────────────────────────────
        $sariSlip = $payroll->payslips()->where('user_id', $this->sari->id)->firstOrFail();
        $this->assertEquals(4_000_000, (float) $sariSlip->gross, 'THR pro-rata masa kerja 4 bln (4/12 × 12jt).');
    }

    public function test_regular_and_thr_run_same_period_both_persist(): void
    {
        // Run reguler & run THR pada periode sama SAMA-SAMA boleh (ruang-lingkup jenis berbeda).
        $this->createRun([])->assertStatus(201);
        $this->createRun(['run_type' => 'thr'])->assertStatus(201);

        // Run THR KEDUA untuk periode sama → 422 (duplikat ruang-lingkup).
        $this->createRun(['run_type' => 'thr'])->assertStatus(422);

        $this->assertSame(1, Payroll::where('company_id', $this->company->id)->where('run_type', 'regular')->count());
        $this->assertSame(1, Payroll::where('company_id', $this->company->id)->where('run_type', 'thr')->count());
    }

    public function test_regular_run_does_not_clobber_thr_irregular_totals(): void
    {
        // 1) Run reguler bulan 6 → mengisi kolom teratur; irregular masih 0.
        $regId = $this->createRun([])->assertStatus(201)->json('data.id');
        $this->calculate($regId)->assertOk();

        $before = $this->taxTotal($this->budi);
        $regularGross = (float) $before->regular_gross;
        $regularPph21 = (float) $before->regular_pph21;
        $this->assertGreaterThan(0, $regularGross);
        $this->assertEquals(0, (float) $before->irregular_gross, 'Sebelum THR, kolom tidak teratur = 0.');
        $this->assertEquals(0, (float) $before->irregular_pph21);

        // 2) Run THR bulan 6 → mengisi kolom TIDAK teratur; kolom teratur harus lestari.
        $thrId = $this->createRun(['run_type' => 'thr'])->assertStatus(201)->json('data.id');
        $this->calculate($thrId)->assertOk();

        $mid = $this->taxTotal($this->budi);
        $irregularGross = (float) $mid->irregular_gross;
        $irregularPph21 = (float) $mid->irregular_pph21;
        $this->assertEquals(10_000_000, $irregularGross, 'THR masuk sebagai bruto tidak teratur.');
        $this->assertGreaterThan(0, $irregularPph21, 'PPh21 THR (TER marginal) harus > 0.');
        $this->assertEquals($regularGross, (float) $mid->regular_gross, 'Run THR TIDAK mengubah bruto reguler.');
        $this->assertEquals($regularPph21, (float) $mid->regular_pph21, 'Run THR TIDAK mengubah PPh21 reguler.');
        $this->assertEquals(round($regularGross + $irregularGross, 2), (float) $mid->total_taxable_gross);
        $this->assertEquals(round($regularPph21 + $irregularPph21, 2), (float) $mid->total_pph21_withheld);

        // 3) Recalc run reguler bulan 6 — INTI hazard-2: irregular TIDAK boleh ter-clobber jadi 0.
        $this->calculate($regId)->assertOk();

        $after = $this->taxTotal($this->budi);
        $this->assertEquals($regularGross, (float) $after->regular_gross, 'regular_gross tetap setelah rekalkulasi.');
        $this->assertEquals($regularPph21, (float) $after->regular_pph21);
        $this->assertEquals($irregularGross, (float) $after->irregular_gross, 'irregular_gross TIDAK ter-clobber (bug clobber teratasi).');
        $this->assertEquals($irregularPph21, (float) $after->irregular_pph21, 'irregular_pph21 TIDAK ter-clobber.');
        $this->assertEquals(round($regularGross + $irregularGross, 2), (float) $after->total_taxable_gross);
        $this->assertEquals(round($regularPph21 + $irregularPph21, 2), (float) $after->total_pph21_withheld);
    }

    public function test_annual_1721a1_reflects_thr_in_bruto_irregular(): void
    {
        $thrId = $this->createRun(['run_type' => 'thr'])->assertStatus(201)->json('data.id');
        $this->calculate($thrId)->assertOk();

        $row = (new AnnualTaxAggregator())->forEmployee($this->company->id, $this->budi->id, 2026);
        $this->assertNotNull($row);
        $this->assertEquals(10_000_000, (float) $row['bruto_irregular'], 'THR muncul sebagai bruto tidak teratur di 1721-A1.');
        $this->assertEquals(0, (float) $row['bruto_regular'], 'Tanpa run reguler, bruto reguler = 0.');
        $this->assertEquals(10_000_000, (float) $row['bruto'], 'Total bruto setahun = THR.');
    }

    public function test_year_end_prior_gross_includes_thr_month(): void
    {
        // THR bulan 6 → calculate → submit → approve (payslip menjadi 'approved').
        $thrId = $this->createRun(['run_type' => 'thr', 'period_month' => 6])->assertStatus(201)->json('data.id');
        $this->calculate($thrId)->assertOk();
        $this->submit($thrId)->assertOk();
        $this->approve($thrId)->assertOk();

        $thrSlip = Payslip::where('payroll_id', $thrId)->where('user_id', $this->budi->id)->firstOrFail();
        $this->assertSame('approved', $thrSlip->status, 'Slip THR harus approved agar ikut priorYearAccumulation.');
        $thrGross = (float) $thrSlip->taxable_income; // = 10jt

        // Run reguler Desember (year-end otomatis) → rekonsiliasi Pasal-17.
        $decId = $this->createRun(['period_month' => 12])->assertStatus(201)->json('data.id');
        $this->calculate($decId)->assertOk();

        $decSlip = Payslip::where('payroll_id', $decId)->where('user_id', $this->budi->id)->firstOrFail();
        $step = $decSlip->calculationSteps()
            ->where('step_code', PayslipCalculationStep::STEP_PPH21_PASAL17)
            ->firstOrFail();

        $prior = (float) ($step->input_payload['prior_taxable_gross'] ?? -1);
        $this->assertEquals($thrGross, $prior, 'Bruto THR bulan 6 masuk ke akumulasi prior rekonsiliasi Desember.');
    }

    public function test_thr_run_gl_journal_is_balanced(): void
    {
        $thrId = $this->createRun(['run_type' => 'thr'])->assertStatus(201)->json('data.id');
        $this->calculate($thrId)->assertOk();
        $payroll = Payroll::findOrFail($thrId);

        $journal = (new PayrollGlComposer())->compose($payroll);

        $this->assertTrue($journal['balanced'], 'Jurnal GL run THR harus seimbang.');
        $this->assertEqualsWithDelta($journal['total_debit'], $journal['total_credit'], 0.01);

        $keys = array_column($journal['lines'], 'key');
        $this->assertContains('expense_thr', $keys, 'Beban THR harus muncul di sisi debit.');
        $this->assertContains('payable_net', $keys, 'THR bersih (kredit) harus muncul.');
        $this->assertNotContains('expense_bpjs_company', $keys, 'Run THR tidak boleh memunculkan beban BPJS perusahaan.');

        // Nominal beban THR = total bruto THR se-run.
        $thrLine = collect($journal['lines'])->firstWhere('key', 'expense_thr');
        $this->assertEquals((float) $payroll->total_gross, (float) $thrLine['debit'], 'Beban THR = total bruto THR.');
    }
}
