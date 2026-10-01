<?php

namespace Tests\Feature;

use App\Models\CurrencyRate;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 6 Item 3 - Penggajian Multi-Mata Uang & PPh 26 (ekspatriat).
 *
 * Membuktikan:
 *  (i)   Gaji USD dikonversi ke Rupiah memakai kurs efektif; seluruh kolom uang
 *        slip TETAP Rupiah, nominal valas disimpan pada kolom *_currency.
 *  (ii)  Kurs DIKUNCI pada batch (`payrolls.exchange_rates`) sehingga perubahan
 *        master kurs setelah kalkulasi tidak menggeser angka yang sudah dihitung.
 *  (iii) Kurs tidak tersedia -> batch DITOLAK 422 (tidak pernah diasumsikan 1:1).
 *  (iv)  Subjek Pajak Luar Negeri dipotong PPh 26 20% x bruto - tanpa PTKP/TER,
 *        dan tarif P3B hanya dipakai bila negara mitra terisi (bukti SKD/DGT).
 *  (v)   Karyawan dalam negeri pada batch yang sama tetap memakai PPh 21 biasa.
 *  (vi)  Master kurs: IDR ditolak, duplikat ditolak, baris global read-only,
 *        lintas-company tidak terjangkau, tulis = izin `manage`.
 */
