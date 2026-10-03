<?php

namespace App\Services\Payroll;

use App\Models\EmployeeLoan;
use App\Models\EmployeeTaxPeriodTotal;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\Payslip;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\Receipt;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\Payroll\Currency\CurrencyConverter;
use App\Services\Payroll\Formula\FormulaEngine;
use App\Services\Payroll\Formula\FormulaException;
use App\Services\Payroll\Tax\FinalTaxCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orkestrator perhitungan payroll satu batch periode.
 *
 * Untuk tiap karyawan aktif (yang punya gaji pokok efektif):
 *   Gaji pokok + tunjangan/potongan tetap
 *   + tunjangan lembur (dari Lembur approved, dimonetisasi PP 35/2021)
 *   − potongan ketidakhadiran (dari Presensi)
 *   + reimburse struk approved (opsional, default nonaktif — hindari dobel bayar)
 *   − cicilan kasbon aktif
 *   − PPh21 (metode TER / Pasal 17 via Pph21TerCalculator)
 *   = take-home pay.
 *
 * Semua integrasi bersifat READ-ONLY terhadap modul lain (query builder
 * berparameter, tanpa eval / raw SQL mentah). Kalkulasi bersifat idempoten:
 * memanggil ulang menghapus & membangun ulang payslip. Saldo kasbon TIDAK
 * dikurangi di sini (dilakukan saat markPaid) agar tidak dobel potong.
 */
class PayrollCalculator
{
    /** Divisi hari kerja default untuk potongan absen (upah sehari = gaji pokok / divisor). */
    private const DEFAULT_WORKING_DAYS = 21;

    /** Faktor pembagi upah lembur per jam (PP 35/2021: 1/173 upah sebulan). */
    private const OVERTIME_HOURS_DIVISOR = 173;

    public function __construct(
        private readonly Pph21TerCalculator $tax = new Pph21TerCalculator(),
        private readonly BpjsCalculatorService $bpjs = new BpjsCalculatorService(),
        private readonly FormulaEngine $formula = new FormulaEngine(),
        private readonly FinalTaxCalculator $finalTax = new FinalTaxCalculator(),
        private readonly CurrencyConverter $currency = new CurrencyConverter(),
    ) {
    }

    /**
     * Opsi integrasi (default aman & sesuai pilihan user).
     *
     * @return array<string, bool|int>
     */
    public static function defaultOptions(): array
    {
        return [
            'pph21'                 => true,
            'bpjs'                  => true,
            'overtime'              => true,
            'attendance_deduction'  => true,
            'receipt_reimbursement' => false, // default OFF: cegah dobel bayar dgn modul Struk
            'loan_installment'      => true,
            'adjustments'           => true,  // penyesuaian/koreksi retroaktif approved (Fase 3)
            'working_days_divisor'  => self::DEFAULT_WORKING_DAYS,
        ];
    }

