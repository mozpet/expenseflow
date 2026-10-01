<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeBpjsProfile;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Payroll\BpjsCalculatorService;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji fungsional modul Payroll (Fase 1 MVP):
 *  - CRUD komponen gaji & guard izin
 *  - Alur batch: create → calculate → submit → approve → mark-paid
 *  - Aritmetika slip (bruto − potongan = neto, PPh21 diterapkan)
 *  - Larangan self-approval (maker-checker)
 *  - Immutability setelah approve
 *  - Karyawan hanya melihat slip miliknya & hanya bila sudah final
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;   // penyiap (maker)
    private User $hrd;        // penyetuju (checker)
    private User $budi;       // karyawan, gaji 10jt
    private User $siti;       // karyawan, gaji 8jt
    private User $employee;   // karyawan tanpa izin payroll

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

        AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Jakarta',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $hrdRole       = Role::whereNull('company_id')->where('slug', 'hrd')->first();
        $employeeRole  = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id);
        $this->hrd       = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id);
        $this->budi      = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001');
        $this->siti      = $this->makeUser('Siti Karyawan', 'siti@mb.co.id', 'employee', $employeeRole->id, 'EMP-002');
        $this->employee  = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id, 'EMP-003');

        // Gaji pokok efektif untuk budi & siti (eko sengaja tanpa gaji → tidak masuk payroll)
        $this->setSalary($this->budi, 10_000_000, 'TK/0');
        $this->setSalary($this->siti, 8_000_000, 'K/1');
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

    /** Buat batch periode Juni 2026 (bukan akhir tahun → jalur TER bulanan). */
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

    private function submit(int $runId): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/submit")
            ->assertOk();
    }

    // ─────────────────────────── Tests ───────────────────────────

    public function test_finance_can_create_salary_component(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/components', [
                'code'      => 'tunj_makan',
                'name'      => 'Tunjangan Makan',
                'type'      => 'earning',
                'calc_type' => 'fixed',
            ]);

        $res->assertStatus(201)->assertJsonPath('data.code', 'TUNJ_MAKAN');
        $this->assertDatabaseHas('salary_components', [
            'company_id' => $this->company->id,
            'code'       => 'TUNJ_MAKAN',
            'type'       => 'earning',
        ]);
    }

    public function test_employee_cannot_access_payroll_dashboard(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/runs')
            ->assertStatus(403);
    }

    public function test_calculate_builds_payslips_with_correct_arithmetic(): void
    {
        $runId = $this->createRun();
        $this->calculate($runId);

        $payroll = Payroll::find($runId);
        $this->assertSame(Payroll::STATUS_CALCULATED, $payroll->status);
        $this->assertSame(2, (int) $payroll->employee_count); // hanya budi & siti (eko tanpa gaji)

        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();
        $this->assertNotNull($budiSlip);

        // Bruto = gaji pokok (tanpa komponen lain), dasar pajak = bruto kena pajak.
        $this->assertEqualsWithDelta(10_000_000, (float) $budiSlip->gross, 0.01);
        $this->assertEqualsWithDelta(10_000_000, (float) $budiSlip->taxable_income, 0.01);

        // Aritmetika: neto = bruto − total potongan; tanpa komponen lain, potongan = PPh21.
        $this->assertEqualsWithDelta(
            (float) $budiSlip->gross - (float) $budiSlip->total_deduction,
            (float) $budiSlip->net,
            0.01
        );
        $this->assertEqualsWithDelta((float) $budiSlip->pph21, (float) $budiSlip->total_deduction, 0.01);
        $this->assertGreaterThanOrEqual(0, (float) $budiSlip->pph21);
    }

    public function test_self_approval_is_forbidden(): void
    {
        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);

        // Penyiap (finance) mencoba menyetujui batch buatannya sendiri → 403.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")
            ->assertStatus(403);

        // Orang berbeda (hrd) boleh menyetujui.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")
            ->assertOk();

        $payroll = Payroll::find($runId);
        $this->assertSame(Payroll::STATUS_APPROVED, $payroll->status);
        $this->assertSame($this->hrd->id, (int) $payroll->approved_by);
    }

    public function test_approved_run_is_immutable(): void
    {
        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        // Hitung ulang batch approved → ditolak.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate")
            ->assertStatus(422);

        // Hapus batch approved → ditolak.
        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/runs/{$runId}")
            ->assertStatus(422);
    }

    public function test_employee_sees_only_own_finalized_payslip(): void
    {
        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);

        // Sebelum disetujui (status submitted) → karyawan belum boleh melihat.
        $this->actingAs($this->budi, 'sanctum')
            ->getJson('/api/v1/employee/payslips')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Setujui batch (oleh hrd) → slip menjadi final.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        // Budi kini melihat 1 slip (miliknya saja).
        $this->actingAs($this->budi, 'sanctum')
            ->getJson('/api/v1/employee/payslips')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();
        $sitiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->siti->id)->first();

        // Boleh buka slip sendiri.
        $this->actingAs($this->budi, 'sanctum')
            ->getJson("/api/v1/employee/payslips/{$budiSlip->id}")
            ->assertOk();

        // Tidak boleh buka slip karyawan lain → 404.
        $this->actingAs($this->budi, 'sanctum')
            ->getJson("/api/v1/employee/payslips/{$sitiSlip->id}")
            ->assertStatus(404);
    }

    public function test_full_flow_mark_paid_locks_run(): void
    {
        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/mark-paid")
            ->assertOk();

        $payroll = Payroll::find($runId);
        $this->assertSame(Payroll::STATUS_PAID, $payroll->status);
        $this->assertSame($this->finance->id, (int) $payroll->paid_by);
        $this->assertDatabaseHas('payslips', ['payroll_id' => $runId, 'status' => 'paid']);

        // Fase 5: notifikasi (in-app) terkirim ke tiap karyawan saat payroll ditandai dibayar.
        $this->assertDatabaseHas('notifications', ['user_id' => $this->budi->id, 'type' => 'payroll_paid']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->siti->id, 'type' => 'payroll_paid']);
    }

    public function test_bpjs_is_calculated_for_participant_with_wage_cap(): void
    {
        // Karyawan peserta BPJS dengan upah di atas cap (15jt) → uji batas upah Kes & JP.
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();
        $rina = $this->makeUser('Rina Peserta', 'rina@mb.co.id', 'employee', $employeeRole->id, 'EMP-004');
        $this->setSalary($rina, 15_000_000, 'TK/0');

        EmployeeBpjsProfile::create([
            'company_id'     => $this->company->id,
            'user_id'        => $rina->id,
            'has_bpjs_kes'   => true,
            'has_bpjs_tk'    => true,
            'has_jkp'        => true,
            'jkk_risk_class' => 1,
        ]);

        $runId = $this->createRun();
        $this->calculate($runId);

        $rinaSlip = Payslip::where('payroll_id', $runId)->where('user_id', $rina->id)->first();
        $this->assertNotNull($rinaSlip);

        // Kesehatan karyawan 1% dari upah dibatasi cap 12jt = 120.000.
        $kesItem = PayslipItem::where('payslip_id', $rinaSlip->id)
            ->where('code', BpjsCalculatorService::CODE_KES_EMP)->first();
        $this->assertNotNull($kesItem);
        $this->assertEqualsWithDelta(120_000, (float) $kesItem->amount, 0.01);

        // JHT karyawan 2% dari upah riil (tanpa cap) = 300.000.
        $jhtItem = PayslipItem::where('payslip_id', $rinaSlip->id)
            ->where('code', BpjsCalculatorService::CODE_JHT_EMP)->first();
        $this->assertNotNull($jhtItem);
        $this->assertEqualsWithDelta(300_000, (float) $jhtItem->amount, 0.01);

        // JP karyawan 1% dari upah dibatasi cap 10.042.300 = 100.423.
        $jpItem = PayslipItem::where('payslip_id', $rinaSlip->id)
            ->where('code', BpjsCalculatorService::CODE_JP_EMP)->first();
        $this->assertNotNull($jpItem);
        $this->assertEqualsWithDelta(100_423, (float) $jpItem->amount, 0.01);

        // Total potongan BPJS karyawan = 120.000 + 300.000 + 100.423 = 520.423.
        $this->assertEqualsWithDelta(520_423, (float) $rinaSlip->bpjs_employee_total, 0.01);

        // Iuran perusahaan tercatat (bukan pengurang neto) & > 0.
        $this->assertGreaterThan(0, (float) $rinaSlip->bpjs_company_total);

        // Neto = bruto − total potongan; total potongan = PPh21 + BPJS karyawan (tanpa absen/kasbon).
        $this->assertEqualsWithDelta(
            (float) $rinaSlip->pph21 + (float) $rinaSlip->bpjs_employee_total,
            (float) $rinaSlip->total_deduction,
            0.01
        );
        $this->assertEqualsWithDelta(
            (float) $rinaSlip->gross - (float) $rinaSlip->total_deduction,
            (float) $rinaSlip->net,
            0.01
        );

        // Header batch mengakumulasi iuran perusahaan & karyawan.
        $payroll = Payroll::find($runId);
        $this->assertGreaterThan(0, (float) $payroll->total_bpjs_company);
        $this->assertEqualsWithDelta(520_423, (float) $payroll->total_bpjs_employee, 0.01);

        // Konsolidasi masa pajak terbentuk untuk rina.
        $this->assertDatabaseHas('employee_tax_period_totals', [
            'user_id'   => $rina->id,
            'tax_year'  => 2026,
            'tax_month' => 6,
        ]);

        // Jejak perhitungan tercatat (termasuk langkah BPJS Kesehatan).
        $this->assertDatabaseHas('payslip_calculation_steps', [
            'payslip_id' => $rinaSlip->id,
            'step_code'  => 'BPJS_KES',
        ]);
        $this->assertGreaterThan(0, $rinaSlip->calculationSteps()->count());
    }

    public function test_employee_without_bpjs_profile_has_no_bpjs_deduction(): void
    {
        // budi tidak punya profil BPJS → tidak ada potongan BPJS (regresi Fase 1 tetap hijau).
        $runId = $this->createRun();
        $this->calculate($runId);

        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();
        $this->assertEqualsWithDelta(0, (float) $budiSlip->bpjs_employee_total, 0.01);
        $this->assertEqualsWithDelta(0, (float) $budiSlip->bpjs_company_total, 0.01);
        $this->assertSame(0, PayslipItem::where('payslip_id', $budiSlip->id)->where('source', 'bpjs')->count());
    }

    public function test_finance_can_save_bpjs_profile_and_number_is_masked(): void
    {
        // Simpan profil BPJS via endpoint → tersimpan, nomor termasking di response,
        // dan plaintext TIDAK bocor (tersimpan terenkripsi di kolom).
        $res = $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/salaries/{$this->budi->id}/bpjs-profile", [
                'has_bpjs_kes'   => true,
                'has_bpjs_tk'    => true,
                'has_jkp'        => true,
                'jkk_risk_class' => 3,
                'bpjs_kes_no'    => '0001234567890',
                'bpjs_tk_no'     => '9876543210001',
            ]);

        $res->assertOk()
            ->assertJsonPath('data.has_bpjs_kes', true)
            ->assertJsonPath('data.has_bpjs_tk', true)
            ->assertJsonPath('data.jkk_risk_class', 3);

        // Nomor dikirim termasking (bukan plaintext) — hanya 4 digit terakhir terlihat.
        $this->assertStringContainsString('7890', $res->json('data.bpjs_kes_no_masked'));
        $this->assertStringNotContainsString('0001234567890', $res->json('data.bpjs_kes_no_masked'));

        // Kolom DB menyimpan ciphertext, bukan digit mentah.
        $profile = EmployeeBpjsProfile::where('user_id', $this->budi->id)->first();
        $this->assertNotNull($profile);
        $this->assertTrue($profile->has_bpjs_kes);
        $this->assertSame(3, $profile->jkkRiskClass());
        $this->assertSame('0001234567890', $profile->bpjs_kes_no); // dekripsi via cast
        $raw = \Illuminate\Support\Facades\DB::table('employee_bpjs_profiles')
            ->where('user_id', $this->budi->id)->value('bpjs_kes_no');
        $this->assertNotSame('0001234567890', $raw); // di DB tersimpan terenkripsi

        // Detail gaji ikut mengembalikan profil BPJS termasking.
        $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/salaries/{$this->budi->id}")
            ->assertOk()
            ->assertJsonPath('data.bpjs_profile.jkk_risk_class', 3)
            ->assertJsonPath('data.bpjs_profile.has_bpjs_tk', true);
    }

    public function test_employee_cannot_save_bpjs_profile(): void
    {
        // Karyawan tanpa izin payroll tidak boleh mengubah profil BPJS orang lain → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/salaries/{$this->budi->id}/bpjs-profile", [
                'has_bpjs_kes' => true,
                'has_bpjs_tk'  => true,
            ])
            ->assertStatus(403);
    }

    public function test_payslip_detail_exposes_bpjs_deductions_and_calculation_trace(): void
    {
        // Peserta BPJS → detail slip dashboard menampilkan potongan BPJS karyawan
        // dan jejak perhitungan (untuk dirender FE web & mobile).
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();
        $rina = $this->makeUser('Rina Peserta', 'rina@mb.co.id', 'employee', $employeeRole->id, 'EMP-004');
        $this->setSalary($rina, 15_000_000, 'TK/0');
        EmployeeBpjsProfile::create([
            'company_id'     => $this->company->id,
            'user_id'        => $rina->id,
            'has_bpjs_kes'   => true,
            'has_bpjs_tk'    => true,
            'has_jkp'        => true,
            'jkk_risk_class' => 1,
        ]);

        $runId = $this->createRun();
        $this->calculate($runId);
        $rinaSlip = Payslip::where('payroll_id', $runId)->where('user_id', $rina->id)->first();

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/payslips/{$rinaSlip->id}")
            ->assertOk();

        // Header BPJS ada pada objek payslip.
        $res->assertJsonPath('data.payslip.bpjs_employee_total', fn ($v) => (float) $v > 0);

        // Ada minimal satu potongan bersumber BPJS di daftar deductions.
        $deductions = collect($res->json('data.deductions'));
        $this->assertTrue($deductions->contains(fn ($d) => ($d['source'] ?? null) === 'bpjs'));

        // Jejak perhitungan terekspos & memuat langkah BPJS Kesehatan.
        $steps = collect($res->json('data.calculation_steps'));
        $this->assertTrue($steps->isNotEmpty());
        $this->assertTrue($steps->contains(fn ($s) => ($s['step_code'] ?? null) === 'BPJS_KES'));
    }
}
