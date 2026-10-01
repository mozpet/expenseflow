<?php

namespace Database\Seeders;

use App\Models\StatutoryRuleVersion;
use Illuminate\Database\Seeder;

/**
 * Seeder aturan perpajakan (effective-dated, GLOBAL / company_id = NULL):
 *   - PTKP 2024 (per status)
 *   - Tarif Pasal 17 UU HPP (progresif tahunan)
 *   - Tarif Efektif Rata-rata (TER) BULANAN 2024 — PMK 168/2023, kategori A/B/C
 *
 * ⚠️ VERIFIKASI: nilai bracket berikut menyalin Lampiran PMK 168/2023 & UU HPP
 * sepanjang pengetahuan. WAJIB dicek ulang terhadap dokumen resmi DJP sebelum
 * dipakai produksi. Koreksi cukup meng-update payload baris terkait (tanpa ubah kode).
 *
 * Format bracket TER: [{up_to, rate}] urut menaik; `up_to` = batas atas penghasilan
 * bruto bulanan (inklusif) untuk tarif tsb; entri terakhir up_to=null = lapisan teratas.
 * Satu tarif dikalikan seluruh bruto bulan itu (bukan marginal).
 */
class PayrollStatutorySeeder extends Seeder
{
    public function run(): void
    {
        $effective = '2024-01-01';

        // ─── PTKP 2024 (setahun) ─────────────────────────────────────
        $this->put(StatutoryRuleVersion::TYPE_PTKP, 'PTKP 2024', $effective, [
            'TK/0' => 54_000_000, 'TK/1' => 58_500_000, 'TK/2' => 63_000_000, 'TK/3' => 67_500_000,
            'K/0'  => 58_500_000, 'K/1'  => 63_000_000, 'K/2'  => 67_500_000, 'K/3'  => 72_000_000,
        ]);

        // ─── Pasal 17 UU HPP (progresif tahunan, marginal) ───────────
        $this->put(StatutoryRuleVersion::TYPE_PASAL17, 'Tarif Pasal 17 UU HPP', '2022-01-01', [
            'brackets' => [
                ['up_to' => 60_000_000,    'rate' => 0.05],
                ['up_to' => 250_000_000,   'rate' => 0.15],
                ['up_to' => 500_000_000,   'rate' => 0.25],
                ['up_to' => 5_000_000_000, 'rate' => 0.30],
                ['up_to' => null,          'rate' => 0.35],
            ],
        ]);

        // ─── TER Bulanan 2024 (PMK 168/2023) ─────────────────────────
        $this->put(StatutoryRuleVersion::TYPE_PPH21_TER, 'TER Bulanan 2024 (PMK 168/2023)', $effective, [
            'categories' => [
                'A' => $this->terA(),
                'B' => $this->terB(),
                'C' => $this->terC(),
            ],
        ]);

        // ─── BPJS Kesehatan (Perpres 64/2020) ────────────────────────
        // Total 5% dari upah: 4% pemberi kerja + 1% pekerja. Batas atas upah Rp 12.000.000.
        $this->put(StatutoryRuleVersion::TYPE_BPJS_KES, 'BPJS Kesehatan', $effective, [
            'company_rate'  => 0.04,
            'employee_rate' => 0.01,
            'wage_cap'      => 12_000_000,
        ]);

        // ─── BPJS Ketenagakerjaan (PP 44/2015, PP 45/2015, PP 46/2015, PP 37/2021) ──
        // JKK: 0,24%–1,74% (per kelas risiko, dibayar perusahaan).
        // JKM: 0,30% (perusahaan). JHT: 3,7% perusahaan + 2% pekerja (upah riil, tanpa cap).
        // JP : 2% perusahaan + 1% pekerja (batas upah Rp 10.042.300 — 2024).
        // JKP: rekomposisi iuran (0,24% JKK & 0,3% JKM sudah termasuk peran pemerintah);
        //      TIDAK menambah potongan pekerja / beban baru perusahaan → rate 0.
        $this->put(StatutoryRuleVersion::TYPE_BPJS_TK, 'BPJS Ketenagakerjaan', $effective, [
            'jkk' => [
                'company_rate' => 0.0024, // default kelas risiko I (very low)
                'classes'      => ['1' => 0.0024, '2' => 0.0054, '3' => 0.0089, '4' => 0.0127, '5' => 0.0174],
            ],
            'jkm' => ['company_rate' => 0.003],
            'jht' => ['company_rate' => 0.037, 'employee_rate' => 0.02],
            'jp'  => ['company_rate' => 0.02, 'employee_rate' => 0.01, 'wage_cap' => 10_042_300],
            'jkp' => ['company_rate' => 0.0, 'employee_rate' => 0.0],
        ]);

        // ─── PPh 21 FINAL atas Uang Pesangon (PP 68/2009 Pasal 4) ────
        // Progresif MARGINAL atas jumlah bruto pesangon yang dibayar sekaligus:
        //   s.d. 50 jt → 0% | >50–100 jt → 5% | >100–500 jt → 15% | >500 jt → 25%.
        // Bersifat FINAL: tidak digabung penghasilan lain & tidak dikreditkan di 1721-A1.
        $this->put(StatutoryRuleVersion::TYPE_PESANGON_FINAL, 'PPh 21 Final Pesangon (PP 68/2009)', '2009-11-16', [
            'brackets' => [
                ['up_to' => 50_000_000,  'rate' => 0.00],
                ['up_to' => 100_000_000, 'rate' => 0.05],
                ['up_to' => 500_000_000, 'rate' => 0.15],
                ['up_to' => null,        'rate' => 0.25],
            ],
        ]);

        // ─── Tabel Uang Pesangon (UP) & UPMK — PP 35/2021 Pasal 40 ───
        // UP  (ayat 2): masa kerja < 1 th = 1 bln upah … ≥ 8 th = 9 bln upah.
        // UPMK(ayat 3): mulai masa kerja ≥ 3 th = 2 bln upah … ≥ 24 th = 10 bln upah.
        // `min_years` inklusif, `max_years` eksklusif (null = tanpa batas atas).
        $this->put(StatutoryRuleVersion::TYPE_SEVERANCE_TABLE, 'Tabel UP & UPMK (PP 35/2021)', '2021-02-02', [
            'up' => [
                ['min_years' => 0, 'max_years' => 1,    'months' => 1],
                ['min_years' => 1, 'max_years' => 2,    'months' => 2],
                ['min_years' => 2, 'max_years' => 3,    'months' => 3],
                ['min_years' => 3, 'max_years' => 4,    'months' => 4],
                ['min_years' => 4, 'max_years' => 5,    'months' => 5],
                ['min_years' => 5, 'max_years' => 6,    'months' => 6],
                ['min_years' => 6, 'max_years' => 7,    'months' => 7],
                ['min_years' => 7, 'max_years' => 8,    'months' => 8],
                ['min_years' => 8, 'max_years' => null, 'months' => 9],
            ],
            'upmk' => [
                ['min_years' => 3,  'max_years' => 6,    'months' => 2],
                ['min_years' => 6,  'max_years' => 9,    'months' => 3],
                ['min_years' => 9,  'max_years' => 12,   'months' => 4],
                ['min_years' => 12, 'max_years' => 15,   'months' => 5],
                ['min_years' => 15, 'max_years' => 18,   'months' => 6],
                ['min_years' => 18, 'max_years' => 21,   'months' => 7],
                ['min_years' => 21, 'max_years' => 24,   'months' => 8],
                ['min_years' => 24, 'max_years' => null, 'months' => 10],
            ],
            // UPH: persentase uang penggantian perumahan & pengobatan dari (UP + UPMK).
            'uph_housing_medical_rate' => 0.15,
        ]);

        // ─── PPh 26 Subjek Pajak Luar Negeri (Pasal 26 UU PPh) ───────
        // Tarif umum 20% dari penghasilan BRUTO; dapat lebih rendah bila ada P3B
        // (tarif P3B diisi per-karyawan pada profil pajak + bukti DGT/SKD).
        $this->put(StatutoryRuleVersion::TYPE_PPH26, 'Tarif PPh 26 (Pasal 26 UU PPh)', '2009-01-01', [
            'default_rate' => 0.20,
        ]);
    }