    /**
     * Hitung ulang seluruh payslip untuk sebuah batch payroll.
     * Harus dipanggil di dalam DB::transaction oleh controller.
     *
     * @param array<string, bool|int> $options
     */
    public function calculate(Payroll $payroll, array $options = []): Payroll
    {
        $opt = array_merge(self::defaultOptions(), $options);

        $year = (int) $payroll->period_year;
        $month = (int) $payroll->period_month;
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $periodEndDate = $periodEnd->toDateString();
        $onDate = $periodEndDate;

        // Hapus payslip lama (idempoten). Item ikut terhapus (cascade).
        $payroll->payslips()->delete();

        // Kunci kurs valas untuk batch ini (Fase 6). Snapshot lama dimuat lebih
        // dulu agar rekalkulasi tidak mengubah angka bila master kurs berubah.
        $this->currency->beginRun($payroll);

        // Modul Adjustments (Fase 3): lepas klaim penyesuaian dari batch ini agar
        // rekalkulasi idempoten (yang tadinya applied kembali menjadi approved & antre).
        $adjustmentsEnabled = (bool) $opt['adjustments'];
        if ($adjustmentsEnabled) {
            PayrollAdjustment::where('payroll_id', $payroll->id)
                ->where('status', PayrollAdjustment::STATUS_APPLIED)
                ->update([
                    'payroll_id' => null,
                    'status'     => PayrollAdjustment::STATUS_APPROVED,
                    'applied_at' => null,
                ]);
        }

        $users = $this->eligibleUsers($payroll);

        // Peta penyesuaian yang siap diklaim (approved & belum terpakai) per karyawan.
        $adjustmentsByUser = collect();
        if ($adjustmentsEnabled) {
            $eligibleIds = $users->pluck('id')->all();
            if (! empty($eligibleIds)) {
                $adjustmentsByUser = PayrollAdjustment::where('company_id', $payroll->company_id)
                    ->where('status', PayrollAdjustment::STATUS_APPROVED)
                    ->whereNull('payroll_id')
                    ->whereIn('user_id', $eligibleIds)
                    ->orderBy('id')
                    ->get()
                    ->groupBy('user_id');
            }
        }
        $claimedAdjustmentIds = [];

        $totalGross = 0.0;
        $totalDeduction = 0.0;
        $totalTax = 0.0;
        $totalBpjsCompany = 0.0;
        $totalBpjsEmployee = 0.0;
        $totalNet = 0.0;
        $count = 0;

        foreach ($users as $user) {
            $salary = $user->activeSalaryOn($periodEndDate);
            if (! $salary) {
                continue; // Tanpa gaji pokok efektif → tidak diikutkan payroll.
            }

            $items = [];      // baris slip
            $calcSteps = [];  // jejak perhitungan (audit)
            $sort = 0;

            // Penggajian valuta asing (Fase 6): gaji pokok yang didenominasi valas
            // DIKONVERSI ke Rupiah di sini, sehingga seluruh langkah berikutnya
            // (BPJS, PPh 21/26, jurnal GL, 1721-A1) tetap bekerja dalam Rupiah.
            // Nominal valasnya disimpan terpisah pada kolom *_currency payslip.
            // Kurs tidak tersedia → RuntimeException (batch ditolak, bukan 1:1).
            $payCurrency = $this->currency->normalize($salary->currency ?? null);
            $basicCurrency = (float) $salary->basic_salary;
            $fxRate = $this->currency->rate($payCurrency, $onDate, $payroll->company_id);
            $basic = $fxRate === 1.0 ? $basicCurrency : round($basicCurrency * $fxRate);
            if ($this->currency->isForeign($payCurrency)) {
                $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_CURRENCY, [
                    'currency'      => $payCurrency,
                    'amount_currency' => round($basicCurrency, 2),
                    'exchange_rate' => $fxRate,
                    'base_currency' => CurrencyConverter::BASE,
                ], $basic, 'Kurs terkunci pada batch');
            }

            // 1) Gaji pokok
            $items[] = $this->item(
                'Gaji Pokok',
                PayslipItem::TYPE_EARNING,
                $basic,
                true,
                false,
                'basic',
                $sort++,
                notes: $this->currency->isForeign($payCurrency)
                    ? sprintf('%s %s × kurs %s', $payCurrency, number_format($basicCurrency, 2, ',', '.'), number_format($fxRate, 2, ',', '.'))
                    : null,
            );

            // 2) Tunjangan / potongan tetap
            $fixedEarnings = 0.0;    // hanya tunjangan tetap KENA PAJAK (basis lembur & pajak)
            $fixedEarningsAll = 0.0; // seluruh tunjangan tetap (basis upah BPJS)
            $componentValues = [];   // KODE komponen tetap → nominal (leaf untuk formula DSL, Fase 6)
            foreach ($this->fixedComponents($user, $periodEndDate) as $row) {
                $isEarning = $row->type === 'earning';
                $items[] = $this->item(
                    $row->name,
                    $isEarning ? PayslipItem::TYPE_EARNING : PayslipItem::TYPE_DEDUCTION,
                    (float) $row->amount,
                    (bool) $row->is_taxable,
                    false,
                    'fixed',
                    $sort++,
                    salaryComponentId: (int) $row->salary_component_id,
                );
                if ($row->code !== null) {
                    $componentValues[strtoupper((string) $row->code)] = (float) $row->amount;
                }
                if ($isEarning) {
                    $fixedEarningsAll += (float) $row->amount;
                    if ((float) $row->is_taxable) {
                        $fixedEarnings += (float) $row->amount;
                    }
                }
            }

            // Basis upah sebulan untuk lembur & potongan (gaji pokok + tunjangan tetap kena pajak).
            $monthlyWageBase = $basic + $fixedEarnings;
            // Basis upah BPJS (gaji pokok + seluruh tunjangan tetap).
            $bpjsWageBase = $basic + $fixedEarningsAll;

            // Prakomputasi kehadiran: dipakai potongan absen (langkah 5) DAN konteks
            // formula DSL (PRESENT_DAYS / ABSENT_DAYS). Selalu dihitung agar formula
            // tidak bergantung pada urutan langkah maupun flag attendance_deduction.
            [$absentDays, $presentDays] = $this->attendanceDays($user, $periodStart, $periodEnd);

            // 3) Tunjangan lembur (PP 35/2021)
            $overtimeHours = 0.0;
            if ($opt['overtime']) {
                [$overtimePay, $overtimeHours] = $this->overtimePay($user, $year, $month, $monthlyWageBase);
                if ($overtimePay > 0) {
                    $items[] = $this->item(
                        'Tunjangan Lembur',
                        PayslipItem::TYPE_EARNING,
                        $overtimePay,
                        true,
                        false,
                        'overtime',
                        $sort++,
                        notes: number_format($overtimeHours, 1, ',', '.') . ' jam (PP 35/2021)',
                    );
                    $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_OVERTIME, [
                        'overtime_hours'   => $overtimeHours,
                        'monthly_wage_base' => $monthlyWageBase,
                        'hourly_divisor'   => self::OVERTIME_HOURS_DIVISOR,
                    ], $overtimePay, 'PP 35/2021');
                }
            }

