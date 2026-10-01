<?php

namespace App\Services\Payroll;

use App\Models\EmployeeBpjsProfile;
use App\Models\PayslipCalculationStep;
use App\Models\StatutoryRuleVersion;

/**
 * Kalkulator iuran BPJS (Kesehatan & Ketenagakerjaan) — Fase 2.
 *
 * Sumber tarif & batas upah dibaca dari `statutory_rule_versions` (effective-dated),
 * TANPA eval / raw SQL — koreksi cukup lewat data (override per-company atau global).
 *
 * Konvensi beban:
 *   - Iuran PERUSAHAAN (JKK, JKM, JHT 3,7%, JP 2%, Kesehatan 4%) BUKAN pengurang
 *     take-home pay karyawan → hanya disimpan sebagai info slip + total batch.
 *   - Iuran KARYAWAN (Kesehatan 1%, JHT 2%, JP 1%) → menjadi baris POTONGAN slip.
 *   - Pengurang PPh21 (hanya rekonsiliasi tahunan Pasal 17): iuran pensiun/JHT + JP
 *     yang ditanggung karyawan (JHT 2% + JP 1%). Kesehatan 1% BUKAN pengurang.
 *
 * Basis upah (wage base) = gaji pokok + seluruh tunjangan tetap (dihitung pemanggil).
 */
class BpjsCalculatorService
{
    /** Kode potongan (disimpan di payslip_items.code) agar stabil untuk pelaporan. */
    public const CODE_KES_EMP = 'BPJS_KES_EMP';
    public const CODE_JHT_EMP = 'BPJS_JHT_EMP';
    public const CODE_JP_EMP  = 'BPJS_JP_EMP';

    /**
     * Hitung seluruh iuran BPJS untuk satu karyawan pada satu masa.
     *
     * @return array{
     *   applicable: bool,
     *   wage_base: float,
     *   programs: array<string, array<string, mixed>>,
     *   company_total: float,
     *   employee_total: float,
     *   employee_deductions: array<int, array{label:string, code:string, amount:float, notes:?string}>,
     *   pension_employee: float,
     *   steps: array<int, array<string, mixed>>
     * }
     */
    public function calculate(
        EmployeeBpjsProfile $profile,
        float $wageBase,
        string $onDate,
        ?int $companyId = null
    ): array {
        $wageBase = max(0.0, $wageBase);

        $programs = [];
        $employeeDeductions = [];
        $steps = [];
        $companyTotal = 0.0;
        $employeeTotal = 0.0;
        $pensionEmployee = 0.0;
        $sequence = 0;

        // ─── BPJS Kesehatan (4% perusahaan / 1% karyawan, cap upah) ───
        if ($profile->has_bpjs_kes) {
            $kesRule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_BPJS_KES, $onDate, $companyId);
            $kes = $this->kesehatan($wageBase, $kesRule);

            if ($kes['company'] > 0 || $kes['employee'] > 0) {
                $programs['kesehatan'] = $kes;
                $companyTotal += $kes['company'];
                $employeeTotal += $kes['employee'];

                if ($kes['employee'] > 0) {
                    $employeeDeductions[] = [
                        'label'  => 'BPJS Kesehatan (1%)',
                        'code'   => self::CODE_KES_EMP,
                        'amount' => $kes['employee'],
                        'notes'  => 'Iuran BPJS Kesehatan bagian karyawan',
                    ];
                }

                $steps[] = $this->step(
                    PayslipCalculationStep::STEP_BPJS_KES,
                    ++$sequence,
                    [
                        'wage_base'     => $wageBase,
                        'capped_base'   => $kes['base'],
                        'company_rate'  => $kes['company_rate'],
                        'employee_rate' => $kes['employee_rate'],
                        'wage_cap'      => $kes['wage_cap'],
                    ],
                    $kes['company'] + $kes['employee'],
                    $kes['rule_reference']
                );
            }
        }

