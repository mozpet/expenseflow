<?php

namespace App\Services\Payroll\Severance;

use App\Models\EmployeeLoan;
use App\Models\Payroll;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\SeveranceCase;
use App\Models\StatutoryRuleVersion;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Tax\FinalTaxCalculator;
use Carbon\Carbon;

/**
 * Orkestrator perhitungan batch EXIT SETTLEMENT (Fase 6, roadmap §9).
 *
 * Batch bertipe `severance` TIDAK menghitung seluruh karyawan aktif seperti run
 * reguler/THR; ia hanya memproses `severance_cases` yang tertaut pada batch itu.
 * Satu kasus = satu payslip.
 *
 * ── Komponen slip (PP 35/2021) ───────────────────────────────────────────────
 *  PENDAPATAN (PPh 21 FINAL — PP 68/2009):
 *    • Uang Pesangon (UP)                 Pasal 40(2) × faktor pengali alasan PHK
 *    • Uang Penghargaan Masa Kerja (UPMK) Pasal 40(3) × faktor pengali
 *    • Uang Penggantian Hak (UPH)         Pasal 40(4): sisa cuti tahunan +
 *      15% × (UP + UPMK) perumahan & pengobatan + ongkos pulang + lain-lain
 *  PENDAPATAN (PPh 21 NON-final / tidak teratur):
 *    • Uang Kompensasi PKWT               Pasal 15–17: (masa kerja bln / 12) × upah
 *    • Uang Pisah                         bila diatur PK/PP/PKB
 *  POTONGAN:
 *    • PPh 21 Final atas (UP + UPMK + UPH)
 *    • PPh 21 atas komponen non-final (TER marginal) — bila ada
 *    • Pelunasan sisa kasbon (opsional per kasus)
 *    • Potongan aset/inventaris yang tidak dikembalikan
 *
 * CATATAN PENTING: batch ini TIDAK memuat gaji bulan berjalan, lembur, potongan
 * absen, maupun iuran BPJS — itu tetap dibayar lewat run REGULER bulan terakhir.
 * Memisahkan keduanya menjaga dasar pengenaan PPh 21 Final tetap bersih
 * (objek final hanya UP+UPMK+UPH) dan jurnal GL tetap terbaca.
 *
 * Bersifat idempoten: memanggil ulang menghapus & membangun ulang seluruh slip
 * batch. Seluruh persistensi didelegasikan ke PayrollCalculator (satu sumber
 * kebenaran untuk snapshot slip), sehingga kelas ini hanya memuat ATURAN BISNIS.
 */
class SeveranceCalculatorService
{
    /** Pembagi upah sehari untuk kompensasi sisa cuti tahunan. */
    private const LEAVE_DAY_DIVISOR = 25;

    public function __construct(
        private readonly PayrollCalculator $base = new PayrollCalculator(),
        private readonly FinalTaxCalculator $finalTax = new FinalTaxCalculator(),
    ) {
    }