            // 4) Reimburse struk approved (opsional, non-taxable)
            //    Satu baris slip PER STRUK (ref_type/ref_id terisi) agar:
            //      a) markPaid() tahu struk mana saja yang dibayar lewat gaji dan bisa
            //         menandainya lunas (cegah dobel bayar lewat pencairan terpisah);
            //      b) Calculation Trace & slip PDF dapat menampilkan rinciannya.
            if ($opt['receipt_reimbursement']) {
                $receipts = $this->approvedReimbursements($user, $payroll->company_id, $periodStart, $periodEnd);
                $reimburseTotal = 0.0;
                $reimburseRefs = [];
                foreach ($receipts as $receipt) {
                    $amount = (float) $receipt->reimburse_amount;
                    if ($amount <= 0) {
                        continue;
                    }
                    $items[] = $this->item(
                        'Reimburse Struk ' . $receipt->receipt_number,
                        PayslipItem::TYPE_EARNING,
                        $amount,
                        false, // non-taxable
                        false,
                        'receipt',
                        $sort++,
                        refType: Receipt::class,
                        refId: (int) $receipt->id,
                        // notes dibatasi agar tidak melampaui kolom varchar(255).
                        notes: mb_substr(trim(($receipt->vendor_name ?: '—') . ' · ' . $receipt->receipt_date), 0, 255),
                    );
                    $reimburseTotal += $amount;
                    $reimburseRefs[] = [
                        'receipt_id'     => (int) $receipt->id,
                        'receipt_number' => $receipt->receipt_number,
                        'amount'         => round($amount, 2),
                    ];
                }
                if ($reimburseTotal > 0) {
                    $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_REIMBURSEMENT, [
                        'receipt_count' => count($reimburseRefs),
                        'receipts'      => $reimburseRefs,
                    ], $reimburseTotal, 'Reimburse struk approved (non-objek PPh 21)');
                }
            }

            // 4b) Penyesuaian / koreksi retroaktif (Fase 3) — earning & deduction sekaligus.
            //     Disisipkan SEBELUM penghitungan bruto agar earning kena pajak otomatis
            //     ikut ke bruto/taxableGross/PPh21, dan deduction ikut ke total potongan.
            //     Catatan: TIDAK memengaruhi basis upah BPJS/lembur (dihitung di atas) —
            //     penyesuaian bersifat sekali-waktu, bukan komponen upah tetap.
            if ($adjustmentsEnabled && $adjustmentsByUser->has($user->id)) {
                $adjEarning = 0.0;
                $adjDeduction = 0.0;
                foreach ($adjustmentsByUser->get($user->id) as $adj) {
                    $isEarn = $adj->type === PayrollAdjustment::TYPE_EARNING;
                    $items[] = $this->item(
                        $adj->name,
                        $isEarn ? PayslipItem::TYPE_EARNING : PayslipItem::TYPE_DEDUCTION,
                        (float) $adj->amount,
                        $isEarn ? (bool) $adj->is_taxable : false,
                        false,
                        'adjustment',
                        $sort++,
                        refType: PayrollAdjustment::class,
                        refId: (int) $adj->id,
                        notes: $adj->reason,
                    );
                    if ($isEarn) {
                        $adjEarning += (float) $adj->amount;
                    } else {
                        $adjDeduction += (float) $adj->amount;
                    }
                    $claimedAdjustmentIds[] = (int) $adj->id;
                }
                if ($adjEarning > 0 || $adjDeduction > 0) {
                    $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_ADJUSTMENT, [
                        'adjustment_earning'   => round($adjEarning, 2),
                        'adjustment_deduction' => round($adjDeduction, 2),
                    ], $adjEarning - $adjDeduction, 'Koreksi/penyesuaian manual');
                }
            }

            // 4c) Komponen bertipe FORMULA (Fase 6 §6.A — DSL aman, dievaluasi FormulaEngine).
            //     Disisipkan SEBELUM penghitungan bruto agar earning formula yang kena pajak
            //     otomatis ikut ke bruto/taxableGross/PPh21. Formula TIDAK memengaruhi basis
            //     upah BPJS/lembur (konservatif, seperti penyesuaian): basis itu sudah dikunci.
            $this->applyFormulaComponents(
                $user, $periodEnd, $periodEndDate, $opt,
                $basic, $fixedEarnings, $fixedEarningsAll, $overtimeHours,
                $absentDays, $presentDays, $componentValues,
                $items, $calcSteps, $sort,
            );

            // Hitung bruto & bruto kena pajak dari earning yang sudah terkumpul.
            $grossEarning = 0.0;
            $taxableGross = 0.0;
            foreach ($items as $it) {
                if ($it['type'] === PayslipItem::TYPE_EARNING) {
                    $grossEarning += $it['amount'];
                    if ($it['is_taxable']) {
                        $taxableGross += $it['amount'];
                    }
                }
            }

            $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_GROSS, [
                'basic'                  => $basic,
                'fixed_earnings_taxable' => $fixedEarnings,
                'fixed_earnings_all'     => $fixedEarningsAll,
                'overtime_hours'         => $overtimeHours,
            ], $grossEarning);

            // 5) Potongan ketidakhadiran (Presensi) — hari sudah diprakomputasi di atas.
            if ($opt['attendance_deduction'] && $absentDays > 0) {
                $divisor = max(1, (int) $opt['working_days_divisor']);
                $dailyWage = $basic / $divisor;
                $deduction = round($dailyWage * $absentDays);
                if ($deduction > 0) {
                    $items[] = $this->item(
                        'Potongan Ketidakhadiran',
                        PayslipItem::TYPE_DEDUCTION,
                        $deduction,
                        false,
                        false,
                        'attendance',
                        $sort++,
                        notes: $absentDays . ' hari × (gaji pokok / ' . $divisor . ')',
                    );
                    $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_ATTENDANCE, [
                        'absent_days' => $absentDays,
                        'divisor'     => $divisor,
                        'daily_wage'  => round($dailyWage, 2),
                    ], $deduction, 'PP 35/2021');
                }
            }

            // 6) Cicilan kasbon aktif
            if ($opt['loan_installment']) {
                foreach ($this->activeLoanInstallments($user, $year, $month) as [$loan, $amount]) {
                    if ($amount > 0) {
                        $items[] = $this->item(
                            'Cicilan Kasbon' . ($loan->title ? ' — ' . $loan->title : ''),
                            PayslipItem::TYPE_DEDUCTION,
                            $amount,
                            false,
                            false,
                            'loan',
                            $sort++,
                            refType: EmployeeLoan::class,
                            refId: (int) $loan->id,
                        );
                    }
                }
            }

            // 6b) Iuran BPJS (Kesehatan & Ketenagakerjaan)
            //     Bagian karyawan → potongan slip; bagian perusahaan → info total (bukan pengurang neto).
            $bpjsCompanyTotal = 0.0;
            $bpjsEmployeeTotal = 0.0;
            $pensionEmployeeCurrent = 0.0; // JHT 2% + JP 1% karyawan (pengurang PPh21 tahunan)
            $profile = $user->bpjsProfile;
            if ($opt['bpjs'] && $profile) {
                $bpjs = $this->bpjs->calculate($profile, $bpjsWageBase, $onDate, $payroll->company_id);
                $bpjsCompanyTotal = $bpjs['company_total'];
                $bpjsEmployeeTotal = $bpjs['employee_total'];
                $pensionEmployeeCurrent = $bpjs['pension_employee'];

                foreach ($bpjs['employee_deductions'] as $ded) {
                    $items[] = $this->item(
                        $ded['label'],
                        PayslipItem::TYPE_DEDUCTION,
                        $ded['amount'],
                        false,
                        true, // statutory
                        'bpjs',
                        $sort++,
                        code: $ded['code'],
                        notes: $ded['notes'],
                    );
                }

                foreach ($bpjs['steps'] as $step) {
                    $calcSteps[] = $step;
                }
            }

            // 7) PPh21
            $pph21 = 0.0;
            $ptkpStatus = $user->taxProfile?->ptkp_status ?? 'TK/0';
            $hasNpwp = (bool) ($user->taxProfile?->has_npwp ?? false);
            $taxProfile = $user->taxProfile;
            $isForeignSubject = (bool) $taxProfile?->isForeignSubject();
            if ($opt['pph21'] && $isForeignSubject) {
                // ── Subjek Pajak Luar Negeri (ekspatriat) → PPh 26 (Fase 6) ──
                // 20% × penghasilan BRUTO (atau tarif P3B bila ada SKD/DGT).
                // FINAL: tanpa PTKP, tanpa TER, tanpa rekonsiliasi Desember —
                // karena itu cabang ini mendahului seluruh logika PPh 21.
                $pph26 = $this->finalTax->pph26(
                    $taxableGross,
                    $onDate,
                    $payroll->company_id,
                    $taxProfile?->treaty_rate !== null ? (float) $taxProfile->treaty_rate : null,
                    $taxProfile?->treaty_country,
                );
                $pph21 = $pph26['amount'];

                $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_PPH26, [
                    'gross_income'   => round($taxableGross, 2),
                    'rate'           => $pph26['rate'],
                    'rate_source'    => $pph26['source'],
                    'treaty_country' => $taxProfile?->treaty_country,
                ], $pph21, 'Pasal 26 UU PPh');

                if ($pph21 > 0) {
                    $items[] = $this->item(
                        'PPh 26',
                        PayslipItem::TYPE_DEDUCTION,
                        $pph21,
                        false,
                        true, // statutory
                        'tax',
                        $sort++,
                        notes: $pph26['source'] === 'pasal26'
                            ? 'Tarif umum 20% (Pasal 26 UU PPh)'
                            : 'Tarif P3B ' . $taxProfile?->treaty_country,
                    );
                }
            } elseif ($opt['pph21']) {
                if ($payroll->is_year_end) {
                    [$priorGross, $priorTax] = $this->priorYearAccumulation($user, $year, $month);
                    // Iuran pensiun/JHT+JP karyawan setahun = akumulasi masa lalu + masa berjalan.
                    $priorPension = $this->priorPensionAccumulation($user, $year, $month);
                    $pensionAnnual = $priorPension + $pensionEmployeeCurrent;
                    $result = $this->tax->yearEndReconciliation(
                        $taxableGross, $priorGross, $priorTax, $ptkpStatus, $onDate,
                        $payroll->company_id, $hasNpwp, $pensionAnnual
                    );
                    $pph21 = $result['amount'];
                    $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_PPH21_PASAL17, [
                        'taxable_gross_current' => $taxableGross,
                        'prior_taxable_gross'   => $priorGross,
                        'prior_tax_withheld'    => $priorTax,
                        'pension_annual'        => $pensionAnnual,
                        'ptkp_status'           => $ptkpStatus,
                        'pkp'                   => $result['pkp'],
                        'annual_tax'            => $result['annual_tax'],
                    ], $pph21, 'UU HPP Pasal 17');
                } else {
                    $result = $this->tax->monthlyTer($taxableGross, $ptkpStatus, $onDate, $payroll->company_id, $hasNpwp);
                    $pph21 = $result['amount'];
                    $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_PPH21_TER, [
                        'taxable_gross' => $taxableGross,
                        'ptkp_status'   => $ptkpStatus,
                        'category'      => $result['category'],
                        'rate'          => $result['rate'],
                        'has_npwp'      => $hasNpwp,
                    ], $pph21, 'PMK 168/2023');
                }

                if ($pph21 > 0) {
                    $items[] = $this->item(
                        'PPh21',
                        PayslipItem::TYPE_DEDUCTION,
                        $pph21,
                        false,
                        true, // statutory
                        'tax',
                        $sort++,
                    );
                }
            }

            // Total potongan (termasuk BPJS bagian karyawan yang sudah masuk $items).
            $deductionTotal = 0.0;
            foreach ($items as $it) {
                if ($it['type'] === PayslipItem::TYPE_DEDUCTION) {
                    $deductionTotal += $it['amount'];
                }
            }

            $net = $grossEarning - $deductionTotal;

            $calcSteps[] = $this->calcStep(PayslipCalculationStep::STEP_NETT, [
                'gross'          => round($grossEarning, 2),
                'deduction'      => round($deductionTotal, 2),
                'bpjs_employee'  => $bpjsEmployeeTotal,
            ], $net);

            $payslip = $this->createPayslip(
                $payroll, $user, $salary, $basic,
                $grossEarning, $taxableGross, $pph21,
                $bpjsCompanyTotal, $bpjsEmployeeTotal, $deductionTotal, $net,
                $ptkpStatus, $absentDays, $presentDays, $overtimeHours, $items, $calcSteps,
                $payCurrency, $fxRate
            );

            // Konsolidasi masa pajak (untuk rekonsiliasi tahunan & Bukti Potong 1721-A1).
            $this->upsertTaxPeriodTotal($payroll, $user, $year, $month, $taxableGross, $pph21);

            $totalGross += $grossEarning;
            $totalDeduction += $deductionTotal;
            $totalTax += $pph21;
            $totalBpjsCompany += $bpjsCompanyTotal;
            $totalBpjsEmployee += $bpjsEmployeeTotal;
            $totalNet += $net;
            $count++;

            unset($payslip);
        }

        $payroll->update([
            'status'              => Payroll::STATUS_CALCULATED,
            'calculated_at'       => now(),
            'total_gross'         => round($totalGross, 2),
            'total_deduction'     => round($totalDeduction, 2),
            'total_tax'           => round($totalTax, 2),
            'total_bpjs_company'  => round($totalBpjsCompany, 2),
            'total_bpjs_employee' => round($totalBpjsEmployee, 2),
            'total_net'           => round($totalNet, 2),
            'employee_count'      => $count,
            // Snapshot kurs yang dipakai batch ini (NULL bila murni Rupiah).
            'exchange_rates'      => $this->currency->lockedRates(),
        ]);

        // Klaim penyesuaian yang benar-benar dipakai batch ini (idempoten: sudah
        // dilepas di awal calculate, sekarang ditandai applied + terikat ke batch).
        if ($adjustmentsEnabled && ! empty($claimedAdjustmentIds)) {
            PayrollAdjustment::whereIn('id', $claimedAdjustmentIds)->update([
                'payroll_id' => $payroll->id,
                'status'     => PayrollAdjustment::STATUS_APPLIED,
                'applied_at' => now(),
            ]);
        }

        return $payroll->refresh();
    }

    /**
     * Karyawan yang berhak diikutkan: aktif, satu perusahaan, dan (bila batch
     * per cabang) sesuai kantor cabang.
     *
     * Publik agar ThrCalculatorService memakai aturan kelayakan yang SAMA
     * (satu sumber kebenaran: aktif + company + scope cabang + scope grup).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function eligibleUsers(Payroll $payroll): \Illuminate\Support\Collection
    {
        return User::query()
            ->with(['position:id,name', 'taxProfile', 'bpjsProfile'])
            ->where('company_id', $payroll->company_id)
            ->where('is_active', true)
            ->when($payroll->attendance_setting_id, function ($q) use ($payroll) {
                $q->where('attendance_setting_id', $payroll->attendance_setting_id);
            })
            // Scoping grup (item lanjutan): batch ber-grup hanya menghitung anggota grup itu.
            // NULL grup → tak ada filter (perilaku lama: seluruh perusahaan/cabang).
            ->when($payroll->payroll_group_id, function ($q) use ($payroll) {
                $q->where('payroll_group_id', $payroll->payroll_group_id);
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Tunjangan/potongan tetap karyawan yang berlaku pada tanggal periode.
     *
     * Komponen bertipe FORMULA (Fase 6 §6.A) DIKECUALIKAN di sini — ditangani
     * terpisah oleh formulaComponents() karena nominalnya dihitung, bukan tetap.
     */
    private function fixedComponents(User $user, string $onDate): \Illuminate\Support\Collection
    {
        return DB::table('employee_salary_components as esc')
            ->join('salary_components as sc', 'sc.id', '=', 'esc.salary_component_id')
            ->where('esc.user_id', $user->id)
            ->where('esc.is_active', true)
            ->where('sc.is_active', true)
            ->where('sc.calc_type', '!=', SalaryComponent::CALC_FORMULA)
            ->whereDate('esc.effective_date', '<=', $onDate)
            ->where(function ($q) use ($onDate) {
                // Masih berlaku: belum ada tanggal akhir, atau akhir >= tanggal periode.
                $q->whereNull('esc.end_date')
                    ->orWhereDate('esc.end_date', '>=', $onDate);
            })
            ->select('esc.amount', 'esc.salary_component_id', 'sc.code', 'sc.name', 'sc.type', 'sc.is_taxable')
            ->orderBy('sc.sort_order')
            ->get();
    }

    /**
     * Komponen gaji bertipe FORMULA yang terpasang & berlaku pada tanggal periode.
     * Mengembalikan kode, nama, tipe, taxable, id komponen, dan rumus DSL mentah
     * (untuk dievaluasi FormulaEngine — bukan eval PHP).
     */
    private function formulaComponents(User $user, string $onDate): \Illuminate\Support\Collection
    {
        return DB::table('employee_salary_components as esc')
            ->join('salary_components as sc', 'sc.id', '=', 'esc.salary_component_id')
            ->where('esc.user_id', $user->id)
            ->where('esc.is_active', true)
            ->where('sc.is_active', true)
            ->where('sc.calc_type', SalaryComponent::CALC_FORMULA)
            ->whereNotNull('sc.formula_dsl')
            ->whereDate('esc.effective_date', '<=', $onDate)
            ->where(function ($q) use ($onDate) {
                $q->whereNull('esc.end_date')
                    ->orWhereDate('esc.end_date', '>=', $onDate);
            })
            ->select('esc.salary_component_id', 'sc.code', 'sc.name', 'sc.type', 'sc.is_taxable', 'sc.formula_dsl')
            ->orderBy('sc.sort_order')
            ->get();
    }

    /**
     * Evaluasi & sisipkan komponen bertipe formula (Fase 6 §6.A) ke slip karyawan.
     *
     * Konteks variabel = variabel sistem (BASIC_SALARY, GROSS_TAXABLE, PRESENT_DAYS,
     * dst) + nilai KODE komponen tetap sebagai leaf. Urutan evaluasi disusun via
     * dependencyOrder() (topo-sort) sehingga komponen formula boleh merujuk komponen
     * formula lain selama tak melingkar (siklus dicegah saat simpan, & dijaga ulang
     * di sini). Kegagalan satu komponen bersifat non-fatal: dicatat sebagai jejak
     * FORMULA_ERROR dan nilainya dianggap 0, slip lain tetap terhitung.
     *
     * @param array<string, bool|int>    $opt
     * @param array<string, float>       $componentValues  KODE komponen tetap → nominal
     * @param array<int, array<string, mixed>> $items       (by-ref) baris slip
     * @param array<int, array<string, mixed>> $calcSteps   (by-ref) jejak perhitungan
     * @param int                        $sort             (by-ref) nomor urut baris
     */
    private function applyFormulaComponents(
        User $user,
        Carbon $periodEnd,
        string $periodEndDate,
        array $opt,
        float $basic,
        float $fixedEarnings,
        float $fixedEarningsAll,
        float $overtimeHours,
        int $absentDays,
        int $presentDays,
        array $componentValues,
        array &$items,
        array &$calcSteps,
        int &$sort,
    ): void {
        $formulaComponents = $this->formulaComponents($user, $periodEndDate);
        if ($formulaComponents->isEmpty()) {
            return;
        }

        // Snapshot bruto kena pajak SEBELUM formula (agar rujukan GROSS_TAXABLE stabil,
        // tidak bergantung pada urutan evaluasi antar-komponen formula).
        $grossTaxableSnapshot = 0.0;
        foreach ($items as $it) {
            if ($it['type'] === PayslipItem::TYPE_EARNING && $it['is_taxable']) {
                $grossTaxableSnapshot += $it['amount'];
            }
        }

        // Variabel sistem + nilai komponen tetap (leaf, dirujuk via KODE).
        $context = $componentValues;
        $context['BASIC_SALARY']        = $basic;
        $context['FIXED_ALLOWANCE']     = $fixedEarnings;     // tunjangan tetap KENA PAJAK
        $context['FIXED_ALLOWANCE_ALL'] = $fixedEarningsAll;  // seluruh tunjangan tetap
        $context['GROSS_TAXABLE']       = $grossTaxableSnapshot;
        $context['PRESENT_DAYS']        = (float) $presentDays;
        $context['ABSENT_DAYS']         = (float) $absentDays;
        $context['WORKING_DAYS']        = (float) max(1, (int) $opt['working_days_divisor']);
        $context['OVERTIME_HOURS']      = $overtimeHours;
        $context['TENURE_MONTHS']       = (float) $this->tenureMonths($user, $periodEnd);
        $context['UMR_AMOUNT']          = 0.0; // belum ada sumber UMR per wilayah — placeholder aman.

        // Peta KODE → formula + metadata komponen (by KODE) untuk penyusunan urutan.
        $formulaMap = [];
        $byCode = [];
        foreach ($formulaComponents as $row) {
            $code = strtoupper((string) $row->code);
            $formulaMap[$code] = (string) $row->formula_dsl;
            $byCode[$code] = $row;
        }

        // Susun urutan evaluasi (topo-sort). Bila melingkar (harusnya sudah dicegah
        // saat simpan), catat diagnostik & lewati seluruh fase formula.
        try {
            $order = $this->formula->dependencyOrder($formulaMap);
        } catch (FormulaException $e) {
            $calcSteps[] = $this->calcStep(
                PayslipCalculationStep::STEP_FORMULA_ERROR,
                ['error' => $this->truncate($e->getMessage(), 300)],
                0.0,
                'Fase 6 §6.A',
                'dsl-2024',
            );

            return;
        }

        foreach ($order as $code) {
            $row = $byCode[$code] ?? null;
            if (! $row) {
                continue;
            }

            try {
                $raw = $this->formula->evaluate($formulaMap[$code], $context);
            } catch (FormulaException $e) {
                // Non-fatal: satu komponen gagal tak menggagalkan seluruh slip.
                $calcSteps[] = $this->calcStep(
                    PayslipCalculationStep::STEP_FORMULA_ERROR,
                    [
                        'code'    => $code,
                        'formula' => $this->truncate($formulaMap[$code], 200),
                        'error'   => $this->truncate($e->getMessage(), 200),
                    ],
                    0.0,
                    'Fase 6 §6.A',
                    'dsl-2024',
                );
                $context[$code] = 0.0; // agar komponen turunannya tetap terdefinisi.
                continue;
            }

            $value = round($raw);
            $context[$code] = $value; // hasil jadi konteks bagi komponen formula turunannya.

            if (abs($value) < 0.005) {
                continue; // nol → tidak menambah baris slip.
            }

            $isEarning = $row->type === 'earning';
            $items[] = $this->item(
                $row->name,
                $isEarning ? PayslipItem::TYPE_EARNING : PayslipItem::TYPE_DEDUCTION,
                $isEarning ? $value : abs($value),
                $isEarning ? (bool) $row->is_taxable : false,
                false,
                'formula',
                $sort++,
                salaryComponentId: (int) $row->salary_component_id,
                notes: $this->truncate((string) $row->formula_dsl, 160),
            );
            $calcSteps[] = $this->calcStep(
                PayslipCalculationStep::STEP_FORMULA,
                ['code' => $code, 'formula' => $this->truncate($formulaMap[$code], 200)],
                $raw,
                'Fase 6 §6.A',
                'dsl-2024',
            );
        }
    }

    /**
     * Masa kerja dalam bulan penuh hingga akhir periode. 0 bila joined_date kosong
     * atau berada di masa depan relatif periode.
     */
    private function tenureMonths(User $user, Carbon $periodEnd): int
    {
        $joined = $user->joined_date;
        if (! $joined) {
            return 0;
        }
        $joinedDate = $joined instanceof Carbon ? $joined : Carbon::parse((string) $joined);
        if ($joinedDate->greaterThan($periodEnd)) {
            return 0;
        }

        return (int) abs($joinedDate->diffInMonths($periodEnd));
    }

    /** Potong teks panjang untuk catatan/jejak (cegah payload jejak membengkak). */
    private function truncate(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, max(1, $max - 1)) . '…' : $text;
    }

    /**
     * Monetisasi lembur approved dalam periode (PP 35/2021).
     * Weekday: jam ke-1 ×1,5; jam berikutnya ×2. Hari libur: MVP ×2 seluruh jam.
     *
     * @return array{0: float, 1: float} [totalPay, totalHours]
     */
    private function overtimePay(User $user, int $year, int $month, float $monthlyWageBase): array
    {
        $start = Carbon::create($year, $month, 1)->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $rows = DB::table('overtime_approvals as o')
            ->join('attendances as a', 'a.id', '=', 'o.attendance_id')
            ->where('o.user_id', $user->id)
            ->where('o.status', 'approved')
            ->whereBetween('a.date', [$start, $end])
            ->get(['o.overtime_minutes', 'a.is_holiday']);

        if ($rows->isEmpty() || $monthlyWageBase <= 0) {
            return [0.0, 0.0];
        }

        $hourly = $monthlyWageBase / self::OVERTIME_HOURS_DIVISOR;
        $totalPay = 0.0;
        $totalHours = 0.0;

        foreach ($rows as $row) {
            $hours = ((int) $row->overtime_minutes) / 60;
            if ($hours <= 0) {
                continue;
            }
            $totalHours += $hours;

            if ($row->is_holiday) {
                // MVP: hari libur seluruh jam ×2 (penyederhanaan PP 35/2021).
                $totalPay += $hours * 2 * $hourly;
            } else {
                $first = min($hours, 1) * 1.5;
                $rest = max(0, $hours - 1) * 2;
                $totalPay += ($first + $rest) * $hourly;
            }
        }

        return [round($totalPay), round($totalHours, 2)];
    }

    /**
     * Hitung hari alpha & hari hadir dalam periode.
     *
     * @return array{0: int, 1: int} [absentDays, presentDays]
     */
    private function attendanceDays(User $user, Carbon $start, Carbon $end): array
    {
        $range = [$start->toDateString(), $end->toDateString()];

        $absent = (int) DB::table('attendances')
            ->where('user_id', $user->id)
            ->whereBetween('date', $range)
            ->where('status', 'absent')
            ->where('is_holiday', false)
            ->count();

        $present = (int) DB::table('attendances')
            ->where('user_id', $user->id)
            ->whereBetween('date', $range)
            ->whereIn('status', ['present', 'late', 'wfh', 'early_leave'])
            ->count();

        return [$absent, $present];
    }

    /**
     * Struk approved (belum dibayar) dalam periode — satu baris per struk.
     *
     * Dikembalikan sebagai daftar (bukan total) agar tiap struk dapat dirujuk
     * dari baris slip (`ref_id`) sehingga markPaid() bisa menandainya lunas.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function approvedReimbursements(User $user, ?int $companyId, Carbon $start, Carbon $end)
    {
        return DB::table('receipts')
            ->where('user_id', $user->id)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->whereNull('paid_at') // cegah dobel bayar: struk yg sudah dicairkan via alur sendiri di-skip
            ->whereBetween('receipt_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('id')
            ->get([
                'id',
                'receipt_number',
                'vendor_name',
                'receipt_date',
                'approved_amount as reimburse_amount',
            ]);
    }

    /**
     * Kasbon aktif yang sudah masuk periode potong.
     *
     * @return array<int, array{0: EmployeeLoan, 1: float}>
     */
    private function activeLoanInstallments(User $user, int $year, int $month): array
    {
        $periodKey = $year * 12 + ($month - 1);

        $loans = EmployeeLoan::query()
            ->where('user_id', $user->id)
            ->where('status', EmployeeLoan::STATUS_ACTIVE)
            ->where('remaining_amount', '>', 0)
            ->get();

        $result = [];
        foreach ($loans as $loan) {
            $startKey = $loan->start_period_year * 12 + ($loan->start_period_month - 1);
            if ($startKey > $periodKey) {
                continue;
            }
            $amount = min((float) $loan->installment_amount, (float) $loan->remaining_amount);
            $result[] = [$loan, round($amount, 2)];
        }

        return $result;
    }

    /**
     * Akumulasi bruto kena pajak & PPh21 masa Jan–(month-1) tahun sama (untuk rekonsiliasi Desember).
     *
     * @return array{0: float, 1: float}
     */
    private function priorYearAccumulation(User $user, int $year, int $month): array
    {
        $prior = Payslip::query()
            ->where('user_id', $user->id)
            ->where('period_year', $year)
            ->where('period_month', '<', $month)
            ->whereIn('status', ['approved', 'paid'])
            ->get(['taxable_income', 'pph21']);

        return [
            (float) $prior->sum('taxable_income'),
            (float) $prior->sum('pph21'),
        ];
    }

    /**
     * Akumulasi iuran pensiun/JHT+JP bagian KARYAWAN masa Jan–(month-1) tahun sama.
     * Dipakai sebagai pengurang penghasilan bruto pada rekonsiliasi PPh21 tahunan.
     */
    private function priorPensionAccumulation(User $user, int $year, int $month): float
    {
        return (float) DB::table('payslip_items as pi')
            ->join('payslips as p', 'p.id', '=', 'pi.payslip_id')
            ->where('p.user_id', $user->id)
            ->where('p.period_year', $year)
            ->where('p.period_month', '<', $month)
            ->whereIn('p.status', ['approved', 'paid'])
            ->whereIn('pi.code', [
                BpjsCalculatorService::CODE_JHT_EMP,
                BpjsCalculatorService::CODE_JP_EMP,
            ])
            ->sum('pi.amount');
    }

    /**
     * Upsert konsolidasi masa pajak untuk kontribusi run REGULER (satu baris per
     * user per bulan). Idempoten: dipanggil ulang saat rekalkulasi.
     *
     * Run reguler HANYA "memiliki" kolom teratur (`regular_gross`,`regular_pph21`).
     * Kontribusi run lain pada bulan yang sama — THR/bonus (`irregular_*`) & natura
     * (`taxable_benefit_gross`) — DIBACA-PERTAHANKAN agar tidak ter-clobber ketika
     * run reguler dihitung/dihitung-ulang. Kedua total selalu dihitung ulang dari
     * keempat kontributor sehingga tetap konsisten:
     *   total_taxable_gross  = regular_gross + irregular_gross + taxable_benefit_gross
     *   total_pph21_withheld = regular_pph21 + irregular_pph21
     *
     * Regresi: pada bulan tanpa THR/natura, irregular & benefit = 0 ⇒ kedua total
     * identik dengan perilaku lama (regular_gross / pph21 saja).
     */
    public function upsertTaxPeriodTotal(
        Payroll $payroll,
        User $user,
        int $year,
        int $month,
        float $taxableGross,
        float $pph21,
    ): void {
        $existing = EmployeeTaxPeriodTotal::where('user_id', $user->id)
            ->where('tax_year', $year)
            ->where('tax_month', $month)
            ->first();

        $regularGross = round($taxableGross, 2);
        $regularPph21 = round($pph21, 2);
        // Baca-pertahankan kontribusi run lain (THR/bonus & natura).
        $irregularGross = round((float) ($existing->irregular_gross ?? 0), 2);
        $irregularPph21 = round((float) ($existing->irregular_pph21 ?? 0), 2);
        $benefitGross   = round((float) ($existing->taxable_benefit_gross ?? 0), 2);

        EmployeeTaxPeriodTotal::updateOrCreate(
            [
                'user_id'   => $user->id,
                'tax_year'  => $year,
                'tax_month' => $month,
            ],
            [
                'company_id'            => $payroll->company_id,
                'regular_gross'         => $regularGross,
                'regular_pph21'         => $regularPph21,
                'irregular_gross'       => $irregularGross,
                'irregular_pph21'       => $irregularPph21,
                'taxable_benefit_gross' => $benefitGross,
                'total_taxable_gross'   => round($regularGross + $irregularGross + $benefitGross, 2),
                'total_pph21_withheld'  => round($regularPph21 + $irregularPph21, 2),
            ]
        );
    }

    /**
     * Upsert konsolidasi masa pajak untuk kontribusi run THR (tidak teratur).
     *
     * Cerminan dari upsertTaxPeriodTotal(): run THR HANYA "memiliki" kolom tidak
     * teratur (`irregular_gross`,`irregular_pph21`); kontribusi run reguler
     * (`regular_*`) & natura (`taxable_benefit_gross`) DIBACA-PERTAHANKAN. Baris
     * dibuat bila belum ada (mis. run THR jalan sebelum run reguler bulan itu).
     * Idempoten terhadap rekalkulasi batch THR.
     */
    public function upsertTaxPeriodTotalThr(
        Payroll $payroll,
        User $user,
        int $year,
        int $month,
        float $thrGross,
        float $thrPph21,
    ): void {
        $existing = EmployeeTaxPeriodTotal::where('user_id', $user->id)
            ->where('tax_year', $year)
            ->where('tax_month', $month)
            ->first();

        $irregularGross = round($thrGross, 2);
        $irregularPph21 = round($thrPph21, 2);
        // Baca-pertahankan kontribusi run reguler & natura.
        $regularGross = round((float) ($existing->regular_gross ?? 0), 2);
        $regularPph21 = round((float) ($existing->regular_pph21 ?? 0), 2);
        $benefitGross = round((float) ($existing->taxable_benefit_gross ?? 0), 2);

        EmployeeTaxPeriodTotal::updateOrCreate(
            [
                'user_id'   => $user->id,
                'tax_year'  => $year,
                'tax_month' => $month,
            ],
            [
                'company_id'            => $payroll->company_id,
                'regular_gross'         => $regularGross,
                'regular_pph21'         => $regularPph21,
                'irregular_gross'       => $irregularGross,
                'irregular_pph21'       => $irregularPph21,
                'taxable_benefit_gross' => $benefitGross,
                'total_taxable_gross'   => round($regularGross + $irregularGross + $benefitGross, 2),
                'total_pph21_withheld'  => round($regularPph21 + $irregularPph21, 2),
            ]
        );
    }

    /**
     * Bangun definisi satu baris slip (belum disimpan).
     * Publik agar dipakai ulang oleh ThrCalculatorService (bentuk baris seragam).
     *
     * @return array<string, mixed>
     */
    public function item(
        string $label,
        string $type,
        float $amount,
        bool $isTaxable,
        bool $isStatutory,
        string $source,
        int $sort,
        ?int $salaryComponentId = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $notes = null,
        ?string $code = null,
    ): array {
        return [
            'label'               => $label,
            'code'                => $code,
            'type'                => $type,
            'amount'              => round($amount, 2),
            'is_taxable'          => $isTaxable,
            'is_statutory'        => $isStatutory,
            'source'              => $source,
            'salary_component_id' => $salaryComponentId,
            'ref_type'            => $refType,
            'ref_id'              => $refId,
            'sort_order'          => $sort,
            'notes'               => $notes,
        ];
    }

    /**
     * Bentuk satu baris jejak perhitungan generik (dibulatkan ke rupiah penuh).
     * `step_sequence` diberikan saat persist (urut sesuai kemunculan).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function calcStep(
        string $code,
        array $input,
        float $raw,
        ?string $ruleReference = null,
        string $formulaVersion = 'core-2024',
    ): array {
        $final = round($raw);

        return [
            'step_code'       => $code,
            'formula_version' => $formulaVersion,
            'input_payload'   => $input,
            'raw_result'      => $raw,
            'rounding_diff'   => $final - $raw,
            'final_result'    => $final,
            'rule_reference'  => $ruleReference,
        ];
    }

    /**
     * Simpan payslip + item + jejak perhitungan (dengan snapshot identitas).
     * Publik agar ThrCalculatorService & SeveranceCalculatorService memakai
     * snapshot payslip yang SAMA (identitas, cost center division_id /
     * attendance_setting_id, bank, NPWP termasking).
     *
     * Seluruh argumen uang WAJIB dalam Rupiah. `$currency` & `$exchangeRate`
     * hanya informasi tambahan valuta asing (Fase 6): kolom `gross_currency` &
     * `net_currency` diturunkan dari nominal Rupiah dibagi kurs.
     */
    public function createPayslip(
        Payroll $payroll,
        User $user,
        $salary,
        float $basic,
        float $gross,
        float $taxableGross,
        float $pph21,
        float $bpjsCompanyTotal,
        float $bpjsEmployeeTotal,
        float $deductionTotal,
        float $net,
        string $ptkpStatus,
        int $absentDays,
        int $presentDays,
        float $overtimeHours,
        array $items,
        array $calcSteps = [],
        string $currency = CurrencyConverter::BASE,
        float $exchangeRate = 1.0,
    ): Payslip {
        $rate = $exchangeRate > 0 ? $exchangeRate : 1.0;

        $payslip = $payroll->payslips()->create([
            'company_id'          => $payroll->company_id,
            'user_id'             => $user->id,
            'period_month'        => $payroll->period_month,
            'period_year'         => $payroll->period_year,
            'employee_name'       => $user->name,
            'employee_code'       => $user->employee_code,
            'position_name'       => $user->position?->name,
            'department_name'     => $user->department,
            // Snapshot cost center (Item 4): bekukan dimensi divisi & cabang saat kalkulasi,
            // agar alokasi jurnal GL historis-akurat walau keanggotaan master berubah kelak.
            'division_id'           => $user->division_id,
            'attendance_setting_id' => $user->attendance_setting_id,
            'npwp_masked'         => $user->taxProfile?->maskedNpwp(),
            'ptkp_status'         => $ptkpStatus,
            // Valuta asing (Fase 6) — kolom uang lain tetap Rupiah.
            'currency'            => $currency,
            'exchange_rate'       => $rate,
            'gross_currency'      => round($gross / $rate, 2),
            'net_currency'        => round($net / $rate, 2),
            'bank_name'           => $user->bank_name,
            'bank_account_no'     => $user->bank_account_no,
            'bank_account_holder' => $user->bank_account_holder,
            'basic_salary'        => $basic,
            'total_earning'       => round($gross, 2),
            'gross'               => round($gross, 2),
            'taxable_income'      => round($taxableGross, 2),
            'pph21'               => round($pph21, 2),
            'bpjs_company_total'  => round($bpjsCompanyTotal, 2),
            'bpjs_employee_total' => round($bpjsEmployeeTotal, 2),
            'total_deduction'     => round($deductionTotal, 2),
            'net'                 => round($net, 2),
            'working_days'        => null,
            'present_days'        => $presentDays,
            'absent_days'         => $absentDays,
            'overtime_hours'      => $overtimeHours,
            'status'              => 'calculated',
        ]);

        foreach ($items as $it) {
            $payslip->items()->create($it);
        }

        // Jejak perhitungan — nomor urut diberikan sesuai kemunculan.
        $sequence = 0;
        foreach ($calcSteps as $step) {
            $step['step_sequence'] = ++$sequence;
            $payslip->calculationSteps()->create($step);
        }

        return $payslip;
    }
}
