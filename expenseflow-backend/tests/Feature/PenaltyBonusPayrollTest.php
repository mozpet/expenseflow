<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\AttendancePolicyCalculator;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integrasi "Peraturan Denda & Bonus Kantor" (2026-10-11) ke slip gaji.
 *
 * Gaji pokok 10.500.000 dengan divisor 21 → upah sehari tepat 500.000 dan upah
 * per jam tepat 62.500, sehingga semua ekspektasi rupiah di tes ini bulat dan
 * bisa dibaca tanpa kalkulator.
 */
class PenaltyBonusPayrollTest extends TestCase
{
    use RefreshDatabase;

    private const BASIC      = 10_500_000; // / 21 = 500.000 sehari
    private const DAILY_WAGE = 500_000;

    private Company $company;
    private AttendanceSetting $office;
    private User $finance;
    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create(['name' => 'PT Denda Bonus', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'                    => $this->company->id,
            'office_name'                   => 'Kantor Jakarta',
            'office_latitude'               => -6.2,
            'office_longitude'              => 106.8166,
            'radius_meters'                 => 100,
            'work_start_time'               => '08:00:00',
            'work_end_time'                 => '17:00:00',
            'late_tolerance_minutes'        => 15,
            'early_leave_tolerance_minutes' => 30,
        ]);

        $this->finance = $this->makeUser('Andi Finance', 'andi@db.co.id', 'finance', null);
        $this->budi    = $this->makeUser('Budi', 'budi@db.co.id', 'employee', 'EMP-001');
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

    /** @param array<string, mixed> $extra */
    private function attendance(string $date, string $status, array $extra = []): Attendance
    {
        return Attendance::create(array_merge([
            'company_id'    => $this->company->id,
            'user_id'       => $this->budi->id,
            'date'          => $date,
            'check_in_time' => $date . ' 08:00:00',
            'check_in_type' => 'onsite',
            'status'        => $status,
        ], $extra));
    }

