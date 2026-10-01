<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxPeriodTotal;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\PayrollGlAccount;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji fungsional Payroll Fase 4 (lanjutan) — Pajak 1721-A1, Ekspor GL & Calculation-Trace.
 *
 *  - GL: pemetaan akun editable per-perusahaan (baca/simpan/reset + gating & validasi key);
 *    pratinjau jurnal DIJAMIN seimbang (Σdebit == Σkredit); ekspor CSV/JSON gated & teraudit.
 *  - 1721-A1: daftar/detail read-gated & HANYA npwp_masked (NPWP penuh tak pernah di JSON);
 *    angka tahunan benar; PDF & ekspor manage-gated + teraudit; NPWP penuh hanya di berkas.
 *  - Calculation-trace: langkah urut & lengkap; read-gated; lintas-perusahaan diblok.
 */
class PayrollPhase4TaxGlTraceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;   // izin manage payroll
    private User $hrd;        // checker (approve)
    private User $budi;       // gaji 10jt, TK/0, NPWP penuh diketahui
    private User $siti;       // gaji 8jt, K/1
    private User $employee;   // tanpa izin payroll

    /** NPWP penuh budi — dipakai untuk membuktikan kebocoran/masking. */
    private const BUDI_NPWP = '123456789012345';

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
        $hrdRole       = Role::whereNull('company_id')->where('slug', 'hrd')->first();
        $employeeRole  = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id, null, $this->company->id);
        $this->hrd       = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id, null, $this->company->id);
        $this->budi      = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001', $this->company->id);
        $this->siti      = $this->makeUser('Siti Karyawan', 'siti@mb.co.id', 'employee', $employeeRole->id, 'EMP-002', $this->company->id);
        $this->employee  = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id, 'EMP-003', $this->company->id);

        $this->setSalary($this->budi, 10_000_000, 'TK/0', self::BUDI_NPWP);
        $this->setSalary($this->siti, 8_000_000, 'K/1', null);
    }

    private function makeUser(string $name, string $email, string $role, int $roleId, ?string $code, int $companyId): User
    {
        return User::create([
            'company_id'    => $companyId,
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

    private function setSalary(User $user, int $basic, string $ptkp, ?string $npwp): void
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
            'npwp'        => $npwp,               // tersimpan terenkripsi.
            'ptkp_status' => $ptkp,
            'has_npwp'    => $npwp !== null,
            'tax_method'  => 'gross',
        ]);
    }

    /** Buat run 6/2026 → calculate → submit → approve → kembalikan runId (approved). */
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

    private function otherCompanyFinance(): User
    {
        $other = Company::create([
            'name' => 'PT Lain', 'email' => 'lain@x.co.id', 'phone' => '0', 'address' => '-', 'is_active' => true,
        ]);
        $financeRole = Role::whereNull('company_id')->where('slug', 'finance')->first();

        return $this->makeUser('Rina Finance', 'rina@lain.co.id', 'finance', $financeRole->id, null, $other->id);
    }

    // ═══════════════════════ Modul A — Pemetaan Akun GL ═══════════════════════

    public function test_gl_accounts_returns_canonical_buckets_with_defaults(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/gl-accounts')
            ->assertOk();

        $data = $res->json('data');
        $this->assertSame(count(config('payroll_gl.buckets')), count($data));

        $keys = array_column($data, 'key');
        $this->assertContains('expense_salary', $keys);
        $this->assertContains('payable_net', $keys);

        $salary = collect($data)->firstWhere('key', 'expense_salary');
        $this->assertSame('5100', $salary['account_code']);
        $this->assertSame('debit', $salary['side']);
        $this->assertFalse($salary['is_overridden']);
    }

    public function test_gl_accounts_read_forbidden_without_permission(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/gl-accounts')
            ->assertStatus(403);
    }

    public function test_gl_accounts_save_override_applies_and_is_manage_gated(): void
    {
        $payload = ['accounts' => [
            ['key' => 'payable_net', 'account_code' => '1102', 'account_name' => 'Bank BCA Operasional'],
        ]];

        // Karyawan biasa (tanpa manage) → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/gl-accounts', $payload)
            ->assertStatus(403);

        // Finance (manage) → tersimpan.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/gl-accounts', $payload)
            ->assertOk();

        $this->assertDatabaseHas('payroll_gl_accounts', [
            'company_id'   => $this->company->id,
            'key'          => 'payable_net',
            'account_code' => '1102',
            'account_name' => 'Bank BCA Operasional',
        ]);

        // Terlihat pada pembacaan berikutnya sbg override.
        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/gl-accounts')->assertOk();
        $net = collect($res->json('data'))->firstWhere('key', 'payable_net');
        $this->assertSame('1102', $net['account_code']);
        $this->assertTrue($net['is_overridden']);
        $this->assertSame('1101', $net['default_code']); // default tetap terbawa.
    }

    public function test_gl_accounts_save_rejects_unknown_key(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/gl-accounts', ['accounts' => [
                ['key' => 'bukan_key_kanonik', 'account_code' => '9999', 'account_name' => 'Ngawur'],
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accounts.0.key');

        $this->assertSame(0, PayrollGlAccount::where('company_id', $this->company->id)->count());
    }

    public function test_gl_accounts_reset_reverts_to_default(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/gl-accounts', ['accounts' => [
                ['key' => 'payable_net', 'account_code' => '1102', 'account_name' => 'Bank BCA'],
            ]])->assertOk();
        $this->assertSame(1, PayrollGlAccount::where('company_id', $this->company->id)->count());

        // Reset (manage) → hapus override.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/gl-accounts/reset')
            ->assertOk();
        $this->assertSame(0, PayrollGlAccount::where('company_id', $this->company->id)->count());

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/gl-accounts')->assertOk();
        $net = collect($res->json('data'))->firstWhere('key', 'payable_net');
        $this->assertSame('1101', $net['account_code']);
        $this->assertFalse($net['is_overridden']);
    }

    // ═══════════════════════ Modul A — Jurnal (preview/export) ═══════════════════════

    public function test_gl_preview_is_balanced(): void
    {
        $runId = $this->approvedRun();

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$runId}/gl-preview")
            ->assertOk();

        $data = $res->json('data');
        $this->assertTrue($data['balanced']);
        $this->assertEqualsWithDelta((float) $data['total_debit'], (float) $data['total_credit'], 0.01);
        $this->assertNotEmpty($data['lines']);

        // Debit = total_gross + total_bpjs_company (bukti seimbang di composer).
        $payroll = Payroll::find($runId);
        $expectedDebit = (float) $payroll->total_gross + (float) $payroll->total_bpjs_company;
        $this->assertEqualsWithDelta($expectedDebit, (float) $data['total_debit'], 0.01);

        // Kredit memuat pos gaji bersih = total_net.
        $net = collect($data['lines'])->firstWhere('key', 'payable_net');
        $this->assertNotNull($net);
        $this->assertEqualsWithDelta((float) $payroll->total_net, (float) $net['credit'], 0.01);
    }

    public function test_gl_preview_read_gated_and_cross_company_blocked(): void
    {
        $runId = $this->approvedRun();

        $this->actingAs($this->employee, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$runId}/gl-preview")
            ->assertStatus(403);

        // Lintas-perusahaan diblok CompanyMiddleware (route-model {payroll}).
        $this->actingAs($this->otherCompanyFinance(), 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$runId}/gl-preview")
            ->assertStatus(403);
    }

    public function test_gl_export_csv_is_gated_and_audited(): void
    {
        $runId = $this->approvedRun();

        // Tanpa manage → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$runId}/gl-export?format=csv")
            ->assertStatus(403);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$runId}/gl-export?format=csv")
            ->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $content = $res->getContent();
        $this->assertStringContainsString('Kode Akun', $content);
        $this->assertStringContainsString('TOTAL', $content);

        $this->assertDatabaseHas('activity_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_GL_EXPORTED',
        ]);
    }

    public function test_gl_export_json_and_invalid_format(): void
    {
        $runId = $this->approvedRun();

        $res = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$runId}/gl-export?format=json")
            ->assertOk();
        $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('balanced', $res->getContent());

        $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$runId}/gl-export?format=xml")
            ->assertStatus(422);
    }

    // ═══════════════════════ Modul B — 1721-A1 ═══════════════════════

    public function test_1721a1_index_masks_npwp_and_is_read_gated(): void
    {
        $this->approvedRun();

        // Tanpa read → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/tax/1721a1?tax_year=2026')
            ->assertStatus(403);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/tax/1721a1?tax_year=2026')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));

        $body = $res->getContent();
        // NPWP penuh TIDAK pernah bocor ke JSON; hanya termasking (4 digit terakhir).
        $this->assertStringNotContainsString(self::BUDI_NPWP, $body);
        $this->assertStringContainsString('2345', $body);

        // Field `npwp` (penuh) tak boleh ada di payload JSON.
        foreach ($res->json('data') as $row) {
            $this->assertArrayNotHasKey('npwp', $row);
            $this->assertArrayHasKey('npwp_masked', $row);
        }
    }

    public function test_1721a1_detail_returns_correct_annual_figures(): void
    {
        $this->approvedRun();

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/tax/1721a1/{$this->budi->id}?tax_year=2026")
            ->assertOk();
        $row = $res->json('data');

        // Sumber tunggal: employee_tax_period_totals (dikonsolidasi PayrollCalculator).
        $totals = EmployeeTaxPeriodTotal::where('company_id', $this->company->id)
            ->where('user_id', $this->budi->id)->where('tax_year', 2026)->get();

        $expectedBruto    = (float) $totals->sum('total_taxable_gross');
        $expectedWithheld = (float) $totals->sum('total_pph21_withheld');

        $this->assertSame(1, $row['months_count']); // hanya masa Juni.
        $this->assertEqualsWithDelta($expectedBruto, (float) $row['bruto'], 0.01);
        $this->assertEqualsWithDelta($expectedWithheld, (float) $row['pph21_dipotong'], 0.01);

        // Biaya jabatan = min(5% bruto, 6jt).
        $expectedBiaya = round(min($expectedBruto * 0.05, 6_000_000), 2);
        $this->assertEqualsWithDelta($expectedBiaya, (float) $row['biaya_jabatan'], 0.01);

        // PTKP TK/0 terisi; neto & pkp konsisten.
        $this->assertGreaterThan(0, (float) $row['ptkp']);
        $this->assertEqualsWithDelta(
            max(0.0, $expectedBruto - $expectedBiaya - (float) $row['iuran_pensiun']),
            (float) $row['neto'],
            0.01
        );

        // Termasking di JSON; NPWP penuh tak bocor.
        $this->assertStringNotContainsString(self::BUDI_NPWP, $res->getContent());
        $this->assertArrayNotHasKey('npwp', $row);
        $this->assertStringEndsWith('2345', (string) $row['npwp_masked']);
    }

    public function test_1721a1_detail_cross_company_returns_404(): void
    {
        $this->approvedRun();

        // {userId} bukan route-model → controller yang menolak (404) via scoping aggregator.
        $this->actingAs($this->otherCompanyFinance(), 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/tax/1721a1/{$this->budi->id}?tax_year=2026")
            ->assertStatus(404);
    }

    public function test_1721a1_pdf_is_manage_gated_and_audited(): void
    {
        $this->approvedRun();

        // Tanpa manage → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->get("/api/v1/dashboard/payroll/tax/1721a1/{$this->budi->id}/pdf?tax_year=2026")
            ->assertStatus(403);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/tax/1721a1/{$this->budi->id}/pdf?tax_year=2026")
            ->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));

        // Unduh berkas ber-PII = peristiwa sensitif → teraudit (SECURITY/WARNING).
        $this->assertDatabaseHas('activity_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_1721A1_DOWNLOADED',
            'severity'   => 'warning',
        ]);
    }

    public function test_1721a1_export_contains_full_npwp_and_is_gated_and_audited(): void
    {
        $this->approvedRun();

        // Tanpa manage → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/1721a1/export?tax_year=2026&format=csv')
            ->assertStatus(403);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/1721a1/export?tax_year=2026&format=csv')
            ->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $content = $res->getContent();
        // Berkas ekspor MEMUAT NPWP penuh (untuk impor Coretax) — inilah satu-satunya tempatnya.
        $this->assertStringContainsString(self::BUDI_NPWP, $content);
        $this->assertStringContainsString('Budi Karyawan', $content);

        $this->assertDatabaseHas('activity_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_1721A1_EXPORTED',
            'severity'   => 'warning',
        ]);

        // Format tak didukung → 422.
        $this->actingAs($this->finance, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/1721a1/export?tax_year=2026&format=pdf')
            ->assertStatus(422);
    }

    // ═══════════════════════ Modul C — Calculation-trace ═══════════════════════

    public function test_calculation_trace_returns_ordered_steps_and_is_read_gated(): void
    {
        $runId = $this->approvedRun();
        $payslip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();

        // Tanpa read → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/payslips/{$payslip->id}/calculation-trace")
            ->assertStatus(403);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/payslips/{$payslip->id}/calculation-trace")
            ->assertOk();
        $data = $res->json('data');

        $this->assertSame($payslip->id, $data['payslip']['id']);
        $this->assertNotEmpty($data['steps']);
        $this->assertNotEmpty($data['earnings']);

        // step_sequence menaik.
        $sequences = array_column($data['steps'], 'step_sequence');
        $sorted = $sequences;
        sort($sorted);
        $this->assertSame($sorted, $sequences);

        // Selalu ada langkah GROSS & NETT.
        $codes = array_column($data['steps'], 'step_code');
        $this->assertContains('GROSS', $codes);
        $this->assertContains('NETT', $codes);

        // Tak membocorkan NPWP (slip hanya menyimpan termasking).
        $this->assertStringNotContainsString(self::BUDI_NPWP, $res->getContent());
    }

    public function test_calculation_trace_cross_company_blocked(): void
    {
        $runId = $this->approvedRun();
        $payslip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();

        // Lintas-perusahaan diblok CompanyMiddleware (route-model {payslip}).
        $this->actingAs($this->otherCompanyFinance(), 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/payslips/{$payslip->id}/calculation-trace")
            ->assertStatus(403);
    }
}
