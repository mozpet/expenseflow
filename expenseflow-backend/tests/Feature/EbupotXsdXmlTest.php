<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6 — Ekspor e-Bupot XML TERVALIDASI SKEMA XSD.
 *
 * Setiap dokumen wajib lolos `DOMDocument::schemaValidate()` sebelum dikirim; gagal
 * validasi ⇒ 422 dan berkas TIDAK dikirim. Label `resmi` hanya "true" bila XSD RESMI
 * DJP terpasang di server — pada lingkungan uji skema internal yang dipakai, sehingga
 * `resmi="false"` tetapi dokumen SUDAH tervalidasi skema.
 *
 * Berkas memuat NPWP PENUH (pemberi kerja & karyawan) → gated `manage` + audit
 * SECURITY/WARNING.
 */
class EbupotXsdXmlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $hrd;       // checker (approve)
    private User $budi;      // NPWP penuh diketahui
    private User $siti;      // tanpa NPWP
    private User $employee;  // tanpa izin payroll

    private const COMPANY_NPWP = '099888777666555';
    private const BUDI_NPWP    = '123456789012345';

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
        $this->company->npwp = self::COMPANY_NPWP; // tersimpan terenkripsi.
        $this->company->save();

        $financeRole  = Role::whereNull('company_id')->where('slug', 'finance')->first();
        $hrdRole      = Role::whereNull('company_id')->where('slug', 'hrd')->first();
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->finance  = $this->makeUser('Andi Finance', 'andi@mb.co.id', 'finance', $financeRole->id, null);
        $this->hrd      = $this->makeUser('Dewi HRD', 'dewi@mb.co.id', 'hrd', $hrdRole->id, null);
        $this->budi     = $this->makeUser('Budi Karyawan', 'budi@mb.co.id', 'employee', $employeeRole->id, 'EMP-001');
        $this->siti     = $this->makeUser('Siti Karyawan', 'siti@mb.co.id', 'employee', $employeeRole->id, 'EMP-002');
        $this->employee = $this->makeUser('Eko Biasa', 'eko@mb.co.id', 'employee', $employeeRole->id, 'EMP-003');

        $this->setSalary($this->budi, 10_000_000, 'TK/0', self::BUDI_NPWP);
        $this->setSalary($this->siti, 8_000_000, 'K/1', null);
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

    private function setSalary(User $user, int $basic, string $ptkp, ?string $npwp): void
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
            'npwp'        => $npwp,
            'ptkp_status' => $ptkp,
            'has_npwp'    => $npwp !== null,
            'tax_method'  => 'gross',
        ]);
    }

    /** Buat run 6/2026 → calculate → submit → approve. */
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

    public function test_export_is_manage_gated(): void
    {
        $this->approvedRun();

        $this->actingAs($this->employee, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/ebupot/export?tax_year=2026')
            ->assertStatus(403);
    }

    public function test_export_returns_xml_with_full_npwp(): void
    {
        $this->approvedRun();

        $res = $this->actingAs($this->finance, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/ebupot/export?tax_year=2026')
            ->assertOk();

        $this->assertStringContainsString('application/xml', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('.xml', (string) $res->headers->get('Content-Disposition'));

        $body = $res->getContent();

        // Well-formed & bisa diparse.
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml, 'XML harus well-formed & dapat diparse.');

        $this->assertSame('eBupot21', $xml->getName());
        $this->assertSame('2026', (string) $xml['tahunPajak']);
        // XSD resmi DJP tidak terpasang di lingkungan uji ⇒ label resmi tetap false,
        // tetapi dokumen sudah lolos skema internal (diuji terpisah di bawah).
        $this->assertSame('false', (string) $xml['resmi']);
        $this->assertSame('internal-v2', (string) $xml['skema']);
        $this->assertSame('2', (string) $xml['jumlahBuktiPotong']);

        // NPWP pemberi kerja (penuh) ada di blok Pemotong.
        $this->assertSame(self::COMPANY_NPWP, (string) $xml->Pemotong->NPWP);

        // Satu node BuktiPotong per karyawan (2: budi & siti).
        $this->assertCount(2, $xml->DaftarBuktiPotong->BuktiPotong);

        // NPWP karyawan (penuh) muncul pada berkas — inilah satu-satunya tempatnya.
        $this->assertStringContainsString(self::BUDI_NPWP, $body);
    }

    /**
     * INTI ITEM 4: berkas yang dikirim benar-benar lolos XSD — divalidasi ulang di
     * sisi uji, bukan sekadar dipercaya dari label atributnya.
     */
    public function test_exported_xml_validates_against_xsd_schema(): void
    {
        $this->approvedRun();

        $body = $this->actingAs($this->finance, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/ebupot/export?tax_year=2026')
            ->assertOk()
            ->getContent();

        $schema = config('payroll_ebupot.schema.fallback');
        $this->assertFileExists($schema, 'Skema XSD internal harus ikut terdistribusi.');

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($body), 'XML harus dapat dimuat DOMDocument.');

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $doc->schemaValidate($schema);
        $errors = array_map(
            fn ($e) => sprintf('baris %d: %s', $e->line, trim($e->message)),
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($valid, "Dokumen harus lolos XSD. Galat:
" . implode("
", $errors));
    }

    /** Karyawan tanpa NPWP: elemen NPWP kosong tetapi dokumen tetap lolos skema. */
    public function test_employee_without_npwp_still_produces_schema_valid_document(): void
    {
        $this->approvedRun();

        $body = $this->actingAs($this->finance, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/ebupot/export?tax_year=2026')
            ->assertOk()
            ->getContent();

        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);

        $tanpaNpwp = null;
        foreach ($xml->DaftarBuktiPotong->BuktiPotong as $bp) {
            if ((string) $bp->Penerima->Nama === 'Siti Karyawan') {
                $tanpaNpwp = $bp;
            }
        }

        $this->assertNotNull($tanpaNpwp, 'Bukti potong Siti harus ada.');
        $this->assertSame('', (string) $tanpaNpwp->Penerima->NPWP);
        $this->assertSame('false', (string) $tanpaNpwp->Penerima->BerNPWP);
    }

    /** Status skema dapat dibaca pengguna ber-izin read (tanpa membocorkan isi XSD). */
    public function test_schema_status_endpoint_reports_internal_schema(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/tax/ebupot/schema')
            ->assertOk();

        $this->assertTrue($res->json('data.validated'));
        $this->assertFalse($res->json('data.official_schema_installed'));
        $this->assertFalse($res->json('data.resmi'));
        $this->assertSame('internal-v2', $res->json('data.schema_label'));
    }

    public function test_export_is_audited(): void
    {
        $this->approvedRun();

        $this->actingAs($this->finance, 'sanctum')
            ->get('/api/v1/dashboard/payroll/tax/ebupot/export?tax_year=2026')
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'company_id' => $this->company->id,
            'action'     => 'PAYROLL_EBUPOT_EXPORTED',
            'severity'   => 'warning',
        ]);
    }
}
