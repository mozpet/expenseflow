<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Item 1 — NPWP Pemberi Kerja (perusahaan).
 *
 * NPWP perusahaan disimpan TERENKRIPSI (cast `encrypted` mirror EmployeeTaxProfile);
 * response JSON HANYA mengirim versi termasking; nilai penuh muncul pada berkas
 * ter-stream (PDF 1721-A1). Endpoint update di-gate izin `manage`.
 */
class CompanyNpwpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;   // izin manage payroll
    private User $employee;  // tanpa izin payroll

    private const NPWP = '123456789012345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::create([
            'name'      => 'PT Maju Bersama',
            'email'     => 'info@majubersama.co.id',
            'phone'     => '021-12345678',
            'address'   => 'Jl. Sudirman No. 1, Jakarta',
            'is_active' => true,
        ]);

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id);
        $this->employee = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id);
    }

    private function makeUser(string $name, string $email, string $role, int $roleId): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'role_id'    => $roleId,
            'name'       => $name,
            'email'      => $email,
            'password'   => bcrypt('password'),
            'role'       => $role,
            'is_active'  => true,
        ]);
    }

    public function test_update_stores_npwp_encrypted_and_returns_masked(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/company-tax-profile', ['npwp' => self::NPWP])
            ->assertOk();

        // Response HANYA termasking; NPWP penuh tak pernah bocor ke JSON.
        $this->assertStringNotContainsString(self::NPWP, $res->getContent());
        $this->assertTrue($res->json('data.has_npwp'));
        $this->assertStringEndsWith('2345', (string) $res->json('data.npwp_masked'));

        // Nilai tersimpan di DB adalah ciphertext (bukan plaintext).
        $raw = DB::table('companies')->where('id', $this->company->id)->value('npwp');
        $this->assertNotNull($raw);
        $this->assertNotSame(self::NPWP, $raw);

        // Dekripsi via model mengembalikan digit asli.
        $this->assertSame(self::NPWP, Company::find($this->company->id)->npwp);
    }

    public function test_update_normalizes_formatting_and_validates_length(): void
    {
        // Format dengan titik/strip → dinormalisasi ke digit.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/company-tax-profile', ['npwp' => '12.345.678.9-012.345'])
            ->assertOk();
        $this->assertSame(self::NPWP, Company::find($this->company->id)->npwp);

        // Terlalu pendek → 422.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/company-tax-profile', ['npwp' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('npwp');

        // String kosong → menghapus NPWP.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/company-tax-profile', ['npwp' => ''])
            ->assertOk();
        $this->assertNull(Company::find($this->company->id)->npwp);
    }

    public function test_show_returns_masked_only(): void
    {
        $this->company->npwp = self::NPWP;
        $this->company->save();

        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/company-tax-profile')
            ->assertOk();

        $this->assertStringNotContainsString(self::NPWP, $res->getContent());
        $this->assertTrue($res->json('data.has_npwp'));
        $this->assertStringEndsWith('2345', (string) $res->json('data.npwp_masked'));
        $this->assertArrayNotHasKey('npwp', $res->json('data'));
    }

    public function test_read_and_update_are_permission_gated(): void
    {
        // Karyawan biasa tak punya izin read → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/company-tax-profile')
            ->assertStatus(403);

        // Tak punya izin manage → 403.
        $this->actingAs($this->employee, 'sanctum')
            ->putJson('/api/v1/dashboard/payroll/company-tax-profile', ['npwp' => self::NPWP])
            ->assertStatus(403);
    }

    public function test_1721a1_view_renders_employer_npwp(): void
    {
        $this->company->npwp = self::NPWP;
        $this->company->save();

        // Render blade langsung dengan $row lengkap → memastikan NPWP pemberi kerja
        // tampil pada berkas (assert deterministik; teks di dalam PDF biner tak andal).
        $html = view('payroll.tax.1721a1', [
            'company'  => $this->company->fresh(),
            'tax_year' => 2026,
            'row'      => [
                'employee_name'  => 'Budi Karyawan',
                'employee_code'  => 'EMP-001',
                'position_name'  => 'Staff',
                'npwp'           => '999888777666555',
                'npwp_masked'    => '•••••••••••6555',
                'has_npwp'       => true,
                'ptkp_status'    => 'TK/0',
                'months_count'   => 12,
                'bruto'          => 120_000_000,
                'biaya_jabatan'  => 6_000_000,
                'iuran_pensiun'  => 3_000_000,
                'neto'           => 111_000_000,
                'ptkp'           => 54_000_000,
                'pkp'            => 57_000_000,
                'pph21_terutang' => 5_550_000,
                'pph21_dipotong' => 5_550_000,
                'selisih'        => 0,
            ],
        ])->render();

        $this->assertStringContainsString(self::NPWP, $html);
        $this->assertStringContainsString('NPWP Pemberi Kerja', $html);
    }
}
