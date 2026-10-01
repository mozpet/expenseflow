<?php

namespace App\Services\Payroll\Thr;

use App\Models\EmployeeTaxPeriodTotal;
use App\Models\Payroll;
use App\Models\PayslipCalculationStep;
use App\Models\PayslipItem;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Pph21TerCalculator;
use Carbon\Carbon;

/**
 * Orkestrator perhitungan batch THR (Tunjangan Hari Raya) — Run THR terpisah.
 *
 * Berbeda dari gaji reguler, batch THR menghasilkan slip yang SANGAT ringkas:
 *   + 1 baris pendapatan "Tunjangan Hari Raya (THR)" (kena pajak, non-statutori)
 *   − 1 baris potongan "PPh21 THR" (bila terutang)
 *   = THR bersih.
 * TIDAK ada gaji pokok, tunjangan tetap, lembur, potongan absen, kasbon, atau BPJS
 * pada slip THR (THR bukan komponen upah rutin & tidak dikenai iuran BPJS).
 *
 * Nominal THR (Permenaker 6/2016):
 *   - masa kerja ≥ 12 bulan  → 1× upah sebulan
 *   - 1 ≤ masa kerja < 12 bln → pro-rata: (masa kerja / 12) × upah sebulan
 *   - masa kerja < 1 bulan    → tidak berhak (tidak dibuatkan slip)
 * "Upah sebulan" pada MVP ini memakai gaji pokok efektif (activeSalaryOn) —
 * tunjangan tetap dapat ditambahkan kelak tanpa mengubah kontrak ini.
 *
 * PPh21 THR (penghasilan tidak teratur) memakai metode TER marginal (PMK 168/2023):
 * pajak atas (bruto reguler bulan berjalan + THR) dikurangi pajak reguler yang sudah
 * dipotong pada bulan tsb. Selisih tahunan dinormalkan otomatis oleh rekonsiliasi
 * Pasal 17 pada run reguler Desember (payslip THR ikut ke priorYearAccumulation).
 *
 * Bersifat idempoten: memanggil ulang menghapus & membangun ulang slip THR batch.
 * Semua pencatatan payslip & konsolidasi pajak DIDELEGASIKAN ke PayrollCalculator
 * (satu sumber kebenaran untuk snapshot slip & tabel employee_tax_period_totals),
 * sehingga service ini hanya memuat ATURAN BISNIS THR.
 */
class ThrCalculatorService
{
    public function __construct(
        private readonly PayrollCalculator $base = new PayrollCalculator(),
        private readonly Pph21TerCalculator $tax = new Pph21TerCalculator(),
    ) {
    }

