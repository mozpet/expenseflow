<?php

namespace App\Services\Payroll\Tax;

use App\Models\EmployeeTaxPeriodTotal;
use App\Models\User;
use App\Services\Payroll\BpjsCalculatorService;
use App\Services\Payroll\Pph21TerCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agregator angka tahunan PPh 21 untuk Bukti Potong Formulir 1721-A1 (Fase 4 lanjutan).
 *
 * MURNI-BACA (read-only): merangkai angka setahun dari sumber tunggal
 * `employee_tax_period_totals` (dikonsolidasi per masa oleh PayrollCalculator) +
 * iuran pensiun karyawan dari `payslip_items`. TIDAK menyimpan apa pun — dokumen
 * 1721-A1 bersifat on-demand, tanpa tabel dokumen (keputusan produk).
 *
 * Perhitungan mengikuti aturan yang sama dengan rekonsiliasi tahunan pada
 * Pph21TerCalculator (biaya jabatan 5% maks Rp 6.000.000, pengurang iuran
 * pensiun JHT+JP karyawan, PTKP, PKP dibulatkan ke bawah ribuan, tarif Pasal 17
 * UU HPP, sanksi 20% tanpa NPWP) dengan memakai method publiknya (ptkpAmount,
 * pasal17) — tidak menduplikasi tabel tarif.
 *
 * KEAMANAN: `npwp` (penuh) HANYA untuk berkas PDF/ekspor yang di-stream (gated
 * `manage` + audit). Respons JSON WAJIB memakai `npwp_masked` saja — controller
 * yang membuang field `npwp` sebelum mengirim JSON.
 */
class AnnualTaxAggregator
{
    /** Biaya jabatan: 5% penghasilan bruto, maksimal Rp 6.000.000/tahun (mirror Pph21TerCalculator). */
    private const BIAYA_JABATAN_RATE = 0.05;
    private const BIAYA_JABATAN_MAX_YEAR = 6_000_000;

    public function __construct(private readonly Pph21TerCalculator $tax = new Pph21TerCalculator())
    {
    }

