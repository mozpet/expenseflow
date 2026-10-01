<?php

namespace App\Services\Payroll\Tax;

use App\Models\StatutoryRuleVersion;

/**
 * Pajak bersifat FINAL di luar skema PPh 21 reguler — Fase 6.
 *
 *  (a) PPh 21 FINAL atas Uang Pesangon (PP 68/2009) — progresif MARGINAL atas
 *      jumlah bruto pesangon yang dibayar sekaligus (dalam jangka waktu paling
 *      lama 2 tahun kalender): 0% s.d. 50 jt, 5% s.d. 100 jt, 15% s.d. 500 jt,
 *      25% di atasnya. Objeknya HANYA UP + UPMK + UPH — bukan gaji terakhir,
 *      bukan uang pisah, bukan kompensasi PKWT (keduanya PPh 21 non-final).
 *
 *  (b) PPh 26 atas penghasilan Subjek Pajak Luar Negeri (Pasal 26 UU PPh) —
 *      20% dari penghasilan BRUTO, atau tarif P3B bila karyawan menyerahkan
 *      SKD/DGT yang sah. Bersifat final: tidak direkonsiliasi akhir tahun.
 *
 * Tarif dibaca dari `statutory_rule_versions` (effective-dated, dapat di-override
 * per-perusahaan). Tanpa eval / raw SQL — sama seperti Pph21TerCalculator.
 */
class FinalTaxCalculator
{
    /** Sanksi tarif lebih tinggi bagi penerima tanpa NPWP (PP 68/2009 Pasal 5). */
    private const NO_NPWP_SURCHARGE = 1.2;

    /**
     * PPh 21 Final atas uang pesangon (dan sejenisnya) yang dibayar sekaligus.
     *
     * @param  float  $severanceGross  Jumlah bruto UP + UPMK + UPH.
     * @return array{amount: float, effective_rate: float, brackets_used: array<int, array{lower: float, upper: float|null, rate: float, tax: float}>}
     */
    public function pesangonFinal(
        float $severanceGross,
        string $onDate,
        ?int $companyId = null,
        bool $hasNpwp = true
    ): array {
        if ($severanceGross <= 0) {
            return ['amount' => 0.0, 'effective_rate' => 0.0, 'brackets_used' => []];
        }

        $rule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_PESANGON_FINAL, $onDate, $companyId);
        $brackets = $rule?->payload['brackets'] ?? $this->pesangonFallback();

        $tax = 0.0;
        $lower = 0.0;
        $used = [];

        foreach ($brackets as $bracket) {
            $upTo = $bracket['up_to'] ?? null;      // null = lapisan teratas (tanpa batas)
            $rate = (float) ($bracket['rate'] ?? 0);

            $ceil = $upTo === null ? $severanceGross : min($severanceGross, (float) $upTo);
            if ($ceil > $lower) {
                $layerTax = ($ceil - $lower) * $rate;
                $tax += $layerTax;
                $used[] = [
                    'lower' => round($lower, 2),
                    'upper' => $upTo === null ? null : (float) $upTo,
                    'rate'  => $rate,
                    'tax'   => round($layerTax, 2),
                ];
                $lower = $ceil;
            }

            if ($upTo !== null && $severanceGross <= (float) $upTo) {
                break;
            }
        }

        // Tanpa NPWP: 20% lebih tinggi dari tarif yang seharusnya.
        if (! $hasNpwp) {
            $tax *= self::NO_NPWP_SURCHARGE;
        }

        $tax = (float) round($tax);

        return [
            'amount'         => $tax,
            'effective_rate' => $severanceGross > 0 ? round($tax / $severanceGross, 6) : 0.0,
            'brackets_used'  => $used,
        ];
    }

    /**
     * PPh 26 atas penghasilan bruto Subjek Pajak Luar Negeri.
     *
     * @param  float|null  $treatyRate  Tarif P3B (mis. 0.10). NULL → tarif umum dari rule.
     * @return array{amount: float, rate: float, source: string}
     */
    public function pph26(
        float $grossIncome,
        string $onDate,
        ?int $companyId = null,
        ?float $treatyRate = null,
        ?string $treatyCountry = null
    ): array {
        if ($grossIncome <= 0) {
            return ['amount' => 0.0, 'rate' => 0.0, 'source' => 'nihil'];
        }

        $rule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_PPH26, $onDate, $companyId);
        $defaultRate = (float) ($rule?->payload['default_rate'] ?? 0.20);

        // Tarif P3B hanya dipakai bila valid (0–tarif umum) DAN negara mitra diisi:
        // tanpa bukti SKD/DGT, pemotong wajib memakai tarif umum 20%.
        $useTreaty = $treatyRate !== null
            && $treatyCountry !== null && trim($treatyCountry) !== ''
            && $treatyRate >= 0 && $treatyRate <= $defaultRate;

        $rate = $useTreaty ? (float) $treatyRate : $defaultRate;

        return [
            'amount' => (float) round($grossIncome * $rate),
            'rate'   => $rate,
            'source' => $useTreaty ? 'p3b:' . strtoupper(trim((string) $treatyCountry)) : 'pasal26',
        ];
    }

    /** Bracket PP 68/2009 (fallback bila rule belum di-seed). */
    private function pesangonFallback(): array
    {
        return [
            ['up_to' => 50_000_000,  'rate' => 0.00],
            ['up_to' => 100_000_000, 'rate' => 0.05],
            ['up_to' => 500_000_000, 'rate' => 0.15],
            ['up_to' => null,        'rate' => 0.25],
        ];
    }
}
