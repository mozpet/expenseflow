<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\OvertimeApproval;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Aturan upah lembur per kantor: pemerintah (PP 35/2021) vs aturan sendiri. */
class OvertimeRulePayrollTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Jakarta';

    private Company $company;
    private AttendanceSetting $office;
    private User $finance;
    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create(['name' => 'PT Lembur Aturan', 'is_active' => true]);
        $this->office  = $this->makeOffice();

        $this->finance = $this->makeUser('Andi Finance', 'andi@pt.co.id', 'finance', null);
        $this->budi    = $this->makeUser('Budi', 'budi@pt.co.id', 'employee', 'EMP-001');

        EmployeeSalary::create([
            'company_id' => $this->company->id, 'user_id' => $this->budi->id,
            'basic_salary' => 17_300_000, 'effective_date' => '2026-01-01',
            'is_active' => true, 'created_by' => $this->finance->id,
        ]);
        EmployeeTaxProfile::create([
            'company_id' => $this->company->id, 'user_id' => $this->budi->id,
            'ptkp_status' => 'TK/0', 'has_npwp' => true, 'tax_method' => 'gross',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeOffice(array $override = []): AttendanceSetting
    {
        return AttendanceSetting::create(array_merge([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Pusat',
            'office_latitude'        => -6.2,
            'office_longitude'       => 106.8166,
            'radius_meters'          => 200,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'work_days'              => [1, 2, 3, 4, 5],
            'late_tolerance_minutes' => 15,
            'overtime_enabled'       => true,
            'min_overtime_minutes'   => 30,
        ], $override));
    }

    private function makeUser(string $name, string $email, string $role, ?string $code): User
    {
        $roleId = Role::whereNull('company_id')->where('slug', $role)->value('id');

        return User::create([
            'company_id' => $this->company->id, 'role_id' => $roleId, 'name' => $name,
            'email' => $email, 'password' => bcrypt('password'), 'role' => $role,
            'employee_code' => $code, 'department' => 'Umum', 'is_active' => true,
            'attendance_setting_id' => $this->office->id ?? null,
            'attendance_enabled' => true, 'wfh_enabled' => true,
        ]);
    }

    /** Presensi + persetujuan lembur approved pada periode Juni 2026. */
    private function overtime(string $date, int $minutes, array $snap = [], bool $holiday = false): Attendance
    {
        $att = Attendance::create(array_merge([
            'company_id' => $this->company->id, 'user_id' => $this->budi->id,
            'date' => $date, 'check_in_time' => $date . ' 08:00:00',
            'check_in_type' => 'onsite', 'status' => 'present',
            'overtime_minutes' => $minutes, 'is_holiday' => $holiday,
        ], $snap));

        OvertimeApproval::create([
            'attendance_id' => $att->id, 'user_id' => $this->budi->id,
            'company_id' => $this->company->id, 'overtime_minutes' => $minutes,
            'status' => 'approved', 'current_step' => 'hrd',
        ]);

        return $att;
    }

    private function custom(float $amount, string $unit, bool $taxable = true): array
    {
        return [
            'snap_overtime_rule' => 'custom', 'snap_overtime_rate_amount' => $amount,
            'snap_overtime_rate_unit' => $unit, 'snap_overtime_rate_taxable' => $taxable,
        ];
    }

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

    private function item(Payslip $slip, string $label)
    {
        return PayslipItem::where('payslip_id', $slip->id)->where('label', $label)->get();
    }

    // ── Payroll ─────────────────────────────────────────────────────────

    public function test_tanpa_snapshot_tetap_memakai_aturan_pemerintah(): void
    {
        // upah 17.300.000 / 173 = 100.000 per jam; 2 jam hari kerja = (1,5 + 2) x 100.000
        $this->overtime('2026-06-02', 120);

        $slip = $this->runPayroll();

        $tunjangan = $this->item($slip, 'Tunjangan Lembur');
        $this->assertCount(1, $tunjangan);
        $this->assertEqualsWithDelta(350000, (float) $tunjangan->first()->amount, 0.01);
        $this->assertTrue((bool) $tunjangan->first()->is_taxable);
        $this->assertCount(0, $this->item($slip, 'Bonus Lembur'));
    }

    public function test_snapshot_government_sama_dengan_aturan_pemerintah(): void
    {
        $this->overtime('2026-06-02', 120, ['snap_overtime_rule' => 'government']);

        $slip = $this->runPayroll();

        $this->assertEqualsWithDelta(350000, (float) $this->item($slip, 'Tunjangan Lembur')->first()->amount, 0.01);
    }

    public function test_aturan_sendiri_per_jam(): void
    {
        $this->overtime('2026-06-02', 90, $this->custom(20000, 'hour')); // 1,5 jam x 20.000

        $slip = $this->runPayroll();

        $bonus = $this->item($slip, 'Bonus Lembur');
        $this->assertCount(1, $bonus);
        $this->assertEqualsWithDelta(30000, (float) $bonus->first()->amount, 0.01);
        $this->assertSame(PayslipItem::TYPE_EARNING, $bonus->first()->type);
        $this->assertCount(0, $this->item($slip, 'Tunjangan Lembur'));
    }

    public function test_aturan_sendiri_per_menit_dan_hari_libur_tarif_sama(): void
    {
        $this->overtime('2026-06-02', 90, $this->custom(500, 'minute'));            // 45.000
        $this->overtime('2026-06-06', 60, $this->custom(500, 'minute'), holiday: true); // 30.000

        $slip = $this->runPayroll();

        $this->assertEqualsWithDelta(75000, (float) $this->item($slip, 'Bonus Lembur')->first()->amount, 0.01);
    }

    public function test_flag_kena_pajak_tercermin_di_slip(): void
    {
        $this->overtime('2026-06-02', 60, $this->custom(20000, 'hour', false));
        $slip = $this->runPayroll();
        $this->assertFalse((bool) $this->item($slip, 'Bonus Lembur')->first()->is_taxable);
    }

    public function test_campuran_aturan_dan_status_pajak_menjadi_baris_terpisah(): void
    {
        $this->overtime('2026-06-02', 60);                                           // pemerintah
        $this->overtime('2026-06-03', 60, $this->custom(20000, 'hour', true));       // 20.000 kena pajak
        $this->overtime('2026-06-04', 60, $this->custom(10000, 'hour', false));      // 10.000 bebas pajak

        $slip = $this->runPayroll();

        $this->assertEqualsWithDelta(150000, (float) $this->item($slip, 'Tunjangan Lembur')->first()->amount, 0.01);
        $bonus = $this->item($slip, 'Bonus Lembur');
        $this->assertCount(2, $bonus);
        $this->assertEqualsWithDelta(20000, (float) $bonus->firstWhere('is_taxable', true)->amount, 0.01);
        $this->assertEqualsWithDelta(10000, (float) $bonus->firstWhere('is_taxable', false)->amount, 0.01);
    }

    public function test_opsi_overtime_false_menghapus_kedua_baris(): void
    {
        $this->overtime('2026-06-02', 60);
        $this->overtime('2026-06-03', 60, $this->custom(20000, 'hour'));

        $slip = $this->runPayroll(['overtime' => false]);

        $this->assertCount(0, $this->item($slip, 'Tunjangan Lembur'));
        $this->assertCount(0, $this->item($slip, 'Bonus Lembur'));
    }

    public function test_lembur_di_luar_periode_diabaikan(): void
    {
        $this->overtime('2026-07-02', 60, $this->custom(20000, 'hour'));

        $slip = $this->runPayroll();

        $this->assertCount(0, $this->item($slip, 'Bonus Lembur'));
    }

    // ── Snapshot saat check-in ──────────────────────────────────────────

    private function checkIn(User $user, AttendanceSetting $office): void
    {
        // Rabu 2026-10-07, dalam jam kerja kantor.
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:05:00', self::TZ));
        $user->update(['attendance_setting_id' => $office->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2000, 'longitude' => 106.8166,
        ])->assertCreated();
    }

    public function test_check_in_membekukan_aturan_custom(): void
    {
        $office = $this->makeOffice([
            'office_name' => 'Kantor Custom', 'overtime_rule' => 'custom',
            'overtime_rate_amount' => 25000, 'overtime_rate_unit' => 'hour', 'overtime_rate_taxable' => false,
        ]);

        $this->checkIn($this->budi, $office);
        $office->update(['overtime_rate_amount' => 99999, 'overtime_rule' => 'government']);

        $att = Attendance::where('user_id', $this->budi->id)->firstOrFail();
        $this->assertSame('custom', $att->snap_overtime_rule);
        $this->assertEquals(25000, (float) $att->snap_overtime_rate_amount);
        $this->assertSame('hour', $att->snap_overtime_rate_unit);
        $this->assertFalse((bool) $att->snap_overtime_rate_taxable);
    }

    public function test_check_in_kantor_pemerintah_menyimpan_rule_tanpa_tarif(): void
    {
        $this->checkIn($this->budi, $this->office);

        $att = Attendance::where('user_id', $this->budi->id)->firstOrFail();
        $this->assertSame('government', $att->snap_overtime_rule);
        $this->assertNull($att->snap_overtime_rate_amount);
    }

    // ── Pengaturan kantor ───────────────────────────────────────────────

    private function hrd(): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id, 'attendance_setting_id' => $this->office->id,
            'role' => 'hrd', 'is_active' => true,
        ]);
    }

    public function test_aturan_sendiri_tanpa_nominal_ditolak(): void
    {
        $hrd = $this->hrd();
        $url = "/api/v1/dashboard/attendance/settings/{$this->office->id}";

        $this->actingAs($hrd, 'sanctum')->putJson($url, [
            'overtime_rule' => 'custom', 'confirm_dangerous' => 'SIMPAN',
        ])->assertStatus(422)->assertJsonValidationErrors('overtime_rate_amount');

        $this->actingAs($hrd, 'sanctum')->putJson($url, [
            'overtime_rule' => 'custom', 'overtime_rate_amount' => 0, 'confirm_dangerous' => 'SIMPAN',
        ])->assertStatus(422);
    }

    public function test_validasi_nilai_enum_dan_negatif(): void
    {
        $hrd = $this->hrd();
        $url = "/api/v1/dashboard/attendance/settings/{$this->office->id}";

        $this->actingAs($hrd, 'sanctum')->putJson($url, ['overtime_rule' => 'ngawur', 'confirm_dangerous' => 'SIMPAN'])
            ->assertStatus(422)->assertJsonValidationErrors('overtime_rule');
        $this->actingAs($hrd, 'sanctum')->putJson($url, ['overtime_rate_unit' => 'hari', 'confirm_dangerous' => 'SIMPAN'])
            ->assertStatus(422)->assertJsonValidationErrors('overtime_rate_unit');
        $this->actingAs($hrd, 'sanctum')->putJson($url, ['overtime_rate_amount' => -1, 'confirm_dangerous' => 'SIMPAN'])
            ->assertStatus(422)->assertJsonValidationErrors('overtime_rate_amount');
    }

    public function test_mengubah_aturan_lembur_butuh_konfirmasi_simpan(): void
    {
        $hrd = $this->hrd();
        $url = "/api/v1/dashboard/attendance/settings/{$this->office->id}";
        $payload = [
            'overtime_rule' => 'custom', 'overtime_rate_amount' => 15000,
            'overtime_rate_unit' => 'minute', 'overtime_rate_taxable' => false,
        ];

        $res = $this->actingAs($hrd, 'sanctum')->putJson($url, $payload);
        $res->assertStatus(422)->assertJsonPath('requires_confirmation', true);
        foreach (['overtime_rule', 'overtime_rate_amount', 'overtime_rate_unit', 'overtime_rate_taxable'] as $f) {
            $this->assertContains($f, $res->json('dangerous_changed_fields'));
        }

        $this->actingAs($hrd, 'sanctum')->putJson($url, $payload + ['confirm_dangerous' => 'SIMPAN'])->assertOk();

        $this->office->refresh();
        $this->assertSame('custom', $this->office->overtime_rule);
        $this->assertEquals(15000, (float) $this->office->overtime_rate_amount);
        $this->assertSame('minute', $this->office->overtime_rate_unit);
        $this->assertFalse((bool) $this->office->overtime_rate_taxable);
    }

    public function test_nilai_sama_tidak_dianggap_perubahan_berbahaya(): void
    {
        $this->office->update(['overtime_rule' => 'custom', 'overtime_rate_amount' => 15000]);
        $hrd = $this->hrd();

        $this->actingAs($hrd, 'sanctum')
            ->putJson("/api/v1/dashboard/attendance/settings/{$this->office->id}", [
                'overtime_rule' => 'custom', 'overtime_rate_amount' => '15000.00',
                'overtime_rate_unit' => 'hour', 'overtime_rate_taxable' => true,
            ])->assertOk();
    }

    public function test_buat_kantor_menyimpan_aturan_lembur(): void
    {
        $hrd = $this->hrd();
        $base = [
            'office_name' => 'Cabang Baru', 'office_latitude' => -6.9, 'office_longitude' => 107.6,
            'radius_meters' => 150, 'work_start_time' => '08:00', 'work_end_time' => '17:00',
            'work_days' => [1, 2, 3, 4, 5], 'default_leave_quota' => 12,
        ];

        $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/settings', $base + [
            'overtime_rule' => 'custom',
        ])->assertStatus(422);

        $res = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/settings', $base + [
            'overtime_rule' => 'custom', 'overtime_rate_amount' => 12000,
            'overtime_rate_unit' => 'minute', 'overtime_rate_taxable' => false,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('attendance_settings', [
            'id' => $res->json('setting.id'), 'overtime_rule' => 'custom',
            'overtime_rate_amount' => 12000, 'overtime_rate_unit' => 'minute', 'overtime_rate_taxable' => 0,
        ]);

        $default = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/settings', array_merge($base, [
            'office_name' => 'Cabang Default',
        ]));
        $default->assertCreated();
        $this->assertSame('government', $default->json('setting.overtime_rule'));
    }
}