class PayrollMultiCurrencyPph26Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $employee;
    private User $john;    // ekspatriat, gaji USD, subjek luar negeri
    private User $budi;    // WNI, gaji IDR, subjek dalam negeri

    private const RATE_JUN = 16000.0;
    private const USD_SALARY = 5000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create([
            'name' => 'PT Maju Bersama', 'email' => 'info@mb.co.id',
            'phone' => '021-1', 'address' => 'Jakarta', 'is_active' => true,
        ]);

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id);
        $this->employee = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id);
        $this->john     = $this->makeUser('John Expat', 'john@mb.co.id', 'employee', $employeeRole->id);
        $this->budi     = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id);
    }

    private function makeUser(string $name, string $email, string $role, int $roleId): User
    {
        return User::create([
            'company_id' => $this->company->id, 'role_id' => $roleId, 'name' => $name,
            'email' => $email, 'password' => bcrypt('password'), 'role' => $role,
            'department' => 'Umum', 'joined_date' => '2024-01-01', 'is_active' => true,
        ]);
    }

    /** Kurs berlaku 1 USD -> Rupiah (baris perusahaan). */
    private function seedRate(float $rate, string $effectiveDate = '2026-01-01'): CurrencyRate
    {
        return CurrencyRate::create([
            'company_id'     => $this->company->id,
            'currency'       => 'USD',
            'rate_to_idr'    => $rate,
            'effective_date' => $effectiveDate,
            'source'         => 'manual',
        ]);
    }

    private function setSalary(User $user, float $basic, string $currency = 'IDR'): void
    {
        EmployeeSalary::create([
            'company_id' => $user->company_id, 'user_id' => $user->id,
            'basic_salary' => $basic, 'effective_date' => '2026-01-01',
            'currency' => $currency, 'is_active' => true, 'created_by' => $this->finance->id,
        ]);
    }

    private function setTaxProfile(User $user, string $subjectType, ?string $country = null, ?float $treatyRate = null): void
    {
        EmployeeTaxProfile::create([
            'company_id' => $user->company_id, 'user_id' => $user->id,
            'npwp' => '123456789012345', 'has_npwp' => true,
            'ptkp_status' => 'TK/0', 'tax_method' => 'gross',
            'tax_subject_type' => $subjectType,
            'treaty_country' => $country,
            'treaty_rate' => $treatyRate,
        ]);
    }

    private function createRun(): int
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', ['period_month' => 6, 'period_year' => 2026]);
        $res->assertStatus(201);

        return (int) $res->json('data.id');
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

    // -- (i)+(ii) Konversi valas & penguncian kurs ---------------------------

    public function test_usd_salary_is_converted_to_idr_with_locked_rate(): void
    {
        $this->seedRate(self::RATE_JUN);
        $this->setSalary($this->john, self::USD_SALARY, 'USD');
        $this->setTaxProfile($this->john, EmployeeTaxProfile::SUBJECT_DOMESTIC);

        $runId = $this->createRun();
        $this->calculate($runId)->assertOk();

        $slip = $this->slipOf($runId, $this->john);
        $expectedIdr = self::USD_SALARY * self::RATE_JUN; // 80.000.000

        // Kolom uang slip TETAP Rupiah.
        $this->assertEqualsWithDelta($expectedIdr, (float) $slip->basic_salary, 1);

        // Nominal valas disimpan terpisah (dasar transfer valas).
        $this->assertSame('USD', $slip->currency);
        $this->assertEqualsWithDelta(self::RATE_JUN, (float) $slip->exchange_rate, 0.01);
        $this->assertEqualsWithDelta(self::USD_SALARY, (float) $slip->gross_currency, 0.01);

        // Jejak konversi terekam.
        $step = PayslipCalculationStep::where('payslip_id', $slip->id)
            ->where('step_code', PayslipCalculationStep::STEP_CURRENCY)->first();
        $this->assertNotNull($step, 'Langkah CURRENCY harus terekam untuk gaji valas.');

        // Kurs terkunci pada batch.
        $payroll = Payroll::find($runId);
        $this->assertSame(self::RATE_JUN, (float) ($payroll->exchange_rates['USD'] ?? 0));
    }

    public function test_locked_rate_survives_master_rate_change(): void
    {
        $this->seedRate(self::RATE_JUN);
        $this->setSalary($this->john, self::USD_SALARY, 'USD');
        $this->setTaxProfile($this->john, EmployeeTaxProfile::SUBJECT_DOMESTIC);

        $runId = $this->createRun();
        $this->calculate($runId)->assertOk();
        $before = (float) $this->slipOf($runId, $this->john)->basic_salary;

        // Master kurs naik SETELAH batch dihitung.
        $this->seedRate(18000.0, '2026-06-01');

        // Hitung ulang batch yang sama: kurs terkunci tetap dipakai.
        $this->calculate($runId)->assertOk();
        $after = (float) $this->slipOf($runId, $this->john)->basic_salary;

        $this->assertEqualsWithDelta($before, $after, 1, 'Kurs batch harus terkunci (rate lock).');
        $this->assertEqualsWithDelta(self::RATE_JUN, (float) Payroll::find($runId)->exchange_rates['USD'], 0.01);
    }

    // -- (iii) Kurs tidak tersedia -> 422, bukan 1:1 -------------------------

    public function test_missing_rate_rejects_the_batch(): void
    {
        // TIDAK ada baris kurs USD sama sekali.
        $this->setSalary($this->john, self::USD_SALARY, 'USD');
        $this->setTaxProfile($this->john, EmployeeTaxProfile::SUBJECT_DOMESTIC);

        $runId = $this->createRun();
        $res = $this->calculate($runId)->assertStatus(422);

        $this->assertStringContainsString('Kurs USD', (string) $res->json('message'));

        // Batch gagal total: tidak ada slip yang dibuat dengan asumsi 1:1.
        $this->assertSame(0, Payslip::where('payroll_id', $runId)->count());
    }

    public function test_salary_assignment_rejects_currency_without_rate(): void
    {
        // Pencegahan di muka: kurs belum ada -> penetapan gaji valas ditolak.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/salaries/{$this->john->id}", [
                'basic_salary' => self::USD_SALARY, 'effective_date' => '2026-01-01',
                'currency' => 'USD',
            ])->assertStatus(422)->assertJsonValidationErrors('currency');

        $this->seedRate(self::RATE_JUN);

        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/salaries/{$this->john->id}", [
                'basic_salary' => self::USD_SALARY, 'effective_date' => '2026-01-01',
                'currency' => 'usd',
            ])->assertStatus(201);

        $this->assertDatabaseHas('employee_salaries', [
            'user_id' => $this->john->id, 'currency' => 'USD',
        ]);
    }

    // -- (iv)+(v) PPh 26 ekspatriat vs PPh 21 domestik -----------------------

    public function test_foreign_subject_is_withheld_pph26_at_twenty_percent(): void
    {
        $this->seedRate(self::RATE_JUN);
        $this->setSalary($this->john, self::USD_SALARY, 'USD');
        $this->setTaxProfile($this->john, EmployeeTaxProfile::SUBJECT_FOREIGN);

        $runId = $this->createRun();
        $this->calculate($runId)->assertOk();

        $slip = $this->slipOf($runId, $this->john);
        $gross = (float) $slip->taxable_income;

        // 20% x BRUTO — tanpa PTKP, tanpa biaya jabatan, tanpa TER.
        $this->assertEqualsWithDelta(round($gross * 0.20), (float) $slip->pph21, 1);

        $step = PayslipCalculationStep::where('payslip_id', $slip->id)
            ->where('step_code', PayslipCalculationStep::STEP_PPH26)->first();
        $this->assertNotNull($step, 'Langkah PPH26 harus terekam untuk subjek luar negeri.');

        // Tidak ada langkah PPh 21 (TER / Pasal 17) untuk subjek luar negeri.
        $steps = PayslipCalculationStep::where('payslip_id', $slip->id)->pluck('step_code')->all();
        $this->assertNotContains(PayslipCalculationStep::STEP_PPH21_TER, $steps);
        $this->assertNotContains(PayslipCalculationStep::STEP_PPH21_PASAL17, $steps);

        $taxItem = PayslipItem::where('payslip_id', $slip->id)->where('source', 'tax')->first();
        $this->assertNotNull($taxItem);
        $this->assertSame('PPh 26', $taxItem->label);
    }

    public function test_treaty_rate_applies_only_with_partner_country(): void
    {
        $this->seedRate(self::RATE_JUN);
        $this->setSalary($this->john, self::USD_SALARY, 'USD');
        // Tarif P3B 10% DENGAN negara mitra (bukti SKD/DGT) -> dipakai.
        $this->setTaxProfile($this->john, EmployeeTaxProfile::SUBJECT_FOREIGN, 'SG', 0.10);

        // Karyawan kedua: tarif P3B diisi TANPA negara mitra -> wajib 20%.
        $this->setSalary($this->budi, 20000000);
        $this->setTaxProfile($this->budi, EmployeeTaxProfile::SUBJECT_FOREIGN, null, 0.10);

        $runId = $this->createRun();
        $this->calculate($runId)->assertOk();

        $johnSlip = $this->slipOf($runId, $this->john);
        $this->assertEqualsWithDelta(
            round((float) $johnSlip->taxable_income * 0.10),
            (float) $johnSlip->pph21,
            1,
            'Tarif P3B berlaku bila negara mitra terisi.',
        );

        $budiSlip = $this->slipOf($runId, $this->budi);
        $this->assertEqualsWithDelta(
            round((float) $budiSlip->taxable_income * 0.20),
            (float) $budiSlip->pph21,
            1,
            'Tanpa negara mitra, pemotong wajib memakai tarif umum 20%.',
        );
    }

    public function test_domestic_employee_in_same_batch_still_uses_pph21(): void
    {
        $this->seedRate(self::RATE_JUN);
        $this->setSalary($this->john, self::USD_SALARY, 'USD');
        $this->setTaxProfile($this->john, EmployeeTaxProfile::SUBJECT_FOREIGN);

        $this->setSalary($this->budi, 20000000);
        $this->setTaxProfile($this->budi, EmployeeTaxProfile::SUBJECT_DOMESTIC);

        $runId = $this->createRun();
        $this->calculate($runId)->assertOk();

        $budiSlip = $this->slipOf($runId, $this->budi);
        $steps = PayslipCalculationStep::where('payslip_id', $budiSlip->id)->pluck('step_code')->all();

        $this->assertContains(PayslipCalculationStep::STEP_PPH21_TER, $steps);
        $this->assertNotContains(PayslipCalculationStep::STEP_PPH26, $steps);

        // Slip Rupiah tetap berkurs 1 dan tanpa jejak konversi.
        $this->assertSame('IDR', $budiSlip->currency);
        $this->assertEqualsWithDelta(1.0, (float) $budiSlip->exchange_rate, 0.0001);
        $this->assertNotContains(PayslipCalculationStep::STEP_CURRENCY, $steps);
    }

    // -- (vi) Master kurs: aturan & scoping ----------------------------------

    public function test_currency_rate_master_rules(): void
    {
        // IDR adalah mata uang basis — tidak boleh punya baris kurs.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/currency-rates', [
                'currency' => 'IDR', 'rate_to_idr' => 1, 'effective_date' => '2026-01-01',
            ])->assertStatus(422);

        $id = (int) $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/currency-rates', [
                'currency' => 'usd', 'rate_to_idr' => 16000, 'effective_date' => '2026-01-01',
                'source' => 'KMK',
            ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('currency_rates', [
            'id' => $id, 'company_id' => $this->company->id, 'currency' => 'USD',
        ]);

        // Duplikat [company, currency, effective_date] ditolak.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/currency-rates', [
                'currency' => 'USD', 'rate_to_idr' => 17000, 'effective_date' => '2026-01-01',
            ])->assertStatus(422);
    }

    public function test_global_rate_is_visible_but_read_only(): void
    {
        // Baris global (company_id NULL) dipakai bersama semua perusahaan.
        $global = CurrencyRate::create([
            'company_id' => null, 'currency' => 'SGD', 'rate_to_idr' => 12000,
            'effective_date' => '2026-01-01', 'source' => 'BI Tengah',
        ]);

        $rows = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/currency-rates')
            ->assertOk()->json('data');

        $row = collect($rows)->firstWhere('id', $global->id);
        $this->assertNotNull($row, 'Baris kurs global harus terlihat.');
        $this->assertFalse($row['is_editable']);
        $this->assertSame('global', $row['scope']);

        // Tidak dapat diubah/dihapus dari dashboard perusahaan.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/currency-rates/{$global->id}", [
                'currency' => 'SGD', 'rate_to_idr' => 1, 'effective_date' => '2026-01-01',
            ])->assertStatus(404);

        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/currency-rates/{$global->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('currency_rates', ['id' => $global->id, 'rate_to_idr' => 12000.000000]);
    }

    public function test_rate_from_other_company_is_not_reachable(): void
    {
        $other = Company::create([
            'name' => 'PT Lain', 'email' => 'x@lain.co.id', 'phone' => '021-9', 'is_active' => true,
        ]);
        $alien = CurrencyRate::create([
            'company_id' => $other->id, 'currency' => 'USD', 'rate_to_idr' => 99000,
            'effective_date' => '2026-01-01',
        ]);

        $rows = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/currency-rates')
            ->assertOk()->json('data');
        $this->assertNotContains($alien->id, array_column($rows, 'id'));

        // Berlapis: CompanyMiddleware menahan binding lintas-company (403);
        // bila lolos, guard controller menutup dengan 404.
        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/currency-rates/{$alien->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('currency_rates', ['id' => $alien->id]);
    }

    // -- (vii) Penetapan subjek pajak ekspatriat lewat endpoint profil pajak ---

    public function test_tax_profile_endpoint_sets_foreign_subject_and_treaty(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/salaries/{$this->john->id}/tax-profile", [
                'ptkp_status'      => 'TK/0',
                'tax_subject_type' => 'foreign',
                'treaty_country'   => 'sg',
                'treaty_rate'      => 0.10,
                'foreign_tax_id'   => 'SG-TIN-0099',
            ])->assertOk();

        $this->assertSame('foreign', $res->json('data.tax_subject_type'));
        $this->assertSame('SG', $res->json('data.treaty_country'));

        $this->assertDatabaseHas('employee_tax_profiles', [
            'user_id'          => $this->john->id,
            'tax_subject_type' => 'foreign',
            'treaty_country'   => 'SG',
        ]);

        // NPWP/TIN asing adalah PII: tersimpan TERENKRIPSI (kolom mentah != nilai asli),
        // dan hanya terbaca lewat cast model.
        $raw = DB::table('employee_tax_profiles')->where('user_id', $this->john->id)->value('foreign_tax_id');
        $this->assertNotSame('SG-TIN-0099', $raw, 'TIN asing wajib tersimpan terenkripsi.');
        $this->assertSame('SG-TIN-0099', EmployeeTaxProfile::where('user_id', $this->john->id)->first()->foreign_tax_id);

        // Penyimpanan berikutnya TANPA field ekspatriat tidak boleh menghapusnya.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/salaries/{$this->john->id}/tax-profile", [
                'ptkp_status' => 'K/1',
            ])->assertOk();

        $this->assertDatabaseHas('employee_tax_profiles', [
            'user_id'          => $this->john->id,
            'ptkp_status'      => 'K/1',
            'tax_subject_type' => 'foreign',
            'treaty_country'   => 'SG',
        ]);
    }

    public function test_treaty_rate_without_partner_country_is_rejected(): void
    {
        // Tarif istimewa tanpa dasar SKD/DGT ditolak di muka (bukan diam-diam jadi 20%).
        $this->actingAs($this->finance, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/salaries/{$this->john->id}/tax-profile", [
                'ptkp_status'      => 'TK/0',
                'tax_subject_type' => 'foreign',
                'treaty_rate'      => 0.05,
            ])->assertStatus(422)->assertJsonValidationErrors('treaty_country');

        $this->assertDatabaseCount('employee_tax_profiles', 0);
    }

    public function test_tax_profile_write_requires_manage_permission(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->putJson("/api/v1/dashboard/payroll/salaries/{$this->john->id}/tax-profile", [
                'ptkp_status'      => 'TK/0',
                'tax_subject_type' => 'foreign',
            ])->assertStatus(403);

        $this->assertDatabaseCount('employee_tax_profiles', 0);
    }

    public function test_rate_write_requires_manage_permission(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/currency-rates', [
                'currency' => 'USD', 'rate_to_idr' => 16000, 'effective_date' => '2026-01-01',
            ])->assertStatus(403);

        $this->assertDatabaseCount('currency_rates', 0);
    }
}