    /**
     * Hitung ulang seluruh slip exit settlement untuk sebuah batch.
     * Harus dipanggil di dalam DB::transaction oleh controller.
     *
     * @param array<string, bool|int> $options
     */
    public function calculate(Payroll $payroll, array $options = []): Payroll
    {
        $opt = array_merge(PayrollCalculator::defaultOptions(), $options);
        $withholdTax = (bool) $opt['pph21'];
        $settleLoansOpt = (bool) $opt['loan_installment'];

        $year  = (int) $payroll->period_year;
        $month = (int) $payroll->period_month;
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        $periodEndDate = $periodEnd->toDateString();

        // Idempoten: buang slip lama (item & jejak ikut terhapus via cascade).
        $payroll->payslips()->delete();

        $cases = SeveranceCase::query()
            ->with(['user.position', 'user.taxProfile'])
            ->where('company_id', $payroll->company_id)
            ->where('payroll_id', $payroll->id)
            ->orderBy('id')
            ->get();

        $totalGross = 0.0;
        $totalDeduction = 0.0;
        $totalTax = 0.0;
        $totalNet = 0.0;
        $count = 0;

        foreach ($cases as $case) {
            $user = $case->user;
            if (! $user || (int) $user->company_id !== (int) $payroll->company_id) {
                continue; // Lintas perusahaan → tidak pernah diproses (anti-IDOR).
            }

            // Kurs & tarif dibaca pada TANGGAL PENGAKHIRAN (bukan akhir periode):
            // hak pesangon melekat pada saat hubungan kerja berakhir.
            $onDate = $case->termination_date
                ? Carbon::parse($case->termination_date)->toDateString()
                : $periodEndDate;

            $salary = $user->activeSalaryOn($onDate) ?? $user->activeSalaryOn($periodEndDate);
            if (! $salary) {
                continue; // Tanpa gaji pokok efektif → tidak dapat dihitung.
            }

            $monthlyWage = (float) $salary->basic_salary;
            if ($monthlyWage <= 0) {
                continue;
            }

            $tenureMonths = $this->tenureMonths($user, $case, $onDate);
            $tenureYears  = $tenureMonths / 12;

            $items = [];
            $calcSteps = [];
            $sort = 0;

            // ── 1) UP & UPMK (hanya skema pesangon; PKWT tidak berhak) ──────
            $up = 0.0;
            $upmk = 0.0;
            if (! $case->isPkwt()) {
                $table = $this->severanceTable($onDate, $payroll->company_id);

                $upMonths = $this->monthsFromTable($table['up'] ?? [], $tenureYears);
                $upFactor = (float) $case->up_multiplier;
                $up = round($monthlyWage * $upMonths * $upFactor);

                $upmkMonths = $this->monthsFromTable($table['upmk'] ?? [], $tenureYears);
                $upmkFactor = (float) $case->upmk_multiplier;
                $upmk = round($monthlyWage * $upmkMonths * $upmkFactor);

                if ($up > 0) {
                    $items[] = $this->base->item(
                        'Uang Pesangon (UP)',
                        PayslipItem::TYPE_EARNING,
                        $up,
                        false, // objek PPh 21 FINAL — bukan bagian bruto PPh 21 reguler
                        true,  // statutori
                        'severance_up',
                        $sort++,
                        refType: SeveranceCase::class,
                        refId: (int) $case->id,
                        notes: sprintf('%d bulan upah × %s (PP 35/2021 Pasal 40 ayat 2)', $upMonths, $this->factorLabel($upFactor)),
                    );
                    $calcSteps[] = $this->base->calcStep(
                        PayslipCalculationStep::STEP_SEVERANCE_UP,
                        [
                            'monthly_wage'   => round($monthlyWage, 2),
                            'tenure_months'  => $tenureMonths,
                            'table_months'   => $upMonths,
                            'multiplier'     => $upFactor,
                            'termination_type' => $case->termination_type,
                        ],
                        $up,
                        'PP 35/2021 Pasal 40 ayat (2)',
                    );
                }

                if ($upmk > 0) {
                    $items[] = $this->base->item(
                        'Uang Penghargaan Masa Kerja (UPMK)',
                        PayslipItem::TYPE_EARNING,
                        $upmk,
                        false,
                        true,
                        'severance_upmk',
                        $sort++,
                        refType: SeveranceCase::class,
                        refId: (int) $case->id,
                        notes: sprintf('%d bulan upah × %s (PP 35/2021 Pasal 40 ayat 3)', $upmkMonths, $this->factorLabel($upmkFactor)),
                    );
                    $calcSteps[] = $this->base->calcStep(
                        PayslipCalculationStep::STEP_SEVERANCE_UPMK,
                        [
                            'monthly_wage'  => round($monthlyWage, 2),
                            'tenure_months' => $tenureMonths,
                            'table_months'  => $upmkMonths,
                            'multiplier'    => $upmkFactor,
                        ],
                        $upmk,
                        'PP 35/2021 Pasal 40 ayat (3)',
                    );
                }
            }

            // ── 2) UPH — Uang Penggantian Hak (Pasal 40 ayat 4) ─────────────
            $uph = 0.0;
            if ($case->include_uph) {
                $leaveDays = (float) $case->annual_leave_balance_days;
                $leaveCompensation = $leaveDays > 0
                    ? round($monthlyWage / self::LEAVE_DAY_DIVISOR * $leaveDays)
                    : 0.0;

                $housingRate = $this->housingMedicalRate($onDate, $payroll->company_id);
                $housingMedical = round(($up + $upmk) * $housingRate);

                $relocation = round((float) $case->relocation_cost);
                $other      = round((float) $case->other_compensation);

                $uph = $leaveCompensation + $housingMedical + $relocation + $other;

                if ($uph > 0) {
                    $items[] = $this->base->item(
                        'Uang Penggantian Hak (UPH)',
                        PayslipItem::TYPE_EARNING,
                        $uph,
                        false,
                        true,
                        'severance_uph',
                        $sort++,
                        refType: SeveranceCase::class,
                        refId: (int) $case->id,
                        notes: sprintf(
                            'Sisa cuti %s hari + %s%% perumahan/pengobatan + ongkos pulang & lain-lain',
                            rtrim(rtrim(number_format($leaveDays, 2, ',', '.'), '0'), ','),
                            rtrim(rtrim(number_format($housingRate * 100, 2, ',', '.'), '0'), ','),
                        ),
                    );
                    $calcSteps[] = $this->base->calcStep(
                        PayslipCalculationStep::STEP_SEVERANCE_UPH,
                        [
                            'leave_balance_days'   => round($leaveDays, 2),
                            'leave_day_divisor'    => self::LEAVE_DAY_DIVISOR,
                            'leave_compensation'   => $leaveCompensation,
                            'housing_medical_rate' => $housingRate,
                            'housing_medical'      => $housingMedical,
                            'relocation_cost'      => $relocation,
                            'other_compensation'   => $other,
                        ],
                        $uph,
                        'PP 35/2021 Pasal 40 ayat (4)',
                    );
                }
            }

            // ── 3) Uang Kompensasi PKWT (Pasal 15–17) ───────────────────────
            $pkwtCompensation = 0.0;
            if ($case->isPkwt()) {
                $pkwtMonths = $this->pkwtMonths($case, $onDate);
                $pkwtCompensation = $pkwtMonths > 0
                    ? round($monthlyWage * $pkwtMonths / 12)
                    : 0.0;

                if ($pkwtCompensation > 0) {
                    $items[] = $this->base->item(
                        'Uang Kompensasi PKWT',
                        PayslipItem::TYPE_EARNING,
                        $pkwtCompensation,
                        true,  // PPh 21 non-final (penghasilan tidak teratur)
                        true,
                        'severance_pkwt',
                        $sort++,
                        refType: SeveranceCase::class,
                        refId: (int) $case->id,
                        notes: sprintf('Masa kerja %d bulan ÷ 12 × upah sebulan (PP 35/2021 Pasal 15–17)', $pkwtMonths),
                    );
                    $calcSteps[] = $this->base->calcStep(
                        PayslipCalculationStep::STEP_PKWT_COMPENSATION,
                        [
                            'monthly_wage'   => round($monthlyWage, 2),
                            'contract_months' => $pkwtMonths,
                        ],
                        $pkwtCompensation,
                        'PP 35/2021 Pasal 15–17',
                    );
                }
            }

            // ── 4) Uang Pisah (bila diatur PK/PP/PKB) ───────────────────────
            $separation = round((float) $case->separation_pay);
            if ($separation > 0) {
                $items[] = $this->base->item(
                    'Uang Pisah',
                    PayslipItem::TYPE_EARNING,
                    $separation,
                    true, // non-final: masuk bruto PPh 21 tidak teratur
                    false,
                    'severance_separation',
                    $sort++,
                    refType: SeveranceCase::class,
                    refId: (int) $case->id,
                    notes: 'Sesuai PK/PP/PKB',
                );
            }

            // ── 5) PPh 21 FINAL atas (UP + UPMK + UPH) — PP 68/2009 ─────────
            $finalBase = $up + $upmk + $uph;
            $hasNpwp = (bool) ($user->taxProfile?->has_npwp ?? false);
            $finalTaxAmount = 0.0;
            if ($withholdTax && $finalBase > 0) {
                $final = $this->finalTax->pesangonFinal($finalBase, $onDate, $payroll->company_id, $hasNpwp);
                $finalTaxAmount = $final['amount'];

                if ($finalTaxAmount > 0) {
                    $items[] = $this->base->item(
                        'PPh 21 Final Pesangon',
                        PayslipItem::TYPE_DEDUCTION,
                        $finalTaxAmount,
                        false,
                        true,
                        'tax',
                        $sort++,
                        notes: 'PP 68/2009 — bersifat final',
                    );
                }
                $calcSteps[] = $this->base->calcStep(
                    PayslipCalculationStep::STEP_PPH21_FINAL,
                    [
                        'severance_gross' => round($finalBase, 2),
                        'up'              => $up,
                        'upmk'            => $upmk,
                        'uph'             => $uph,
                        'has_npwp'        => $hasNpwp,
                        'effective_rate'  => $final['effective_rate'],
                        'brackets_used'   => $final['brackets_used'],
                    ],
                    $finalTaxAmount,
                    'PP 68/2009 (PPh 21 Final)',
                );
            }

            // ── 6) Pelunasan sisa kasbon ────────────────────────────────────
            $loanSettlement = 0.0;
            if ($settleLoansOpt && $case->settle_loans) {
                foreach ($this->outstandingLoans($user->id, $payroll->company_id) as $loan) {
                    $remaining = round((float) $loan->remaining_amount);
                    if ($remaining <= 0) {
                        continue;
                    }
                    $loanSettlement += $remaining;
                    $items[] = $this->base->item(
                        'Pelunasan Kasbon' . ($loan->title ? ' — ' . $loan->title : ''),
                        PayslipItem::TYPE_DEDUCTION,
                        $remaining,
                        false,
                        false,
                        'loan',
                        $sort++,
                        refType: EmployeeLoan::class,
                        refId: (int) $loan->id,
                        notes: 'Pelunasan sisa saldo saat pengakhiran hubungan kerja',
                    );
                }
            }

            // ── 7) Potongan aset/inventaris ─────────────────────────────────
            $assetDeduction = round((float) $case->asset_deduction);
            if ($assetDeduction > 0) {
                $items[] = $this->base->item(
                    'Potongan Aset / Inventaris',
                    PayslipItem::TYPE_DEDUCTION,
                    $assetDeduction,
                    false,
                    false,
                    'severance_asset',
                    $sort++,
                    refType: SeveranceCase::class,
                    refId: (int) $case->id,
                );
            }

            // ── Total ───────────────────────────────────────────────────────
            $grossEarning = 0.0;
            $taxableGross = 0.0; // bruto PPh 21 NON-final (kompensasi PKWT & uang pisah)
            foreach ($items as $it) {
                if ($it['type'] === PayslipItem::TYPE_EARNING) {
                    $grossEarning += $it['amount'];
                    if ($it['is_taxable']) {
                        $taxableGross += $it['amount'];
                    }
                }
            }

            if ($grossEarning <= 0) {
                continue; // Tidak ada hak yang dibayar → tidak membuat slip kosong.
            }

            $deductionTotal = 0.0;
            foreach ($items as $it) {
                if ($it['type'] === PayslipItem::TYPE_DEDUCTION) {
                    $deductionTotal += $it['amount'];
                }
            }

            $net = $grossEarning - $deductionTotal;

            $calcSteps[] = $this->base->calcStep(
                PayslipCalculationStep::STEP_NETT,
                [
                    'gross'            => round($grossEarning, 2),
                    'deduction'        => round($deductionTotal, 2),
                    'final_tax'        => $finalTaxAmount,
                    'loan_settlement'  => round($loanSettlement, 2),
                    'asset_deduction'  => $assetDeduction,
                ],
                $net,
            );

            $ptkpStatus = $user->taxProfile?->ptkp_status ?? 'TK/0';

            $this->base->createPayslip(
                $payroll, $user, $salary,
                0.0,            // basic_salary: slip pesangon tidak memuat gaji bulan berjalan
                $grossEarning,
                $taxableGross,  // hanya komponen NON-final
                $finalTaxAmount,
                0.0, 0.0,       // BPJS: pesangon bukan objek iuran
                $deductionTotal,
                $net,
                $ptkpStatus,
                0, 0, 0.0,
                $items, $calcSteps
            );

            // Konsolidasi masa pajak: HANYA komponen non-final yang masuk 1721-A1.
            // PPh 21 Final atas pesangon dilaporkan terpisah (1721-VII) & TIDAK
            // dikreditkan pada rekonsiliasi tahunan Pasal 17.
            if ($taxableGross > 0) {
                $this->base->upsertTaxPeriodTotalThr($payroll, $user, $year, $month, $taxableGross, 0.0);
            }

            $case->update(['status' => SeveranceCase::STATUS_CALCULATED]);

            $totalGross += $grossEarning;
            $totalDeduction += $deductionTotal;
            $totalTax += $finalTaxAmount;
            $totalNet += $net;
            $count++;
        }

        $payroll->update([
            'status'              => Payroll::STATUS_CALCULATED,
            'calculated_at'       => now(),
            'total_gross'         => round($totalGross, 2),
            'total_deduction'     => round($totalDeduction, 2),
            'total_tax'           => round($totalTax, 2),
            'total_bpjs_company'  => 0,
            'total_bpjs_employee' => 0,
            'total_net'           => round($totalNet, 2),
            'employee_count'      => $count,
        ]);

        return $payroll->refresh();
    }