    /** Simpan/aktifkan satu versi aturan global (idempoten). */
    private function put(string $type, string $name, string $effectiveDate, array $payload): void
    {
        StatutoryRuleVersion::updateOrCreate(
            [
                'company_id'     => null,
                'type'           => $type,
                'effective_date' => $effectiveDate,
            ],
            [
                'name'      => $name,
                'end_date'  => null,
                'payload'   => $payload,
                'is_active' => true,
            ]
        );
    }

    /** Bracket → array {up_to, rate}. */
    private function brackets(array $rows): array
    {
        return array_map(fn ($r) => ['up_to' => $r[0], 'rate' => $r[1]], $rows);
    }

    /** TER Kategori A — PTKP TK/0, TK/1, K/0. */
    private function terA(): array
    {
        return $this->brackets([
            [5_400_000, 0.0], [5_650_000, 0.0025], [5_950_000, 0.005], [6_300_000, 0.0075],
            [6_750_000, 0.01], [7_500_000, 0.0125], [8_550_000, 0.015], [9_650_000, 0.0175],
            [10_050_000, 0.02], [10_350_000, 0.0225], [10_700_000, 0.025], [11_050_000, 0.03],
            [11_600_000, 0.035], [12_500_000, 0.04], [13_750_000, 0.05], [15_100_000, 0.06],
            [16_950_000, 0.07], [19_750_000, 0.08], [24_150_000, 0.09], [26_450_000, 0.10],
            [28_000_000, 0.11], [30_050_000, 0.12], [32_400_000, 0.13], [35_400_000, 0.14],
            [39_100_000, 0.15], [43_850_000, 0.16], [47_800_000, 0.17], [51_400_000, 0.18],
            [56_300_000, 0.19], [62_200_000, 0.20], [68_600_000, 0.21], [77_500_000, 0.22],
            [89_000_000, 0.23], [103_000_000, 0.24], [125_000_000, 0.25], [157_000_000, 0.26],
            [206_000_000, 0.27], [337_000_000, 0.28], [454_000_000, 0.29], [550_000_000, 0.30],
            [695_000_000, 0.31], [910_000_000, 0.32], [1_400_000_000, 0.33], [null, 0.34],
        ]);
    }

