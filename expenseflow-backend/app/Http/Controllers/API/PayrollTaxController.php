<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\Payroll\Tax\AnnualTaxAggregator;
use App\Services\Payroll\Tax\EbupotSchemaException;
use App\Services\Payroll\Tax\EbupotXmlBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bukti Potong PPh 21 Formulir 1721-A1 + draf ekspor Coretax (Fase 4 lanjutan).
 *
 * Semua dokumen ON-DEMAND, tanpa tabel — dihasilkan dari data yang sudah immutable
 * pasca-approve (employee_tax_period_totals + payslip_items) via AnnualTaxAggregator.
 *
 * ⚠️ CATATAN KEPATUHAN: keluaran ini adalah **DRAF** untuk kebutuhan internal &
 * bahan impor. BUKAN Bukti Potong pajak resmi dan BUKAN berkas e-Bupot XML DJP.
 * Nilai wajib diverifikasi sebelum dilaporkan.
 *
 * Prinsip keamanan (mirror PayrollPaymentController):
 *  - Izin `read` untuk list/detail (JSON), `manage` untuk PDF/ekspor (berkas ber-PII).
 *  - Scoping company (lintas-company → 404 di controller; 403 oleh CompanyMiddleware).
 *  - NPWP: respons JSON HANYA `npwp_masked`; NPWP PENUH hanya di berkas PDF/CSV/JSON
 *    yang di-stream (gated `manage` + audit SECURITY/WARNING). Uang `decimal:2`.
 */
