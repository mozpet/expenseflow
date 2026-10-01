<?php

namespace App\Services\Payroll;

use App\Models\StatutoryRuleVersion;

/**
 * Kalkulator PPh21 metode TER (Tarif Efektif Rata-rata) sesuai PMK 168/2023.
 *
 * - Masa Januari–November (dan masa biasa): tarif TER bulanan per kategori (A/B/C)
 *   berdasarkan status PTKP, dikalikan penghasilan bruto bulan tsb.
 * - Masa Desember / masa pajak terakhir: disetahunkan lalu dihitung progresif
 *   Pasal 17 (UU HPP), dikurangi PPh21 yang sudah dipotong pada masa sebelumnya.
 *
 * Seluruh angka tarif dibaca dari tabel `statutory_rule_versions` (effective-dated),
 * TANPA eval / raw SQL, sehingga bisa dikoreksi lewat data tanpa ubah kode.
 *
 * CATATAN VERIFIKASI: nilai bracket TER/PTKP/Pasal 17 yang di-seed mengikuti
 * PMK 168/2023 & UU HPP sepanjang pengetahuan; verifikasi terhadap Lampiran resmi
 * sebelum dipakai produksi. Koreksi cukup dengan meng-update baris statutory_rule_versions.
 */
class Pph21TerCalculator
{
    /** Batas biaya jabatan: 5% bruto, maksimal Rp 6.000.000 / tahun. */
    private const BIAYA_JABATAN_RATE = 0.05;
    private const BIAYA_JABATAN_MAX_YEAR = 6_000_000;

    /** PTKP fallback (2024) bila rule 'ptkp' belum di-seed. */
    private const PTKP_FALLBACK = [
        'TK/0' => 54_000_000, 'TK/1' => 58_500_000, 'TK/2' => 63_000_000, 'TK/3' => 67_500_000,
        'K/0' => 58_500_000, 'K/1' => 63_000_000, 'K/2' => 67_500_000, 'K/3' => 72_000_000,
    ];

    /**
     * Pemetaan status PTKP → kategori TER (PMK 168/2023):
     *   A: TK/0, TK/1, K/0
     *   B: TK/2, TK/3, K/1, K/2
     *   C: K/3
     */
    public function terCategoryForPtkp(string $ptkpStatus): string
    {
        $status = strtoupper(trim($ptkpStatus));

        return match ($status) {
            'TK/0', 'TK/1', 'K/0'         => 'A',
            'TK/2', 'TK/3', 'K/1', 'K/2'  => 'B',
            'K/3'                          => 'C',
            default                        => 'A',
        };
    }

    /**
     * PPh21 bulanan metode TER untuk masa Januari–November.
     *
     * @return array{category:string, rate:float, amount:float}
     */
    public function monthlyTer(
        float $monthlyGross,
        string $ptkpStatus,
        string $onDate,
        ?int $companyId = null,
        bool $hasNpwp = true
    ): array {
        $category = $this->terCategoryForPtkp($ptkpStatus);

        if ($monthlyGross <= 0) {
            return ['category' => $category, 'rate' => 0.0, 'amount' => 0.0];
        }

        $rule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_PPH21_TER, $onDate, $companyId);
        $brackets = $rule?->payload['categories'][$category] ?? [];
        $rate = $this->findRate($brackets, $monthlyGross);

        $tax = $monthlyGross * $rate;

        // Sanksi tanpa NPWP: 20% lebih tinggi (Pasal 21 ayat 5a UU PPh).
        if (! $hasNpwp) {
            $tax *= 1.2;
        }