    /**
     * Ringkasan 1721-A1 untuk seluruh karyawan yang punya masa pajak di tahun tsb.
     * Diurutkan berdasarkan nama karyawan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forCompanyYear(int $companyId, int $taxYear): Collection
    {
        $userIds = EmployeeTaxPeriodTotal::where('company_id', $companyId)
            ->where('tax_year', $taxYear)
            ->distinct()
            ->pluck('user_id');

        return $userIds
            ->map(fn ($userId) => $this->forEmployee($companyId, (int) $userId, $taxYear))
            ->filter()
            ->sortBy('employee_name')
            ->values();
    }

    /**
     * Angka 1721-A1 setahun untuk satu karyawan. Mengembalikan null bila karyawan
     * tidak ditemukan di perusahaan tsb atau tak punya masa pajak di tahun tsb.
     *
     * @return array<string, mixed>|null
     */
    public function forEmployee(int $companyId, int $userId, int $taxYear): ?array
    {
        $user = User::with('taxProfile')->find($userId);
        if (! $user || (int) $user->company_id !== $companyId) {
            return null;
        }

        $totals = EmployeeTaxPeriodTotal::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->get();
        if ($totals->isEmpty()) {
            return null;
        }

        $bruto        = (float) $totals->sum('total_taxable_gross');
        $regular      = (float) $totals->sum('regular_gross');
        $irregular    = (float) $totals->sum('irregular_gross');
        $benefit      = (float) $totals->sum('taxable_benefit_gross');
        $pph21Dipotong = (float) $totals->sum('total_pph21_withheld');
        $monthsCount  = $totals->pluck('tax_month')->unique()->count();

        // Iuran pensiun karyawan (JHT 2% + JP 1%) setahun — pengurang penghasilan bruto.
        // (Kesehatan 1% BUKAN pengurang.) Konsisten dgn sumber period-totals: tanpa filter status.
        $iuranPensiun = (float) DB::table('payslip_items as pi')
            ->join('payslips as p', 'p.id', '=', 'pi.payslip_id')
            ->where('p.company_id', $companyId)
            ->where('p.user_id', $userId)
            ->where('p.period_year', $taxYear)
            ->whereIn('pi.code', [
                BpjsCalculatorService::CODE_JHT_EMP,
                BpjsCalculatorService::CODE_JP_EMP,
            ])
            ->sum('pi.amount');
        $iuranPensiun = (float) $iuranPensiun;

        $ptkpStatus = $user->taxProfile?->ptkp_status ?? 'TK/0';
        $hasNpwp    = (bool) ($user->taxProfile?->has_npwp ?? false);
        $onDate     = sprintf('%04d-12-31', $taxYear);

        // Pengurang & neto (mirror Pph21TerCalculator::yearEndReconciliation).
        $biayaJabatan = min($bruto * self::BIAYA_JABATAN_RATE, self::BIAYA_JABATAN_MAX_YEAR);
        $neto = max(0.0, $bruto - $biayaJabatan - max(0.0, $iuranPensiun));

        $ptkp = $this->tax->ptkpAmount($ptkpStatus, $onDate, $companyId);
        $pkp  = max(0.0, $neto - $ptkp);
        $pkp  = floor($pkp / 1000) * 1000; // PKP dibulatkan ke bawah ribuan penuh.

        $pph21Terutang = $this->tax->pasal17($pkp, $onDate, $companyId);
        if (! $hasNpwp) {
            $pph21Terutang *= 1.2; // Sanksi 20% lebih tinggi tanpa NPWP (Pasal 21 (5a) UU PPh).
        }
        $pph21Terutang = (float) round($pph21Terutang);

        $selisih = round($pph21Terutang - $pph21Dipotong, 2);

        return [
            'user_id'         => (int) $user->id,
            'employee_name'   => (string) $user->name,
            'employee_code'   => $user->employee_code,
            'position_name'   => $user->position?->name,
            'npwp'            => $user->taxProfile?->npwp,        // PENUH — hanya untuk PDF/ekspor.
            'npwp_masked'     => $user->taxProfile?->maskedNpwp(), // untuk respons JSON.
            'has_npwp'        => $hasNpwp,
            // Subjek luar negeri (ekspatriat) dipotong PPh 26, bukan PPh 21 — dipakai
            // ekspor e-Bupot untuk menandai jenis bukti potong (Fase 6).
            'is_foreign_subject' => (bool) $user->taxProfile?->isForeignSubject(),
            'ptkp_status'     => $ptkpStatus,
            'tax_year'        => $taxYear,
            'months_count'    => $monthsCount,
            'bruto'           => round($bruto, 2),
            'bruto_regular'   => round($regular, 2),
            'bruto_irregular' => round($irregular, 2),
            'bruto_benefit'   => round($benefit, 2),
            'biaya_jabatan'   => round($biayaJabatan, 2),
            'iuran_pensiun'   => round($iuranPensiun, 2),
            'neto'            => round($neto, 2),
            'ptkp'            => round($ptkp, 2),
            'pkp'             => round($pkp, 2),
            'pph21_terutang'  => round($pph21Terutang, 2),
            'pph21_dipotong'  => round($pph21Dipotong, 2),
            'selisih'         => $selisih,
        ];
    }

    /**
     * Versi ringkasan aman-JSON: sama dengan forEmployee tetapi TANPA `npwp` penuh.
     *
     * @return array<string, mixed>|null
     */
    public function forEmployeeMasked(int $companyId, int $userId, int $taxYear): ?array
    {
        $row = $this->forEmployee($companyId, $userId, $taxYear);
        if ($row === null) {
            return null;
        }
        unset($row['npwp']);

        return $row;
    }
}
