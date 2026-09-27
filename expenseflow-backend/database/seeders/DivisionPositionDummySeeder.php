<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\OvertimeApproval;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DivisionPositionDummySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        if (! $company) {
            $this->command->warn('Tidak ada company yang ditemukan.');
            return;
        }

        $this->command->info("Membuat data dummy Divisi & Jabatan untuk Company: {$company->name} (ID: {$company->id})");

        // 1. Pastikan Divisi Utama Tersedia
        $divisionsData = [
            ['name' => 'Information Technology', 'code' => 'IT', 'description' => 'Pengembangan sistem, software engineering, dan infrastruktur IT'],
            ['name' => 'Sales', 'code' => 'SALE', 'description' => 'Penjualan produk, akuisisi klien, dan kemitraan bisnis'],
            ['name' => 'Finance & Accounting', 'code' => 'FINA', 'description' => 'Pengelolaan keuangan, perpajakan, disbursement, dan payroll'],
            ['name' => 'Operations', 'code' => 'OPER', 'description' => 'Operasional lapangan, proses harian kantor cabang dan logistik kerja'],
            ['name' => 'Logistics & Warehouse', 'code' => 'LOGI', 'description' => 'Manajemen rantai pasok, inventaris barang, dan ekspedisi'],
            ['name' => 'Marketing & Brand', 'code' => 'MARK', 'description' => 'Digital marketing, promosi produk, branding, dan public relations'],
        ];

        $divisionsMap = [];
        foreach ($divisionsData as $dData) {
            $division = Division::where('company_id', $company->id)
                ->where(function ($q) use ($dData) {
                    $q->where('code', $dData['code'])
                        ->orWhere('name', $dData['name']);
                })
                ->first();

            if ($division) {
                $division->update([
                    'name'        => $dData['name'],
                    'code'        => $dData['code'],
                    'description' => $dData['description'],
                    'is_active'   => true,
                ]);
            } else {
                $division = Division::create([
                    'company_id'  => $company->id,
                    'name'        => $dData['name'],
                    'code'        => $dData['code'],
                    'description' => $dData['description'],
                    'is_active'   => true,
                ]);
            }
            $divisionsMap[$dData['code']] = $division;
        }

        // Juga petakan nama pendek jika ada
        $divIt = Division::where('company_id', $company->id)->where(fn ($q) => $q->where('name', 'IT')->orWhere('code', 'IT'))->first() ?: $divisionsMap['IT'];
        $divSales = Division::where('company_id', $company->id)->where(fn ($q) => $q->where('name', 'Sales')->orWhere('code', 'SALE'))->first() ?: $divisionsMap['SALE'];
        $divFinance = Division::where('company_id', $company->id)->where(fn ($q) => $q->where('name', 'Finance')->orWhere('code', 'FINA'))->first() ?: $divisionsMap['FINA'];
        $divOps = Division::where('company_id', $company->id)->where(fn ($q) => $q->where('name', 'Operations')->orWhere('code', 'OPER'))->first() ?: $divisionsMap['OPER'];
        $divLogi = Division::where('company_id', $company->id)->where(fn ($q) => $q->where('name', 'Logistics')->orWhere('code', 'LOGI'))->first() ?: $divisionsMap['LOGI'];
        $divMark = Division::where('company_id', $company->id)->where(fn ($q) => $q->where('name', 'Marketing')->orWhere('code', 'MARK'))->first() ?: $divisionsMap['MARK'];

        // 2. Buat Daftar Posisi / Jabatan (Termasuk Flag is_supervisor)
        $positionsData = [
            // IT
            ['name' => 'IT Supervisor', 'division_id' => $divIt->id, 'is_supervisor' => true, 'description' => 'Memimpin tim engineering dan menyetujui lembur teknis'],
            ['name' => 'Senior Backend Engineer', 'division_id' => $divIt->id, 'is_supervisor' => false, 'description' => 'Pengembangan core API backend dan database'],
            ['name' => 'Frontend Developer', 'division_id' => $divIt->id, 'is_supervisor' => false, 'description' => 'Pengembangan antarmuka web dashboard dan mobile'],
            ['name' => 'Staff IT Support', 'division_id' => $divIt->id, 'is_supervisor' => false, 'description' => 'Maintenance hardware, jaringan kantor, dan user helpdesk'],

            // Sales
            ['name' => 'Sales Supervisor', 'division_id' => $divSales->id, 'is_supervisor' => true, 'description' => 'Supervisi target tim sales dan pengesahan dinas/lembur sales'],
            ['name' => 'Senior Account Executive', 'division_id' => $divSales->id, 'is_supervisor' => false, 'description' => 'Negosiasi proyek skala korporat dan retensi klien'],
            ['name' => 'Sales Representative', 'division_id' => $divSales->id, 'is_supervisor' => false, 'description' => 'Direct selling dan prospek customer baru'],

            // Finance
            ['name' => 'Finance & Accounting Supervisor', 'division_id' => $divFinance->id, 'is_supervisor' => true, 'description' => 'Otorisator keuangan, approval lembur finance, dan audit'],
            ['name' => 'Senior Accountant', 'division_id' => $divFinance->id, 'is_supervisor' => false, 'description' => 'Penyusunan laporan laba rugi dan rekonsiliasi bank'],
            ['name' => 'Staff Finance & Payroll', 'division_id' => $divFinance->id, 'is_supervisor' => false, 'description' => 'Pemrosesan klaim struk dan payroll karyawan'],

            // Operations
            ['name' => 'Operational Supervisor', 'division_id' => $divOps->id, 'is_supervisor' => true, 'description' => 'Supervisi operasional harian cabang dan tim lapangan'],
            ['name' => 'Field Operations Lead', 'division_id' => $divOps->id, 'is_supervisor' => true, 'description' => 'Koordinator lapangan per wilayah cabang operasional'],
            ['name' => 'Staff Operasional', 'division_id' => $divOps->id, 'is_supervisor' => false, 'description' => 'Pelaksanaan tugas operasional cabang harian'],

            // Logistics
            ['name' => 'Logistics Supervisor', 'division_id' => $divLogi->id, 'is_supervisor' => true, 'description' => 'Pengawasan gudang, persetujuan lembur shift muat barang'],
            ['name' => 'Staff Gudang & Ekspedisi', 'division_id' => $divLogi->id, 'is_supervisor' => false, 'description' => 'Pengepakan dan pengiriman barang antar cabang'],

            // Marketing
            ['name' => 'Marketing Lead / Supervisor', 'division_id' => $divMark->id, 'is_supervisor' => true, 'description' => 'Memimpin strategi promosi brand dan campaign'],
            ['name' => 'Digital Marketing Specialist', 'division_id' => $divMark->id, 'is_supervisor' => false, 'description' => 'Pengelolaan iklan digital dan analitik trafik'],

            // Global / Cross-Division Positions
            ['name' => 'General Manager', 'division_id' => null, 'is_supervisor' => true, 'description' => 'Manajemen eksekutif seluruh unit bisnis dan cabang'],
            ['name' => 'Staff Administrasi Umum', 'division_id' => null, 'is_supervisor' => false, 'description' => 'Administrasi kesekretariatan dan pengarsipan umum'],
        ];

        $positionsMap = [];
        foreach ($positionsData as $pData) {
            $pos = Position::updateOrCreate(
                ['company_id' => $company->id, 'name' => $pData['name']],
                [
                    'division_id'   => $pData['division_id'],
                    'is_supervisor' => $pData['is_supervisor'],
                    'description'   => $pData['description'],
                    'is_active'     => true,
                ]
            );
            $positionsMap[$pData['name']] = $pos;
        }

        // 3. Pasangkan Jabatan & Atasan Langsung pada User yang Ada
        $userAssignments = [
            // User 1: Budi Wicaksono -> SPV IT
            1 => [
                'division_id' => $divIt->id,
                'position_id' => $positionsMap['IT Supervisor']->id,
                'manager_id'  => null,
            ],
            // User 8: Ricardo Abet -> Senior Backend Engineer (Atasan: Budi Wicaksono)
            8 => [
                'division_id' => $divIt->id,
                'position_id' => $positionsMap['Senior Backend Engineer']->id,
                'manager_id'  => 1,
            ],
            // User 11820: Dimas Aditya -> Staff IT Support (Atasan: Budi Wicaksono)
            11820 => [
                'division_id' => $divIt->id,
                'position_id' => $positionsMap['Staff IT Support']->id,
                'manager_id'  => 1,
            ],
            // User 4: Rina Susanti -> Sales Supervisor
            4 => [
                'division_id' => $divSales->id,
                'position_id' => $positionsMap['Sales Supervisor']->id,
                'manager_id'  => null,
            ],
            // User 2: Siti Rahayu -> Senior Account Executive (Atasan: Rina Susanti)
            2 => [
                'division_id' => $divSales->id,
                'position_id' => $positionsMap['Senior Account Executive']->id,
                'manager_id'  => 4,
            ],
            // User 3: Andi Pratama -> Finance & Accounting Supervisor
            3 => [
                'division_id' => $divFinance->id,
                'position_id' => $positionsMap['Finance & Accounting Supervisor']->id,
                'manager_id'  => null,
            ],
            // User 11821: Nadia Putri -> Staff Finance & Payroll (Atasan: Andi Pratama)
            11821 => [
                'division_id' => $divFinance->id,
                'position_id' => $positionsMap['Staff Finance & Payroll']->id,
                'manager_id'  => 3,
            ],
            // User 6: Hendra Gunawan -> Operational Supervisor
            6 => [
                'division_id' => $divOps->id,
                'position_id' => $positionsMap['Operational Supervisor']->id,
                'manager_id'  => null,
            ],
            // User 11: Joko Santoso -> Field Operations Lead (Kantor Semarang, Atasan: Hendra Gunawan)
            11 => [
                'division_id' => $divOps->id,
                'position_id' => $positionsMap['Field Operations Lead']->id,
                'manager_id'  => 6,
            ],
            // User 9: matius -> Staff Operasional (Atasan: Hendra Gunawan)
            9 => [
                'division_id' => $divOps->id,
                'position_id' => $positionsMap['Staff Operasional']->id,
                'manager_id'  => 6,
            ],
            // User 13: Agus Wibowo -> Logistics Supervisor (Kantor Semarang)
            13 => [
                'division_id' => $divLogi->id,
                'position_id' => $positionsMap['Logistics Supervisor']->id,
                'manager_id'  => null,
            ],
            // User 11817: Vidi -> Marketing Lead / Supervisor
            11817 => [
                'division_id' => $divMark->id,
                'position_id' => $positionsMap['Marketing Lead / Supervisor']->id,
                'manager_id'  => null,
            ],
            // User 7: Super Admin -> General Manager
            7 => [
                'division_id' => null,
                'position_id' => $positionsMap['General Manager']->id,
                'manager_id'  => null,
            ],
            // User 5: Dewi Lestari -> Senior Accountant
            5 => [
                'division_id' => $divFinance->id,
                'position_id' => $positionsMap['Senior Accountant']->id,
                'manager_id'  => 3,
            ],
        ];

        foreach ($userAssignments as $userId => $assign) {
            $target = User::find($userId);
            if ($target) {
                $target->update($assign);
            }
        }

        // 4. Siapkan Data Dummy Pengajuan Lembur Fresh (Status Pending Tahap 1: SPV)
        $today = Carbon::today()->format('Y-m-d');
        $yesterday = Carbon::yesterday()->format('Y-m-d');

        $overtimeDummies = [
            [
                'user_id'          => 8, // Ricardo Abet (IT) -> SPV Budi
                'date'             => $today,
                'check_in_time'    => "{$today} 08:00:00",
                'check_out_time'   => "{$today} 19:30:00",
                'work_minutes'     => 570,
                'overtime_minutes' => 90,
                'reason'           => 'Percepatan deployment migrasi modul multi-cabang dan pengujian API',
            ],
            [
                'user_id'          => 2, // Siti Rahayu (Sales) -> SPV Rina
                'date'             => $today,
                'check_in_time'    => "{$today} 08:30:00",
                'check_out_time'   => "{$today} 19:00:00",
                'work_minutes'     => 510,
                'overtime_minutes' => 60,
                'reason'           => 'Presentasi dan negosiasi proposal pitching klien corporate Q4',
            ],
            [
                'user_id'          => 11821, // Nadia Putri (Finance) -> SPV Andi
                'date'             => $yesterday,
                'check_in_time'    => "{$yesterday} 08:00:00",
                'check_out_time'   => "{$yesterday} 20:00:00",
                'work_minutes'     => 600,
                'overtime_minutes' => 120,
                'reason'           => 'Rekapitulasi cut-off payroll bulanan dan verifikasi disbursement reimbursements',
            ],
            [
                'user_id'          => 11820, // Dimas Aditya (IT Magang) -> SPV Budi
                'date'             => $yesterday,
                'check_in_time'    => "{$yesterday} 08:00:00",
                'check_out_time'   => "{$yesterday} 18:45:00",
                'work_minutes'     => 525,
                'overtime_minutes' => 45,
                'reason'           => 'Troubleshooting jaringan LAN switch server kantor dan backup berkas',
            ],
        ];

        foreach ($overtimeDummies as $od) {
            $u = User::find($od['user_id']);
            if (! $u) {
                continue;
            }

            $att = Attendance::updateOrCreate(
                ['user_id' => $u->id, 'date' => $od['date']],
                [
                    'company_id'       => $company->id,
                    'check_in_time'    => $od['check_in_time'],
                    'check_out_time'   => $od['check_out_time'],
                    'work_minutes'     => $od['work_minutes'],
                    'overtime_minutes' => $od['overtime_minutes'],
                    'status'           => 'present',
                ]
            );

            OvertimeApproval::updateOrCreate(
                ['attendance_id' => $att->id],
                [
                    'user_id'          => $u->id,
                    'company_id'       => $company->id,
                    'overtime_minutes' => $od['overtime_minutes'],
                    'status'           => 'pending',
                    'current_step'     => 'spv',
                    'spv_id'           => null,
                    'spv_approved_at'  => null,
                    'spv_notes'        => null,
                    'is_auto_checkout' => false,
                    'overtime_reason'  => $od['reason'],
                ]
            );
        }

        $this->command->info('Sukses! Data dummy Divisi, Jabatan SPV/Staff, Atasan Langsung, dan Pengajuan Lembur berhasil dibuat.');
    }
}
