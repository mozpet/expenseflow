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
use App\Models\SeveranceCase;
use App\Models\User;
use App\Services\Payroll\Gl\PayrollGlComposer;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6 Item 1 - Modul Exit Settlement (Pesangon PHK & Kompensasi PKWT).
 *
 * Membuktikan:
 *  (i)   PHK masa kerja 8 tahun: UP 9 bulan (PP 35/2021 Psl 40 ayat 2), UPMK 3 bulan
 *        (ayat 3), UPH = sisa cuti + 15% x (UP+UPMK) (ayat 4).
 *  (ii)  PPh 21 FINAL berlapis (PP 68/2009) dengan basis HANYA UP+UPMK+UPH.
 *  (iii) PKWT berakhir: TIDAK ada UP/UPMK; yang timbul uang kompensasi (Psl 15-17)
 *        sebagai penghasilan NON-final (is_taxable = true).
 *  (iv)  Slip pesangon bersih: tanpa gaji pokok/BPJS/lembur.
 *  (v)   PPh 21 Final TIDAK dikreditkan pada rekonsiliasi tahunan Pasal 17.
 *  (vi)  Jurnal GL seimbang & memakai pos `expense_severance`.
 *  (vii) Endpoint: preview murni-baca, scoping lintas-company 404, immutability 422.
 */
class PayrollSeveranceRunTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $hrd;
    private User $budi;      // PKWTT, bergabung 2018-01-01 -> 8 thn per 2026-06-30
    private User $sari;      // PKWT 2025-01-01 s.d. 2026-06-30 -> 18 bulan
    private User $employee;  // tanpa izin payroll

    private const WAGE_BUDI = 10000000;
    private const WAGE_SARI = 8000000;

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

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id, null, null);
        $this->hrd      = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id, null, null);
        $this->employee = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id, 'EMP-009', null);

        $this->budi = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001', '2018-01-01');
        $this->setSalary($this->budi, self::WAGE_BUDI);

        $this->sari = $this->makeUser('Sari Kontrak', 'sari@mb.co.id', 'employee', $employeeRole->id, 'EMP-002', '2025-01-01');
        $this->setSalary($this->sari, self::WAGE_SARI);
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
            'effective_date' => '2024-01-01',
            'is_active'      => true,
            'created_by'     => $this->finance->id,
        ]);

        EmployeeTaxProfile::create([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'npwp'        => '123456789012345',
            'ptkp_status' => 'TK/0',
            'has_npwp'    => true,
            'tax_method'  => 'gross',
        ]);
    }

    /** Buat batch bertipe severance periode Juni 2026. */
    private function createSeveranceRun(): int
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', [
                'period_month' => 6,
                'period_year'  => 2026,
                'run_type'     => Payroll::RUN_TYPE_SEVERANCE,
            ]);
        $res->assertStatus(201);

        return (int) $res->json('data.id');
    }

    private function createCase(array $payload)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/severance-cases', $payload);
    }

    private function calculate(int $runId)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate");
    }

    private function slipOf(int $runId, User $user): Payslip
    {
        return Payslip::where('payroll_id', $runId)->where('user_id', $user->id)->firstOrFail();
    }

    private function itemAmount(Payslip $slip, string $source): float
    {
        return (float) PayslipItem::where('payslip_id', $slip->id)->where('source', $source)->sum('amount');
    }

    // -- (i)+(ii) PHK: UP/UPMK/UPH + PPh 21 Final berlapis --------------------

    public function test_phk_produces_up_upmk_uph_and_final_tax(): void
    {
        $runId = $this->createSeveranceRun();

        $this->createCase([
            'payroll_id'                => $runId,
            'user_id'                   => $this->budi->id,
            'termination_type'          => SeveranceCase::TYPE_PHK,
            'termination_reason'        => 'Efisiensi perusahaan',
            'termination_date'          => '2026-06-30',
            'up_multiplier'             => 1,
            'upmk_multiplier'           => 1,
            'annual_leave_balance_days' => 10,
        ])->assertStatus(201);

        $this->calculate($runId)->assertOk();

        $slip = $this->slipOf($runId, $this->budi);

        // Masa kerja 8 thn (2018-01-01 -> 2026-06-30): UP 9 bulan, UPMK 3 bulan.
        $up   = self::WAGE_BUDI * 9;   // 90.000.000
        $upmk = self::WAGE_BUDI * 3;   // 30.000.000
        $this->assertEqualsWithDelta($up, $this->itemAmount($slip, 'severance_up'), 1);
        $this->assertEqualsWithDelta($upmk, $this->itemAmount($slip, 'severance_upmk'), 1);

        // UPH = sisa cuti (upah/25 x 10 hari) + 15% x (UP + UPMK).
        $leave   = round(self::WAGE_BUDI / 25 * 10);   // 4.000.000
        $housing = round(0.15 * ($up + $upmk));        // 18.000.000
        $this->assertEqualsWithDelta($leave + $housing, $this->itemAmount($slip, 'severance_uph'), 2);

        // PPh 21 FINAL berlapis atas basis 142.000.000:
        //   0-50jt    -> 0%  = 0
        //   50-100jt  -> 5%  = 2.500.000
        //   100-142jt -> 15% = 6.300.000
        $base = $up + $upmk + $leave + $housing;
        $this->assertEqualsWithDelta(142000000, $base, 2);
        $expectedTax = (50000000 * 0.05) + (42000000 * 0.15);
        $this->assertEqualsWithDelta($expectedTax, $this->itemAmount($slip, 'tax'), 2);

        $this->assertEqualsWithDelta($base, (float) $slip->gross, 2);
        $this->assertEqualsWithDelta($base - $expectedTax, (float) $slip->net, 2);

        // Jejak perhitungan memuat langkah pesangon & pajak final.
        $steps = PayslipCalculationStep::where('payslip_id', $slip->id)->pluck('step_code')->all();
        $this->assertContains(PayslipCalculationStep::STEP_SEVERANCE_UP, $steps);
        $this->assertContains(PayslipCalculationStep::STEP_SEVERANCE_UPMK, $steps);
        $this->assertContains(PayslipCalculationStep::STEP_SEVERANCE_UPH, $steps);
        $this->assertContains(PayslipCalculationStep::STEP_PPH21_FINAL, $steps);
    }

    // -- (iv) Slip pesangon bersih: tanpa gaji pokok/BPJS ---------------------

    public function test_severance_slip_has_no_basic_salary_or_bpjs(): void
    {
        $runId = $this->createSeveranceRun();
        $this->createCase([
            'payroll_id'       => $runId,
            'user_id'          => $this->budi->id,
            'termination_type' => SeveranceCase::TYPE_PHK,
            'termination_date' => '2026-06-30',
        ])->assertStatus(201);
        $this->calculate($runId)->assertOk();

        $slip = $this->slipOf($runId, $this->budi);

        $this->assertEqualsWithDelta(0, (float) $slip->basic_salary, 0.01);
        $this->assertEqualsWithDelta(0, (float) $slip->bpjs_company_total, 0.01);
        $this->assertEqualsWithDelta(0, (float) $slip->bpjs_employee_total, 0.01);

        $sources = PayslipItem::where('payslip_id', $slip->id)->pluck('source')->unique()->all();
        $this->assertNotContains('basic', $sources);
        $this->assertNotContains('bpjs', $sources);
        $this->assertNotContains('overtime', $sources);
        $this->assertNotContains('attendance', $sources);

        $payroll = Payroll::find($runId);
        $this->assertEqualsWithDelta(0, (float) $payroll->total_bpjs_company, 0.01);
        $this->assertEqualsWithDelta(0, (float) $payroll->total_bpjs_employee, 0.01);
    }

    // -- (iii) PKWT: kompensasi, bukan pesangon ------------------------------

    public function test_pkwt_end_pays_compensation_not_severance(): void
    {
        $runId = $this->createSeveranceRun();

        $this->createCase([
            'payroll_id'          => $runId,
            'user_id'             => $this->sari->id,
            'termination_type'    => SeveranceCase::TYPE_PKWT_END,
            'termination_date'    => '2026-06-30',
            'contract_start_date' => '2025-01-01',
            'contract_end_date'   => '2026-06-30',
        ])->assertStatus(201);

        $this->calculate($runId)->assertOk();

        $slip = $this->slipOf($runId, $this->sari);

        // PKWT tidak berhak UP/UPMK.
        $this->assertEqualsWithDelta(0, $this->itemAmount($slip, 'severance_up'), 0.01);
        $this->assertEqualsWithDelta(0, $this->itemAmount($slip, 'severance_upmk'), 0.01);

        // Uang kompensasi = (masa kontrak bulan penuh / 12) x upah sebulan.
        // 2025-01-01 s.d. 2026-06-30 = 17 bulan penuh (tgl 30 belum menggenapkan bulan ke-18).
        $this->assertEqualsWithDelta(round(8000000 * 17 / 12), $this->itemAmount($slip, 'severance_pkwt'), 2);

        // Kompensasi PKWT = penghasilan NON-final -> is_taxable = true.
        $pkwt = PayslipItem::where('payslip_id', $slip->id)->where('source', 'severance_pkwt')->firstOrFail();
        $this->assertTrue((bool) $pkwt->is_taxable);

        // Tidak ada PPh 21 Final karena basis final (UP+UPMK+UPH) nol.
        $this->assertEqualsWithDelta(0, $this->itemAmount($slip, 'tax'), 0.01);

        $steps = PayslipCalculationStep::where('payslip_id', $slip->id)->pluck('step_code')->all();
        $this->assertContains(PayslipCalculationStep::STEP_PKWT_COMPENSATION, $steps);
        $this->assertNotContains(PayslipCalculationStep::STEP_SEVERANCE_UP, $steps);
    }

    // -- (v) PPh 21 Final tidak mencemari rekonsiliasi Pasal 17 --------------

    public function test_final_tax_is_not_credited_to_annual_reconciliation(): void
    {
        $runId = $this->createSeveranceRun();
        $this->createCase([
            'payroll_id'       => $runId,
            'user_id'          => $this->budi->id,
            'termination_type' => SeveranceCase::TYPE_PHK,
            'termination_date' => '2026-06-30',
            'separation_pay'   => 5000000, // uang pisah = NON-final
        ])->assertStatus(201);
        $this->calculate($runId)->assertOk();

        $total = EmployeeTaxPeriodTotal::where('user_id', $this->budi->id)
            ->where('tax_year', 2026)
            ->where('tax_month', 6)
            ->first();

        $this->assertNotNull($total, 'Komponen non-final tetap tercatat pada total masa pajak.');

        // Hanya uang pisah (5jt) yang masuk bruto; UP/UPMK/UPH (objek final) TIDAK.
        $this->assertEqualsWithDelta(5000000, (float) $total->total_taxable_gross, 2);
        $this->assertEqualsWithDelta(5000000, (float) $total->irregular_gross, 2);

        // PPh 21 Final tidak dikreditkan sebagai pemotongan Pasal 21 tahunan.
        $this->assertEqualsWithDelta(0, (float) $total->total_pph21_withheld, 0.01);
    }

    // -- (vi) Jurnal GL seimbang & memakai pos beban pesangon ----------------

    public function test_gl_journal_is_balanced_and_uses_severance_account(): void
    {
        $runId = $this->createSeveranceRun();
        $this->createCase([
            'payroll_id'                => $runId,
            'user_id'                   => $this->budi->id,
            'termination_type'          => SeveranceCase::TYPE_PHK,
            'termination_date'          => '2026-06-30',
            'annual_leave_balance_days' => 5,
        ])->assertStatus(201);
        $this->calculate($runId)->assertOk();

        $journal = (new PayrollGlComposer())->compose(Payroll::find($runId));

        $this->assertTrue($journal['balanced'], 'Jurnal pesangon harus seimbang.');
        $this->assertEqualsWithDelta($journal['total_debit'], $journal['total_credit'], 0.01);

        $keys = array_column($journal['lines'], 'key');
        $this->assertContains('expense_severance', $keys);
        $this->assertNotContains('expense_salary', $keys);
        $this->assertNotContains('expense_bpjs_company', $keys);
        $this->assertContains('payable_pph21', $keys);
    }

    // -- (vii) Endpoint: preview, scoping, immutability ----------------------

    public function test_preview_returns_breakdown_without_persisting(): void
    {
        $runId = $this->createSeveranceRun();
        $caseId = (int) $this->createCase([
            'payroll_id'                => $runId,
            'user_id'                   => $this->budi->id,
            'termination_type'          => SeveranceCase::TYPE_PHK,
            'termination_date'          => '2026-06-30',
            'annual_leave_balance_days' => 10,
        ])->assertStatus(201)->json('data.id');

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/severance-cases/{$caseId}/preview")
            ->assertOk();

        // Uang dikirim sebagai STRING decimal:2 (konvensi lintas modul).
        $this->assertSame('90000000.00', $res->json('data.up'));
        $this->assertSame('30000000.00', $res->json('data.upmk'));
        $this->assertSame('22000000.00', $res->json('data.uph'));
        $this->assertSame(9, $res->json('data.up_months'));
        $this->assertSame(3, $res->json('data.upmk_months'));
        $this->assertSame('142000000.00', $res->json('data.final_tax_base'));
        $this->assertSame('8800000.00', $res->json('data.pph21_final'));

        // Murni-baca: tidak ada slip yang tercipta.
        $this->assertSame(0, Payslip::where('payroll_id', $runId)->count());
        $this->assertSame(
            SeveranceCase::STATUS_DRAFT,
            SeveranceCase::find($caseId)->status,
            'Preview tidak boleh mengubah status berkas.',
        );
    }

    public function test_case_from_other_company_is_not_visible(): void
    {
        $other = Company::create([
            'name' => 'PT Lain', 'email' => 'x@lain.co.id', 'phone' => '021-9', 'is_active' => true,
        ]);
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();
        $alien = User::create([
            'company_id' => $other->id, 'role_id' => $employeeRole->id, 'name' => 'Alien',
            'email' => 'alien@lain.co.id', 'password' => bcrypt('password'), 'role' => 'employee',
            'joined_date' => '2020-01-01', 'is_active' => true,
        ]);
        $alienCase = SeveranceCase::create([
            'company_id' => $other->id, 'user_id' => $alien->id, 'payroll_id' => null,
            'termination_type' => SeveranceCase::TYPE_PHK, 'termination_date' => '2026-06-30',
            'up_multiplier' => 1, 'upmk_multiplier' => 1, 'status' => SeveranceCase::STATUS_DRAFT,
        ]);

        // Ditolak berlapis: CompanyMiddleware menahan lebih dulu (403); bila lolos,
        // guard scoping di controller tetap menutup dengan 404. Keduanya = tidak bocor.
        $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/severance-cases/{$alienCase->id}")
            ->assertForbidden();

        $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/severance-cases/{$alienCase->id}/preview")
            ->assertForbidden();

        // Berkas perusahaan lain tidak muncul pada daftar.
        $listed = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/severance-cases')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($alienCase->id, array_column($listed, 'id'));

        // Karyawan perusahaan lain tidak dapat dijadikan subjek berkas.
        $runId = $this->createSeveranceRun();
        $this->createCase([
            'payroll_id'       => $runId,
            'user_id'          => $alien->id,
            'termination_type' => SeveranceCase::TYPE_PHK,
            'termination_date' => '2026-06-30',
        ])->assertStatus(422);
    }

    public function test_case_is_immutable_once_batch_approved(): void
    {
        $runId = $this->createSeveranceRun();
        $caseId = (int) $this->createCase([
            'payroll_id'       => $runId,
            'user_id'          => $this->budi->id,
            'termination_type' => SeveranceCase::TYPE_PHK,
            'termination_date' => '2026-06-30',
        ])->assertStatus(201)->json('data.id');

        $this->calculate($runId)->assertOk();
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/submit")->assertOk();
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/severance-cases/{$caseId}", [
                'user_id'          => $this->budi->id,
                'payroll_id'       => $runId,
                'termination_type' => SeveranceCase::TYPE_PHK,
                'termination_date' => '2026-06-30',
                'separation_pay'   => 99000000,
            ])->assertStatus(422);

        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/severance-cases/{$caseId}")
            ->assertStatus(422);

        $this->assertEqualsWithDelta(
            0,
            (float) SeveranceCase::find($caseId)->separation_pay,
            0.01,
            'Nilai berkas pada batch yang sudah disetujui tidak boleh berubah.',
        );
    }

    public function test_duplicate_case_per_batch_is_rejected(): void
    {
        $runId = $this->createSeveranceRun();
        $payload = [
            'payroll_id'       => $runId,
            'user_id'          => $this->budi->id,
            'termination_type' => SeveranceCase::TYPE_PHK,
            'termination_date' => '2026-06-30',
        ];

        $this->createCase($payload)->assertStatus(201);
        $this->createCase($payload)->assertStatus(422);
    }

    public function test_write_requires_manage_permission(): void
    {
        $runId = $this->createSeveranceRun();

        $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/severance-cases', [
                'payroll_id'       => $runId,
                'user_id'          => $this->budi->id,
                'termination_type' => SeveranceCase::TYPE_PHK,
                'termination_date' => '2026-06-30',
            ])->assertStatus(403);
    }
}
