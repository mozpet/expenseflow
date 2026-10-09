<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payslip;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Denda keterlambatan menjadi potongan "Potongan Keterlambatan" pada slip gaji. */
class LateFinePayrollTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create(['name' => 'PT Potong Telat', 'is_active' => true]);

        AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Jakarta',
            'office_latitude'        => -6.2,
            'office_longitude'       => 106.8166,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $this->finance = $this->makeUser('Andi Finance', 'andi@pt.co.id', 'finance', null);
        $this->budi    = $this->makeUser('Budi', 'budi@pt.co.id', 'employee', 'EMP-001');

        EmployeeSalary::create([
            'company_id' => $this->company->id, 'user_id' => $this->budi->id,
            'basic_salary' => 10_000_000, 'effective_date' => '2026-01-01',
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

    private function late(string $date, ?int $minutes, ?float $fine): void
    {
        Attendance::create([
            'company_id' => $this->company->id, 'user_id' => $this->budi->id,
            'date' => $date, 'check_in_time' => $date . ' 08:20:00',
            'check_in_type' => 'onsite', 'status' => 'late',
            'late_minutes' => $minutes, 'late_fine_amount' => $fine,
        ]);
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

    public function test_denda_telat_dijumlah_menjadi_potongan_slip(): void
    {
        $this->late('2026-06-02', 20, 20000);
        $this->late('2026-06-03', 16, 16000);
        $this->late('2026-07-01', 30, 30000); // di luar periode
        $this->late('2026-06-04', null, null); // baris lama

        $slip = $this->runPayroll();

        $item = PayslipItem::where('payslip_id', $slip->id)->where('label', 'Potongan Keterlambatan')->first();
        $this->assertNotNull($item);
        $this->assertSame(PayslipItem::TYPE_DEDUCTION, $item->type);
        $this->assertSame('attendance', $item->source);
        $this->assertEqualsWithDelta(36000, (float) $item->amount, 0.01);

        $this->assertTrue(
            PayslipCalculationStep::where('payslip_id', $slip->id)
                ->where('step_code', PayslipCalculationStep::STEP_LATE_PENALTY)->exists()
        );
    }

    public function test_tanpa_denda_tidak_ada_baris_potongan(): void
    {
        $slip = $this->runPayroll();

        $this->assertFalse(
            PayslipItem::where('payslip_id', $slip->id)->where('label', 'Potongan Keterlambatan')->exists()
        );
    }

    public function test_opsi_late_penalty_false_menonaktifkan_potongan(): void
    {
        $this->late('2026-06-02', 20, 20000);

        $slip = $this->runPayroll(['late_penalty' => false]);

        $this->assertFalse(
            PayslipItem::where('payslip_id', $slip->id)->where('label', 'Potongan Keterlambatan')->exists()
        );
    }
}
