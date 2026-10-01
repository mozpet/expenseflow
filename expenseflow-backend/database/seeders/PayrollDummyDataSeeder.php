<?php

namespace Database\Seeders;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\CurrencyRate;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeBpjsProfile;
use App\Models\EmployeeLoan;
use App\Models\EmployeeSalary;
use App\Models\EmployeeSalaryComponent;
use App\Models\EmployeeTaxProfile;
use App\Models\JobLevel;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\PayrollGroup;
use App\Models\PayrollPaymentBatch;
use App\Models\PayrollPaymentItem;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\SalaryComponent;
use App\Models\SalaryGrade;
use App\Models\SeveranceCase;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Severance\SeveranceCalculatorService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PayrollDummyDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Mengisi dataset dummy komprehensif untuk fitur Payroll (Fase 1 s.d. 6)
     * yang dihubungkan langsung ke 200+ data karyawan PT Maju Bersama.
     */
    public function run(): void
    {
        $company = Company::find(1) ?? Company::first();
        if (! $company) {
            $this->command?->error('❌ Company tidak ditemukan. Jalankan DatabaseSeeder terlebih dahulu.');
            return;
        }

        $this->command?->info("🚀 Memulai seeding data dummy Payroll untuk {$company->name}...");

        // ─── 0. Pastikan Aturan Statutori Terpasang (PTKP, TER, BPJS, PPh 17/26) ─
        $this->call(PayrollStatutorySeeder::class);

        DB::transaction(function () use ($company) {
            // ─── 1. Profil Pajak Perusahaan (NPWP Badan) ───────────────
            $this->seedCompanyTaxProfile($company);

            // ─── 2. Master Kurs Valuta Asing (Fase 6) ──────────────────
            $this->seedCurrencyRates($company);

            // ─── 3. Struktur & Skala Upah (Job Levels & Salary Grades) ─
            $grades = $this->seedSalaryScale($company);

            // ─── 4. Master Grup Payroll & Pemetaan Karyawan ────────────
            $groups = $this->seedPayrollGroups($company);

            // ─── 5. Master Komponen Gaji (Allowances & Deductions) ─────
            $components = $this->seedSalaryComponents($company);

            // ─── 6. Data Kompensasi & Pajak Setiap Karyawan ───────────
            $this->seedEmployeeCompensationAndTax($company, $grades, $groups, $components);

            // ─── 7. Kasbon Aktif & Penyesuaian Retroaktif (Fase 3) ─────
            $this->seedLoansAndAdjustments($company);

            // ─── 8. Berkas Pengakhiran Hubungan Kerja / Pesangon ───────
            $this->seedSeveranceCases($company);

            // ─── 9. Generate Sampel Payroll Runs (Agustus & September) ──
            $this->seedSamplePayrollRuns($company);
        });

        $this->command?->info('✅ Data dummy Payroll berhasil disiapkan secara lengkap dan terintegrasi!');
    }

    /**
     * 1. Perbarui NPWP Perusahaan (Terenkripsi di database).
     */
    private function seedCompanyTaxProfile(Company $company): void
    {
        $company->update([
            'npwp' => '01.234.567.8-012.000',
        ]);
        $this->command?->info('  [1/9] NPWP Perusahaan diset: 01.234.567.8-012.000 (Terenkripsi).');
    }

    /**
     * 2. Master Kurs Valuta Asing (KMK / BI) bertanggal efektif.
     */
    private function seedCurrencyRates(Company $company): void
    {
        $rates = [
            // Kurs per awal tahun 2026
            ['currency' => 'USD', 'rate_to_idr' => 16250.000000, 'effective_date' => '2026-01-01', 'source' => 'KMK No. 01/KM.10/2026'],
            ['currency' => 'SGD', 'rate_to_idr' => 12450.000000, 'effective_date' => '2026-01-01', 'source' => 'KMK No. 01/KM.10/2026'],
            ['currency' => 'EUR', 'rate_to_idr' => 17800.000000, 'effective_date' => '2026-01-01', 'source' => 'KMK No. 01/KM.10/2026'],
            ['currency' => 'JPY', 'rate_to_idr' => 108.500000,   'effective_date' => '2026-01-01', 'source' => 'KMK No. 01/KM.10/2026'],
            // Pembaruan kurs semester 2 (Agustus 2026)
            ['currency' => 'USD', 'rate_to_idr' => 16320.000000, 'effective_date' => '2026-08-01', 'source' => 'KMK No. 34/KM.10/2026'],
            ['currency' => 'SGD', 'rate_to_idr' => 12510.000000, 'effective_date' => '2026-08-01', 'source' => 'KMK No. 34/KM.10/2026'],
        ];

        foreach ($rates as $r) {
            CurrencyRate::updateOrCreate(
                [
                    'company_id'     => $company->id,
                    'currency'       => $r['currency'],
                    'effective_date' => $r['effective_date'],
                ],
                [
                    'rate_to_idr' => $r['rate_to_idr'],
                    'source'      => $r['source'],
                ]
            );
        }

        $this->command?->info('  [2/9] Master Kurs Valas (USD, SGD, EUR, JPY) terdaftar.');
    }

    /**
     * 3. Struktur & Skala Upah (Permenaker 1/2017) — Job Levels & Salary Grades.
     *
     * @return array<string, SalaryGrade>
     */
    private function seedSalaryScale(Company $company): array
    {
        $levelsData = [
            ['rank' => 1, 'name' => 'Staff / Pelaksana',            'code' => 'LVL-STF',  'desc' => 'Pelaksana operasional dan staf lapangan'],
            ['rank' => 2, 'name' => 'Senior Staff / Specialist',    'code' => 'LVL-SNR',  'desc' => 'Spesialis teknis, analis senior, dan tenaga ahli'],
            ['rank' => 3, 'name' => 'Team Lead / Koordinator',      'code' => 'LVL-LEAD', 'desc' => 'Koordinator tim kerja dan pelaksana lapangan'],
            ['rank' => 4, 'name' => 'Supervisor',                  'code' => 'LVL-SPV',  'desc' => 'Supervisi operasional harian dan lini pertama'],
            ['rank' => 5, 'name' => 'Manager / Kepala Cabang',      'code' => 'LVL-MGR',  'desc' => 'Pimpinan tertinggi operasional cabang atau fungsi'],
            ['rank' => 6, 'name' => 'Direksi / Top Executive',      'code' => 'LVL-DIR',  'desc' => 'Jajaran pimpinan eksekutif korporat'],
        ];

        $levels = [];
        foreach ($levelsData as $ld) {
            $levels[$ld['code']] = JobLevel::updateOrCreate(
                ['company_id' => $company->id, 'code' => $ld['code']],
                [
                    'name'        => $ld['name'],
                    'rank'        => $ld['rank'],
                    'description' => $ld['desc'],
                    'is_active'   => true,
                ]
            );
        }

        $gradesData = [
            'G-JUN' => [
                'level' => 'LVL-STF', 'name' => 'Golongan Junior Staff / Entry',
                'min' => 5_200_000, 'mid' => 6_000_000, 'max' => 7_000_000, 'curr' => 'IDR',
                'desc' => 'Staf kontrak pemula, CS, Driver, dan pelaksana entry level',
            ],
            'G-MID' => [
                'level' => 'LVL-STF', 'name' => 'Golongan Staff Pelaksana',
                'min' => 6_500_000, 'mid' => 8_000_000, 'max' => 9_500_000, 'curr' => 'IDR',
                'desc' => 'Staf reguler tetap operasional, sales, dan finance',
            ],
            'G-SNR' => [
                'level' => 'LVL-SNR', 'name' => 'Golongan Senior Staff & Ahli',
                'min' => 9_000_000, 'mid' => 11_500_000, 'max' => 14_000_000, 'curr' => 'IDR',
                'desc' => 'Senior staf operasional, akuntan senior, dan engineer',
            ],
            'G-LEAD' => [
                'level' => 'LVL-LEAD', 'name' => 'Golongan Koordinator & Lead',
                'min' => 12_000_000, 'mid' => 15_000_000, 'max' => 18_000_000, 'curr' => 'IDR',
                'desc' => 'Koordinator lapangan dan pimpinan regu kerja',
            ],
            'G-SPV' => [
                'level' => 'LVL-SPV', 'name' => 'Golongan Supervisor',
                'min' => 15_000_000, 'mid' => 19_000_000, 'max' => 23_000_000, 'curr' => 'IDR',
                'desc' => 'Supervisor operasional, finance, sales, HR, dan IT cabang',
            ],
            'G-MGR' => [
                'level' => 'LVL-MGR', 'name' => 'Golongan Kepala Cabang & Manager',
                'min' => 20_000_000, 'mid' => 26_000_000, 'max' => 32_000_000, 'curr' => 'IDR',
                'desc' => 'Pimpinan cabang utama dan kepala divisi fungsional',
            ],
            'G-DIR' => [
                'level' => 'LVL-DIR', 'name' => 'Golongan Direksi / Eksekutif',
                'min' => 35_000_000, 'mid' => 50_000_000, 'max' => 70_000_000, 'curr' => 'IDR',
                'desc' => 'Dewan direksi dan top management perusahaan',
            ],
            'G-EXP' => [
                'level' => 'LVL-SNR', 'name' => 'Golongan Expatriate Advisor (USD)',
                'min' => 4_000.00, 'mid' => 5_500.00, 'max' => 7_500.00, 'curr' => 'USD',
                'desc' => 'Tenaga ahli asing & konsultan teknis (denominasi valas)',
            ],
        ];

        $grades = [];
        foreach ($gradesData as $code => $gd) {
            $grades[$code] = SalaryGrade::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'job_level_id' => $levels[$gd['level']]->id,
                    'name'         => $gd['name'],
                    'min_salary'   => $gd['min'],
                    'mid_salary'   => $gd['mid'],
                    'max_salary'   => $gd['max'],
                    'currency'     => $gd['curr'],
                    'description'  => $gd['desc'],
                    'is_active'    => true,
                ]
            );
        }

        $this->command?->info('  [3/9] Struktur & Skala Upah (6 Jenjang, 8 Golongan) siap.');
        return $grades;
    }

    /**
     * 4. Grup Payroll (Grup Kantor Pusat & 3 Cabang Lapangan).
     *
     * @return array<string, PayrollGroup>
     */
    private function seedPayrollGroups(Company $company): array
    {
        $groupsData = [
            'GRP-HO'  => ['name' => 'Kantor Pusat Jakarta', 'desc' => 'Seluruh staf dan pimpinan HO Jakarta'],
            'GRP-LPG' => ['name' => 'Operasional Lapangan Bekasi', 'desc' => 'Staf cabang dan operasional pergudangan Bekasi'],
            'GRP-SMG' => ['name' => 'Cabang Semarang', 'desc' => 'Staf kantor dan sales cabang Semarang'],
            'GRP-SBY' => ['name' => 'Cabang Surabaya', 'desc' => 'Staf operasional dan layanan cabang Surabaya'],
        ];

        $groups = [];
        foreach ($groupsData as $code => $d) {
            $groups[$code] = PayrollGroup::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name'        => $d['name'],
                    'description' => $d['desc'],
                    'is_active'   => true,
                ]
            );
        }

        // Petakan users ke masing-masing payroll_group sesuai kantor cabang (attendance_setting_id)
        User::where('company_id', $company->id)->where('attendance_setting_id', 1)->update(['payroll_group_id' => $groups['GRP-HO']->id]);
        User::where('company_id', $company->id)->where('attendance_setting_id', 4)->update(['payroll_group_id' => $groups['GRP-LPG']->id]);
        User::where('company_id', $company->id)->where('attendance_setting_id', 6)->update(['payroll_group_id' => $groups['GRP-SMG']->id]);
        User::where('company_id', $company->id)->where('attendance_setting_id', 11)->update(['payroll_group_id' => $groups['GRP-SBY']->id]);

        $this->command?->info('  [4/9] Grup Payroll dibuat dan 200 karyawan dipetakan ke grup masing-masing.');
        return $groups;
    }

    /**
     * 5. Master Komponen Gaji (Salary Components).
     *
     * @return array<string, SalaryComponent>
     */
    private function seedSalaryComponents(Company $company): array
    {
        $componentsData = [
            'TJ_JBT' => [
                'name' => 'Tunjangan Jabatan', 'type' => SalaryComponent::TYPE_EARNING,
                'calc_type' => SalaryComponent::CALC_FIXED, 'formula_dsl' => null,
                'category' => 'allowance', 'is_taxable' => true, 'sort_order' => 10,
            ],
            'TJ_TRP' => [
                'name' => 'Tunjangan Transportasi', 'type' => SalaryComponent::TYPE_EARNING,
                'calc_type' => SalaryComponent::CALC_FIXED, 'formula_dsl' => null,
                'category' => 'allowance', 'is_taxable' => true, 'sort_order' => 20,
            ],
            'TJ_MKN' => [
                'name' => 'Tunjangan Uang Makan', 'type' => SalaryComponent::TYPE_EARNING,
                'calc_type' => SalaryComponent::CALC_FIXED, 'formula_dsl' => null,
                'category' => 'allowance', 'is_taxable' => true, 'sort_order' => 30,
            ],
            'TJ_KOM' => [
                'name' => 'Tunjangan Komunikasi & Pulsa', 'type' => SalaryComponent::TYPE_EARNING,
                'calc_type' => SalaryComponent::CALC_FIXED, 'formula_dsl' => null,
                'category' => 'allowance', 'is_taxable' => true, 'sort_order' => 40,
            ],
            'BNS_KIN' => [
                'name' => 'Bonus Prestasi & Insentif Kinerja', 'type' => SalaryComponent::TYPE_EARNING,
                'calc_type' => SalaryComponent::CALC_MANUAL, 'formula_dsl' => null,
                'category' => 'bonus', 'is_taxable' => true, 'sort_order' => 50,
            ],
            'POT_KOP' => [
                'name' => 'Simpanan Wajib Koperasi Karyawan', 'type' => SalaryComponent::TYPE_DEDUCTION,
                'calc_type' => SalaryComponent::CALC_FIXED, 'formula_dsl' => null,
                'category' => 'deduction', 'is_taxable' => false, 'sort_order' => 60,
            ],
            'TJ_PRSN' => [
                'name' => 'Tunjangan Kehadiran Penuh', 'type' => SalaryComponent::TYPE_EARNING,
                'calc_type' => SalaryComponent::CALC_FORMULA, 'formula_dsl' => 'attendance_present * 25000',
                'category' => 'allowance', 'is_taxable' => true, 'sort_order' => 15,
            ],
        ];

        $components = [];
        foreach ($componentsData as $code => $c) {
            $components[$code] = SalaryComponent::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name'        => $c['name'],
                    'type'        => $c['type'],
                    'calc_type'   => $c['calc_type'],
                    'formula_dsl' => $c['formula_dsl'],
                    'category'    => $c['category'],
                    'is_taxable'  => $c['is_taxable'],
                    'sort_order'  => $c['sort_order'],
                    'is_active'   => true,
                ]
            );
        }

        $this->command?->info('  [5/9] Master Komponen Gaji (Tunjangan, Potongan, & Formula) siap.');
        return $components;
    }

    /**
     * 6. Tetapkan Gaji Pokok, Profil Pajak, BPJS, Rekening Bank, dan Tunjangan Karyawan.
     *
     * @param array<string, SalaryGrade> $grades
     * @param array<string, PayrollGroup> $groups
     * @param array<string, SalaryComponent> $components
     */
    private function seedEmployeeCompensationAndTax(Company $company, array $grades, array $groups, array $components): void
    {
        $users = User::where('company_id', $company->id)->with('position')->orderBy('id')->get();
        $adminUser = User::where('company_id', $company->id)->where('role', 'admin')->first() ?: $users->first();
        $hrdUser   = User::where('company_id', $company->id)->where('role', 'hrd')->first() ?: $users->first();

        // 2 Karyawan Tenaga Ahli Asing (Ekspatriat) untuk mendemonstrasikan Fase 6 PPh 26 & Multi-Currency USD
        $expatUser1 = $users->firstWhere('name', 'Jessica Tanujaya') ?: $users->get(18);
        $expatUser2 = $users->firstWhere('name', 'Xaverius Sigit') ?: $users->get(32);
        $expatIds = array_filter([$expatUser1?->id, $expatUser2?->id]);

        $counter = 0;
        foreach ($users as $user) {
            $counter++;
            $posName = $user->position?->name ?? '';
            $isSupervisor = $user->position?->is_supervisor ?? false;
            $empType = strtoupper((string) ($user->employment_type ?? 'PKWTT'));
            $isExpat = in_array($user->id, $expatIds, true);

            // A. Tentukan Grade & Gaji Pokok
            if ($isExpat) {
                $grade = $grades['G-EXP'];
                $currency = 'USD';
                $basicSalary = $user->id === $expatUser1?->id ? 4800.00 : 5400.00;
            } elseif (str_contains($posName, 'Branch Manager') || $user->role === 'kepala_cabang') {
                $grade = $grades['G-MGR'];
                $currency = 'IDR';
                $basicSalary = 24_000_000.00;
            } elseif ($user->role === 'super_admin' || $user->role === 'admin') {
                $grade = $grades['G-DIR'];
                $currency = 'IDR';
                $basicSalary = 42_000_000.00;
            } elseif ($isSupervisor || str_contains($posName, 'Supervisor')) {
                $grade = $grades['G-SPV'];
                $currency = 'IDR';
                $basicSalary = 18_500_000.00;
            } elseif (str_contains($posName, 'Lead') || str_contains($posName, 'Koordinator')) {
                $grade = $grades['G-LEAD'];
                $currency = 'IDR';
                $basicSalary = 14_500_000.00;
            } elseif (str_contains($posName, 'Senior') || str_contains($posName, 'Accountant')) {
                $grade = $grades['G-SNR'];
                $currency = 'IDR';
                $basicSalary = 11_000_000.00;
            } elseif ($empType === 'PKWTT') {
                $grade = $grades['G-MID'];
                $currency = 'IDR';
                $basicSalary = 7_800_000.00;
            } else {
                // PKWT, Driver, CS, Gudang
                $grade = $grades['G-JUN'];
                $currency = 'IDR';
                $basicSalary = 5_800_000.00;
            }

            // Pastikan basic_salary mematuhi rentang min-max grade
            $basicSalary = max((float) $grade->min_salary, min((float) $grade->max_salary, $basicSalary));

            $effectiveDate = $user->joined_date ? Carbon::parse($user->joined_date)->toDateString() : '2024-01-01';

            // Simpan / update EmployeeSalary
            EmployeeSalary::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'user_id'    => $user->id,
                ],
                [
                    'salary_grade_id' => $grade->id,
                    'job_level_id'    => $grade->job_level_id,
                    'basic_salary'    => $basicSalary,
                    'currency'        => $currency,
                    'effective_date'  => $effectiveDate,
                    'end_date'        => null,
                    'is_active'       => true,
                    'notes'           => $isExpat ? 'Kontrak ekspatriat spesialis valas' : 'Penetapan struktur & skala upah reguler',
                    'created_by'      => $adminUser->id,
                ]
            );

            // B. Profil Pajak Karyawan (EmployeeTaxProfile)
            if ($isExpat) {
                $isSingapore = ($user->id === $expatUser1?->id);
                EmployeeTaxProfile::updateOrCreate(
                    ['company_id' => $company->id, 'user_id' => $user->id],
                    [
                        'npwp'             => null,
                        'has_npwp'         => false,
                        'ptkp_status'      => 'TK/0',
                        'tax_method'       => 'gross',
                        'tax_subject_type' => EmployeeTaxProfile::SUBJECT_FOREIGN,
                        'treaty_country'   => $isSingapore ? 'Singapore' : 'Japan',
                        'treaty_rate'      => 0.1000,
                        'foreign_tax_id'   => $isSingapore ? 'S-G8765432A' : 'JP-0987654321',
                    ]
                );
            } else {
                // Karyawan Domestik (PPh 21 TER / Pasal 17)
                $marital = strtolower((string) ($user->marital_status ?? 'single'));
                $dependents = min(3, max(0, (int) ($user->number_of_dependents ?? 0)));
                $ptkpStatus = ($marital === 'married' ? 'K/' : 'TK/') . $dependents;

                // 90% memiliki NPWP, 10% belum punya
                $hasNpwp = ($counter % 10 !== 0);
                $cleanDigits = '31' . str_pad((string) ($user->attendance_setting_id ?? 1), 2, '0', STR_PAD_LEFT)
                    . str_pad((string) $user->id, 8, '0', STR_PAD_LEFT) . '000';
                $formattedNpwp = substr($cleanDigits, 0, 2) . '.' . substr($cleanDigits, 2, 3) . '.'
                    . substr($cleanDigits, 5, 3) . '.' . substr($cleanDigits, 8, 1) . '-'
                    . substr($cleanDigits, 9, 3) . '.' . substr($cleanDigits, 12, 3);

                // Manager & Supervisor menggunakan skema Gross-Up (tunjangan pajak ditanggung kantor)
                $isGrossUp = ($grade->code === 'G-MGR' || $grade->code === 'G-SPV' || $grade->code === 'G-DIR');

                EmployeeTaxProfile::updateOrCreate(
                    ['company_id' => $company->id, 'user_id' => $user->id],
                    [
                        'npwp'             => $hasNpwp ? $formattedNpwp : null,
                        'has_npwp'         => $hasNpwp,
                        'ptkp_status'      => $ptkpStatus,
                        'tax_method'       => $isGrossUp ? 'gross_up' : 'gross',
                        'tax_subject_type' => EmployeeTaxProfile::SUBJECT_DOMESTIC,
                        'treaty_country'   => null,
                        'treaty_rate'      => null,
                        'foreign_tax_id'   => null,
                    ]
                );
            }

            // C. Profil BPJS Karyawan (EmployeeBpjsProfile)
            $isFieldWorker = str_contains($posName, 'Driver') || str_contains($posName, 'Gudang') || str_contains($posName, 'Logistics');
            $jkkRiskClass = $isFieldWorker ? 2 : 1; // 2 = 0.54% risiko sedang, 1 = 0.24% risiko kantor

            $bpjsKesNo = '0001' . str_pad((string) ($user->attendance_setting_id ?? 1), 2, '0', STR_PAD_LEFT) . str_pad((string) $user->id, 7, '0', STR_PAD_LEFT);
            $bpjsTkNo  = '2101' . str_pad((string) ($user->attendance_setting_id ?? 1), 2, '0', STR_PAD_LEFT) . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT);

            EmployeeBpjsProfile::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $user->id],
                [
                    'bpjs_kes_no'    => $bpjsKesNo,
                    'bpjs_tk_no'     => $bpjsTkNo,
                    'has_bpjs_kes'   => true,
                    'has_bpjs_tk'    => true,
                    'has_jkp'        => ($empType === 'PKWTT' || $empType === 'PKWT'),
                    'jkk_risk_class' => $jkkRiskClass,
                ]
            );

            // D. Rekening Bank Karyawan (EmployeeBankAccount — Maker-Checker Verifikasi)
            $banks = ['BCA', 'Mandiri', 'BNI', 'BRI'];
            $bankName = $user->bank_name ?: $banks[$counter % 4];
            $swiftMap = [
                'BCA'     => 'CENAIDJA',
                'Mandiri' => 'BMRIIDJA',
                'BNI'     => 'BBNIIDJA',
                'BRI'     => 'BRINIDJA',
            ];
            $accountNo = '7890' . str_pad((string) ($user->attendance_setting_id ?? 1), 2, '0', STR_PAD_LEFT) . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);

            EmployeeBankAccount::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'user_id'    => $user->id,
                    'is_primary' => true,
                ],
                [
                    'bank_name'           => $bankName,
                    'bank_account_no'     => $accountNo,
                    'bank_account_holder' => $user->name,
                    'bank_branch'         => 'Kantor Cabang Utama ' . ($user->ktp_city ?? 'Jakarta'),
                    'swift_code'          => $swiftMap[$bankName] ?? 'CENAIDJA',
                    'status'              => EmployeeBankAccount::STATUS_ACTIVE,
                    'requested_by'        => $hrdUser->id,
                    'verified_by'         => $adminUser->id,
                    'verified_at'         => now()->subMonths(6),
                    'notes'               => 'Rekening payroll terverifikasi resmi',
                ]
            );

            // E. Komponen Gaji Tetap (EmployeeSalaryComponent — Tunjangan & Potongan Rutin)
            // Bersihkan data lama jika re-seed
            EmployeeSalaryComponent::where('company_id', $company->id)->where('user_id', $user->id)->delete();

            $userComponents = [];
            if ($grade->code === 'G-MGR' || $grade->code === 'G-DIR') {
                $userComponents[] = ['comp' => $components['TJ_JBT'], 'amount' => 3_500_000];
                $userComponents[] = ['comp' => $components['TJ_TRP'], 'amount' => 1_500_000];
                $userComponents[] = ['comp' => $components['TJ_KOM'], 'amount' => 500_000];
            } elseif ($grade->code === 'G-SPV') {
                $userComponents[] = ['comp' => $components['TJ_JBT'], 'amount' => 2_000_000];
                $userComponents[] = ['comp' => $components['TJ_TRP'], 'amount' => 1_000_000];
                $userComponents[] = ['comp' => $components['TJ_KOM'], 'amount' => 350_000];
            } elseif ($grade->code === 'G-LEAD') {
                $userComponents[] = ['comp' => $components['TJ_TRP'], 'amount' => 800_000];
                $userComponents[] = ['comp' => $components['TJ_MKN'], 'amount' => 600_000];
                $userComponents[] = ['comp' => $components['TJ_KOM'], 'amount' => 250_000];
            } elseif ($grade->code === 'G-SNR') {
                $userComponents[] = ['comp' => $components['TJ_TRP'], 'amount' => 650_000];
                $userComponents[] = ['comp' => $components['TJ_MKN'], 'amount' => 500_000];
                $userComponents[] = ['comp' => $components['TJ_KOM'], 'amount' => 200_000];
            } else {
                // G-MID & G-JUN
                $userComponents[] = ['comp' => $components['TJ_TRP'], 'amount' => 500_000];
                $userComponents[] = ['comp' => $components['TJ_MKN'], 'amount' => 450_000];
            }

            // Simpanan Koperasi untuk sebagian karyawan
            if ($counter % 3 === 0) {
                $userComponents[] = ['comp' => $components['POT_KOP'], 'amount' => 150_000];
            }

            foreach ($userComponents as $uc) {
                EmployeeSalaryComponent::create([
                    'company_id'          => $company->id,
                    'user_id'             => $user->id,
                    'salary_component_id' => $uc['comp']->id,
                    'amount'              => $uc['amount'],
                    'effective_date'      => '2024-01-01',
                    'end_date'            => null,
                    'is_active'           => true,
                ]);
            }
        }

        $this->command?->info("  [6/9] Kompensasi, Pajak (termasuk 2 Expat PPh 26 USD), BPJS, & Rekening {$counter} karyawan terisi lengkap.");
    }

    /**
     * 7. Kasbon Karyawan & Penyesuaian Gaji Retroaktif (Fase 3).
     */
    private function seedLoansAndAdjustments(Company $company): void
    {
        $admin = User::where('company_id', $company->id)->where('role', 'admin')->first() ?: User::where('company_id', $company->id)->first();
        $staff1 = User::where('company_id', $company->id)->where('name', 'Budi Wicaksono')->first();
        $staff2 = User::where('company_id', $company->id)->where('name', 'Siti Rahayu')->first();
        $staff3 = User::where('company_id', $company->id)->where('email', 'like', '%SMG%')->first();

        // 3 Pinjaman Karyawan (EmployeeLoan)
        if ($staff1) {
            EmployeeLoan::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $staff1->id, 'title' => 'Pinjaman Pembelian Laptop Kerja'],
                [
                    'principal'          => 12_000_000,
                    'installment_amount' => 1_000_000,
                    'tenor_months'       => 12,
                    'installments_paid'  => 4,
                    'remaining_amount'   => 8_000_000,
                    'start_period_month' => 5,
                    'start_period_year'  => 2026,
                    'status'             => EmployeeLoan::STATUS_ACTIVE,
                    'approved_by'        => $admin->id,
                    'approved_at'        => Carbon::create(2026, 5, 1, 9, 0),
                    'notes'              => 'Cicilan kasbon perangkat penunjang kantor',
                ]
            );
        }

        if ($staff2) {
            EmployeeLoan::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $staff2->id, 'title' => 'Pinjaman Darurat Medis Keluarga'],
                [
                    'principal'          => 5_000_000,
                    'installment_amount' => 500_000,
                    'tenor_months'       => 10,
                    'installments_paid'  => 3,
                    'remaining_amount'   => 3_500_000,
                    'start_period_month' => 6,
                    'start_period_year'  => 2026,
                    'status'             => EmployeeLoan::STATUS_ACTIVE,
                    'approved_by'        => $admin->id,
                    'approved_at'        => Carbon::create(2026, 6, 1, 10, 0),
                    'notes'              => 'Bantuan biaya persalinan darurat',
                ]
            );
        }

        if ($staff3) {
            EmployeeLoan::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $staff3->id, 'title' => 'Pinjaman Renovasi Tempat Tinggal'],
                [
                    'principal'          => 6_000_000,
                    'installment_amount' => 600_000,
                    'tenor_months'       => 10,
                    'installments_paid'  => 2,
                    'remaining_amount'   => 4_800_000,
                    'start_period_month' => 7,
                    'start_period_year'  => 2026,
                    'status'             => EmployeeLoan::STATUS_ACTIVE,
                    'approved_by'        => $admin->id,
                    'approved_at'        => Carbon::create(2026, 7, 1, 11, 0),
                    'notes'              => 'Perbaikan atap rumah dinas cabang',
                ]
            );
        }

        // Penyesuaian Gaji Retroaktif (PayrollAdjustment) yang siap diaplikasikan
        if ($staff1) {
            PayrollAdjustment::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $staff1->id, 'name' => 'Koreksi Keterlambatan Dinas Luar Kota'],
                [
                    'payroll_id'             => null,
                    'retroactive_payroll_id' => null,
                    'type'                   => PayrollAdjustment::TYPE_EARNING,
                    'amount'                 => 450_000,
                    'is_taxable'             => true,
                    'reason'                 => 'Kompensasi dinas luar cabang melebihi jam kerja normal',
                    'status'                 => PayrollAdjustment::STATUS_APPROVED,
                    'created_by'             => $admin->id,
                    'approved_by'            => $admin->id,
                    'approved_at'            => Carbon::create(2026, 9, 20, 14, 0),
                ]
            );
        }

        if ($staff2) {
            PayrollAdjustment::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $staff2->id, 'name' => 'Insentif Tambahan Target Penjualan Q3'],
                [
                    'payroll_id'             => null,
                    'retroactive_payroll_id' => null,
                    'type'                   => PayrollAdjustment::TYPE_EARNING,
                    'amount'                 => 1_250_000,
                    'is_taxable'             => true,
                    'reason'                 => 'Pencapaian KPI kuartal 3 melebihi target 120%',
                    'status'                 => PayrollAdjustment::STATUS_APPROVED,
                    'created_by'             => $admin->id,
                    'approved_by'            => $admin->id,
                    'approved_at'            => Carbon::create(2026, 9, 21, 10, 30),
                ]
            );
        }

        $this->command?->info('  [7/9] Kasbon aktif dan penyesuaian gaji retroaktif berhasil dibuat.');
    }

    /**
     * 8. Berkas Pengakhiran Hubungan Kerja (Exit Settlement / Severance Cases — PP 35/2021).
     */
    private function seedSeveranceCases(Company $company): void
    {
        $admin = User::where('company_id', $company->id)->where('role', 'admin')->first() ?: User::where('company_id', $company->id)->first();

        // Cari 3 karyawan perwakilan dari cabang Bekasi (LPG=4), Semarang (SMG=6), dan Surabaya (SBY=11)
        $userPhk    = User::where('company_id', $company->id)->where('attendance_setting_id', 4)->where('employment_type', 'PKWTT')->first();
        $userPkwt   = User::where('company_id', $company->id)->where('attendance_setting_id', 6)->where('employment_type', 'PKWT')->first();
        $userResign = User::where('company_id', $company->id)->where('attendance_setting_id', 11)->first();

        if ($userPhk) {
            SeveranceCase::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $userPhk->id],
                [
                    'payroll_id'                => null,
                    'termination_type'          => SeveranceCase::TYPE_PHK,
                    'termination_reason'        => 'Efisiensi operasional karena penyesuaian struktur cabang (PP 35/2021 Pasal 43)',
                    'termination_date'          => '2026-09-15',
                    'last_working_date'         => '2026-09-15',
                    'employment_type'           => 'pkwtt',
                    'up_multiplier'             => 0.50,
                    'upmk_multiplier'           => 1.00,
                    'include_uph'               => true,
                    'annual_leave_balance_days' => 5.0,
                    'relocation_cost'           => 1_500_000,
                    'other_compensation'        => 0,
                    'separation_pay'            => 0,
                    'asset_deduction'           => 250_000,
                    'settle_loans'              => true,
                    'status'                    => SeveranceCase::STATUS_DRAFT,
                    'notes'                     => 'Karyawan telah menyerahkan seluruh inventaris laptop & kartu akses',
                    'created_by'                => $admin->id,
                ]
            );
        }

        if ($userPkwt) {
            SeveranceCase::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $userPkwt->id],
                [
                    'payroll_id'                => null,
                    'termination_type'          => SeveranceCase::TYPE_PKWT_END,
                    'termination_reason'        => 'Berakhirnya jangka waktu perjanjian kerja waktu tertentu (Pasal 15-17)',
                    'termination_date'          => '2026-09-25',
                    'last_working_date'         => '2026-09-25',
                    'employment_type'           => 'pkwt',
                    'contract_start_date'       => '2025-09-26',
                    'contract_end_date'         => '2026-09-25',
                    'up_multiplier'             => 1.00,
                    'upmk_multiplier'           => 1.00,
                    'include_uph'               => false,
                    'annual_leave_balance_days' => 2.0,
                    'relocation_cost'           => 0,
                    'other_compensation'        => 0,
                    'separation_pay'            => 0,
                    'asset_deduction'           => 0,
                    'settle_loans'              => false,
                    'status'                    => SeveranceCase::STATUS_DRAFT,
                    'notes'                     => 'Masa kerja genap 12 bulan, berhak kompensasi 1 bulan upah pokok',
                    'created_by'                => $admin->id,
                ]
            );
        }

        if ($userResign) {
            SeveranceCase::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $userResign->id],
                [
                    'payroll_id'                => null,
                    'termination_type'          => SeveranceCase::TYPE_RESIGN,
                    'termination_reason'        => 'Mengundurkan diri atas kemauan sendiri dengan one-month notice',
                    'termination_date'          => '2026-09-30',
                    'last_working_date'         => '2026-09-30',
                    'employment_type'           => 'pkwtt',
                    'up_multiplier'             => 0.00,
                    'upmk_multiplier'           => 0.00,
                    'include_uph'               => true,
                    'annual_leave_balance_days' => 4.0,
                    'relocation_cost'           => 0,
                    'other_compensation'        => 0,
                    'separation_pay'            => 4_500_000,
                    'asset_deduction'           => 0,
                    'settle_loans'              => true,
                    'status'                    => SeveranceCase::STATUS_DRAFT,
                    'notes'                     => 'Uang pisah sesuai kesepakatan Peraturan Perusahaan (PP)',
                    'created_by'                => $admin->id,
                ]
            );
        }

        $this->command?->info('  [8/9] 3 Kasus Exit Settlement (PHK Efisiensi, PKWT End, & Resign) dibuat.');
    }

    /**
     * 9. Buat & Kalkulasi Sampel Payroll Runs (Agustus & September 2026).
     */
    private function seedSamplePayrollRuns(Company $company): void
    {
        $admin = User::where('company_id', $company->id)->where('role', 'admin')->first() ?: User::where('company_id', $company->id)->first();
        $finance = User::where('company_id', $company->id)->where('role', 'finance')->first() ?: $admin;
        $calculator = app(PayrollCalculator::class);
        $severanceCalc = app(SeveranceCalculatorService::class);

        // Idempoten: bersihkan batch disbursement dan payment item lama bila seeder dijalankan ulang
        $existingPayrollIds = Payroll::where('company_id', $company->id)->pluck('id')->all();
        if (! empty($existingPayrollIds)) {
            PayrollPaymentItem::whereHas('batch', fn ($q) => $q->whereIn('payroll_id', $existingPayrollIds))->delete();
            PayrollPaymentBatch::whereIn('payroll_id', $existingPayrollIds)->delete();
        }

        // ─────────────────────────────────────────────────────────────
        // Batch 1: Agustus 2026 — Batch Reguler SUDAH DIBAYAR (Paid)
        // ─────────────────────────────────────────────────────────────
        $payrollAug = Payroll::updateOrCreate(
            [
                'company_id'   => $company->id,
                'period_month' => 8,
                'period_year'  => 2026,
                'run_type'     => Payroll::RUN_TYPE_REGULAR,
            ],
            [
                'period_label'          => 'Agustus 2026',
                'attendance_setting_id' => null, // Seluruh perusahaan
                'payroll_group_id'      => null,
                'is_year_end'           => false,
                'status'                => Payroll::STATUS_DRAFT,
                'prepared_by'           => $admin->id,
                'notes'                 => 'Penggajian bulanan reguler seluruh cabang periode Agustus 2026',
            ]
        );

        // Jalankan perhitungan otomatis via PayrollCalculator
        $calculator->calculate($payrollAug);

        // Ubah status menjadi APPROVED & PAID untuk batch Agustus 2026
        $augCalcAt = Carbon::create(2026, 8, 25, 10, 30);
        $augSubAt  = Carbon::create(2026, 8, 25, 14, 0);
        $augAppAt  = Carbon::create(2026, 8, 26, 9, 15);
        $augPaidAt = Carbon::create(2026, 8, 27, 11, 45);

        $payrollAug->update([
            'status'        => Payroll::STATUS_PAID,
            'calculated_at' => $augCalcAt,
            'submitted_by'  => $admin->id,
            'submitted_at'  => $augSubAt,
            'approved_by'   => $admin->id,
            'approved_at'   => $augAppAt,
            'paid_by'       => $finance->id,
            'paid_at'       => $augPaidAt,
        ]);

        // Rekam Batch Pembayaran Bank (Disbursement via BCA KlikBisnis)
        $paymentBatch = PayrollPaymentBatch::updateOrCreate(
            [
                'company_id' => $company->id,
                'payroll_id' => $payrollAug->id,
            ],
            [
                'batch_reference' => 'DISB-20260827-BCA01',
                'bank_format'     => PayrollPaymentBatch::BANK_BCA,
                'total_records'   => $payrollAug->employee_count,
                'total_amount'    => $payrollAug->total_net,
                'status'          => PayrollPaymentBatch::STATUS_SETTLED,
                'generated_by'    => $finance->id,
                'reconciled_by'   => $finance->id,
                'reconciled_at'   => $augPaidAt,
            ]
        );

        // Salin rincian item pembayaran per payslip
        PayrollPaymentItem::where('payment_batch_id', $paymentBatch->id)->delete();
        $augPayslips = $payrollAug->payslips()->with('user.bankAccounts')->get();
        foreach ($augPayslips as $ps) {
            $bankAcc = $ps->user->bankAccounts->firstWhere('is_primary', true);
            PayrollPaymentItem::create([
                'payment_batch_id'       => $paymentBatch->id,
                'payslip_id'             => $ps->id,
                'user_id'                => $ps->user_id,
                'bank_name'              => $bankAcc?->bank_name ?? 'BCA',
                'bank_account_no_masked' => $bankAcc?->maskedAccountNo() ?? '••••••••1234',
                'bank_account_holder'    => $ps->employee_name,
                'amount'                 => $ps->net,
                'status'                 => PayrollPaymentItem::STATUS_SUCCESS,
                'bank_reference_no'      => 'BCA-TRX-' . $ps->id . '-' . rand(100000, 999999),
                'failure_reason'         => null,
                'settled_at'             => $augPaidAt,
            ]);
        }

        // ─────────────────────────────────────────────────────────────
        // Batch 2: September 2026 — Batch Reguler SIAP REVIEW (Calculated)
        // ─────────────────────────────────────────────────────────────
        $payrollSep = Payroll::updateOrCreate(
            [
                'company_id'   => $company->id,
                'period_month' => 9,
                'period_year'  => 2026,
                'run_type'     => Payroll::RUN_TYPE_REGULAR,
            ],
            [
                'period_label'          => 'September 2026',
                'attendance_setting_id' => null,
                'payroll_group_id'      => null,
                'is_year_end'           => false,
                'status'                => Payroll::STATUS_DRAFT,
                'prepared_by'           => $admin->id,
                'notes'                 => 'Penggajian bulanan reguler periode September 2026 (status Calculated / Siap Approval)',
            ]
        );

        $calculator->calculate($payrollSep);

        // ─────────────────────────────────────────────────────────────
        // Batch 3: September 2026 — Batch Pesangon / Exit Settlement
        // ─────────────────────────────────────────────────────────────
        $severancePayroll = Payroll::updateOrCreate(
            [
                'company_id'   => $company->id,
                'period_month' => 9,
                'period_year'  => 2026,
                'run_type'     => Payroll::RUN_TYPE_SEVERANCE,
            ],
            [
                'period_label'          => 'Exit Settlement September 2026',
                'attendance_setting_id' => null,
                'payroll_group_id'      => null,
                'is_year_end'           => false,
                'status'                => Payroll::STATUS_DRAFT,
                'prepared_by'           => $admin->id,
                'notes'                 => 'Batch khusus penyelesaian pembayaran pesangon & kompensasi PKWT September 2026',
            ]
        );

        // Kaitkan kasus pesangon ke batch ini
        SeveranceCase::where('company_id', $company->id)
            ->whereNull('payroll_id')
            ->update(['payroll_id' => $severancePayroll->id]);

        // Kalkulasi slip pesangon via SeveranceCalculatorService
        $severanceCalc->calculate($severancePayroll);

        $this->command?->info("  [9/9] Sampel Payroll Runs berhasil dihitung:");
        $this->command?->info("       - Batch Agustus 2026 (Regular): {$payrollAug->employee_count} Karyawan, Rp " . number_format($payrollAug->total_net, 0, ',', '.') . " (Status: PAID)");
        $this->command?->info("       - Batch September 2026 (Regular): {$payrollSep->employee_count} Karyawan, Rp " . number_format($payrollSep->total_net, 0, ',', '.') . " (Status: CALCULATED)");
        $this->command?->info("       - Batch September 2026 (Severance): {$severancePayroll->employee_count} Karyawan, Rp " . number_format($severancePayroll->total_net, 0, ',', '.') . " (Status: CALCULATED)");
    }
}