    /** TER Kategori B — PTKP TK/2, TK/3, K/1, K/2. */
    private function terB(): array
    {
        return $this->brackets([
            [6_200_000, 0.0], [6_500_000, 0.0025], [6_850_000, 0.005], [7_300_000, 0.0075],
            [9_200_000, 0.01], [10_750_000, 0.015], [11_250_000, 0.02], [11_600_000, 0.025],
            [12_600_000, 0.03], [13_600_000, 0.04], [14_950_000, 0.05], [16_400_000, 0.06],
            [18_450_000, 0.07], [21_850_000, 0.08], [26_000_000, 0.09], [27_700_000, 0.10],
            [29_350_000, 0.11], [31_450_000, 0.12], [33_950_000, 0.13], [37_100_000, 0.14],
            [41_100_000, 0.15], [45_800_000, 0.16], [49_500_000, 0.17], [53_800_000, 0.18],
            [58_500_000, 0.19], [64_000_000, 0.20], [71_000_000, 0.21], [80_000_000, 0.22],
            [93_000_000, 0.23], [109_000_000, 0.24], [129_000_000, 0.25], [163_000_000, 0.26],
            [211_000_000, 0.27], [374_000_000, 0.28], [459_000_000, 0.29], [555_000_000, 0.30],
            [704_000_000, 0.31], [957_000_000, 0.32], [1_405_000_000, 0.33], [null, 0.34],
        ]);
    }

    /** TER Kategori C — PTKP K/3. */
    private function terC(): array
    {
        return $this->brackets([
            [6_600_000, 0.0], [6_950_000, 0.0025], [7_350_000, 0.005], [7_800_000, 0.0075],
            [8_850_000, 0.01], [9_800_000, 0.0125], [10_950_000, 0.015], [11_200_000, 0.0175],
            [12_050_000, 0.02], [12_950_000, 0.03], [14_150_000, 0.04], [15_550_000, 0.05],
            [17_050_000, 0.06], [19_500_000, 0.07], [22_700_000, 0.08], [26_600_000, 0.09],
            [28_100_000, 0.10], [30_100_000, 0.11], [32_600_000, 0.12], [35_400_000, 0.13],
            [38_900_000, 0.14], [43_000_000, 0.15], [47_400_000, 0.16], [51_200_000, 0.17],
            [55_800_000, 0.18], [60_400_000, 0.19], [66_700_000, 0.20], [74_500_000, 0.21],
            [83_200_000, 0.22], [95_000_000, 0.23], [110_000_000, 0.24], [134_000_000, 0.25],
            [169_000_000, 0.26], [221_000_000, 0.27], [390_000_000, 0.28], [463_000_000, 0.29],
            [561_000_000, 0.30], [709_000_000, 0.31], [965_000_000, 0.32], [1_419_000_000, 0.33],
            [null, 0.34],
        ]);
    }
}
