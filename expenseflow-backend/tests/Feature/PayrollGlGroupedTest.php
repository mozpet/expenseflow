<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Division;
use App\Models\EmployeeBpjsProfile;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\User;
use App\Services\Payroll\Gl\PayrollGlComposer;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 4 — Alokasi cost center pada Jurnal GL (`?group_by=none|division|branch`).
 *
 * Membuktikan invarian utama pengelompokan:
 *  (i)   group_by=none = jurnal flat compose() (byte-identik perilaku lama).
 *  (ii)  setiap segmen SELF-BALANCE (Σdebit == Σkredit).
 *  (iii) Σ seluruh segmen == jurnal flat (total & per-pos) — item, BPJS perusahaan, & net
 *        terpartisi habis antar segmen (BPJS/net diturunkan per-slip, BUKAN skalar payroll).
 *  (iv)  karyawan tanpa dimensi → segmen sentinel tetap seimbang.
 *  (v)   ekspor CSV/JSON berdimensi 200 & teraudit; group_by invalid → 422.
 */
class PayrollGlGroupedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $hrd;
    private User $employee; // tanpa izin payroll
    private Division $divEng;
    private Division $divFin;
    private AttendanceSetting $officeJkt;
    private AttendanceSetting $officeBdg;

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

        $this->divEng = Division::create(['company_id' => $this->company->id, 'name' => 'Teknologi', 'code' => 'ENG', 'is_active' => true]);
        $this->divFin = Division::create(['company_id' => $this->company->id, 'name' => 'Keuangan', 'code' => 'FIN', 'is_active' => true]);

        $this->officeJkt = $this->makeOffice('Kantor Jakarta');
        $this->officeBdg = $this->makeOffice('Kantor Bandung');

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $hrdRole      = Role::whereNull('company_id')->where('slug', 'hrd')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id, null);
        $this->hrd      = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id, null);
        $this->employee = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id, 'EMP-000');

        // Tiga karyawan bergaji tersebar di dimensi berbeda + satu tanpa dimensi.
        $budi = $this->makeUser('Budi Teknologi', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001', $this->divEng->id, $this->officeJkt->id);
        $siti = $this->makeUser('Siti Keuangan', 'siti@mb.co.id', 'employee', $employeeRole->id, 'EMP-002', $this->divFin->id, $this->officeBdg->id);
        $joni = $this->makeUser('Joni Lepas', 'joni@mb.co.id', 'employee', $employeeRole->id, 'EMP-003'); // tanpa divisi/cabang

        $this->setSalary($budi, 10_000_000, 'TK/0');
        $this->setSalary($siti, 8_000_000, 'K/1');
        $this->setSalary($joni, 6_000_000, 'TK/0');

        // BPJS hanya untuk budi (divisi ENG) → membuktikan BPJS perusahaan diturunkan
        // PER SEGMEN dari slip, bukan dari skalar payroll.total_bpjs_company.
        EmployeeBpjsProfile::create([
            'company_id'     => $this->company->id,
            'user_id'        => $budi->id,
            'has_bpjs_kes'   => true,
            'has_bpjs_tk'    => true,
            'jkk_risk_class' => 1,
        ]);
    }

    private function makeOffice(string $name): AttendanceSetting
    {
        return AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => $name,
            'office_latitude'             => -6.20000000,
            'office_longitude'            => 106.81666700,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);
    }

    private function makeUser(string $name, string $email, string $role, int $roleId, ?string $code, ?int $divisionId = null, ?int $attendanceSettingId = null): User
    {
        return User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $roleId,
            'name'                  => $name,
            'email'                 => $email,
            'password'              => bcrypt('password'),
            'role'                  => $role,
            'employee_code'         => $code,
            'department'            => 'Umum',
            'division_id'           => $divisionId,
            'attendance_setting_id' => $attendanceSettingId,
            'is_active'             => true,
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
            'npwp'        => null,
            'ptkp_status' => $ptkp,
            'has_npwp'    => false,
            'tax_method'  => 'gross',
        ]);
    }

    /** Buat run 6/2026 → calculate → submit → approve → kembalikan Payroll (approved). */
    private function approvedRun(): Payroll
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

        return Payroll::findOrFail($runId);
    }

    /** Peta pos-jurnal → [debit, credit] dari daftar baris. */
    private function bucketMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $key = $line['key'];
            $map[$key]['debit']  = ($map[$key]['debit'] ?? 0.0) + (float) $line['debit'];
            $map[$key]['credit'] = ($map[$key]['credit'] ?? 0.0) + (float) $line['credit'];
        }

        return $map;
    }

    public function test_grouped_grand_total_equals_flat_compose(): void
    {
        $payroll = $this->approvedRun();
        $composer = new PayrollGlComposer();

        $flat = $composer->compose($payroll);
        $this->assertTrue($flat['balanced']);

        foreach (['division', 'attendance_setting'] as $dim) {
            $grouped = $composer->composeGrouped($payroll, $dim);

            $this->assertTrue($grouped['balanced'], "Grand total dimensi {$dim} harus seimbang.");
            $this->assertEqualsWithDelta((float) $flat['total_debit'], (float) $grouped['grand_total_debit'], 0.01, "Debit dimensi {$dim} harus == flat.");
            $this->assertEqualsWithDelta((float) $flat['total_credit'], (float) $grouped['grand_total_credit'], 0.01, "Kredit dimensi {$dim} harus == flat.");
        }
    }

    public function test_each_segment_is_balanced_and_buckets_sum_to_flat_division(): void
    {
        $payroll = $this->approvedRun();
        $composer = new PayrollGlComposer();

        $flat = $composer->compose($payroll);
        $grouped = $composer->composeGrouped($payroll, 'division');

        // 3 segmen: Teknologi (ENG), Keuangan (FIN), Tanpa Divisi.
        $this->assertCount(3, $grouped['segments']);

        // (ii) tiap segmen seimbang & tak kosong.
        $aggregate = [];
        foreach ($grouped['segments'] as $seg) {
            $this->assertTrue($seg['balanced'], "Segmen {$seg['label']} harus seimbang.");
            $this->assertNotEmpty($seg['lines']);
            $this->assertEqualsWithDelta((float) $seg['total_debit'], (float) $seg['total_credit'], 0.01);
            foreach ($this->bucketMap($seg['lines']) as $key => $amt) {
                $aggregate[$key]['debit']  = ($aggregate[$key]['debit'] ?? 0.0) + $amt['debit'];
                $aggregate[$key]['credit'] = ($aggregate[$key]['credit'] ?? 0.0) + $amt['credit'];
            }
        }

        // (iii) Σ per-pos antar segmen == jurnal flat per-pos (himpunan pos sama; urutan bebas).
        $flatMap = $this->bucketMap($flat['lines']);
        $this->assertEqualsCanonicalizing(array_keys($flatMap), array_keys($aggregate), 'Himpunan pos jurnal harus sama.');
        foreach ($flatMap as $key => $amt) {
            $this->assertEqualsWithDelta($amt['debit'], $aggregate[$key]['debit'], 0.01, "Debit pos {$key} harus == flat.");
            $this->assertEqualsWithDelta($amt['credit'], $aggregate[$key]['credit'], 0.01, "Kredit pos {$key} harus == flat.");
        }

        // BPJS perusahaan HANYA muncul pada segmen Teknologi (budi), bukan lainnya.
        $eng = collect($grouped['segments'])->firstWhere('label', 'Teknologi (ENG)');
        $fin = collect($grouped['segments'])->firstWhere('label', 'Keuangan (FIN)');
        $this->assertNotNull($eng);
        $this->assertNotNull($fin);
        $engBpjs = $this->bucketMap($eng['lines'])['expense_bpjs_company']['debit'] ?? 0.0;
        $finBpjs = $this->bucketMap($fin['lines'])['expense_bpjs_company']['debit'] ?? 0.0;
        $this->assertGreaterThan(0, $engBpjs, 'Segmen Teknologi harus memuat BPJS perusahaan.');
        $this->assertSame(0.0, $finBpjs, 'Segmen Keuangan tidak boleh memuat BPJS perusahaan.');
    }

    public function test_branch_grouping_is_balanced_and_sums_to_flat(): void
    {
        $payroll = $this->approvedRun();
        $composer = new PayrollGlComposer();

        $flat = $composer->compose($payroll);
        $grouped = $composer->composeGrouped($payroll, 'attendance_setting');

        $this->assertCount(3, $grouped['segments']); // Jakarta, Bandung, Tanpa Cabang.

        $sumDebit = 0.0;
        $sumCredit = 0.0;
        foreach ($grouped['segments'] as $seg) {
            $this->assertTrue($seg['balanced']);
            $sumDebit += (float) $seg['total_debit'];
            $sumCredit += (float) $seg['total_credit'];
        }

        $this->assertEqualsWithDelta((float) $flat['total_debit'], $sumDebit, 0.01);
        $this->assertEqualsWithDelta((float) $flat['total_credit'], $sumCredit, 0.01);

        // Label cabang memakai office_name.
        $labels = array_column($grouped['segments'], 'label');
        $this->assertContains('Kantor Jakarta', $labels);
        $this->assertContains('Kantor Bandung', $labels);
        $this->assertContains('Tanpa Cabang', $labels);
    }

    public function test_employee_without_dimension_lands_in_balanced_sentinel(): void
    {
        $payroll = $this->approvedRun();
        $composer = new PayrollGlComposer();

        $grouped = $composer->composeGrouped($payroll, 'division');
        $sentinel = collect($grouped['segments'])->firstWhere('key', 'tanpa_divisi');

        $this->assertNotNull($sentinel, 'Segmen "tanpa_divisi" harus ada untuk karyawan tanpa divisi.');
        $this->assertSame('Tanpa Divisi', $sentinel['label']);
        $this->assertTrue($sentinel['balanced']);
        $this->assertGreaterThan(0, (float) $sentinel['total_debit']);

        // Sentinel selalu di posisi terakhir.
        $this->assertSame('tanpa_divisi', $grouped['segments'][array_key_last($grouped['segments'])]['key']);
    }

    public function test_preview_group_by_via_http_and_invalid_rejected(): void
    {
        $payroll = $this->approvedRun();

        // group_by=none (default) → jurnal flat (tanpa segmen).
        $none = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-preview")
            ->assertOk()->json('data');
        $this->assertSame('none', $none['group_by']);
        $this->assertArrayHasKey('lines', $none);
        $this->assertArrayNotHasKey('segments', $none);

        // group_by=division → berdimensi.
        $div = $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-preview?group_by=division")
            ->assertOk()->json('data');
        $this->assertSame('division', $div['group_by']);
        $this->assertTrue($div['balanced']);
        $this->assertCount(3, $div['segments']);

        // group_by tidak valid → 422.
        $this->actingAs($this->finance, 'sanctum')
            ->getJson("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-preview?group_by=ngawur")
            ->assertStatus(422);
    }

    public function test_export_grouped_csv_and_json_are_gated_and_audited(): void
    {
        $payroll = $this->approvedRun();

        // Tanpa manage → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-export?format=csv&group_by=division")
            ->assertStatus(403);

        // CSV berdimensi: kolom Segmen + grand total.
        $csv = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-export?format=csv&group_by=division")
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $content = $csv->getContent();
        $this->assertStringContainsString('Segmen', $content);
        $this->assertStringContainsString('GRAND TOTAL', $content);
        $this->assertStringContainsString('Teknologi', $content);

        // JSON berdimensi: struktur bersarang segments + grand_total_*.
        $json = $this->actingAs($this->finance, 'sanctum')
            ->get("/api/v1/dashboard/payroll/runs/{$payroll->id}/gl-export?format=json&group_by=branch")
            ->assertOk();
        $this->assertStringContainsString('application/json', (string) $json->headers->get('Content-Type'));
        $body = $json->getContent();
        $this->assertStringContainsString('segments', $body);
        $this->assertStringContainsString('grand_total_debit', $body);

        $this->assertDatabaseHas('activity_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_GL_EXPORTED',
        ]);
    }
}
