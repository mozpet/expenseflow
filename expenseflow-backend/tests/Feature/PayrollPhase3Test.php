<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use App\Services\PayrollAuditLogger;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uji fungsional Payroll Fase 3 (backend):
 *  - Adjustments & koreksi retroaktif (maker-checker, klaim-saat-calculate, void terkunci, idempotensi)
 *  - Proteksi perubahan rekening bank (maker-checker, sinkron users.*, supersede, masking/enkripsi, notifikasi)
 *  - payroll_logs hash-chain SHA-256 (verifyChain ok + deteksi tamper)
 *  - Step-up PIN (soft rollout: wajib hanya bila approver sudah set PIN)
 */
class PayrollPhase3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;   // maker
    private User $hrd;        // checker
    private User $budi;       // karyawan gaji 10jt
    private User $siti;       // karyawan gaji 8jt
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

    /** Buat penyesuaian (sebagai finance) → kembalikan id. */
    private function createAdjustment(int $userId, string $type, float $amount, bool $isTaxable = true, string $name = 'Koreksi'): int
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/adjustments', [
                'user_id'    => $userId,
                'type'       => $type,
                'name'       => $name,
                'amount'     => $amount,
                'is_taxable' => $isTaxable,
                'reason'     => 'Uji penyesuaian',
            ]);
        $res->assertStatus(201);

        return $res->json('data.id');
    }

    /** Setujui penyesuaian sebagai hrd (checker berbeda dari finance/maker). */
    private function approveAdjustment(int $id): void
    {
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/adjustments/{$id}/approve")
            ->assertOk();
    }

    // ───────────────────────── Adjustments ─────────────────────────

    public function test_earning_adjustment_applies_on_calculate_and_increases_gross(): void
    {
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 1_000_000, true, 'Bonus Koreksi');
        $this->approveAdjustment($adjId);

        $runId = $this->createRun();
        $this->calculate($runId);

        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();
        $this->assertNotNull($budiSlip);

        // Bruto naik sebesar penyesuaian kena pajak: 10jt + 1jt = 11jt.
        $this->assertEqualsWithDelta(11_000_000, (float) $budiSlip->gross, 0.01);
        $this->assertEqualsWithDelta(11_000_000, (float) $budiSlip->taxable_income, 0.01);

        // Baris slip penyesuaian tercatat & tertaut ke sumbernya.
        $item = PayslipItem::where('payslip_id', $budiSlip->id)
            ->where('source', 'adjustment')
            ->where('ref_type', PayrollAdjustment::class)
            ->where('ref_id', $adjId)
            ->first();
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(1_000_000, (float) $item->amount, 0.01);

        // Penyesuaian kini terklaim ke batch (applied).
        $adj = PayrollAdjustment::find($adjId);
        $this->assertSame(PayrollAdjustment::STATUS_APPLIED, $adj->status);
        $this->assertSame($runId, (int) $adj->payroll_id);
        $this->assertNotNull($adj->applied_at);
    }

    public function test_deduction_adjustment_reduces_net_and_is_forced_non_taxable(): void
    {
        // Deduction is_taxable dipaksa false meski dikirim true.
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_DEDUCTION, 500_000, true, 'Potongan Koreksi');
        $this->assertFalse((bool) PayrollAdjustment::find($adjId)->is_taxable);
        $this->approveAdjustment($adjId);

        $runId = $this->createRun();
        $this->calculate($runId);

        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();

        // Bruto tetap 10jt (deduction tidak menambah earning), tapi potongan bertambah 500rb.
        $this->assertEqualsWithDelta(10_000_000, (float) $budiSlip->gross, 0.01);
        $item = PayslipItem::where('payslip_id', $budiSlip->id)
            ->where('source', 'adjustment')->where('ref_id', $adjId)->first();
        $this->assertNotNull($item);
        $this->assertSame(PayslipItem::TYPE_DEDUCTION, $item->type);

        // Neto = bruto − total potongan (potongan mencakup PPh21 + penyesuaian 500rb).
        $this->assertEqualsWithDelta(
            (float) $budiSlip->gross - (float) $budiSlip->total_deduction,
            (float) $budiSlip->net,
            0.01
        );
        $this->assertGreaterThanOrEqual(500_000, (float) $budiSlip->total_deduction);
    }

    public function test_adjustment_self_approval_is_forbidden(): void
    {
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 250_000);

        // Pembuat (finance) menyetujui buatannya sendiri → 403.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/adjustments/{$adjId}/approve")
            ->assertStatus(403);

        $this->assertSame(PayrollAdjustment::STATUS_PENDING, PayrollAdjustment::find($adjId)->status);
    }

    public function test_pending_adjustment_is_not_applied_on_calculate(): void
    {
        // Belum di-approve → tidak diklaim / tidak muncul di slip.
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 750_000);

        $runId = $this->createRun();
        $this->calculate($runId);

        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();
        $this->assertEqualsWithDelta(10_000_000, (float) $budiSlip->gross, 0.01);
        $this->assertSame(0, PayslipItem::where('payslip_id', $budiSlip->id)->where('source', 'adjustment')->count());
        $this->assertSame(PayrollAdjustment::STATUS_PENDING, PayrollAdjustment::find($adjId)->status);
    }

    public function test_recalculate_is_idempotent_for_adjustments(): void
    {
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 1_000_000);
        $this->approveAdjustment($adjId);

        $runId = $this->createRun();
        $this->calculate($runId);
        $this->calculate($runId); // hitung ulang → release lalu klaim lagi

        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();

        // Tetap satu baris penyesuaian (tidak dobel) & bruto tetap 11jt.
        $this->assertSame(1, PayslipItem::where('payslip_id', $budiSlip->id)->where('source', 'adjustment')->count());
        $this->assertEqualsWithDelta(11_000_000, (float) $budiSlip->gross, 0.01);

        $adj = PayrollAdjustment::find($adjId);
        $this->assertSame(PayrollAdjustment::STATUS_APPLIED, $adj->status);
        $this->assertSame($runId, (int) $adj->payroll_id);
    }

    public function test_void_is_locked_when_batch_is_approved(): void
    {
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 1_000_000);
        $this->approveAdjustment($adjId);

        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        // Void penyesuaian yang sudah terpakai di batch approved → 422 (terkunci).
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/adjustments/{$adjId}/void", ['reason' => 'coba batalkan'])
            ->assertStatus(422);

        $this->assertSame(PayrollAdjustment::STATUS_APPLIED, PayrollAdjustment::find($adjId)->status);
    }

    public function test_void_releases_pending_batch_and_drops_from_recalc(): void
    {
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 1_000_000);
        $this->approveAdjustment($adjId);

        $runId = $this->createRun();
        $this->calculate($runId); // status applied, payroll_id = runId (batch masih calculated)

        // Void (butuh alasan) → lepas dari batch.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/adjustments/{$adjId}/void", ['reason' => 'salah input'])
            ->assertOk();

        $adj = PayrollAdjustment::find($adjId);
        $this->assertSame(PayrollAdjustment::STATUS_VOIDED, $adj->status);
        $this->assertNull($adj->payroll_id);

        // Hitung ulang → penyesuaian yang di-void tidak lagi muncul; bruto kembali 10jt.
        $this->calculate($runId);
        $budiSlip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->first();
        $this->assertEqualsWithDelta(10_000_000, (float) $budiSlip->gross, 0.01);
        $this->assertSame(0, PayslipItem::where('payslip_id', $budiSlip->id)->where('source', 'adjustment')->count());
    }

    public function test_void_requires_reason(): void
    {
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 300_000);
        $this->approveAdjustment($adjId);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/adjustments/{$adjId}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_destroy_batch_releases_applied_adjustment(): void
    {
        $adjId = $this->createAdjustment($this->budi->id, PayrollAdjustment::TYPE_EARNING, 1_000_000);
        $this->approveAdjustment($adjId);

        $runId = $this->createRun();
        $this->calculate($runId); // applied → terklaim ke batch (status calculated, boleh dihapus)

        $this->actingAs($this->finance, 'sanctum')
            ->deleteJson("/api/v1/dashboard/payroll/runs/{$runId}")
            ->assertOk();

        // Batch terhapus → penyesuaian dilepas kembali ke approved & bisa dipakai batch lain.
        $adj = PayrollAdjustment::find($adjId);
        $this->assertSame(PayrollAdjustment::STATUS_APPROVED, $adj->status);
        $this->assertNull($adj->payroll_id);
        $this->assertNull($adj->applied_at);
    }

    public function test_employee_cannot_create_adjustment(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/adjustments', [
                'user_id' => $this->budi->id,
                'type'    => PayrollAdjustment::TYPE_EARNING,
                'name'    => 'Nakal',
                'amount'  => 1000,
            ])
            ->assertStatus(403);
    }

    // ─────────────────────── Rekening Bank ───────────────────────

    private function submitBankAccount(int $userId, string $no = '1234567890', string $bank = 'BCA'): int
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/employees/{$userId}/bank-account", [
                'bank_name'           => $bank,
                'bank_account_no'     => $no,
                'bank_account_holder' => 'Budi Karyawan',
            ]);
        $res->assertStatus(201);

        return $res->json('data.id');
    }

    public function test_bank_account_number_is_masked_and_encrypted(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/employees/{$this->budi->id}/bank-account", [
                'bank_name'           => 'BCA',
                'bank_account_no'     => '1234567890',
                'bank_account_holder' => 'Budi Karyawan',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', EmployeeBankAccount::STATUS_PENDING);

        // Nomor termasking (4 digit terakhir) & plaintext tidak dikembalikan.
        $this->assertStringContainsString('7890', $res->json('data.bank_account_no_masked'));
        $this->assertStringNotContainsString('1234567890', $res->json('data.bank_account_no_masked'));
        $this->assertNull($res->json('data.bank_account_no'));

        // Kolom DB tersimpan terenkripsi (bukan digit mentah).
        $raw = DB::table('employee_bank_accounts')->where('user_id', $this->budi->id)->value('bank_account_no');
        $this->assertNotSame('1234567890', $raw);
    }

    public function test_verify_activates_syncs_users_and_supersedes_previous(): void
    {
        // Rekening pertama → verify → aktif + sinkron users.*
        $first = $this->submitBankAccount($this->budi->id, '1111111111', 'BCA');
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/bank-accounts/{$first}/verify")
            ->assertOk();

        $this->assertSame(EmployeeBankAccount::STATUS_ACTIVE, EmployeeBankAccount::find($first)->status);
        $this->budi->refresh();
        $this->assertSame('BCA', $this->budi->bank_name);
        $this->assertSame('1111111111', $this->budi->bank_account_no);

        // Notifikasi keamanan terbentuk untuk karyawan.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->budi->id,
            'type'    => 'bank_account_verified',
        ]);

        // Rekening kedua → verify → pertama menjadi superseded, kedua aktif & primary.
        $second = $this->submitBankAccount($this->budi->id, '2222222222', 'Mandiri');
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/bank-accounts/{$second}/verify")
            ->assertOk();

        $this->assertSame(EmployeeBankAccount::STATUS_SUPERSEDED, EmployeeBankAccount::find($first)->status);
        $secondModel = EmployeeBankAccount::find($second);
        $this->assertSame(EmployeeBankAccount::STATUS_ACTIVE, $secondModel->status);
        $this->assertTrue((bool) $secondModel->is_primary);

        $this->budi->refresh();
        $this->assertSame('Mandiri', $this->budi->bank_name);
        $this->assertSame('2222222222', $this->budi->bank_account_no);
    }

    public function test_bank_account_self_verify_is_forbidden(): void
    {
        $id = $this->submitBankAccount($this->budi->id); // requested_by = finance

        // Pengaju (finance) memverifikasi sendiri → 403 (maker-checker).
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/bank-accounts/{$id}/verify")
            ->assertStatus(403);

        $this->assertSame(EmployeeBankAccount::STATUS_PENDING, EmployeeBankAccount::find($id)->status);
    }

    public function test_bank_account_reject_requires_reason_and_maker_checker(): void
    {
        $id = $this->submitBankAccount($this->budi->id);

        // Tanpa alasan → 422.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/bank-accounts/{$id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Pengaju menolak sendiri → 403.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/bank-accounts/{$id}/reject", ['reason' => 'x'])
            ->assertStatus(403);

        // Checker berbeda menolak dengan alasan → ok.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/bank-accounts/{$id}/reject", ['reason' => 'rekening tidak valid'])
            ->assertOk();

        $model = EmployeeBankAccount::find($id);
        $this->assertSame(EmployeeBankAccount::STATUS_REJECTED, $model->status);
        $this->assertSame('rekening tidak valid', $model->reject_reason);

        // users.* TIDAK berubah karena rekening ditolak.
        $this->budi->refresh();
        $this->assertNotSame('1234567890', (string) $this->budi->bank_account_no);
    }

    public function test_employee_cannot_manage_bank_accounts(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/employees/{$this->budi->id}/bank-account", [
                'bank_name'           => 'BCA',
                'bank_account_no'     => '9999999999',
                'bank_account_holder' => 'Budi',
            ])
            ->assertStatus(403);
    }

    // ─────────────────────── payroll_logs hash-chain ───────────────────────

    public function test_payroll_log_chain_is_built_and_verifies_ok(): void
    {
        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$runId}/logs")
            ->assertOk();

        // Rantai berisi event lifecycle batch & integritas valid.
        $this->assertGreaterThanOrEqual(3, count($res->json('data.logs')));
        $res->assertJsonPath('data.integrity.ok', true);
        $this->assertNull($res->json('data.integrity.broken_at'));

        // Verifikasi langsung via service juga ok.
        $this->assertTrue(PayrollAuditLogger::verifyChain($this->company->id)['ok']);
    }

    public function test_payroll_log_chain_detects_tampering(): void
    {
        $runId = $this->createRun();
        $this->calculate($runId);

        // Rusak satu baris secara retroaktif (ubah after_state tanpa memperbarui hash).
        $target = DB::table('payroll_logs')
            ->where('company_id', $this->company->id)
            ->orderBy('sequence')
            ->first();
        DB::table('payroll_logs')->where('id', $target->id)->update([
            'after_state' => json_encode(['status' => 'HACKED']),
        ]);

        $integrity = PayrollAuditLogger::verifyChain($this->company->id);
        $this->assertFalse($integrity['ok']);
        $this->assertSame((int) $target->sequence, $integrity['broken_at']);
    }

    // ─────────────────────── Step-up PIN (soft) ───────────────────────

    private function setPin(User $actor, string $pin, ?string $currentPin = null): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'current_password'      => 'password',
            'pin'                   => $pin,
            'pin_confirmation'      => $pin,
        ];
        if ($currentPin !== null) {
            $payload['current_pin'] = $currentPin;
        }

        return $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/security/pin', $payload);
    }

    public function test_set_pin_then_status_reports_true(): void
    {
        $this->actingAs($this->hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/security/pin')
            ->assertOk()
            ->assertJsonPath('data.has_pin', false);

        $this->setPin($this->hrd, '135790')->assertOk();

        $this->actingAs($this->hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/security/pin')
            ->assertOk()
            ->assertJsonPath('data.has_pin', true);

        // PIN tersimpan ter-hash (bukan plaintext).
        $raw = DB::table('users')->where('id', $this->hrd->id)->value('security_pin');
        $this->assertNotSame('135790', $raw);
    }

    public function test_approver_without_pin_can_still_approve(): void
    {
        // Soft rollout: hrd belum set PIN → approve tanpa pin tetap boleh.
        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")
            ->assertOk();

        $this->assertSame(Payroll::STATUS_APPROVED, Payroll::find($runId)->status);
    }

    public function test_approve_requires_correct_pin_once_set(): void
    {
        $this->setPin($this->hrd, '246810')->assertOk();

        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);

        // Tanpa PIN → 422.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
        $this->assertSame(Payroll::STATUS_SUBMITTED, Payroll::find($runId)->status);

        // PIN salah → 422.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve", ['pin' => '000000'])
            ->assertStatus(422);
        $this->assertSame(Payroll::STATUS_SUBMITTED, Payroll::find($runId)->status);

        // PIN benar → ok.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve", ['pin' => '246810'])
            ->assertOk();
        $this->assertSame(Payroll::STATUS_APPROVED, Payroll::find($runId)->status);
    }

    public function test_mark_paid_requires_pin_once_set(): void
    {
        // PIN diset pada finance (yang menandai bayar).
        $this->setPin($this->finance, '112233')->assertOk();

        $runId = $this->createRun();
        $this->calculate($runId);
        $this->submit($runId);
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/approve")->assertOk();

        // Mark-paid tanpa PIN → 422.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/mark-paid")
            ->assertStatus(422);
        $this->assertSame(Payroll::STATUS_APPROVED, Payroll::find($runId)->status);

        // Mark-paid dengan PIN benar → ok.
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/mark-paid", ['pin' => '112233'])
            ->assertOk();
        $this->assertSame(Payroll::STATUS_PAID, Payroll::find($runId)->status);
    }
}