    /**
     * Pratinjau perhitungan satu kasus TANPA menyimpan apa pun (read-only).
     * Dipakai endpoint preview agar HRD melihat angka sebelum membuat batch.
     *
     * @return array<string, mixed>|null
     */
    public function preview(SeveranceCase $case): ?array
    {
        $user = $case->user()->with('taxProfile')->first();
        if (! $user) {
            return null;
        }

        $onDate = Carbon::parse($case->termination_date)->toDateString();
        $salary = $user->activeSalaryOn($onDate);
        if (! $salary) {
            return null;
        }

        $monthlyWage = (float) $salary->basic_salary;
        $tenureMonths = $this->tenureMonths($user, $case, $onDate);
        $tenureYears = $tenureMonths / 12;

        $up = 0.0;
        $upmk = 0.0;
        $upMonths = 0;
        $upmkMonths = 0;
        if (! $case->isPkwt()) {
            $table = $this->severanceTable($onDate, $case->company_id);
            $upMonths = $this->monthsFromTable($table['up'] ?? [], $tenureYears);
            $upmkMonths = $this->monthsFromTable($table['upmk'] ?? [], $tenureYears);
            $up = round($monthlyWage * $upMonths * (float) $case->up_multiplier);
            $upmk = round($monthlyWage * $upmkMonths * (float) $case->upmk_multiplier);
        }

        $leaveDays = (float) $case->annual_leave_balance_days;
        $leaveCompensation = 0.0;
        $housingMedical = 0.0;
        $housingRate = $this->housingMedicalRate($onDate, $case->company_id);
        if ($case->include_uph) {
            $leaveCompensation = $leaveDays > 0 ? round($monthlyWage / self::LEAVE_DAY_DIVISOR * $leaveDays) : 0.0;
            $housingMedical = round(($up + $upmk) * $housingRate);
        }
        $uph = $case->include_uph
            ? $leaveCompensation + $housingMedical + round((float) $case->relocation_cost) + round((float) $case->other_compensation)
            : 0.0;

        $pkwtMonths = $case->isPkwt() ? $this->pkwtMonths($case, $onDate) : 0;
        $pkwtCompensation = $pkwtMonths > 0 ? round($monthlyWage * $pkwtMonths / 12) : 0.0;

        $separation = round((float) $case->separation_pay);
        $finalBase = $up + $upmk + $uph;
        $hasNpwp = (bool) ($user->taxProfile?->has_npwp ?? false);
        $final = $this->finalTax->pesangonFinal($finalBase, $onDate, $case->company_id, $hasNpwp);

        $loanSettlement = 0.0;
        if ($case->settle_loans) {
            foreach ($this->outstandingLoans($user->id, (int) $case->company_id) as $loan) {
                $loanSettlement += round((float) $loan->remaining_amount);
            }
        }
        $assetDeduction = round((float) $case->asset_deduction);

        $gross = $up + $upmk + $uph + $pkwtCompensation + $separation;
        $deduction = $final['amount'] + $loanSettlement + $assetDeduction;

        return [
            'severance_case_id' => (int) $case->id,
            'user_id'           => (int) $user->id,
            'employee_name'     => (string) $user->name,
            'termination_type'  => $case->termination_type,
            'termination_date'  => $onDate,
            'monthly_wage'      => number_format($monthlyWage, 2, '.', ''),
            'tenure_months'     => $tenureMonths,
            'tenure_years'      => round($tenureYears, 2),
            'up_months'         => $upMonths,
            'upmk_months'       => $upmkMonths,
            'up'                => number_format($up, 2, '.', ''),
            'upmk'              => number_format($upmk, 2, '.', ''),
            'uph'               => number_format($uph, 2, '.', ''),
            'uph_breakdown'     => [
                'leave_compensation' => number_format($leaveCompensation, 2, '.', ''),
                'housing_medical'    => number_format($housingMedical, 2, '.', ''),
                'housing_medical_rate' => $housingRate,
                'relocation_cost'    => number_format(round((float) $case->relocation_cost), 2, '.', ''),
                'other_compensation' => number_format(round((float) $case->other_compensation), 2, '.', ''),
            ],
            'pkwt_compensation' => number_format($pkwtCompensation, 2, '.', ''),
            'pkwt_months'       => $pkwtMonths,
            'separation_pay'    => number_format($separation, 2, '.', ''),
            'final_tax_base'    => number_format($finalBase, 2, '.', ''),
            'pph21_final'       => number_format($final['amount'], 2, '.', ''),
            'pph21_final_effective_rate' => $final['effective_rate'],
            'loan_settlement'   => number_format($loanSettlement, 2, '.', ''),
            'asset_deduction'   => number_format($assetDeduction, 2, '.', ''),
            'total_gross'       => number_format($gross, 2, '.', ''),
            'total_deduction'   => number_format($deduction, 2, '.', ''),
            'total_net'         => number_format($gross - $deduction, 2, '.', ''),
        ];
    }