    /**
     * Hitung ulang seluruh slip THR untuk sebuah batch.
     * Harus dipanggil di dalam DB::transaction oleh controller.
     *
     * @param array<string, bool|int> $options
     */
    public function calculate(Payroll $payroll, array $options = []): Payroll
    {
        $opt = array_merge(PayrollCalculator::defaultOptions(), $options);
        $withholdPph21 = (bool) $opt['pph21'];

        $year  = (int) $payroll->period_year;
        $month = (int) $payroll->period_month;
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        $periodEndDate = $periodEnd->toDateString();
        $onDate = $periodEndDate;

        // Idempoten: buang slip THR lama (item & step ikut terhapus via cascade).
        $payroll->payslips()->delete();

        $users = $this->base->eligibleUsers($payroll);

        $totalGross = 0.0;
        $totalDeduction = 0.0;
        $totalTax = 0.0;
        $totalNet = 0.0;
        $count = 0;

        foreach ($users as $user) {
            $salary = $user->activeSalaryOn($periodEndDate);
            if (! $salary) {
                continue; // Tanpa gaji pokok efektif → tidak dihitung.
            }

            $monthsWorked = $this->monthsOfService($user, $periodEnd);
            if ($monthsWorked < 1) {
                continue; // Permenaker 6/2016: masa kerja < 1 bulan tidak berhak THR.
            }

            $monthlyWage = (float) $salary->basic_salary;
            $thr = $monthsWorked >= 12
                ? round($monthlyWage)
                : round($monthlyWage * $monthsWorked / 12);

            if ($thr <= 0) {
                continue;
            }

            $ptkpStatus = $user->taxProfile?->ptkp_status ?? 'TK/0';
            $hasNpwp = (bool) ($user->taxProfile?->has_npwp ?? false);

            // PPh21 THR (TER marginal) — butuh bruto & PPh21 reguler bulan berjalan.
            $existing = EmployeeTaxPeriodTotal::where('user_id', $user->id)
                ->where('tax_year', $year)
                ->where('tax_month', $month)
                ->first();
            $regularGross = round((float) ($existing->regular_gross ?? 0), 2);
            $regularPph21 = round((float) ($existing->regular_pph21 ?? 0), 2);

            $thrPph21 = 0.0;
            $pphInput = [];
            if ($withholdPph21) {
                if ($regularGross > 0) {
                    // Marginal: pajak atas (reguler + THR) − pajak reguler terpotong.
                    $combined = $this->tax->monthlyTer(
                        $regularGross + $thr, $ptkpStatus, $onDate, $payroll->company_id, $hasNpwp
                    );
                    $thrPph21 = max(0.0, round($combined['amount']) - $regularPph21);
                    $pphInput = [
                        'method'        => 'marginal',
                        'regular_gross' => $regularGross,
                        'regular_pph21' => $regularPph21,
                        'thr_gross'     => round($thr, 2),
                        'combined_gross' => round($regularGross + $thr, 2),
                        'combined_tax'  => round($combined['amount'], 2),
                        'category'      => $combined['category'],
                        'rate'          => $combined['rate'],
                        'ptkp_status'   => $ptkpStatus,
                        'has_npwp'      => $hasNpwp,
                    ];
                } else {
                    // Fallback: run reguler bulan ini belum ada → TER atas THR saja.
                    $only = $this->tax->monthlyTer(
                        $thr, $ptkpStatus, $onDate, $payroll->company_id, $hasNpwp
                    );
                    $thrPph21 = max(0.0, round($only['amount']));
                    $pphInput = [
                        'method'      => 'thr_only',
                        'thr_gross'   => round($thr, 2),
                        'thr_tax'     => round($only['amount'], 2),
                        'category'    => $only['category'],
                        'rate'        => $only['rate'],
                        'ptkp_status' => $ptkpStatus,
                        'has_npwp'    => $hasNpwp,
                    ];
                }
            }

            $net = $thr - $thrPph21;

            // Baris slip: 1 pendapatan THR (kena pajak) + (opsional) 1 potongan PPh21.
            $items = [];
            $items[] = $this->base->item(
                'Tunjangan Hari Raya (THR)',
                PayslipItem::TYPE_EARNING,
                $thr,
                true,   // kena pajak
                false,  // bukan statutori
                'thr',
                0,
                notes: $this->thrNote($monthsWorked),
            );
            if ($thrPph21 > 0) {
                $items[] = $this->base->item(
                    'PPh21 THR',
                    PayslipItem::TYPE_DEDUCTION,
                    $thrPph21,
                    false,
                    true, // statutori
                    'tax',
                    1,
                );
            }

            // Jejak perhitungan (audit).
            $calcSteps = [];
            $calcSteps[] = $this->base->calcStep(
                PayslipCalculationStep::STEP_THR,
                [
                    'monthly_wage'  => round($monthlyWage, 2),
                    'months_worked' => $monthsWorked,
                    'prorated'      => $monthsWorked < 12,
                ],
                $thr,
                'Permenaker 6/2016',
            );
            if ($withholdPph21) {
                $calcSteps[] = $this->base->calcStep(
                    PayslipCalculationStep::STEP_PPH21_THR,
                    $pphInput,
                    $thrPph21,
                    'PMK 168/2023 (TER marginal)',
                );
            }

            // Simpan slip via PayrollCalculator (snapshot identitas + cost center sama).
            $this->base->createPayslip(
                $payroll, $user, $salary,
                0.0,          // basic_salary: slip THR tidak memuat gaji pokok
                $thr,         // gross
                $thr,         // taxable_income
                $thrPph21,    // pph21
                0.0, 0.0,     // BPJS perusahaan & karyawan: nol pada slip THR
                $thrPph21,    // total potongan
                $net,
                $ptkpStatus,
                0, 0, 0.0,    // absent/present days, overtime hours
                $items, $calcSteps
            );

            // Konsolidasi masa pajak: THR = kontribusi TIDAK TERATUR (tanpa clobber reguler).
            $this->base->upsertTaxPeriodTotalThr($payroll, $user, $year, $month, $thr, $thrPph21);

            $totalGross += $thr;
            $totalDeduction += $thrPph21;
            $totalTax += $thrPph21;
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
     * Masa kerja (bulan penuh) dari joined_date hingga akhir periode.
     * Tanpa joined_date → diasumsikan ≥ 12 bulan (THR penuh) agar tidak menahan hak.
     */
    private function monthsOfService(User $user, Carbon $periodEnd): int
    {
        $joined = $user->joined_date;
        if (! $joined) {
            return 12;
        }

        $joinedAt = $joined instanceof Carbon ? $joined : Carbon::parse($joined);
        if ($joinedAt->greaterThan($periodEnd)) {
            return 0;
        }

        return (int) floor($joinedAt->diffInMonths($periodEnd));
    }

    /** Keterangan baris THR (penuh vs pro-rata). */
    private function thrNote(int $monthsWorked): string
    {
        return $monthsWorked >= 12
            ? 'Masa kerja ≥ 12 bulan (1× upah, Permenaker 6/2016)'
            : "Pro-rata masa kerja {$monthsWorked} bulan (Permenaker 6/2016)";
    }
}
