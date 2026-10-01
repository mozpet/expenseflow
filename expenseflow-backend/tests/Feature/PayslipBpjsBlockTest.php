<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeBpjsProfile;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Payroll\Bpjs\EmployerBpjsBreakdown;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 2 — Rincian iuran BPJS DITANGGUNG PERUSAHAAN pada slip gaji (PDF).
 *
 * Blok informasional diturunkan dari jejak perhitungan (`payslip_calculation_steps`)
 * yang sudah persist — TANPA perubahan skema & TANPA hitung ulang kebijakan. Blok
 * ini BUKAN pengurang gaji bersih; penjumlahan komponen = `payslips.bpjs_company_total`.
 */
class PayslipBpjsBlockTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;   // izin manage payroll
    private User $hrd;        // checker (approve)
    private User $budi;       // peserta BPJS (KES + TK)
    private User $siti;       // TANPA profil BPJS

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

        $this->finance = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id, null);
        $this->hrd     = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id, null);
        $this->budi    = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001');
        $this->siti    = $this->makeUser('Siti Karyawan', 'siti@mb.co.id', 'employee', $employeeRole->id, 'EMP-002');

        $this->setSalary($this->budi, 10_000_000, 'TK/0');
        $this->setSalary($this->siti, 8_000_000, 'K/1');

        // Hanya Budi peserta BPJS (Kesehatan + Ketenagakerjaan).
        EmployeeBpjsProfile::create([
            'company_id'     => $this->company->id,
            'user_id'        => $this->budi->id,
            'has_bpjs_kes'   => true,
            'has_bpjs_tk'    => true,
            'has_jkp'        => true,
            'jkk_risk_class' => 1,
        ]);
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

    private function setSalary(User $user, int $basic, string $ptkp): void
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
            'ptkp_status' => $ptkp,
            'has_npwp'    => false,
            'tax_method'  => 'gross',
        ]);
    }

    /** Buat run 6/2026 → calculate → submit → approve → kembalikan runId. */
    private function approvedRun(): int
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', ['period_month' => 6, 'period_year' => 2026]);
        $res->assertStatus(201);
        $runId = $res->json('data.id');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate")->assertOk();
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/submit")->assertOk();
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        return $runId;
    }

    /** Susun data view slip identik dengan PayslipController::streamPdf(). */
    private function viewData(Payslip $payslip): array
    {
        $payslip->load(['items', 'calculationSteps', 'payroll:id,period_label,period_month,period_year']);

        return [
            'payslip'      => $payslip,
            'company'      => $this->company->fresh(),
            'earnings'     => $payslip->items->where('type', PayslipItem::TYPE_EARNING)->values(),
            'deductions'   => $payslip->items->where('type', PayslipItem::TYPE_DEDUCTION)->values(),
            'bpjsEmployer' => EmployerBpjsBreakdown::fromSteps($payslip->calculationSteps),
        ];
    }

    public function test_employer_breakdown_total_equals_payslip_company_total(): void
    {
        $runId = $this->approvedRun();
        $payslip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();
        $payslip->load('calculationSteps');

        $breakdown = EmployerBpjsBreakdown::fromSteps($payslip->calculationSteps);

        // Ada iuran perusahaan (Budi peserta BPJS) & identitas jumlah terjaga.
        $this->assertGreaterThan(0, (float) $payslip->bpjs_company_total);
        $this->assertNotEmpty($breakdown['components']);
        $this->assertEqualsWithDelta(
            (float) $payslip->bpjs_company_total,
            (float) $breakdown['total'],
            0.01,
            'Total rincian BPJS perusahaan harus SAMA dengan payslips.bpjs_company_total.'
        );

        // Penjumlahan komponen == total (konsistensi internal helper).
        $sum = array_sum(array_column($breakdown['components'], 'amount'));
        $this->assertEqualsWithDelta((float) $breakdown['total'], $sum, 0.01);
    }

    public function test_payslip_blade_renders_employer_bpjs_block(): void
    {
        $runId = $this->approvedRun();
        $payslip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();

        // Render blade langsung (teks di dalam PDF biner tak andal untuk di-assert).
        $html = view('payroll.payslip', $this->viewData($payslip))->render();

        $this->assertStringContainsString('Iuran BPJS Ditanggung Perusahaan', $html);
        $this->assertStringContainsString('bukan pengurang', $html);
        $this->assertStringContainsString('Total Iuran Ditanggung Perusahaan', $html);
        // Gaji bersih tetap terender apa adanya (blok tidak mengubahnya).
        $this->assertStringContainsString('GAJI BERSIH', $html);
    }

    public function test_payslip_without_bpjs_omits_block_and_keeps_net(): void
    {
        $runId = $this->approvedRun();
        $payslip = Payslip::where('payroll_id', $runId)->where('user_id', $this->siti->id)->firstOrFail();
        $payslip->load('calculationSteps');

        // Tanpa profil BPJS → tak ada langkah BPJS → helper kosong.
        $breakdown = EmployerBpjsBreakdown::fromSteps($payslip->calculationSteps);
        $this->assertSame(0.0, (float) $breakdown['total']);
        $this->assertEmpty($breakdown['components']);
        $this->assertEqualsWithDelta(0.0, (float) $payslip->bpjs_company_total, 0.01);

        // Blok BPJS absen; gaji bersih tetap terender.
        $html = view('payroll.payslip', $this->viewData($payslip))->render();
        $this->assertStringNotContainsString('Iuran BPJS Ditanggung Perusahaan', $html);
        $this->assertStringContainsString('GAJI BERSIH', $html);
    }

    public function test_dashboard_payslip_pdf_streams_ok(): void
    {
        $runId = $this->approvedRun();
        $payslip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();

        // Tanpa izin read payroll → 403 (karyawan lain).
        $this->actingAs($this->siti, 'sanctum')
            ->get("/api/v1/dashboard/payroll/payslips/{$payslip->id}/pdf")
            ->assertStatus(403);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/payslips/{$payslip->id}/pdf")
            ->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
    }
}