        // ─── BPJS Ketenagakerjaan (JKK/JKM/JHT/JP/JKP) ───
        if ($profile->has_bpjs_tk) {
            $tkRule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_BPJS_TK, $onDate, $companyId);
            $payload = $tkRule?->payload ?? [];
            $ref = $tkRule ? "statutory_rule_versions#{$tkRule->id}" : 'fallback';

            // JKK — dibayar perusahaan penuh, tarif per kelas risiko, upah riil (tanpa cap).
            $jkkRate = $this->jkkRate($payload, $profile->jkkRiskClass());
            $jkk = $this->companyOnly($wageBase, $jkkRate);
            if ($jkk['company'] > 0) {
                $jkk['risk_class'] = $profile->jkkRiskClass();
                $jkk['rule_reference'] = $ref;
                $programs['jkk'] = $jkk;
                $companyTotal += $jkk['company'];
                $steps[] = $this->step(PayslipCalculationStep::STEP_BPJS_JKK, ++$sequence, [
                    'wage_base'  => $wageBase,
                    'rate'       => $jkkRate,
                    'risk_class' => $profile->jkkRiskClass(),
                ], $jkk['company'], $ref);
            }

            // JKM — dibayar perusahaan penuh, upah riil (tanpa cap).
            $jkmRate = (float) ($payload['jkm']['company_rate'] ?? 0);
            $jkm = $this->companyOnly($wageBase, $jkmRate);
            if ($jkm['company'] > 0) {
                $jkm['rule_reference'] = $ref;
                $programs['jkm'] = $jkm;
                $companyTotal += $jkm['company'];
                $steps[] = $this->step(PayslipCalculationStep::STEP_BPJS_JKM, ++$sequence, [
                    'wage_base' => $wageBase,
                    'rate'      => $jkmRate,
                ], $jkm['company'], $ref);
            }

            // JHT — 3,7% perusahaan + 2% karyawan, upah riil (tanpa cap).
            $jhtCompanyRate = (float) ($payload['jht']['company_rate'] ?? 0);
            $jhtEmployeeRate = (float) ($payload['jht']['employee_rate'] ?? 0);
            $jht = $this->twoSided($wageBase, $jhtCompanyRate, $jhtEmployeeRate);
            if ($jht['company'] > 0 || $jht['employee'] > 0) {
                $jht['rule_reference'] = $ref;
                $programs['jht'] = $jht;
                $companyTotal += $jht['company'];
                $employeeTotal += $jht['employee'];
                $pensionEmployee += $jht['employee']; // pengurang PPh21 tahunan
                if ($jht['employee'] > 0) {
                    $employeeDeductions[] = [
                        'label'  => 'BPJS JHT (2%)',
                        'code'   => self::CODE_JHT_EMP,
                        'amount' => $jht['employee'],
                        'notes'  => 'Jaminan Hari Tua bagian karyawan',
                    ];
                }
                $steps[] = $this->step(PayslipCalculationStep::STEP_BPJS_JHT, ++$sequence, [
                    'wage_base'     => $wageBase,
                    'company_rate'  => $jhtCompanyRate,
                    'employee_rate' => $jhtEmployeeRate,
                ], $jht['company'] + $jht['employee'], $ref);
            }

