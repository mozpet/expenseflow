<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\Payslip;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integrasi PENCAIRAN SISA CUTI TAHUNAN ke slip gaji & jurnal GL (2026-10-13).
 *
 * Uji penutupan periode cuti ada di LeaveCarryOverEncashmentTest; berkas ini
 * meneruskan ceritanya dari titik penyesuaian gaji sudah terbit:
 *
 *   1. Baris slipnya memakai source 'leave_encashment', BUKAN 'adjustment' —
 *      inilah satu-satunya hal yang memisahkan pencairan cuti dari koreksi gaji
 *      manual di seluruh rantai hilir (jejak perhitungan, jurnal, laporan).
 *   2. Jejak perhitungannya memakai STEP_LEAVE_ENCASHMENT, dan STEP_ADJUSTMENT
 *      TIDAK tercemar nominalnya — karyawan harus melihat dasar cuti, bukan
 *      "koreksi manual" yang tak pernah dibuat siapa pun.
 *   3. Jurnal GL mengalirkannya ke pos expense_leave_encashment (5180), bukan
 *      menumpuknya ke beban penyesuaian gaji, dan jurnalnya tetap seimbang.
 *   4. Nominalnya objek PPh 21 (is_taxable) sehingga ikut bruto kena pajak.
 *
 * Gaji pokok 10.500.000 dipakai konsisten dengan PenaltyBonusPayrollTest.
 */
class LeaveEncashmentPayrollTest extends TestCase
{
    use RefreshDatabase;

    private const BASIC = 10_500_000;

    private Company $company;
    private AttendanceSetting $office;
    private User $finance;
    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create(['name' => 'PT Pencairan Cuti', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'                    => $this->company->id,
            'office_name'                   => 'Kantor Jakarta',
            'office_latitude'               => -6.2,
            'office_longitude'              => 106.8166,
            'radius_meters'                 => 100,
            'work_start_time'               => '08:00:00',
            'work_end_time'                 => '17:00:00',
            'default_leave_quota'           => 12,
            'leave_encashment_enabled'      => true,
            'leave_encashment_rate_per_day' => 200_000,
        ]);

        $this->finance = $this->makeUser('Andi Finance', 'andi@pc.co.id', 'finance', null);
        $this->budi    = $this->makeUser('Budi', 'budi@pc.co.id', 'employee', 'EMP-001');
        $this->budi->update(['attendance_setting_id' => $this->office->id]);
        $this->budi->refresh();

