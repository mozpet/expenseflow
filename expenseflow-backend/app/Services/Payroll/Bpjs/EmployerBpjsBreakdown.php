<?php

namespace App\Services\Payroll\Bpjs;

use App\Models\PayslipCalculationStep;
use Illuminate\Support\Collection;

/**
 * Turunkan rincian iuran BPJS yang DITANGGUNG PERUSAHAAN dari jejak perhitungan
 * (`payslip_calculation_steps`) yang sudah persist — TANPA menghitung ulang
 * kebijakan/tarif dan TANPA perubahan skema.
 *
 * Fungsi murni: hanya membaca langkah BPJS_* yang sudah tersimpan saat kalkulasi.
 * Sumber angka per program:
 *   - JKK / JKM  → `final_result` (program ini 100% ditanggung perusahaan).
 *   - KES/JHT/JP → basis (ter-cap bila ada) × `company_rate` dari `input_payload`.
 * Pembulatan mengikuti kalkulator (round ke rupiah penuh) → penjumlahan seluruh
 * komponen SAMA PERSIS dengan `payslips.bpjs_company_total`.
 *
 * Ini murni informasi pada slip; BUKAN pengurang gaji bersih (take-home pay).
 */
class EmployerBpjsBreakdown
{
    /** Label human-readable per kode langkah BPJS. */
    private const LABELS = [
        PayslipCalculationStep::STEP_BPJS_KES => 'BPJS Kesehatan',
        PayslipCalculationStep::STEP_BPJS_JKK => 'BPJS JKK (Jaminan Kecelakaan Kerja)',
        PayslipCalculationStep::STEP_BPJS_JKM => 'BPJS JKM (Jaminan Kematian)',
        PayslipCalculationStep::STEP_BPJS_JHT => 'BPJS JHT (Jaminan Hari Tua)',
        PayslipCalculationStep::STEP_BPJS_JP  => 'BPJS JP (Jaminan Pensiun)',
    ];

    /**
     * @param  Collection<int, PayslipCalculationStep>  $steps  langkah perhitungan slip
     * @return array{components: array<int, array{code:string, label:string, amount:float}>, total: float}
     */
    public static function fromSteps(Collection $steps): array
    {
        $components = [];
        $total = 0.0;

        foreach ($steps as $step) {
            $code = $step->step_code;
            if (! array_key_exists($code, self::LABELS)) {
                continue;
            }

            $amount = self::employerAmount($step);
            if ($amount <= 0) {
                continue;
            }

            $components[] = [
                'code'   => $code,
                'label'  => self::LABELS[$code],
                'amount' => $amount,
            ];
            $total += $amount;
        }

        return [
            'components' => $components,
            'total'      => round($total, 2),
        ];
    }

    /** Iuran ditanggung perusahaan untuk satu langkah BPJS. */
    private static function employerAmount(PayslipCalculationStep $step): float
    {
        // JKK & JKM 100% perusahaan → final_result sudah = iuran perusahaan.
        if (in_array($step->step_code, [PayslipCalculationStep::STEP_BPJS_JKK, PayslipCalculationStep::STEP_BPJS_JKM], true)) {
            return (float) $step->final_result;
        }

        // KES/JHT/JP dua sisi → basis (ter-cap bila ada) × tarif perusahaan.
        $input = $step->input_payload ?? [];
        $base = (float) ($input['capped_base'] ?? $input['wage_base'] ?? 0);
        $companyRate = (float) ($input['company_rate'] ?? 0);

        return round($base * $companyRate);
    }
}