        return [
            'category' => $category,
            'rate'     => $rate,
            'amount'   => (float) round($tax),
        ];
    }

    /**
     * Rekonsiliasi masa Desember / masa pajak terakhir (metode Pasal 17 disetahunkan).
     *
     * @param float $currentMonthTaxableGross Bruto kena pajak bulan berjalan (Des).
     * @param float $priorTaxableGrossSum     Total bruto kena pajak masa sebelumnya (Jan–Nov) tahun sama.
     * @param float $priorTaxWithheldSum      Total PPh21 yang sudah dipotong masa sebelumnya.
     * @param float $pensionDeductionAnnual   Iuran pensiun/JHT + JP yang DIBAYAR KARYAWAN setahun
     *                                         (pengurang penghasilan bruto — HANYA di rekonsiliasi
     *                                         tahunan Pasal 17, tidak dipakai pada TER bulanan).
     * @return array{annual_tax:float, prior_withheld:float, amount:float, pkp:float}
     */
    public function yearEndReconciliation(
        float $currentMonthTaxableGross,
        float $priorTaxableGrossSum,
        float $priorTaxWithheldSum,
        string $ptkpStatus,
        string $onDate,
        ?int $companyId = null,
        bool $hasNpwp = true,
        float $pensionDeductionAnnual = 0.0
    ): array {
        $annualGross = max(0, $priorTaxableGrossSum + $currentMonthTaxableGross);

        $biayaJabatan = min($annualGross * self::BIAYA_JABATAN_RATE, self::BIAYA_JABATAN_MAX_YEAR);
        // Pengurang: biaya jabatan + iuran pensiun/JHT+JP yang ditanggung karyawan.
        $pension = max(0, $pensionDeductionAnnual);
        $netAnnual = max(0, $annualGross - $biayaJabatan - $pension);

        $ptkp = $this->ptkpAmount($ptkpStatus, $onDate, $companyId);
        $pkp = max(0, $netAnnual - $ptkp);
        // PKP dibulatkan ke bawah ribuan penuh.
        $pkp = floor($pkp / 1000) * 1000;

        $annualTax = $this->pasal17($pkp, $onDate, $companyId);
        if (! $hasNpwp) {
            $annualTax *= 1.2;
        }

        $decemberTax = max(0, $annualTax - $priorTaxWithheldSum);

        return [
            'annual_tax'     => (float) round($annualTax),
            'prior_withheld' => (float) round($priorTaxWithheldSum),
            'amount'         => (float) round($decemberTax),
            'pkp'            => (float) $pkp,
        ];
    }

    /** Nominal PTKP setahun untuk status tertentu. */
    public function ptkpAmount(string $ptkpStatus, string $onDate, ?int $companyId = null): float
    {
        $status = strtoupper(trim($ptkpStatus));
        $rule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_PTKP, $onDate, $companyId);
        $map = $rule?->payload ?? self::PTKP_FALLBACK;

        return (float) ($map[$status] ?? self::PTKP_FALLBACK[$status] ?? self::PTKP_FALLBACK['TK/0']);
    }

    /**
     * PPh21 progresif Pasal 17 (UU HPP) atas PKP setahun.
     * Bracket bersifat marginal (lapisan), dibaca dari statutory_rule_versions.
     */
    public function pasal17(float $pkp, string $onDate, ?int $companyId = null): float
    {
        if ($pkp <= 0) {
            return 0.0;
        }

        $rule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_PASAL17, $onDate, $companyId);
        $brackets = $rule?->payload['brackets'] ?? $this->pasal17Fallback();

        $tax = 0.0;
        $lower = 0.0;

        foreach ($brackets as $bracket) {
            $upTo = $bracket['up_to'] ?? null;      // null = lapisan teratas (tanpa batas)
            $rate = (float) ($bracket['rate'] ?? 0);

            $ceil = $upTo === null ? $pkp : min($pkp, (float) $upTo);
            if ($ceil > $lower) {
                $tax += ($ceil - $lower) * $rate;
                $lower = $ceil;
            }

            if ($upTo !== null && $pkp <= (float) $upTo) {
                break;
            }
        }

        return $tax;
    }

    /**
     * Cari tarif TER pada bracket bulanan (bukan marginal — satu tarif atas seluruh bruto).
     * Bracket urut menaik berdasarkan `up_to`; `up_to` null = lapisan teratas.
     *
     * @param array<int, array{up_to: int|float|null, rate: float}> $brackets
     */
    private function findRate(array $brackets, float $income): float
    {
        foreach ($brackets as $bracket) {
            $upTo = $bracket['up_to'] ?? null;
            if ($upTo === null || $income <= (float) $upTo) {
                return (float) ($bracket['rate'] ?? 0);
            }
        }

        // Bila tabel kosong / tak lengkap, kembalikan 0 (aman: tidak memotong pajak asal).
        return $brackets === [] ? 0.0 : (float) ($brackets[array_key_last($brackets)]['rate'] ?? 0);
    }

    /** Bracket Pasal 17 UU HPP (fallback bila rule belum di-seed). */
    private function pasal17Fallback(): array
    {
        return [
            ['up_to' => 60_000_000, 'rate' => 0.05],
            ['up_to' => 250_000_000, 'rate' => 0.15],
            ['up_to' => 500_000_000, 'rate' => 0.25],
            ['up_to' => 5_000_000_000, 'rate' => 0.30],
            ['up_to' => null, 'rate' => 0.35],
        ];
    }
}
