<?php

namespace Database\Seeders;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Division;
use App\Models\LeaveBalance;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AllBranches50EmployeesDummySeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Memastikan setiap cabang (4 cabang) memiliki masing-masing 50 karyawan
     * terstruktur lengkap (Pimpinan Cabang, 6 Supervisor, dan 43 Staff).
     */
    public function run(): void
    {
        $company = Company::first();
        if (! $company) {
            $company = Company::create([
                'name'      => 'PT Maju Bersama',
                'email'     => 'info@majubersama.co.id',
                'phone'     => '021-5555-1234',
                'address'   => 'Jl. Sudirman No. 10, Jakarta Selatan',
                'is_active' => true,
            ]);
        }

        $this->command?->info("🏢 Menyiapkan 50 Karyawan terstruktur untuk SETIAP Cabang di {$company->name}...");

        // ─── 1. Pastikan Divisi Tersedia ───
        $divisionsData = [
            'OPER' => ['name' => 'Operations', 'description' => 'Operasional kantor dan layanan lapangan'],
            'SALE' => ['name' => 'Sales', 'description' => 'Penjualan, akuisisi pelanggan, dan account management'],
            'LOGI' => ['name' => 'Logistics & Warehouse', 'description' => 'Gudang, persediaan barang, dan ekspedisi'],
            'FINA' => ['name' => 'Finance & Accounting', 'description' => 'Keuangan, kasir cabang, akuntansi, dan reimbursement'],
            'MARK' => ['name' => 'Marketing', 'description' => 'Pemasaran produk, promosi media sosial, dan event'],
            'HRGA' => ['name' => 'Human Resources & General Affairs', 'description' => 'SDM, kepatuhan absensi, dan operasional umum'],
            'IT'   => ['name' => 'Information Technology', 'description' => 'Infrastruktur IT, jaringan kantor, dan helpdesk'],
        ];

        $divisions = [];
        foreach ($divisionsData as $code => $data) {
            $division = Division::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name'        => $data['name'],
                    'description' => $data['description'],
                    'is_active'   => true,
                ]
            );
            $divisions[$code] = $division;
        }

        // ─── 2. Pastikan Posisi / Jabatan Tersedia ───
        $positionsData = [
            'Branch Manager' => ['division' => null, 'is_supervisor' => true, 'desc' => 'Pimpinan tertinggi kantor cabang'],
            'Operational Supervisor' => ['division' => 'OPER', 'is_supervisor' => true, 'desc' => 'Supervisi operasional harian cabang (SPV Lv1)'],
            'Field Operations Lead' => ['division' => 'OPER', 'is_supervisor' => false, 'desc' => 'Koordinator pelaksanaan tugas lapangan'],
            'Senior Staff Operasional' => ['division' => 'OPER', 'is_supervisor' => false, 'desc' => 'Senior pelaksana proses operasional'],
            'Staff Operasional' => ['division' => 'OPER', 'is_supervisor' => false, 'desc' => 'Pelaksana harian tugas operasional'],
            'Customer Service & Resepsionis' => ['division' => 'OPER', 'is_supervisor' => false, 'desc' => 'Front desk dan keluhan customer'],
            'Driver & Kurir Lapangan' => ['division' => 'OPER', 'is_supervisor' => false, 'desc' => 'Pengemudi dinas dan ekspedisi dokumen'],
            'Sales Supervisor' => ['division' => 'SALE', 'is_supervisor' => true, 'desc' => 'Supervisi target tim sales (SPV Lv1)'],
            'Senior Account Executive' => ['division' => 'SALE', 'is_supervisor' => false, 'desc' => 'Pengelolaan portofolio klien korporat'],
            'Sales Representative' => ['division' => 'SALE', 'is_supervisor' => false, 'desc' => 'Tenaga penjual langsung'],
            'Digital Marketing Specialist' => ['division' => 'MARK', 'is_supervisor' => false, 'desc' => 'Pemasaran digital dan promosi'],
            'Logistics Supervisor' => ['division' => 'LOGI', 'is_supervisor' => true, 'desc' => 'Supervisi gudang dan pengiriman (SPV Lv1)'],
            'Inventory Control & Checker' => ['division' => 'LOGI', 'is_supervisor' => false, 'desc' => 'Pemeriksa keluar-masuk barang & stok opname'],
            'Staff Gudang & Ekspedisi' => ['division' => 'LOGI', 'is_supervisor' => false, 'desc' => 'Penataan gudang dan loading muatan'],
            'Finance Supervisor' => ['division' => 'FINA', 'is_supervisor' => true, 'desc' => 'Supervisi keuangan, kasir, klaim (SPV Lv1)'],
            'Senior Accountant & Tax' => ['division' => 'FINA', 'is_supervisor' => false, 'desc' => 'Penyusunan laporan keuangan dan pajak'],
            'Staff Finance & Reimbursement' => ['division' => 'FINA', 'is_supervisor' => false, 'desc' => 'Verifikasi bukti struk dan pengeluaran'],
            'Kasir & Billing Admin' => ['division' => 'FINA', 'is_supervisor' => false, 'desc' => 'Transaksi kasir dan penagihan invoice'],
            'HRGA Supervisor' => ['division' => 'HRGA', 'is_supervisor' => true, 'desc' => 'Supervisi SDM dan kepatuhan kerja (SPV Lv1)'],
            'Staff Recruitment & Training' => ['division' => 'HRGA', 'is_supervisor' => false, 'desc' => 'Perekrutan dan onboarding karyawan'],
            'Staff Payroll & Absensi' => ['division' => 'HRGA', 'is_supervisor' => false, 'desc' => 'Administrasi presensi dan payroll'],
            'Staff Administrasi Umum' => ['division' => 'HRGA', 'is_supervisor' => false, 'desc' => 'Pengadaan ATK dan fasilitas kantor'],
            'IT Supervisor' => ['division' => 'IT', 'is_supervisor' => true, 'desc' => 'Pengawas sistem IT dan jaringan (SPV Lv1)'],
            'Staff IT Support' => ['division' => 'IT', 'is_supervisor' => false, 'desc' => 'Helpdesk teknis hardware dan printer'],
        ];

        $positions = [];
        foreach ($positionsData as $name => $pData) {
            $divId = $pData['division'] ? $divisions[$pData['division']]->id : null;
            $pos = Position::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'division_id'   => $divId,
                    'is_supervisor' => $pData['is_supervisor'],
                    'description'   => $pData['desc'],
                    'is_active'     => true,
                ]
            );
            $positions[$name] = $pos;
        }

        // Roles
        $roleSuperAdmin   = Role::where('slug', 'super_admin')->first();
        $roleAdmin        = Role::where('slug', 'admin')->first();
        $roleFinance      = Role::where('slug', 'finance')->first();
        $roleHrd          = Role::where('slug', 'hrd')->first();
        $roleEmployee     = Role::where('slug', 'employee')->first();
        $roleKepalaCabang = Role::where('slug', 'kepala_cabang')->first() ?: Role::where('name', 'like', '%kepala cabang%')->first();

        // ─── 3. Template Struktur Organisasi Cabang (50 Karyawan) ───
        $structureTemplate = [
            // [slot_index, role_type, position_name, division_code, manager_slot, emp_type, claim_limit]
            // A. Pimpinan Cabang (Slot 0)
            ['role' => 'kepala_cabang', 'pos' => 'Branch Manager', 'div' => null, 'mgr_slot' => null, 'type' => 'PKWTT', 'limit' => 20000000],

            // B. Operations (14 Orang: Slot 1-14)
            ['role' => 'employee', 'pos' => 'Operational Supervisor', 'div' => 'OPER', 'mgr_slot' => 0, 'type' => 'PKWTT', 'limit' => 10000000],
            ['role' => 'employee', 'pos' => 'Field Operations Lead', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWTT', 'limit' => 7000000],
            ['role' => 'employee', 'pos' => 'Senior Staff Operasional', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWTT', 'limit' => 6000000],
            ['role' => 'employee', 'pos' => 'Staff Operasional', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWTT', 'limit' => 5000000],
            ['role' => 'employee', 'pos' => 'Staff Operasional', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWTT', 'limit' => 5000000],
            ['role' => 'employee', 'pos' => 'Staff Operasional', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWT',  'limit' => 3500000],
            ['role' => 'employee', 'pos' => 'Staff Operasional', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWT',  'limit' => 3500000],
            ['role' => 'employee', 'pos' => 'Staff Operasional', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWT',  'limit' => 3500000],
            ['role' => 'employee', 'pos' => 'Customer Service & Resepsionis', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWTT', 'limit' => 4000000],
            ['role' => 'employee', 'pos' => 'Customer Service & Resepsionis', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWT',  'limit' => 3000000],
            ['role' => 'employee', 'pos' => 'Customer Service & Resepsionis', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWT',  'limit' => 3000000],
            ['role' => 'employee', 'pos' => 'Driver & Kurir Lapangan', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWTT', 'limit' => 3000000],
            ['role' => 'employee', 'pos' => 'Driver & Kurir Lapangan', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWT',  'limit' => 2500000],
            ['role' => 'employee', 'pos' => 'Driver & Kurir Lapangan', 'div' => 'OPER', 'mgr_slot' => 1, 'type' => 'PKWT',  'limit' => 2500000],

            // C. Sales & Marketing (10 Orang: Slot 15-24)
            ['role' => 'employee', 'pos' => 'Sales Supervisor', 'div' => 'SALE', 'mgr_slot' => 0, 'type' => 'PKWTT', 'limit' => 12000000],
            ['role' => 'employee', 'pos' => 'Senior Account Executive', 'div' => 'SALE', 'mgr_slot' => 15, 'type' => 'PKWTT', 'limit' => 8000000],
            ['role' => 'employee', 'pos' => 'Senior Account Executive', 'div' => 'SALE', 'mgr_slot' => 15, 'type' => 'PKWTT', 'limit' => 8000000],
            ['role' => 'employee', 'pos' => 'Sales Representative', 'div' => 'SALE', 'mgr_slot' => 15, 'type' => 'PKWTT', 'limit' => 6000000],
            ['role' => 'employee', 'pos' => 'Sales Representative', 'div' => 'SALE', 'mgr_slot' => 15, 'type' => 'PKWTT', 'limit' => 6000000],
            ['role' => 'employee', 'pos' => 'Sales Representative', 'div' => 'SALE', 'mgr_slot' => 15, 'type' => 'PKWT',  'limit' => 4500000],
            ['role' => 'employee', 'pos' => 'Sales Representative', 'div' => 'SALE', 'mgr_slot' => 15, 'type' => 'PKWT',  'limit' => 4500000],
            ['role' => 'employee', 'pos' => 'Sales Representative', 'div' => 'SALE', 'mgr_slot' => 15, 'type' => 'PKWT',  'limit' => 4500000],
            ['role' => 'employee', 'pos' => 'Digital Marketing Specialist', 'div' => 'MARK', 'mgr_slot' => 15, 'type' => 'PKWTT', 'limit' => 6500000],
            ['role' => 'employee', 'pos' => 'Digital Marketing Specialist', 'div' => 'MARK', 'mgr_slot' => 15, 'type' => 'PKWT',  'limit' => 5000000],

            // D. Logistics & Warehouse (9 Orang: Slot 25-33)
            ['role' => 'employee', 'pos' => 'Logistics Supervisor', 'div' => 'LOGI', 'mgr_slot' => 0, 'type' => 'PKWTT', 'limit' => 9000000],
            ['role' => 'employee', 'pos' => 'Inventory Control & Checker', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWTT', 'limit' => 6000000],
            ['role' => 'employee', 'pos' => 'Inventory Control & Checker', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWT',  'limit' => 4500000],
            ['role' => 'employee', 'pos' => 'Staff Gudang & Ekspedisi', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWTT', 'limit' => 4500000],
            ['role' => 'employee', 'pos' => 'Staff Gudang & Ekspedisi', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWTT', 'limit' => 4500000],
            ['role' => 'employee', 'pos' => 'Staff Gudang & Ekspedisi', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWT',  'limit' => 3500000],
            ['role' => 'employee', 'pos' => 'Staff Gudang & Ekspedisi', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWT',  'limit' => 3500000],
            ['role' => 'employee', 'pos' => 'Staff Gudang & Ekspedisi', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWT',  'limit' => 3500000],
            ['role' => 'employee', 'pos' => 'Staff Gudang & Ekspedisi', 'div' => 'LOGI', 'mgr_slot' => 25, 'type' => 'PKWT',  'limit' => 3500000],

            // E. Finance & Accounting (6 Orang: Slot 34-39)
            ['role' => 'finance',  'pos' => 'Finance Supervisor', 'div' => 'FINA', 'mgr_slot' => 0, 'type' => 'PKWTT', 'limit' => 10000000],
            ['role' => 'finance',  'pos' => 'Senior Accountant & Tax', 'div' => 'FINA', 'mgr_slot' => 34, 'type' => 'PKWTT', 'limit' => 7500000],
            ['role' => 'finance',  'pos' => 'Staff Finance & Reimbursement', 'div' => 'FINA', 'mgr_slot' => 34, 'type' => 'PKWTT', 'limit' => 5500000],
            ['role' => 'finance',  'pos' => 'Staff Finance & Reimbursement', 'div' => 'FINA', 'mgr_slot' => 34, 'type' => 'PKWT',  'limit' => 4000000],
            ['role' => 'finance',  'pos' => 'Kasir & Billing Admin', 'div' => 'FINA', 'mgr_slot' => 34, 'type' => 'PKWTT', 'limit' => 4500000],
            ['role' => 'finance',  'pos' => 'Kasir & Billing Admin', 'div' => 'FINA', 'mgr_slot' => 34, 'type' => 'PKWT',  'limit' => 3500000],

            // F. HRGA (6 Orang: Slot 40-45)
            ['role' => 'hrd',      'pos' => 'HRGA Supervisor', 'div' => 'HRGA', 'mgr_slot' => 0, 'type' => 'PKWTT', 'limit' => 9500000],
            ['role' => 'hrd',      'pos' => 'Staff Recruitment & Training', 'div' => 'HRGA', 'mgr_slot' => 40, 'type' => 'PKWTT', 'limit' => 6000000],
            ['role' => 'hrd',      'pos' => 'Staff Recruitment & Training', 'div' => 'HRGA', 'mgr_slot' => 40, 'type' => 'PKWT',  'limit' => 4500000],
            ['role' => 'hrd',      'pos' => 'Staff Payroll & Absensi', 'div' => 'HRGA', 'mgr_slot' => 40, 'type' => 'PKWTT', 'limit' => 6000000],
            ['role' => 'hrd',      'pos' => 'Staff Administrasi Umum', 'div' => 'HRGA', 'mgr_slot' => 40, 'type' => 'PKWTT', 'limit' => 4500000],
            ['role' => 'hrd',      'pos' => 'Staff Administrasi Umum', 'div' => 'HRGA', 'mgr_slot' => 40, 'type' => 'PKWT',  'limit' => 3500000],

            // G. IT Support (4 Orang: Slot 46-49)
            ['role' => 'employee', 'pos' => 'IT Supervisor', 'div' => 'IT', 'mgr_slot' => 0, 'type' => 'PKWTT', 'limit' => 9000000],
            ['role' => 'employee', 'pos' => 'Staff IT Support', 'div' => 'IT', 'mgr_slot' => 46, 'type' => 'PKWTT', 'limit' => 6000000],
            ['role' => 'employee', 'pos' => 'Staff IT Support', 'div' => 'IT', 'mgr_slot' => 46, 'type' => 'PKWT',  'limit' => 4500000],
            ['role' => 'employee', 'pos' => 'Staff IT Support', 'div' => 'IT', 'mgr_slot' => 46, 'type' => 'PKWT',  'limit' => 4500000],
        ];

        // Daftar Nama Indonesia Realistis untuk setiap Cabang
        $branchNamesData = [
            // Cabang 1: Jakarta (Kantor Pusat)
            1 => [
                'prefix' => 'JKT',
                'city' => 'Jakarta Selatan',
                'province' => 'DKI Jakarta',
                'names' => [
                    'Budi Wicaksono', 'Siti Rahayu', 'Rina Susanti', 'Dewi Lestari', 'Super Admin',
                    'Vidi Handoko', 'Dimas Aditya', 'Nadia Putri', 'Agung Nugroho', 'Bagus Prasetyo',
                    'Cahyo Wibowo', 'Denny Kurniawan', 'Edi Sujarwo', 'Fikri Ramadhan', 'Galang Prakoso',
                    'Hani Safitri', 'Irfan Hakim', 'Jessica Tanujaya', 'Kurniawan Dwi', 'Luki Hermawan',
                    'Muhammad Iqbal', 'Novita Anggraini', 'Oktavianus Putra', 'Panji Gumilang', 'Qori Sandioriva',
                    'Rendy Pangalila', 'Surya Saputra', 'Tommy Kurniawan', 'Utari Dyah', 'Vicky Shu',
                    'Wawan Gunawan', 'Xaverius Sigit', 'Yoga Pratama', 'Zaskia Adya', 'Aldi Taher',
                    'Bayu Oktara', 'Chacha Frederica', 'Desta Mahendra', 'Enzy Storia', 'Ferry Maryadi',
                    'Gading Marten', 'Hesti Purwadinata', 'Indra Bekti', 'Judika Sihotang', 'Kunto Aji',
                    'Luna Maya', 'Maudy Ayunda', 'Najwa Shihab', 'Onadio Leonardo', 'Prisia Nasution',
                ],
            ],
            // Cabang 2: kantor lapangan
            4 => [
                'prefix' => 'LPG',
                'city' => 'Bekasi',
                'province' => 'Jawa Barat',
                'names' => [
                    'Hendra Gunawan', 'Andi Pratama', 'matius', 'Ricardo Abet', 'Achmad Jufriyanto',
                    'Bambang Pamungkas', 'Cristian Gonzales', 'Danurwindo', 'Ellie Aiboy', 'Firman Utina',
                    'Gunawan Dwi Cahyo', 'Hamka Hamzah', 'I Made Wirawan', 'Jajang Sukmara', 'Kurnia Meiga',
                    'Lerby Eliandry', 'Maman Abdurrahman', 'Nova Arianto', 'Osvaldo Haay', 'Ponaryo Astaman',
                    'Rachmat Irianto', 'Syamsul Chaeruddin', 'Tantan Talidong', 'Uston Nawawi', 'Victor Igbonefo',
                    'Wahyu Wijiastanto', 'Yanto Basna', 'Zulham Zamrun', 'Atep Rizal', 'Boaz Solossa',
                    'Charis Yulianto', 'Dedik Setiawan', 'Evan Dimas', 'Fachruddin Aryanto', 'Gavin Kwan Adsit',
                    'Hansamu Yama', 'Ilham Udin', 'Jandia Eka Putra', 'Kakang Rudianto', 'Luthfi Kamal',
                    'Marckho Sandy', 'Nurhidayat Haji', 'Otavio Dutra', 'Pratama Arhan', 'Rizky Pora',
                    'Septian David', 'Teja Paku Alam', 'Usman Diarra', 'Vava Mario Yagalo', 'Witan Sulaeman',
                ],
            ],
            // Cabang 3: Semarang (Gedung Operasional Semarang)
            6 => [
                'prefix' => 'SMG',
                'city' => 'Kota Semarang',
                'province' => 'Jawa Tengah',
                'names' => [
                    'Joko Santoso', 'Agus Wibowo', 'Anjar Purnomo', 'Baskoro Tedjo', 'Cipto Mangunkusumo',
                    'Danang Sutowijoyo', 'Endro Siswanto', 'Faisal Riza', 'Guruh Soekarno', 'Hartono Mall',
                    'Iskandar Muda', 'Jatmiko Suwignyo', 'Kusuma Wardani', 'Lintang Pamungkas', 'Mochtar Riady',
                    'Nugroho Notosusanto', 'Ong Hok Ham', 'Pranowo Subagyo', 'Raden Saleh', 'Soetomo Hendro',
                    'Tirto Adhi Soerjo', 'Untung Surapati', 'Wahidin Soedirohusodo', 'Yos Sudarso', 'Zainul Majdi',
                    'Aryo Penangsang', 'Bratasena Purba', 'Cakrabirawa Jati', 'Duryodana Sakti', 'Ekalaya Panuntun',
                    'Gatotkaca Sena', 'Hanoman Putro', 'Indrajit Langit', 'Janaka Permadi', 'Karno Tanding',
                    'Laksmana Widagdo', 'Madukara Indah', 'Nakula Sadewa', 'Ontoseno Segoro', 'Pandu Dewanata',
                    'Rama Wijaya', 'Semar Badranaya', 'Togog Tejamantri', 'Udawa Patih', 'Werkudara Bima',
                    'Antareja Bumi', 'Baladewa Mandura', 'Citraksa Rupa', 'Dursasana Gede', 'Gareng Punokawan',
                ],
            ],
            // Cabang 4: Surabaya (Kantor Cabang Surabaya)
            11 => [
                'prefix' => 'SBY',
                'city' => 'Kota Surabaya',
                'province' => 'Jawa Timur',
                'names' => [
                    'Bambang Soediro', 'Eko Prasetyo', 'Rahmat Hidayat', 'Tri Wahyuni', 'Bayu Anggoro',
                    'Dian Safitri', 'Fajar Nugroho', 'Indra Lesmana', 'Mega Utami', 'Putri Anggraini',
                    'Lestari Wulandari', 'Nabila Syahrani', 'Agus Santoso', 'Slamet Riyadi', 'Joko Purnomo',
                    'Arif Budiman', 'Dimas Pratama', 'Citra Dewi', 'Reza Fahlevi', 'Aditya Wardhana',
                    'Bella Permata', 'Candra Wijaya', 'Dina Mariana', 'Erick Setiawan', 'Febri Ardiansyah',
                    'Gita Maharani', 'Hadi Susilo', 'Intan Nuraini', 'Kevin Alamsyah', 'Wahyu Hidayat',
                    'Lukman Hakim', 'Miftahul Huda', 'Nanang Kosim', 'Oki Firmansyah', 'Pandu Wicaksono',
                    'Rico Maulana', 'Surya Saputra', 'Taufik Ismail', 'Untung Suropati', 'Vina Rosalina',
                    'Wawan Hendrawan', 'Yeny Marlina', 'Zainal Abidin', 'Anisa Rahmawati', 'Bagus Prakoso',
                    'Chandra Kusuma', 'Dewi Sartika', 'Erwin Gutawa', 'Farhan Alfarizi', 'Gilang Ramadhan',
                ],
            ],
        ];

        $defaultPassword = Hash::make('password');
        $topBoss = User::whereIn('role', ['super_admin', 'admin'])->orderBy('id')->first();
        $currentYear = (int) date('Y');

        $branches = AttendanceSetting::where('company_id', $company->id)->get();

        foreach ($branches as $branch) {
            $branchId = $branch->id;
            if (! isset($branchNamesData[$branchId])) {
                continue;
            }

            $info = $branchNamesData[$branchId];
            $prefix = $info['prefix'];
            $city = $info['city'];
            $province = $info['province'];
            $names = $info['names'];

            $this->command?->info("🏢 Memproses Cabang: ID {$branchId} - {$branch->office_name} (Target: 50 Karyawan)...");

            // Hubungkan role kepala cabang ke cabang ini di role_branches
            if ($roleKepalaCabang) {
                DB::table('role_branches')->updateOrInsert(
                    ['role_id' => $roleKepalaCabang->id, 'attendance_setting_id' => $branch->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            // Ambil karyawan existing yang sudah ada di cabang ini
            $existingUsers = User::where('attendance_setting_id', $branchId)->get()->keyBy('id');
            $existingList = $existingUsers->values()->all();

            $branchUsers = [];

            // Loop 50 Slot Organisasi
            for ($i = 0; $i < 50; $i++) {
                $tmpl = $structureTemplate[$i];
                $slotCode = sprintf('%s-%03d', $prefix, $i + 1);

                // Tentukan nama dan email
                $name = $names[$i] ?? "Karyawan {$prefix} " . ($i + 1);
                $emailSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', trim($name)));
                $email = "{$emailSlug}.{$prefix}@majubersama.co.id";

                // Jika ada user existing di slot awal, pertahankan akunnya!
                $user = null;
                if (isset($existingList[$i])) {
                    $user = $existingList[$i];
                } else {
                    $user = User::where('email', $email)->orWhere('employee_code', $slotCode)->first();
                }

                // Tentukan Role Model
                $roleModel = $roleEmployee;
                $roleSlug = $tmpl['role'];
                if ($roleSlug === 'kepala_cabang') {
                    $roleModel = $roleKepalaCabang ?: $roleAdmin;
                } elseif ($roleSlug === 'admin') {
                    $roleModel = $roleAdmin;
                } elseif ($roleSlug === 'finance') {
                    $roleModel = $roleFinance;
                } elseif ($roleSlug === 'hrd') {
                    $roleModel = $roleHrd;
                }

                // Jika user existing punya role spesial (misal Super Admin, Hendra sbg Admin), pertahankan rolenya!
                if ($user && in_array($user->role, ['super_admin', 'admin'])) {
                    $roleSlug = $user->role;
                    $roleModel = $user->roleRelation ?: ($user->role === 'super_admin' ? $roleSuperAdmin : $roleAdmin);
                }

                $divId = $tmpl['div'] ? ($divisions[$tmpl['div']]->id ?? null) : null;
                $divName = $tmpl['div'] ? ($divisions[$tmpl['div']]->name ?? null) : null;
                $posId = $positions[$tmpl['pos']]->id ?? null;

                $birthYear = 1980 + ($i % 22);
                $birthMonth = str_pad((string) (($i % 12) + 1), 2, '0', STR_PAD_LEFT);
                $birthDay = str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT);
                $birthDate = "{$birthYear}-{$birthMonth}-{$birthDay}";
                $gender = ($i % 3 === 0 || $i % 7 === 0) ? 'Perempuan' : 'Laki-laki';
                $nikKtp = '3201' . str_pad((string) ($branchId), 2, '0', STR_PAD_LEFT) . $birthDay . $birthMonth . substr((string) $birthYear, 2, 2) . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);

                $userData = [
                    'company_id'              => $company->id,
                    'name'                    => $user ? $user->name : $name,
                    'password'                => $user ? $user->password : $defaultPassword,
                    'employee_code'           => $user && $user->employee_code ? $user->employee_code : $slotCode,
                    'identity_number'         => $user && $user->identity_number ? $user->identity_number : $nikKtp,
                    'phone'                   => $user && $user->phone ? $user->phone : ('08' . rand(11, 23) . rand(1000, 9999) . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT)),
                    'gender'                  => $user && $user->gender ? $user->gender : $gender,
                    'birth_place'             => $city,
                    'birth_date'              => $user && $user->birth_date ? $user->birth_date : $birthDate,
                    'attendance_setting_id'   => $branchId,
                    'division_id'             => $user && $user->division_id ? $user->division_id : $divId,
                    'department'              => $user && $user->department ? $user->department : $divName,
                    'position_id'             => $user && $user->position_id ? $user->position_id : $posId,
                    'role'                    => $roleSlug,
                    'role_id'                 => $roleModel?->id,
                    'joined_date'             => $user && $user->joined_date ? $user->joined_date : '2023-01-15',
                    'employment_type'         => $user && $user->employment_type ? $user->employment_type : $tmpl['type'],
                    'monthly_claim_limit'     => $user && $user->monthly_claim_limit ? $user->monthly_claim_limit : $tmpl['limit'],
                    'is_active'               => true,
                    'attendance_enabled'      => true,
                    'overtime_enabled'        => true,
                    'wfh_enabled'             => true,
                    'radius_enabled'          => true,
                    'dinas_luar_enabled'      => true,
                    'allow_attendance'        => true,
                    'allow_wfh'               => true,
                    'allow_radius'            => true,
                    'ktp_address'             => "Jl. Operasional Cabang No. " . ($i + 1),
                    'ktp_city'                => $city,
                    'ktp_province'            => $province,
                    'ktp_postal_code'         => '10000',
                    'domicile_address'        => "Jl. Operasional Cabang No. " . ($i + 1),
                    'is_domicile_same_as_ktp' => true,
                    'religion'                => ($i % 5 === 0 ? 'Kristen' : ($i % 7 === 0 ? 'Katolik' : 'Islam')),
                    'marital_status'          => ($birthYear < 1996 ? 'married' : 'single'),
                    'number_of_dependents'    => ($birthYear < 1996 ? rand(1, 2) : 0),
                    'blood_type'              => ['A', 'B', 'O', 'AB'][$i % 4],
                    'medical_conditions'      => 'Sehat jasmani dan rohani',
                    'bank_name'               => ($i % 2 === 0 ? 'BCA' : 'Mandiri'),
                    'bank_account_no'         => '5820' . str_pad((string) ($branchId), 2, '0', STR_PAD_LEFT) . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                    'bank_account_holder'     => $user ? $user->name : $name,
                ];

                if ($user) {
                    $user->update($userData);
                } else {
                    $userData['email'] = $email;
                    $user = User::create($userData);
                }

                // Inisialisasi Saldo Cuti & Izin tahunan
                LeaveBalance::firstOrCreate(
                    ['user_id' => $user->id, 'year' => $currentYear, 'leave_type' => 'cuti'],
                    ['quota' => 12, 'used' => rand(0, 3)]
                );
                LeaveBalance::firstOrCreate(
                    ['user_id' => $user->id, 'year' => $currentYear, 'leave_type' => 'izin'],
                    ['quota' => 0, 'used' => rand(0, 1)]
                );

                $branchUsers[$i] = $user;
            }

            // Sambungkan Manager Hierarchy (Slot -> Atasan)
            for ($i = 0; $i < 50; $i++) {
                $user = $branchUsers[$i] ?? null;
                if (! $user) {
                    continue;
                }

                $tmpl = $structureTemplate[$i];
                $mgrSlot = $tmpl['mgr_slot'];
                $managerId = null;

                if ($mgrSlot !== null && isset($branchUsers[$mgrSlot])) {
                    $managerId = $branchUsers[$mgrSlot]->id;
                } elseif ($i === 0) {
                    // Pimpinan Cabang melapor ke Top Boss (Super Admin / Direksi)
                    $managerId = $topBoss && $topBoss->id !== $user->id ? $topBoss->id : null;
                }

                // Jangan set manager ke dirinya sendiri
                if ($managerId !== $user->id) {
                    $user->update(['manager_id' => $managerId]);
                }
            }

            $countNow = User::where('attendance_setting_id', $branchId)->count();
            $this->command?->info("   ✅ Selesai: Cabang {$branch->office_name} kini memiliki {$countNow} karyawan aktif!");
        }

        $totalUsers = User::where('company_id', $company->id)->count();
        $this->command?->info("🎉 Selesai! Total Karyawan {$company->name} di seluruh 4 Cabang: {$totalUsers} orang (masing-masing 50 karyawan per cabang).");
    }
}