class PayrollTaxController extends Controller
{
    public function __construct(private readonly AnnualTaxAggregator $aggregator = new AnnualTaxAggregator())
    {
    }

    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang atas bukti potong pajak.'], 403);
    }

    /** Ambil & validasi tahun pajak dari query (default: tahun berjalan). */
    private function resolveTaxYear(Request $request): int
    {
        $year = (int) $request->query('tax_year', (string) now()->year);
        if ($year < 2000 || $year > 2100) {
            $year = (int) now()->year;
        }

        return $year;
    }

    /** GET /dashboard/payroll/tax/1721a1?tax_year= — daftar ringkas (termasking). */
    public function index1721a1(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $taxYear = $this->resolveTaxYear($request);
        $rows = $this->aggregator->forCompanyYear((int) $user->company_id, $taxYear)
            ->map(function (array $row) {
                unset($row['npwp']); // JSON: jangan pernah kirim NPWP penuh.

                return $row;
            })
            ->values();

        return response()->json([
            'tax_year' => $taxYear,
            'data'     => $rows,
        ]);
    }

    /** GET /dashboard/payroll/tax/1721a1/{userId}?tax_year= — detail satu karyawan (termasking). */
    public function show1721a1(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $taxYear = $this->resolveTaxYear($request);
        $row = $this->aggregator->forEmployeeMasked((int) $user->company_id, $userId, $taxYear);
        if ($row === null) {
            return response()->json(['message' => 'Data pajak karyawan tidak ditemukan untuk tahun tersebut.'], 404);
        }

        return response()->json(['data' => $row]);
    }

    /** GET /dashboard/payroll/tax/1721a1/{userId}/pdf?tax_year= — unduh PDF 1721-A1 (NPWP penuh). */
    public function pdf1721a1(Request $request, int $userId): Response
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $taxYear = $this->resolveTaxYear($request);
        $row = $this->aggregator->forEmployee((int) $user->company_id, $userId, $taxYear);
        if ($row === null) {
            return response()->json(['message' => 'Data pajak karyawan tidak ditemukan untuk tahun tersebut.'], 404);
        }

        // Berkas memuat NPWP penuh = peristiwa sensitif → audit.
        AuditLogger::log(
            action: 'PAYROLL_1721A1_DOWNLOADED',
            description: "Draf 1721-A1 (PDF) diunduh untuk karyawan #{$userId} tahun {$taxYear}.",
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'User',
            entityId: $userId,
            newValues: ['tax_year' => $taxYear],
        );

        $company = Company::find($user->company_id);
        $filename = sprintf('1721A1-%s-%d.pdf', $row['employee_code'] ?: $userId, $taxYear);

        return Pdf::loadView('payroll.tax.1721a1', [
            'row'      => $row,
            'company'  => $company,
            'tax_year' => $taxYear,
        ])->setPaper('a4')->download($filename);
    }

    /** GET /dashboard/payroll/tax/1721a1/export?tax_year=&format=csv|json — draf siap-Coretax (NPWP penuh). */
    public function export1721a1(Request $request): Response
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'json'], true)) {
            return response()->json(['message' => 'Format tidak didukung. Gunakan csv atau json.'], 422);
        }

        $taxYear = $this->resolveTaxYear($request);
        $rows = $this->aggregator->forCompanyYear((int) $user->company_id, $taxYear);

        // Ekspor memuat NPWP penuh = peristiwa sensitif → audit.
        AuditLogger::log(
            action: 'PAYROLL_1721A1_EXPORTED',
            description: "Draf 1721-A1 siap-Coretax diekspor ({$format}) tahun {$taxYear}: {$rows->count()} karyawan.",
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'Company',
            entityId: (int) $user->company_id,
            newValues: ['tax_year' => $taxYear, 'format' => $format, 'rows' => $rows->count()],
        );

        $baseName = "1721A1-draf-coretax-{$user->company_id}-{$taxYear}";

        if ($format === 'json') {
            $payload = [
                'catatan'      => 'DRAF — bukan Bukti Potong resmi / bukan e-Bupot XML. Verifikasi sebelum lapor.',
                'tax_year'     => $taxYear,
                'generated_at' => now()->toIso8601String(),
                'data'         => $rows->values()->all(), // NPWP penuh disertakan pada berkas ekspor.
            ];

            return response(
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                200,
                [
                    'Content-Type'        => 'application/json; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $baseName . '.json"',
                ],
            );
        }

        return response($this->buildCoretaxCsv($rows, $taxYear), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $baseName . '.csv"',
        ]);
    }

    /**
     * GET /dashboard/payroll/tax/ebupot/schema — status skema XSD e-Bupot aktif.
     *
     * Memberi tahu frontend apakah XSD RESMI DJP sudah terpasang di server (sehingga
     * ekspor berlabel `resmi="true"`) atau masih memakai skema internal. Tidak
     * membocorkan isi berkas skema — hanya statusnya.
     */
    public function ebupotSchema(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $official = EbupotXmlBuilder::officialSchemaAvailable();

        return response()->json([
            'data' => [
                'official_schema_installed' => $official,
                'schema_label'              => $official
                    ? (string) config('payroll_ebupot.schema.official_label')
                    : (string) config('payroll_ebupot.schema.fallback_label'),
                'validated'    => true, // setiap ekspor SELALU divalidasi terhadap XSD aktif
                'resmi'        => $official,
                'expected_path' => (string) config('payroll_ebupot.schema.official'),
                'message'      => $official
                    ? 'Ekspor e-Bupot divalidasi terhadap skema XSD resmi DJP.'
                    : 'Ekspor e-Bupot divalidasi terhadap skema XSD internal. Pasang XSD resmi DJP pada path yang tertera agar berkas ditandai resmi.',
            ],
        ]);
    }

    /**
     * GET /dashboard/payroll/tax/ebupot/export?tax_year= — ekspor e-Bupot XML (NPWP penuh).
     *
     * Dokumen SELALU divalidasi terhadap XSD aktif sebelum dikirim: XSD resmi DJP bila
     * terpasang (root `resmi="true"`), jika tidak XSD internal (`resmi="false"`). Gagal
     * validasi ⇒ 422 berisi daftar galat, berkas tidak dikirim.
     *
     * Berkas memuat NPWP penuh → gated `manage` + audit SECURITY/WARNING.
     */
    public function exportEbupot(Request $request): Response
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $taxYear = $this->resolveTaxYear($request);
        $rows = $this->aggregator->forCompanyYear((int) $user->company_id, $taxYear);
        $company = Company::find($user->company_id);

        $builder = new EbupotXmlBuilder();
        try {
            $xml = $builder->build($rows, $taxYear, $company);
        } catch (EbupotSchemaException $e) {
            // Berkas cacat skema TIDAK dikirim. Kegagalan ini bernilai audit karena
            // menandakan data master/pemetaan yang perlu diperbaiki sebelum lapor.
            AuditLogger::log(
                action: 'PAYROLL_EBUPOT_SCHEMA_FAILED',
                description: "Ekspor e-Bupot tahun {$taxYear} ditolak: dokumen tidak lolos validasi skema XSD.",
                category: AuditLogger::CATEGORY_SECURITY,
                severity: AuditLogger::SEVERITY_WARNING,
                entityType: 'Company',
                entityId: (int) $user->company_id,
                newValues: ['tax_year' => $taxYear, 'resmi' => $e->isOfficial(), 'errors' => $e->errors()],
            );

            return response()->json([
                'message' => 'Ekspor dibatalkan: dokumen e-Bupot tidak lolos validasi skema XSD.',
                'errors'  => ['schema' => $e->errors()],
            ], 422);
        }

        $official = $builder->isOfficial();

        // Berkas memuat NPWP penuh (pemberi kerja & karyawan) = peristiwa sensitif → audit.
        AuditLogger::log(
            action: 'PAYROLL_EBUPOT_EXPORTED',
            description: sprintf(
                'e-Bupot XML (%s, tervalidasi XSD) diekspor tahun %d: %d karyawan.',
                $official ? 'skema resmi DJP' : 'skema internal',
                $taxYear,
                $rows->count(),
            ),
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'Company',
            entityId: (int) $user->company_id,
            newValues: [
                'tax_year'  => $taxYear,
                'rows'      => $rows->count(),
                'resmi'     => $official,
                'validated' => true,
            ],
        );

        $prefix = $official ? 'ebupot' : 'ebupot-internal';
        $filename = "{$prefix}-{$user->company_id}-{$taxYear}.xml";

        return response($xml, 200, [
            'Content-Type'        => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Bangun CSV draf siap-Coretax (NPWP PENUH). Satu baris per karyawan + baris total.
     *
     * @param Collection<int, array<string, mixed>> $rows
     */
    private function buildCoretaxCsv(Collection $rows, int $taxYear): string
    {
        $header = [
            'Tahun Pajak', 'NPWP', 'Nama Karyawan', 'Kode/NIK', 'Status PTKP', 'Jumlah Masa',
            'Bruto', 'Biaya Jabatan', 'Iuran Pensiun', 'Neto', 'PTKP', 'PKP',
            'PPh21 Terutang', 'PPh21 Dipotong', 'Selisih',
        ];

        $lines = [$header];
        $num = fn ($v) => number_format((float) $v, 2, '.', '');

        foreach ($rows as $r) {
            $lines[] = [
                (string) $taxYear,
                (string) ($r['npwp'] ?? ''), // NPWP penuh (khusus berkas ekspor).
                (string) $r['employee_name'],
                (string) ($r['employee_code'] ?? ''),
                (string) $r['ptkp_status'],
                (string) $r['months_count'],
                $num($r['bruto']),
                $num($r['biaya_jabatan']),
                $num($r['iuran_pensiun']),
                $num($r['neto']),
                $num($r['ptkp']),
                $num($r['pkp']),
                $num($r['pph21_terutang']),
                $num($r['pph21_dipotong']),
                $num($r['selisih']),
            ];
        }

        $lines[] = [
            (string) $taxYear, '', 'TOTAL', '', '', '',
            $num($rows->sum('bruto')),
            $num($rows->sum('biaya_jabatan')),
            $num($rows->sum('iuran_pensiun')),
            $num($rows->sum('neto')),
            '', '',
            $num($rows->sum('pph21_terutang')),
            $num($rows->sum('pph21_dipotong')),
            $num($rows->sum('selisih')),
        ];

        $out = '';
        foreach ($lines as $line) {
            $out .= implode(',', array_map(function (string $cell): string {
                return preg_match('/[",\r\n]/', $cell)
                    ? '"' . str_replace('"', '""', $cell) . '"'
                    : $cell;
            }, $line)) . "\r\n";
        }

        return $out;
    }
}