        EmployeeSalary::create([
            'company_id' => $this->company->id, 'user_id' => $this->budi->id,
            'basic_salary' => self::BASIC, 'effective_date' => '2026-01-01',
            'is_active' => true, 'created_by' => $this->finance->id,
        ]);
        EmployeeTaxProfile::create([
            'company_id' => $this->company->id, 'user_id' => $this->budi->id,
            'ptkp_status' => 'TK/0', 'has_npwp' => true, 'tax_method' => 'gross',
        ]);
    }

    private function makeUser(string $name, string $email, string $role, ?string $code): User
    {
        $roleId = Role::whereNull('company_id')->where('slug', $role)->value('id');

        return User::create([
            'company_id' => $this->company->id, 'role_id' => $roleId, 'name' => $name,
            'email' => $email, 'password' => bcrypt('password'), 'role' => $role,
            'employee_code' => $code, 'department' => 'Umum', 'is_active' => true,
        ]);
    }

    /**
     * Terbitkan penyesuaian pencairan sisa cuti seperti yang dilakukan
     * LeavePeriodCloser saat reset: menunggu batch (payroll_id NULL + approved).
     */
    private function encashment(float $amount, int $days = 4): PayrollAdjustment
    {
        return PayrollAdjustment::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->budi->id,
            'payroll_id' => null,
            'type'       => PayrollAdjustment::TYPE_EARNING,
            'name'       => PayrollAdjustment::NAME_LEAVE_ENCASHMENT,
            'amount'     => $amount,
            'is_taxable' => true,
            'reason'     => "Pencairan {$days} hari sisa cuti periode s/d 31 Des 2025. "
                . 'Reset otomatis sesuai jadwal anniversary kantor.',
            'status'     => PayrollAdjustment::STATUS_APPROVED,
            'created_by' => null,
        ]);
    }

    /** Penyesuaian manual biasa, untuk membuktikan keduanya tidak tercampur. */
    private function manualAdjustment(float $amount): PayrollAdjustment
    {
        return PayrollAdjustment::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->budi->id,
            'payroll_id' => null,
            'type'       => PayrollAdjustment::TYPE_EARNING,
            'name'       => 'Koreksi Gaji Bulan Lalu',
            'amount'     => $amount,
            'is_taxable' => true,
            'reason'     => 'Rapel kenaikan gaji',
            'status'     => PayrollAdjustment::STATUS_APPROVED,
            'created_by' => $this->finance->id,
        ]);
    }

    /** @param array<string, mixed> $options */
    private function runPayroll(array $options = []): Payslip
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson('/api/v1/dashboard/payroll/runs', ['period_month' => 6, 'period_year' => 2026]);
        $res->assertStatus(201);
        $runId = $res->json('data.id');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/payroll/runs/{$runId}/calculate", $options)
            ->assertOk();

        return Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();
    }

    private function step(Payslip $slip, string $code): ?PayslipCalculationStep
    {
        return PayslipCalculationStep::where('payslip_id', $slip->id)
            ->where('step_code', $code)->first();
    }

    // ─────────────── 1. Baris slip ───────────────

    public function test_baris_slip_memakai_source_pencairan_cuti_bukan_penyesuaian(): void
    {
        $adj = $this->encashment(800_000);

        $slip = $this->runPayroll();

        $item = PayslipItem::where('payslip_id', $slip->id)
            ->where('label', PayrollAdjustment::NAME_LEAVE_ENCASHMENT)
            ->first();

        $this->assertNotNull($item, 'Baris pencairan sisa cuti tidak terbentuk di slip.');
        $this->assertSame(PayslipItem::TYPE_EARNING, $item->type);
        // Pembeda satu-satunya di seluruh rantai hilir.
        $this->assertSame('leave_encashment', $item->source);
        $this->assertEqualsWithDelta(800_000, (float) $item->amount, 0.01);
        $this->assertTrue((bool) $item->is_taxable, 'Pencairan sisa cuti adalah objek PPh 21.');

        // Alasan penyesuaian diteruskan ke slip agar karyawan tahu dasarnya.
        $this->assertStringContainsString('sisa cuti', (string) $item->notes);

        // Penyesuaian diklaim batch ini: tidak bisa dibayar dua kali oleh batch lain.
        $adj->refresh();
        $this->assertSame((int) $slip->payroll_id, (int) $adj->payroll_id);
        $this->assertSame(PayrollAdjustment::STATUS_APPLIED, $adj->status);
    }

    public function test_pencairan_masuk_bruto_dan_kena_pajak(): void
    {
        $this->encashment(800_000);

        $slip = $this->runPayroll();

        $this->assertEqualsWithDelta(self::BASIC + 800_000, (float) $slip->gross, 0.01);
        // Objek PPh 21 → menambah dasar pajak, bukan hanya bruto.
        $this->assertGreaterThan(0, (float) $slip->pph21);
    }

    // ─────────────── 2. Jejak perhitungan ───────────────

    public function test_jejak_perhitungan_memakai_langkah_pencairan_cuti_sendiri(): void
    {
        $adj = $this->encashment(800_000);

        $slip = $this->runPayroll();

        $step = $this->step($slip, PayslipCalculationStep::STEP_LEAVE_ENCASHMENT);
        $this->assertNotNull($step, 'Langkah LEAVE_ENCASHMENT tidak tercatat.');
        $this->assertEqualsWithDelta(800_000, (float) $step->final_result, 0.01);

        $inputs = is_array($step->input_payload) ? $step->input_payload : [];
        $this->assertSame(1, (int) $inputs['encashment_count']);
        $this->assertSame((int) $adj->id, (int) $inputs['adjustments'][0]['adjustment_id']);
        $this->assertEqualsWithDelta(800_000, (float) $inputs['adjustments'][0]['amount'], 0.01);

        // Tanpa penyesuaian manual, langkah koreksi manual TIDAK boleh muncul
        // hanya karena ada pencairan cuti.
        $this->assertNull(
            $this->step($slip, PayslipCalculationStep::STEP_ADJUSTMENT),
            'STEP_ADJUSTMENT muncul padahal tidak ada koreksi manual.'
        );
    }

    public function test_langkah_koreksi_manual_tidak_tercemar_nominal_pencairan(): void
    {
        // Keduanya hadir di batch yang sama: masing-masing harus melaporkan
        // angkanya sendiri, tanpa saling menjumlah.
        $this->encashment(800_000);
        $this->manualAdjustment(300_000);

        $slip = $this->runPayroll();

        $encash = $this->step($slip, PayslipCalculationStep::STEP_LEAVE_ENCASHMENT);
        $manual = $this->step($slip, PayslipCalculationStep::STEP_ADJUSTMENT);

        $this->assertNotNull($encash);
        $this->assertNotNull($manual);
        $this->assertEqualsWithDelta(800_000, (float) $encash->final_result, 0.01);
        $this->assertEqualsWithDelta(300_000, (float) $manual->final_result, 0.01);

        $inputs = is_array($manual->input_payload) ? $manual->input_payload : [];
        $this->assertEqualsWithDelta(300_000, (float) $inputs['adjustment_earning'], 0.01);

        // Bruto tetap menampung keduanya sekali saja.
        $this->assertEqualsWithDelta(self::BASIC + 1_100_000, (float) $slip->gross, 0.01);
    }

    public function test_beberapa_pencairan_dijumlah_dalam_satu_langkah(): void
    {
        // Karyawan bisa punya lebih dari satu penyesuaian menunggu (mis. reset
        // cabang lama yang belum pernah kena batch payroll).
        $this->encashment(800_000, 4);
        $this->encashment(400_000, 2);

        $slip = $this->runPayroll();

        $step = $this->step($slip, PayslipCalculationStep::STEP_LEAVE_ENCASHMENT);
        $this->assertNotNull($step);
        $this->assertEqualsWithDelta(1_200_000, (float) $step->final_result, 0.01);

        $inputs = is_array($step->input_payload) ? $step->input_payload : [];
        $this->assertSame(2, (int) $inputs['encashment_count']);

        $this->assertSame(2, PayslipItem::where('payslip_id', $slip->id)
            ->where('source', 'leave_encashment')->count());
    }

    // ─────────────── 3. Jurnal GL ───────────────

    public function test_jurnal_gl_mengalirkan_pencairan_ke_pos_beban_cuti(): void
    {
        $this->encashment(800_000);
        $this->manualAdjustment(300_000);

        $slip    = $this->runPayroll();
        $payroll = Payroll::findOrFail($slip->payroll_id);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-preview");
        $res->assertOk();

        $lines = collect($res->json('data.lines'));

        $cuti = $lines->firstWhere('key', 'expense_leave_encashment');
        $this->assertNotNull($cuti, 'Pos beban pencairan cuti tidak ada di jurnal.');
        $this->assertEqualsWithDelta(800_000, (float) $cuti['debit'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $cuti['credit'], 0.01);
        $this->assertSame('5180', (string) $cuti['account_code']);

        // Beban penyesuaian gaji HANYA memuat koreksi manual — bukti pencairan
        // cuti tidak menumpuk ke sana.
        $penyesuaian = $lines->firstWhere('key', 'expense_adjustment');
        $this->assertNotNull($penyesuaian);
        $this->assertEqualsWithDelta(300_000, (float) $penyesuaian['debit'], 0.01);

        // Jurnal tetap seimbang setelah pos baru ikut.
        $this->assertTrue((bool) $res->json('data.balanced'));
        $this->assertEqualsWithDelta(
            (float) $res->json('data.total_debit'),
            (float) $res->json('data.total_credit'),
            0.01
        );
    }

    public function test_ekspor_csv_gl_memuat_baris_pencairan_cuti(): void
    {
        $this->encashment(800_000);

        $slip    = $this->runPayroll();
        $payroll = Payroll::findOrFail($slip->payroll_id);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-export");
        $res->assertOk();

        $csv = $res->getContent();
        $this->assertStringContainsString('5180', $csv);
        $this->assertStringContainsString('Beban Pencairan Cuti', $csv);
    }

    public function test_jurnal_berdimensi_cabang_tetap_seimbang_dengan_pos_cuti(): void
    {
        $this->encashment(800_000);

        $slip    = $this->runPayroll();
        $payroll = Payroll::findOrFail($slip->payroll_id);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-preview?group_by=branch");
        $res->assertOk();

        $this->assertTrue((bool) $res->json('data.balanced'));

        $segmen = collect($res->json('data.segments'))
            ->first(fn ($s) => collect($s['lines'])->contains(
                fn ($l) => $l['key'] === 'expense_leave_encashment'
            ));

        $this->assertNotNull($segmen, 'Pos pencairan cuti tidak muncul di segmen mana pun.');
        $this->assertTrue((bool) $segmen['balanced']);
        $this->assertSame($this->office->id, (int) $segmen['segment_id']);
    }
}
