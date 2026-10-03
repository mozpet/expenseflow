<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\PayrollGroup;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integrasi Struk Reimbursement ↔ Payroll (rekomendasi #1 & #2).
 *
 * Membuktikan:
 *  (i)   Calculate dgn opsi `receipt_reimbursement` menghasilkan SATU baris slip
 *        PER STRUK (`source='receipt'`, `ref_type=Receipt::class`, `ref_id` terisi,
 *        `is_taxable=false`) + satu langkah jejak `REIMBURSEMENT` berisi daftar struk.
 *  (ii)  Bruto naik sebesar total struk, tetapi `taxable_income` TIDAK (non-objek PPh 21).
 *  (iii) markPaid() menandai tiap struk LUNAS (`status=paid`, `payment_method='payroll'`,
 *        `payment_ref_no='PAYROLL-{id}'`) + baris `activity_logs` `receipt_paid`
 *        + notifikasi `receipt_paid` ke karyawan.
 *  (iv)  Struk yang sudah lunas lewat alur lain TIDAK ikut ditarik (anti dobel bayar),
 *        dan struk yang statusnya bergeser (rejected) setelah hitung TIDAK dipaksa paid.
 *  (v)   Batch berikutnya tidak menarik ulang struk yang sudah lunas via gaji.
 */
class PayrollReceiptSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $hrd;
    private User $budi;

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

        $this->budi = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001');
        $this->setSalary($this->budi, 10_000_000);
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

    /** Struk siap-reimburse (approved, belum dibayar) pada periode Juni 2026. */
    private function makeReceipt(
        User $user,
        string $number,
        int $approved,
        string $status = 'approved',
        ?string $date = '2026-06-10',
        ?string $paidAt = null,
    ): Receipt {
        return Receipt::create([
            'company_id'      => $this->company->id,
            'user_id'         => $user->id,
            'receipt_number'  => $number,
            'vendor_name'     => 'Toko Berkah',
            'total_amount'    => $approved,
            'claimed_amount'  => $approved,
            'approved_amount' => $approved,
            'receipt_date'    => $date,
            'status'          => $status,
            'submitted_at'    => $date . ' 08:00:00',
            'paid_at'         => $paidAt,
        ]);
    }

    private function createRun(array $payload = [])
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', array_merge(['period_month' => 6, 'period_year' => 2026], $payload));
    }

    private function calculate(int $runId, array $options = [])
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate", array_merge(['receipt_reimbursement' => true], $options));
    }

    /** calculate → submit → approve (maker-checker: HRD yang menyetujui). */
    private function runUpToApproved(array $options = []): int
    {
        $runId = $this->createRun()->assertStatus(201)->json('data.id');
        $this->calculate($runId, $options)->assertOk();
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/submit")->assertOk();
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        return (int) $runId;
    }

    private function markPaid(int $runId)
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/mark-paid");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (i) + (ii) Satu baris slip per struk, non-taxable, jejak REIMBURSEMENT.
    // ─────────────────────────────────────────────────────────────────────────
    public function test_calculate_creates_one_payslip_item_per_receipt_with_refs(): void
    {
        $r1 = $this->makeReceipt($this->budi, 'RCP-20260610-0001', 250_000);
        $r2 = $this->makeReceipt($this->budi, 'RCP-20260612-0002', 400_000, date: '2026-06-12');

        $runId = $this->createRun()->assertStatus(201)->json('data.id');

        // Pembanding: hitung dulu TANPA opsi reimburse (bruto murni upah), simpan angkanya.
        // Rekalkulasi bersifat idempoten (payslip lama dihapus & dibangun ulang), jadi
        // batch yang sama bisa dipakai dua kali tanpa menabrak guard duplikat periode.
        $this->calculate($runId, ['receipt_reimbursement' => false])->assertOk();
        $plain = Payroll::findOrFail($runId)->payslips()->where('user_id', $this->budi->id)->firstOrFail();
        $plainGross   = (float) $plain->gross;
        $plainTaxable = (float) $plain->taxable_income;
        $plainNet     = (float) $plain->net;
        $this->assertSame(
            0,
            $plain->items()->where('source', 'receipt')->count(),
            'Opsi reimburse OFF → tidak boleh ada baris struk.',
        );

        $this->calculate($runId)->assertOk();

        $slip = Payroll::findOrFail($runId)->payslips()->where('user_id', $this->budi->id)->firstOrFail();

        $receiptItems = $slip->items()->where('source', 'receipt')->orderBy('ref_id')->get();
        $this->assertCount(2, $receiptItems, 'Harus ada SATU baris slip per struk (bukan satu baris agregat).');

        $first = $receiptItems->firstWhere('ref_id', $r1->id);
        $this->assertNotNull($first, 'Baris slip struk #1 harus merujuk ref_id struk.');
        $this->assertSame(Receipt::class, $first->ref_type);
        $this->assertEquals(250_000, (float) $first->amount);
        $this->assertFalse((bool) $first->is_taxable, 'Reimburse struk BUKAN objek PPh 21.');
        $this->assertStringContainsString($r1->receipt_number, $first->label);

        $second = $receiptItems->firstWhere('ref_id', $r2->id);
        $this->assertNotNull($second);
        $this->assertEquals(400_000, (float) $second->amount);
        $this->assertSame(Receipt::class, $second->ref_type);

        // (ii) Bruto naik sebesar total struk; penghasilan kena pajak TIDAK berubah.
        $this->assertEquals($plainGross + 650_000, (float) $slip->gross);
        $this->assertEquals($plainTaxable, (float) $slip->taxable_income);
        $this->assertEquals($plainNet + 650_000, (float) $slip->net);

        // Jejak perhitungan: satu langkah REIMBURSEMENT memuat daftar struk.
        $step = PayslipCalculationStep::where('payslip_id', $slip->id)
            ->where('step_code', PayslipCalculationStep::STEP_REIMBURSEMENT)
            ->first();
        $this->assertNotNull($step, 'Langkah jejak REIMBURSEMENT harus tercatat.');
        $this->assertEquals(650_000, (float) $step->final_result);
        $this->assertSame(2, (int) $step->input_payload['receipt_count']);
        $this->assertEqualsCanonicalizing(
            [$r1->id, $r2->id],
            array_column($step->input_payload['receipts'], 'receipt_id'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (iii) markPaid menandai struk lunas + log + notifikasi.
    // ─────────────────────────────────────────────────────────────────────────
    public function test_mark_paid_settles_reimbursed_receipts(): void
    {
        $r1 = $this->makeReceipt($this->budi, 'RCP-20260610-0001', 250_000);
        $r2 = $this->makeReceipt($this->budi, 'RCP-20260612-0002', 400_000, date: '2026-06-12');

        $runId = $this->runUpToApproved();

        $res = $this->markPaid($runId)->assertOk();
        $this->assertSame(2, (int) $res->json('meta.receipts_settled'));

        foreach ([$r1, $r2] as $r) {
            $r->refresh();
            $this->assertSame('paid', $r->status, "Struk {$r->receipt_number} harus LUNAS setelah payroll dibayar.");
            $this->assertNotNull($r->paid_at);
            $this->assertSame($this->finance->id, (int) $r->paid_by);
            $this->assertSame('payroll', $r->payment_method);
            $this->assertSame('PAYROLL-' . $runId, $r->payment_ref_no);

            $this->assertDatabaseHas('activity_logs', [
                'company_id'  => $this->company->id,
                'user_id'     => $this->finance->id,
                'action'      => 'receipt_paid',
                'entity_type' => 'receipt',
                'entity_id'   => $r->id,
            ]);

            $this->assertDatabaseHas('notifications', [
                'user_id'     => $this->budi->id,
                'type'        => 'receipt_paid',
                'entity_type' => 'receipt',
                'entity_id'   => $r->id,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (iv-a) Struk yang sudah dicairkan lewat alur bank TIDAK ikut ditarik.
    // ─────────────────────────────────────────────────────────────────────────
    public function test_already_disbursed_receipt_is_not_pulled_into_payslip(): void
    {
        $paid = $this->makeReceipt($this->budi, 'RCP-20260610-0009', 500_000, paidAt: '2026-06-20 10:00:00');
        $open = $this->makeReceipt($this->budi, 'RCP-20260611-0010', 150_000, date: '2026-06-11');

        $runId = $this->createRun()->assertStatus(201)->json('data.id');
        $this->calculate($runId)->assertOk();

        $slip = Payroll::findOrFail($runId)->payslips()->where('user_id', $this->budi->id)->firstOrFail();
        $refs = $slip->items()->where('source', 'receipt')->pluck('ref_id')->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$open->id], $refs, 'Struk yg sudah dibayar (paid_at terisi) tidak boleh ditarik ke slip.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (iv-b) Struk yang di-reject SETELAH batch dihitung tidak dipaksa jadi paid.
    // ─────────────────────────────────────────────────────────────────────────
    public function test_receipt_rejected_after_calculation_is_skipped_on_settlement(): void
    {
        $ok      = $this->makeReceipt($this->budi, 'RCP-20260610-0011', 300_000);
        $flipped = $this->makeReceipt($this->budi, 'RCP-20260612-0012', 100_000, date: '2026-06-12');

        $runId = $this->runUpToApproved();

        // Struk kedua di-reject di luar alur payroll setelah batch dihitung & disetujui.
        $flipped->update(['status' => 'rejected']);

        $res = $this->markPaid($runId)->assertOk();
        $this->assertSame(1, (int) $res->json('meta.receipts_settled'));

        $this->assertSame('paid', $ok->refresh()->status);
        $this->assertSame('rejected', $flipped->refresh()->status, 'Struk rejected tidak boleh dipaksa menjadi paid.');
        $this->assertNull($flipped->paid_at);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (v) Batch berikutnya tidak menarik ulang struk yang sudah lunas via gaji.
    // ─────────────────────────────────────────────────────────────────────────
    public function test_receipt_settled_via_payroll_is_not_pulled_again(): void
    {
        $r = $this->makeReceipt($this->budi, 'RCP-20260610-0013', 275_000);

        $firstRun = $this->runUpToApproved();
        $this->markPaid($firstRun)->assertOk();
        $this->assertSame('paid', $r->refresh()->status);

        // Batch kedua periode sama (ruang-lingkup grup, agar lolos guard duplikat periode)
        // — struk sudah lunas → tidak ditarik lagi.
        $group = PayrollGroup::create(['company_id' => $this->company->id, 'name' => 'Staf Bulanan']);
        $this->budi->update(['payroll_group_id' => $group->id]);

        $secondRun = $this->createRun(['payroll_group_id' => $group->id])->assertStatus(201)->json('data.id');
        $this->calculate($secondRun)->assertOk();

        $this->assertSame(
            0,
            PayslipItem::query()
                ->join('payslips', 'payslips.id', '=', 'payslip_items.payslip_id')
                ->where('payslips.payroll_id', $secondRun)
                ->where('payslip_items.source', 'receipt')
                ->count(),
            'Struk yang sudah lunas via gaji tidak boleh ditarik ke batch berikutnya (anti dobel bayar).',
        );
    }
}