    private function leave(string $type, string $start, string $end, float $days): void
    {
        LeaveRequest::create([
            'company_id'     => $this->company->id,
            'user_id'        => $this->budi->id,
            'leave_type'     => $type,
            'start_date'     => $start,
            'end_date'       => $end,
            'total_days'     => $days,
            'reason'         => 'Uji potongan izin',
            'status'         => 'approved',
            'approved_by'    => $this->finance->id,
            'approved_at'    => now(),
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

    private function item(Payslip $slip, string $label): ?PayslipItem
    {
        return PayslipItem::where('payslip_id', $slip->id)->where('label', $label)->first();
    }

    private function hasStep(Payslip $slip, string $code): bool
    {
        return PayslipCalculationStep::where('payslip_id', $slip->id)
            ->where('step_code', $code)->exists();
    }

    // ─────────────── 1. Pulang cepat ───────────────

    public function test_denda_pulang_cepat_dijumlah_dari_nilai_presensi(): void
    {
        $this->attendance('2026-06-02', 'early_leave', [
            'early_leave_minutes' => 40, 'early_leave_fine_amount' => 40_000,
            'snap_early_leave_fine_mode' => 'per_minute',
        ]);
        $this->attendance('2026-06-03', 'early_leave', [
            'early_leave_minutes' => 15, 'early_leave_fine_amount' => 15_000,
            'snap_early_leave_fine_mode' => 'per_minute',
        ]);
        // Di luar periode — tidak boleh ikut.
        $this->attendance('2026-07-01', 'early_leave', [
            'early_leave_minutes' => 60, 'early_leave_fine_amount' => 60_000,
            'snap_early_leave_fine_mode' => 'per_minute',
        ]);
        // Baris lama tanpa snapshot — tidak punya denda.
        $this->attendance('2026-06-04', 'early_leave');

        $slip = $this->runPayroll();
        $item = $this->item($slip, 'Potongan Pulang Cepat');

        $this->assertNotNull($item);
        $this->assertSame(PayslipItem::TYPE_DEDUCTION, $item->type);
        $this->assertSame('early_leave', $item->source);
        $this->assertEqualsWithDelta(55_000, (float) $item->amount, 0.01);
        $this->assertStringContainsString('55 menit', (string) $item->notes);
        $this->assertTrue($this->hasStep($slip, PayslipCalculationStep::STEP_EARLY_LEAVE_PENALTY));
    }

    public function test_pulang_cepat_mode_prorata_dihitung_dari_upah_per_jam(): void
    {
        // Mode prorata: presensi membekukan MENIT saja, rupiahnya dihitung payroll.
        // 120 menit = 2 jam × (500.000 / 8) = 125.000.
        $this->attendance('2026-06-02', 'early_leave', [
            'early_leave_minutes' => 120, 'early_leave_fine_amount' => 0,
            'snap_early_leave_fine_mode' => AttendancePolicyCalculator::ALPHA_PRORATA,
        ]);

        $slip = $this->runPayroll();
        $item = $this->item($slip, 'Potongan Pulang Cepat');

        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(125_000, (float) $item->amount, 0.01);
    }

    public function test_opsi_early_leave_penalty_false_menonaktifkan_potongan(): void
    {
        $this->attendance('2026-06-02', 'early_leave', [
            'early_leave_minutes' => 40, 'early_leave_fine_amount' => 40_000,
            'snap_early_leave_fine_mode' => 'per_minute',
        ]);

        $slip = $this->runPayroll(['early_leave_penalty' => false]);

        $this->assertNull($this->item($slip, 'Potongan Pulang Cepat'));
    }

    // ─────────────── 2. Sanksi mangkir (alpha) ───────────────

    public function test_alpha_mode_prorata_identik_dengan_potongan_ketidakhadiran_lama(): void
    {
        $this->attendance('2026-06-02', 'absent');
        $this->attendance('2026-06-03', 'absent');

        $slip = $this->runPayroll();

        $absen = $this->item($slip, 'Potongan Ketidakhadiran');
        $this->assertNotNull($absen);
        $this->assertEqualsWithDelta(2 * self::DAILY_WAGE, (float) $absen->amount, 0.01);
        // Mode prorata TIDAK boleh menghasilkan baris denda tambahan.
        $this->assertNull($this->item($slip, 'Denda Mangkir'));
    }

    public function test_alpha_mode_none_membatalkan_potongan_gaji_pokok(): void
    {
        $this->office->update(['alpha_penalty_mode' => AttendancePolicyCalculator::ALPHA_NONE]);
        $this->attendance('2026-06-02', 'absent');

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Potongan Ketidakhadiran'));
        $this->assertNull($this->item($slip, 'Denda Mangkir'));
    }

    public function test_alpha_mode_prorata_plus_fine_menambah_baris_denda_terpisah(): void
    {
        $this->office->update([
            'alpha_penalty_mode' => AttendancePolicyCalculator::ALPHA_PRORATA_PLUS_FINE,
            'alpha_fine_flat'    => 100_000,
            'alpha_sp_threshold' => 2,
        ]);
        $this->attendance('2026-06-02', 'absent');
        $this->attendance('2026-06-03', 'absent');

        $slip = $this->runPayroll();

        $absen = $this->item($slip, 'Potongan Ketidakhadiran');
        $this->assertNotNull($absen);
        $this->assertEqualsWithDelta(1_000_000, (float) $absen->amount, 0.01);

        $denda = $this->item($slip, 'Denda Mangkir');
        $this->assertNotNull($denda);
        $this->assertSame('alpha_penalty', $denda->source);
        $this->assertEqualsWithDelta(200_000, (float) $denda->amount, 0.01);

        $step = PayslipCalculationStep::where('payslip_id', $slip->id)
            ->where('step_code', PayslipCalculationStep::STEP_ALPHA_PENALTY)->firstOrFail();
        $this->assertTrue($step->input_payload['needs_warning_letter']);
    }

    public function test_opsi_alpha_penalty_false_mengembalikan_perilaku_prorata_lama(): void
    {
        // Kantor memilih 'none', tetapi modul dimatikan untuk batch ini →
        // perilaku kembali seperti sebelum fitur ada (prorata), bukan 'none'.
        $this->office->update(['alpha_penalty_mode' => AttendancePolicyCalculator::ALPHA_NONE]);
        $this->attendance('2026-06-02', 'absent');

        $slip = $this->runPayroll(['alpha_penalty' => false]);

        $absen = $this->item($slip, 'Potongan Ketidakhadiran');
        $this->assertNotNull($absen);
        $this->assertEqualsWithDelta(self::DAILY_WAGE, (float) $absen->amount, 0.01);
    }

    // ─────────────── 3. Potongan izin pribadi ───────────────

    public function test_izin_pribadi_mode_flat_dihitung_per_pengajuan(): void
    {
        $this->office->update([
            'unpaid_leave_penalty_mode' => AttendancePolicyCalculator::UNPAID_FLAT,
            'unpaid_leave_fine_flat'    => 150_000,
        ]);
        $this->leave('izin', '2026-06-02', '2026-06-04', 3); // 1 pengajuan
        $this->leave('izin', '2026-06-10', '2026-06-10', 1); // 1 pengajuan

        $slip = $this->runPayroll();
        $item = $this->item($slip, 'Potongan Izin Pribadi');

        $this->assertNotNull($item);
        $this->assertSame('unpaid_leave', $item->source);
        $this->assertEqualsWithDelta(300_000, (float) $item->amount, 0.01);
        $this->assertStringContainsString('2 pengajuan', (string) $item->notes);
    }

    public function test_izin_pribadi_mode_prorata_dihitung_per_hari_irisan_periode(): void
    {
        $this->office->update([
            'unpaid_leave_penalty_mode' => AttendancePolicyCalculator::UNPAID_PRORATA,
        ]);
        // 28 Jun – 2 Jul: hanya 3 hari (28,29,30) yang jatuh di Juni.
        $this->leave('izin', '2026-06-28', '2026-07-02', 5);

        $slip = $this->runPayroll();
        $item = $this->item($slip, 'Potongan Izin Pribadi');

        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(3 * self::DAILY_WAGE, (float) $item->amount, 0.01);
    }

    public function test_cuti_dan_sakit_tidak_pernah_dipotong_sebagai_izin_pribadi(): void
    {
        $this->office->update([
            'unpaid_leave_penalty_mode' => AttendancePolicyCalculator::UNPAID_PRORATA,
        ]);
        $this->leave('cuti', '2026-06-02', '2026-06-04', 3);
        $this->leave('sakit', '2026-06-10', '2026-06-11', 2);

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Potongan Izin Pribadi'));
    }

    public function test_izin_mode_none_tidak_memotong(): void
    {
        $this->leave('izin', '2026-06-02', '2026-06-04', 3);

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Potongan Izin Pribadi'));
    }

    // ─────────────── 4. Denda lupa check-out ───────────────

    public function test_denda_lupa_checkout_dijumlah_dari_nilai_presensi(): void
    {
        $this->attendance('2026-06-02', 'present', [
            'is_auto_checkout' => true, 'missed_checkout_fine_amount' => 25_000,
        ]);
        $this->attendance('2026-06-03', 'present', [
            'is_auto_checkout' => true, 'missed_checkout_fine_amount' => 25_000,
        ]);

        $slip = $this->runPayroll();
        $item = $this->item($slip, 'Denda Lupa Check-Out');

        $this->assertNotNull($item);
        $this->assertSame('missed_checkout', $item->source);
        $this->assertEqualsWithDelta(50_000, (float) $item->amount, 0.01);
        $this->assertTrue($this->hasStep($slip, PayslipCalculationStep::STEP_MISSED_CHECKOUT));
    }

    public function test_opsi_missed_checkout_penalty_false_menonaktifkan_denda(): void
    {
        $this->attendance('2026-06-02', 'present', [
            'is_auto_checkout' => true, 'missed_checkout_fine_amount' => 25_000,
        ]);

        $slip = $this->runPayroll(['missed_checkout_penalty' => false]);

        $this->assertNull($this->item($slip, 'Denda Lupa Check-Out'));
    }

    // ─────────────── 5. Insentif kehadiran penuh ───────────────

    public function test_insentif_kehadiran_penuh_diberikan_dan_kena_pajak(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
        ]);
        $this->attendance('2026-06-02', 'present');
        $this->attendance('2026-06-03', 'present');

        $slip = $this->runPayroll();
        $item = $this->item($slip, 'Insentif Kehadiran Penuh');

        $this->assertNotNull($item);
        $this->assertSame(PayslipItem::TYPE_EARNING, $item->type);
        $this->assertSame('perfect_attendance', $item->source);
        $this->assertTrue((bool) $item->is_taxable);
        $this->assertEqualsWithDelta(500_000, (float) $item->amount, 0.01);
        // Earning WAJIB ikut bruto (disisipkan sebelum perhitungan bruto).
        $this->assertGreaterThanOrEqual(self::BASIC + 500_000, (float) $slip->gross);
        $this->assertTrue($this->hasStep($slip, PayslipCalculationStep::STEP_PERFECT_ATTENDANCE));
    }

    public function test_insentif_gugur_karena_telat(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
        ]);
        $this->attendance('2026-06-02', 'present');
        $this->attendance('2026-06-03', 'late', ['late_minutes' => 10]);

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Insentif Kehadiran Penuh'));
    }

    public function test_insentif_gugur_karena_alpha(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
        ]);
        $this->attendance('2026-06-02', 'present');
        $this->attendance('2026-06-03', 'absent');

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Insentif Kehadiran Penuh'));
    }

    public function test_insentif_gugur_karena_izin_pribadi(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
        ]);
        $this->attendance('2026-06-02', 'present');
        $this->leave('izin', '2026-06-10', '2026-06-10', 1);

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Insentif Kehadiran Penuh'));
    }

    public function test_insentif_gugur_karena_status_early_leave_tanpa_menit(): void
    {
        // Baris 'mark_early_leave' (lupa check-out): tanpa menit, tanpa denda
        // rupiah, tetapi tetap menggugurkan kehadiran penuh.
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
        ]);
        $this->attendance('2026-06-02', 'present');
        $this->attendance('2026-06-03', 'early_leave', ['is_auto_checkout' => true]);

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Insentif Kehadiran Penuh'));
        // Tanpa menit → tidak ada potongan rupiah pulang cepat.
        $this->assertNull($this->item($slip, 'Potongan Pulang Cepat'));
    }

    public function test_cuti_resmi_tidak_menggugurkan_insentif_bila_allow_leave_true(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
            'perfect_attendance_allow_leave'   => true,
        ]);
        $this->attendance('2026-06-02', 'present');
        $this->leave('cuti', '2026-06-10', '2026-06-12', 3);

        $slip = $this->runPayroll();

        $this->assertNotNull($this->item($slip, 'Insentif Kehadiran Penuh'));
    }

    public function test_cuti_resmi_menggugurkan_insentif_bila_allow_leave_false(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
            'perfect_attendance_allow_leave'   => false,
        ]);
        $this->attendance('2026-06-02', 'present');
        $this->leave('cuti', '2026-06-10', '2026-06-12', 3);

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Insentif Kehadiran Penuh'));
    }

    public function test_tanpa_hari_hadir_tidak_dapat_insentif(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
        ]);

        $slip = $this->runPayroll();

        $this->assertNull($this->item($slip, 'Insentif Kehadiran Penuh'));
    }

    // ─────────────── 6. Premi shift malam ───────────────

    public function test_premi_shift_malam_dijumlah_dan_kena_pajak(): void
    {
        $this->attendance('2026-06-02', 'present', ['night_shift_bonus_amount' => 75_000]);
        $this->attendance('2026-06-03', 'present', ['night_shift_bonus_amount' => 75_000]);
        $this->attendance('2026-07-01', 'present', ['night_shift_bonus_amount' => 75_000]); // luar periode

        $slip = $this->runPayroll();
        $item = $this->item($slip, 'Premi Shift Malam');

        $this->assertNotNull($item);
        $this->assertSame(PayslipItem::TYPE_EARNING, $item->type);
        $this->assertSame('night_shift', $item->source);
        $this->assertTrue((bool) $item->is_taxable);
        $this->assertEqualsWithDelta(150_000, (float) $item->amount, 0.01);
        $this->assertStringContainsString('2 shift malam', (string) $item->notes);
        $this->assertGreaterThanOrEqual(self::BASIC + 150_000, (float) $slip->gross);
        $this->assertTrue($this->hasStep($slip, PayslipCalculationStep::STEP_NIGHT_SHIFT_BONUS));
    }

    public function test_opsi_night_shift_bonus_false_menonaktifkan_premi(): void
    {
        $this->attendance('2026-06-02', 'present', ['night_shift_bonus_amount' => 75_000]);

        $slip = $this->runPayroll(['night_shift_bonus' => false]);

        $this->assertNull($this->item($slip, 'Premi Shift Malam'));
    }

    // ─────────────── Regresi: kantor tanpa kebijakan ───────────────

    public function test_kantor_tanpa_kebijakan_berperilaku_seperti_sebelum_fitur_ada(): void
    {
        $this->attendance('2026-06-02', 'present');
        $this->attendance('2026-06-03', 'absent');

        $slip = $this->runPayroll();

        // Hanya potongan ketidakhadiran lama yang muncul.
        $this->assertNotNull($this->item($slip, 'Potongan Ketidakhadiran'));
        foreach ([
            'Potongan Pulang Cepat', 'Denda Mangkir', 'Potongan Izin Pribadi',
            'Denda Lupa Check-Out', 'Insentif Kehadiran Penuh', 'Premi Shift Malam',
        ] as $label) {
            $this->assertNull($this->item($slip, $label), "Baris '{$label}' seharusnya tidak ada.");
        }
    }

    public function test_jurnal_gl_memetakan_denda_dan_bonus_ke_pos_terpisah(): void
    {
        $this->office->update([
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500_000,
        ]);
        $this->attendance('2026-06-02', 'present', ['night_shift_bonus_amount' => 75_000]);
        $this->attendance('2026-06-03', 'present', [
            'is_auto_checkout' => true, 'missed_checkout_fine_amount' => 25_000,
        ]);

        $slip = $this->runPayroll();
        $this->assertNotNull($this->item($slip, 'Premi Shift Malam'));

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$slip->payroll_id}/gl-preview")
            ->assertOk();

        $lines = collect($res->json('data.lines'))->keyBy('key');

        $this->assertTrue($lines->has('expense_attendance_incentive'));
        $this->assertEqualsWithDelta(
            575_000, // premi 75.000 + insentif 500.000
            (float) $lines['expense_attendance_incentive']['debit'],
            0.01
        );
        $this->assertTrue($lines->has('payable_attendance_penalty'));
        $this->assertEqualsWithDelta(
            25_000,
            (float) $lines['payable_attendance_penalty']['credit'],
            0.01
        );
        // Jurnal tetap seimbang setelah pos baru ditambahkan.
        $this->assertTrue($res->json('data.balanced'));
    }
}