            // JP — 2% perusahaan + 1% karyawan, batas upah (cap).
            $jpCompanyRate = (float) ($payload['jp']['company_rate'] ?? 0);
            $jpEmployeeRate = (float) ($payload['jp']['employee_rate'] ?? 0);
            $jpCap = isset($payload['jp']['wage_cap']) ? (float) $payload['jp']['wage_cap'] : null;
            $jpBase = $jpCap !== null ? min($wageBase, $jpCap) : $wageBase;
            $jp = $this->twoSided($jpBase, $jpCompanyRate, $jpEmployeeRate);
            if ($jp['company'] > 0 || $jp['employee'] > 0) {
                $jp['base'] = $jpBase;
                $jp['wage_cap'] = $jpCap;
                $jp['rule_reference'] = $ref;
                $programs['jp'] = $jp;
                $companyTotal += $jp['company'];
                $employeeTotal += $jp['employee'];
                $pensionEmployee += $jp['employee']; // pengurang PPh21 tahunan
                if ($jp['employee'] > 0) {
                    $employeeDeductions[] = [
                        'label'  => 'BPJS JP (1%)',
                        'code'   => self::CODE_JP_EMP,
                        'amount' => $jp['employee'],
                        'notes'  => 'Jaminan Pensiun bagian karyawan',
                    ];
                }
                $steps[] = $this->step(PayslipCalculationStep::STEP_BPJS_JP, ++$sequence, [
                    'wage_base'     => $wageBase,
                    'capped_base'   => $jpBase,
                    'wage_cap'      => $jpCap,
                    'company_rate'  => $jpCompanyRate,
                    'employee_rate' => $jpEmployeeRate,
                ], $jp['company'] + $jp['employee'], $ref);
            }
            // JKP: rekomposisi APBN — tidak menambah potongan karyawan / beban perusahaan (rate 0).
        }

        return [
            'applicable'          => ($companyTotal + $employeeTotal) > 0,
            'wage_base'           => $wageBase,
            'programs'            => $programs,
            'company_total'       => round($companyTotal, 2),
            'employee_total'      => round($employeeTotal, 2),
            'employee_deductions' => $employeeDeductions,
            'pension_employee'    => round($pensionEmployee, 2),
            'steps'               => $steps,
        ];
    }

    /** BPJS Kesehatan: company & employee dengan batas atas upah. */
    private function kesehatan(float $wageBase, ?StatutoryRuleVersion $rule): array
    {
        $payload = $rule?->payload ?? ['company_rate' => 0.04, 'employee_rate' => 0.01, 'wage_cap' => 12_000_000];
        $companyRate = (float) ($payload['company_rate'] ?? 0);
        $employeeRate = (float) ($payload['employee_rate'] ?? 0);
        $cap = isset($payload['wage_cap']) ? (float) $payload['wage_cap'] : null;
        $base = $cap !== null ? min($wageBase, $cap) : $wageBase;

        return [
            'base'           => $base,
            'wage_cap'       => $cap,
            'company_rate'   => $companyRate,
            'employee_rate'  => $employeeRate,
            'company'        => (float) round($base * $companyRate),
            'employee'       => (float) round($base * $employeeRate),
            'rule_reference' => $rule ? "statutory_rule_versions#{$rule->id}" : 'fallback',
        ];
    }

    /** Tarif JKK sesuai kelas risiko (fallback ke company_rate default lalu kelas 1). */
    private function jkkRate(array $payload, int $riskClass): float
    {
        $classes = $payload['jkk']['classes'] ?? [];
        if (isset($classes[(string) $riskClass])) {
            return (float) $classes[(string) $riskClass];
        }

        return (float) ($payload['jkk']['company_rate'] ?? 0);
    }

    /** Program yang hanya dibayar perusahaan (JKK, JKM). */
    private function companyOnly(float $base, float $rate): array
    {
        return [
            'base'     => $base,
            'rate'     => $rate,
            'company'  => (float) round($base * $rate),
            'employee' => 0.0,
        ];
    }

    /** Program dua sisi (perusahaan + karyawan) atas satu basis upah. */
    private function twoSided(float $base, float $companyRate, float $employeeRate): array
    {
        return [
            'base'          => $base,
            'company_rate'  => $companyRate,
            'employee_rate' => $employeeRate,
            'company'       => (float) round($base * $companyRate),
            'employee'      => (float) round($base * $employeeRate),
        ];
    }

    /** Bentuk satu baris jejak perhitungan (dibulatkan ke rupiah penuh). */
    private function step(string $code, int $sequence, array $input, float $raw, string $ruleReference): array
    {
        $final = round($raw);

        return [
            'step_code'      => $code,
            'step_sequence'  => $sequence,
            'formula_version' => 'bpjs-2024',
            'input_payload'  => $input,
            'raw_result'     => $raw,
            'rounding_diff'  => $final - $raw,
            'final_result'   => $final,
            'rule_reference' => $ruleReference,
        ];
    }
}
