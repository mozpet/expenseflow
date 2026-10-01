<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\PayrollPaymentBatch;
use App\Models\PayrollPaymentItem;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\User;
use App\Services\PayrollAuditLogger;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uji fungsional Payroll Fase 4 (backend) — Disbursement:
 *  - Ekspor berkas transfer bank (arsitektur driver CSV): batch + item termasking +
 *    berkas privat berisi nomor rekening PENUH + checksum SHA-256.
 *  - Gerbang status (hanya approved/paid), izin (manage), scoping company, step-up PIN.
 *  - Unduh berkas (nomor penuh, hanya manage) & masking (nomor penuh tak pernah di JSON).
 *  - Rekonsiliasi: perbarui status item + status batch (partial/tuntas) + notifikasi;
 *    TIDAK mengubah payslip/payroll (decoupled dari mark-paid).
 *  - Rantai audit payroll_logs (export + reconcile) + verifyChain ok.
 */
class PayrollPhase4Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;   // maker + petugas disbursement
    private User $hrd;        // checker (approve)
    private User $budi;       // karyawan gaji 10jt, BCA
    private User $siti;       // karyawan gaji 8jt, Mandiri
    private User $employee;   // karyawan tanpa izin payroll

    protected function setUp(): void
    {
        parent::setUp();

        // Simpan berkas transfer ke disk palsu → tidak mengotori storage nyata.
        Storage::fake('local');

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

        $this->setSalary($this->budi, 10_000_000, 'TK/0');
        $this->setSalary($this->siti, 8_000_000, 'K/1');

        // Rekening bank (di-snapshot payslip oleh PayrollCalculator dari users.bank_*).
        $this->budi->forceFill(['bank_name' => 'BCA', 'bank_account_no' => '1234567890', 'bank_account_holder' => 'Budi Karyawan'])->save();
        $this->siti->forceFill(['bank_name' => 'Mandiri', 'bank_account_no' => '2223334445', 'bank_account_holder' => 'Siti Karyawan'])->save();
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
            'ptkp_status' => $ptkp,
            'has_npwp'    => true,
            'tax_method'  => 'gross',
        ]);
    }

    /** Buat run → calculate → submit → approve (oleh hrd) → kembalikan runId (status approved). */
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

    /** Bikin batch generic_csv untuk sebuah run (sebagai finance) → kembalikan response. */
    private function generateBatch(int $runId, string $format = PayrollPaymentBatch::BANK_GENERIC): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/payment-batches", ['bank_format' => $format]);
    }

    // ───────────────────────── Generate berkas ─────────────────────────

    public function test_generate_creates_batch_with_masked_items_file_and_checksum(): void
    {
        $runId = $this->approvedRun();

        $res = $this->generateBatch($runId)->assertStatus(201);
        $res->assertJsonPath('data.status', PayrollPaymentBatch::STATUS_FILE_GENERATED);

        $batchId = $res->json('data.id');
        $batch = PayrollPaymentBatch::find($batchId);

        // Totals = agregat neto slip.
        $expectedSum   = (float) Payslip::where('payroll_id', $runId)->sum('net');
        $expectedCount = Payslip::where('payroll_id', $runId)->count();
        $this->assertSame(2, $expectedCount);
        $this->assertSame($expectedCount, (int) $batch->total_records);
        $this->assertEqualsWithDelta($expectedSum, (float) $batch->total_amount, 0.01);

        // Item termasking (4 digit terakhir), jumlah item = jumlah slip.
        $items = PayrollPaymentItem::where('payment_batch_id', $batchId)->get();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertStringContainsString('•', $item->bank_account_no_masked);
            $this->assertMatchesRegularExpression('/\d{4}$/', $item->bank_account_no_masked);
            $this->assertSame(PayrollPaymentItem::STATUS_PENDING, $item->status);
        }

        // Checksum + berkas privat ada di disk & checksum cocok dengan isi.
        $this->assertNotNull($batch->file_checksum);
        $this->assertNotNull($batch->file_path);
        Storage::disk('local')->assertExists($batch->file_path);
        $this->assertSame($batch->file_checksum, hash('sha256', Storage::disk('local')->get($batch->file_path)));

        $this->assertDatabaseHas('payroll_payment_batches', [
            'id'          => $batchId,
            'payroll_id'  => $runId,
            'company_id'  => $this->company->id,
            'bank_format' => PayrollPaymentBatch::BANK_GENERIC,
        ]);
    }

    public function test_generate_requires_approved_or_paid_status(): void
    {
        // Run baru (draft) → belum boleh dibuat berkas.
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', ['period_month' => 6, 'period_year' => 2026]);
        $runId = $res->json('data.id');

        $this->generateBatch($runId)->assertStatus(422);

        // Setelah calculate (calculated) pun masih 422 (belum approved).
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate")->assertOk();
        $this->generateBatch($runId)->assertStatus(422);

        $this->assertSame(0, PayrollPaymentBatch::where('payroll_id', $runId)->count());
    }

    public function test_generate_forbidden_without_manage(): void
    {
        $runId = $this->approvedRun();

        $this->actingAs($this->employee, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/payment-batches", ['bank_format' => PayrollPaymentBatch::BANK_GENERIC])
            ->assertStatus(403);
    }

    public function test_generate_cross_company_is_blocked(): void
    {
        $runId = $this->approvedRun();

        // Finance dari perusahaan lain (punya izin manage global, tapi beda company).
        $otherCompany = Company::create([
            'name' => 'PT Lain', 'email' => 'lain@x.co.id', 'phone' => '0', 'address' => '-', 'is_active' => true,
        ]);
        $financeRole = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $otherFinance = $this->makeUser('Rina Finance', 'rina@lain.co.id', 'finance', $financeRole->id, null, $otherCompany->id);

        // Diblokir lintas-perusahaan oleh CompanyMiddleware (403) — route-model {payroll}
        // dengan company_id berbeda. Guard 404 di controller = pertahanan berlapis.
        $this->actingAs($otherFinance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/payment-batches", ['bank_format' => PayrollPaymentBatch::BANK_GENERIC])
            ->assertStatus(403);

        $this->assertSame(0, PayrollPaymentBatch::where('payroll_id', $runId)->count());
    }

    public function test_generate_enforces_step_up_pin_when_set(): void
    {
        // PIN diset pada finance (petugas disbursement).
        $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/security/pin', [
                'current_password' => 'password', 'pin' => '246810', 'pin_confirmation' => '246810',
            ])->assertOk();

        $runId = $this->approvedRun();

        // Tanpa PIN → 422.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/payment-batches", ['bank_format' => PayrollPaymentBatch::BANK_GENERIC])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        // PIN benar → 201.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/payment-batches", ['bank_format' => PayrollPaymentBatch::BANK_GENERIC, 'pin' => '246810'])
            ->assertStatus(201);
    }

    // ───────────────────────── Unduh & masking ─────────────────────────

    public function test_download_returns_full_numbers_and_is_gated(): void
    {
        $runId = $this->approvedRun();
        $batchId = $this->generateBatch($runId)->json('data.id');

        // Finance (manage) → berhasil unduh, isi berkas memuat nomor rekening PENUH.
        $res = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/payment-batches/{$batchId}/download");
        $res->assertOk();
        $content = $res->streamedContent();
        $this->assertStringContainsString('1234567890', $content);
        $this->assertStringContainsString('2223334445', $content);

        // Karyawan biasa → 403 (gate role) / tidak boleh mengunduh.
        $this->actingAs($this->employee, 'sanctum')
            ->get("/api/v1/dashboard/payroll/payment-batches/{$batchId}/download")
            ->assertStatus(403);
    }

    public function test_full_account_number_never_appears_in_json(): void
    {
        $runId = $this->approvedRun();

        $res = $this->generateBatch($runId)->assertStatus(201);
        $body = $res->getContent();

        // Nomor penuh TIDAK bocor ke JSON; hanya 4 digit terakhir (termasking).
        $this->assertStringNotContainsString('1234567890', $body);
        $this->assertStringNotContainsString('2223334445', $body);
        $this->assertStringContainsString('7890', $body);
        $this->assertStringContainsString('4445', $body);

        // show() juga termasking.
        $batchId = $res->json('data.id');
        $showBody = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/payment-batches/{$batchId}")->getContent();
        $this->assertStringNotContainsString('1234567890', $showBody);
    }

    public function test_generic_csv_layout_is_correct(): void
    {
        $runId = $this->approvedRun();
        $batchId = $this->generateBatch($runId)->json('data.id');

        $content = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/payment-batches/{$batchId}/download")->streamedContent();

        // Header kolom generik + baris TOTAL hadir.
        $this->assertStringContainsString('No,Nama Karyawan,Kode Karyawan,Bank,No Rekening,Nama Pemilik,Jumlah', $content);
        $this->assertStringContainsString('TOTAL', $content);
        $this->assertStringContainsString('Budi Karyawan', $content);
        // Nominal berformat 2 desimal tanpa pemisah ribuan.
        $this->assertMatchesRegularExpression('/\d+\.\d{2}/', $content);
    }

    // ───────────────────────── Rekonsiliasi ─────────────────────────

    public function test_reconcile_marks_success_notifies_and_does_not_touch_payroll(): void
    {
        $runId = $this->approvedRun();
        $res = $this->generateBatch($runId)->assertStatus(201);
        $batchId = $res->json('data.id');
        $items = PayrollPaymentItem::where('payment_batch_id', $batchId)->get();

        $payload = ['results' => $items->map(fn ($it) => [
            'payment_item_id'   => $it->id,
            'status'            => 'success',
            'bank_reference_no' => 'TRX-' . $it->id,
        ])->all()];

        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/payment-batches/{$batchId}/reconcile", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', PayrollPaymentBatch::STATUS_RECONCILED);

        $batch = PayrollPaymentBatch::find($batchId);
        $this->assertSame(PayrollPaymentBatch::STATUS_RECONCILED, $batch->status);
        $this->assertSame($this->finance->id, (int) $batch->reconciled_by);
        $this->assertNotNull($batch->reconciled_at);

        foreach ($items as $it) {
            $fresh = PayrollPaymentItem::find($it->id);
            $this->assertSame(PayrollPaymentItem::STATUS_SUCCESS, $fresh->status);
            $this->assertNotNull($fresh->settled_at);
            $this->assertSame('TRX-' . $it->id, $fresh->bank_reference_no);
        }

        // Notifikasi disbursement terbentuk untuk tiap karyawan.
        $this->assertDatabaseHas('notifications', ['user_id' => $this->budi->id, 'type' => 'payroll_disbursed']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->siti->id, 'type' => 'payroll_disbursed']);

        // DECOUPLED: status payroll & payslip TIDAK berubah oleh rekonsiliasi.
        $this->assertSame(Payroll::STATUS_APPROVED, Payroll::find($runId)->status);
        $this->assertSame(0, Payslip::where('payroll_id', $runId)->where('status', 'paid')->count());
    }

    public function test_reconcile_partial_then_complete_transitions(): void
    {
        $runId = $this->approvedRun();
        $batchId = $this->generateBatch($runId)->json('data.id');
        $items = PayrollPaymentItem::where('payment_batch_id', $batchId)->orderBy('id')->get();

        // Rekonsiliasi sebagian (hanya item pertama sukses) → partially_settled.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/payment-batches/{$batchId}/reconcile", [
                'results' => [['payment_item_id' => $items[0]->id, 'status' => 'success', 'bank_reference_no' => 'OK-1']],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PayrollPaymentBatch::STATUS_PARTIALLY_SETTLED);

        // Item kedua gagal → tak ada lagi pending → reconciled (tuntas ditinjau).
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/payment-batches/{$batchId}/reconcile", [
                'results' => [['payment_item_id' => $items[1]->id, 'status' => 'failed', 'failure_reason' => 'Rekening tidak aktif']],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PayrollPaymentBatch::STATUS_RECONCILED);

        $this->assertSame(PayrollPaymentItem::STATUS_SUCCESS, PayrollPaymentItem::find($items[0]->id)->status);
        $this->assertSame(PayrollPaymentItem::STATUS_FAILED, PayrollPaymentItem::find($items[1]->id)->status);
        $this->assertSame('Rekening tidak aktif', PayrollPaymentItem::find($items[1]->id)->failure_reason);
    }

    public function test_reconcile_rejects_item_from_other_batch(): void
    {
        $runId = $this->approvedRun();
        $batchId = $this->generateBatch($runId)->json('data.id');

        // payment_item_id yang tidak ada di batch ini → validasi gagal (422).
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/payment-batches/{$batchId}/reconcile", [
                'results' => [['payment_item_id' => 999999, 'status' => 'success']],
            ])
            ->assertStatus(422);
    }

    public function test_reconcile_forbidden_without_manage(): void
    {
        $runId = $this->approvedRun();
        $batchId = $this->generateBatch($runId)->json('data.id');

        $this->actingAs($this->employee, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/payment-batches/{$batchId}/reconcile", [
                'results' => [['payment_item_id' => 1, 'status' => 'success']],
            ])
            ->assertStatus(403);
    }

    // ───────────────────────── Rantai audit ─────────────────────────

    public function test_payroll_logs_capture_export_and_reconcile_and_chain_ok(): void
    {
        $runId = $this->approvedRun();
        $batchId = $this->generateBatch($runId)->json('data.id');
        $items = PayrollPaymentItem::where('payment_batch_id', $batchId)->get();

        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/payment-batches/{$batchId}/reconcile", [
                'results' => $items->map(fn ($it) => ['payment_item_id' => $it->id, 'status' => 'success'])->all(),
            ])->assertOk();

        $this->assertDatabaseHas('payroll_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_BANK_FILE_GENERATED',
        ]);
        $this->assertDatabaseHas('payroll_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_RECONCILED',
        ]);

        $integrity = PayrollAuditLogger::verifyChain($this->company->id);
        $this->assertTrue($integrity['ok']);
        $this->assertNull($integrity['broken_at']);
    }

    public function test_index_lists_batches_with_bank_format_options(): void
    {
        $runId = $this->approvedRun();
        $this->generateBatch($runId)->assertStatus(201);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$runId}/payment-batches")
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
        // Daftar format bank tersedia untuk dropdown FE.
        $keys = array_column($res->json('bank_formats'), 'key');
        $this->assertContains(PayrollPaymentBatch::BANK_GENERIC, $keys);
        $this->assertContains(PayrollPaymentBatch::BANK_BCA, $keys);
    }
}