    /**
     * Masa kerja (bulan penuh) hingga tanggal pengakhiran. PKWT memakai tanggal
     * mulai kontrak bila diisi; selainnya memakai joined_date karyawan.
     */
    private function tenureMonths($user, SeveranceCase $case, string $onDate): int
    {
        $end = Carbon::parse($onDate);
        $start = $case->isPkwt() && $case->contract_start_date
            ? Carbon::parse($case->contract_start_date)
            : ($user->joined_date ? Carbon::parse((string) $user->joined_date) : null);

        if (! $start || $start->greaterThan($end)) {
            return 0;
        }

        return (int) floor(abs($start->diffInMonths($end)));
    }

    /**
     * Masa kerja PKWT (bulan penuh) untuk uang kompensasi: dari awal kontrak
     * hingga tanggal berakhir kontrak (atau tanggal pengakhiran bila lebih awal).
     */
    private function pkwtMonths(SeveranceCase $case, string $onDate): int
    {
        if (! $case->contract_start_date) {
            return 0;
        }

        $start = Carbon::parse($case->contract_start_date);
        $end = Carbon::parse($onDate);
        if ($case->contract_end_date) {
            $contractEnd = Carbon::parse($case->contract_end_date);
            if ($contractEnd->lessThan($end)) {
                $end = $contractEnd;
            }
        }

        if ($start->greaterThan($end)) {
            return 0;
        }

        return (int) floor(abs($start->diffInMonths($end)));
    }

    /**
     * Tabel UP & UPMK yang berlaku (effective-dated). Fallback ke tabel PP 35/2021
     * bila aturan belum di-seed — agar perhitungan tetap benar di instalasi baru.
     *
     * @return array{up: array<int, array<string, mixed>>, upmk: array<int, array<string, mixed>>}
     */
    private function severanceTable(string $onDate, ?int $companyId): array
    {
        $rule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_SEVERANCE_TABLE, $onDate, $companyId);
        $payload = $rule?->payload ?? [];

        return [
            'up'   => $payload['up']   ?? $this->upFallback(),
            'upmk' => $payload['upmk'] ?? $this->upmkFallback(),
        ];
    }

    /** Persentase penggantian perumahan & pengobatan dari (UP + UPMK). */
    private function housingMedicalRate(string $onDate, ?int $companyId): float
    {
        $rule = StatutoryRuleVersion::resolve(StatutoryRuleVersion::TYPE_SEVERANCE_TABLE, $onDate, $companyId);

        return (float) ($rule?->payload['uph_housing_medical_rate'] ?? 0.15);
    }

    /**
     * Cari jumlah bulan upah untuk masa kerja tertentu.
     * `min_years` inklusif, `max_years` eksklusif (null = tanpa batas atas).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function monthsFromTable(array $rows, float $tenureYears): int
    {
        foreach ($rows as $row) {
            $min = (float) ($row['min_years'] ?? 0);
            $max = $row['max_years'] ?? null;
            if ($tenureYears >= $min && ($max === null || $tenureYears < (float) $max)) {
                return (int) ($row['months'] ?? 0);
            }
        }

        return 0;
    }

    /** Sisa kasbon aktif yang harus dilunasi saat pengakhiran. */
    private function outstandingLoans(int $userId, int $companyId)
    {
        return EmployeeLoan::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('status', EmployeeLoan::STATUS_ACTIVE)
            ->where('remaining_amount', '>', 0)
            ->orderBy('id')
            ->get();
    }

    /** Label faktor pengali untuk catatan baris slip (1 → "1×", 1.75 → "1,75×"). */
    private function factorLabel(float $factor): string
    {
        return rtrim(rtrim(number_format($factor, 2, ',', '.'), '0'), ',') . '×';
    }

    /** Tabel UP PP 35/2021 Pasal 40 ayat (2) — fallback. */
    private function upFallback(): array
    {
        return [
            ['min_years' => 0, 'max_years' => 1,    'months' => 1],
            ['min_years' => 1, 'max_years' => 2,    'months' => 2],
            ['min_years' => 2, 'max_years' => 3,    'months' => 3],
            ['min_years' => 3, 'max_years' => 4,    'months' => 4],
            ['min_years' => 4, 'max_years' => 5,    'months' => 5],
            ['min_years' => 5, 'max_years' => 6,    'months' => 6],
            ['min_years' => 6, 'max_years' => 7,    'months' => 7],
            ['min_years' => 7, 'max_years' => 8,    'months' => 8],
            ['min_years' => 8, 'max_years' => null, 'months' => 9],
        ];
    }

    /** Tabel UPMK PP 35/2021 Pasal 40 ayat (3) — fallback. */
    private function upmkFallback(): array
    {
        return [
            ['min_years' => 3,  'max_years' => 6,    'months' => 2],
            ['min_years' => 6,  'max_years' => 9,    'months' => 3],
            ['min_years' => 9,  'max_years' => 12,   'months' => 4],
            ['min_years' => 12, 'max_years' => 15,   'months' => 5],
            ['min_years' => 15, 'max_years' => 18,   'months' => 6],
            ['min_years' => 18, 'max_years' => 21,   'months' => 7],
            ['min_years' => 21, 'max_years' => 24,   'months' => 8],
            ['min_years' => 24, 'max_years' => null, 'months' => 10],
        ];
    }
}
