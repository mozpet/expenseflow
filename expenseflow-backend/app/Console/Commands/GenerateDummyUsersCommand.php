<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\ShiftController;
use App\Http\Controllers\API\UserController;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GenerateDummyUsersCommand extends Command
{
    protected $signature = 'users:generate-dummy
                            {--count=1000 : Jumlah total akun dummy yang akan dibuat}
                            {--branches=3 : Jumlah cabang yang akan dibagi}
                            {--mode=both : Mode pembuatan: "both" (benchmark kedua mode), "batch" (chunked insert cepat), atau "sequential" (User::create per akun)}
                            {--clean : Hapus semua akun dummy yang pernah dibuat sebelumnya sebelum generate}
                            {--test-queries : Uji performa query endpoint yang diakses UI dengan 1000 user}
                            {--password=password : Password default untuk akun dummy}';

    protected $description = 'Generate akun dummy terbagi ke 3 cabang dan ukur performa pembuatan akun serta pemrosesan data di UI/backend';

    private array $firstNames = [
        'Aditya', 'Agus', 'Ahmad', 'Aji', 'Aldo', 'Alvin', 'Andi', 'Angga', 'Anisa', 'Annisa',
        'Ari', 'Arief', 'Arif', 'Arya', 'Bagus', 'Bambang', 'Bayu', 'Bima', 'Bintang', 'Budi',
        'Cahyono', 'Candra', 'Citra', 'Danang', 'Daniel', 'Dedi', 'Denny', 'Desi', 'Devi', 'Dewi',
        'Dian', 'Dicky', 'Dimas', 'Dina', 'Dini', 'Dodi', 'Doni', 'Dwi', 'Eka', 'Eko',
        'Endang', 'Erna', 'Fadli', 'Fajar', 'Fandi', 'Farhan', 'Febri', 'Ferry', 'Firman', 'Fitri',
        'Galang', 'Gilang', 'Gita', 'Gunawan', 'Hadi', 'Hafiz', 'Hansen', 'Hari', 'Hendra', 'Heri',
        'Ilham', 'Indah', 'Indra', 'Irfan', 'Ivan', 'Joko', 'Kevin', 'Kiki', 'Krisna', 'Kurniawan',
        'Laras', 'Lestari', 'Lukman', 'Mega', 'Melinda', 'Miftah', 'Muhammad', 'Nadia', 'Nanda', 'Nico',
        'Novita', 'Nurul', 'Panji', 'Pratama', 'Putra', 'Putri', 'Rafi', 'Rahma', 'Rahmat', 'Rangga',
        'Rian', 'Rico', 'Rina', 'Rio', 'Riris', 'Riza', 'Rizky', 'Sandy', 'Satria', 'Septian',
        'Siti', 'Sony', 'Surya', 'Taufik', 'Teguh', 'Tri', 'Wahyu', 'Wawan', 'Wibowo', 'Yogi', 'Yudi', 'Yuliana'
    ];

    private array $lastNames = [
        'Adi', 'Adriansyah', 'Agustina', 'Akbar', 'Alamsyah', 'Ananda', 'Anggraini', 'Anwar', 'Ardiansyah', 'Aryanto',
        'Astuti', 'Budiman', 'Cahyadi', 'Damayanti', 'Darman', 'Dharmawan', 'Fadilah', 'Gunawan', 'Hakim', 'Handayani',
        'Hartono', 'Haryanto', 'Hasan', 'Hidayat', 'Irawan', 'Iskandar', 'Kurnia', 'Kurniawan', 'Kusuma', 'Lestari',
        'Mahardika', 'Mahendra', 'Mansyur', 'Maulana', 'Mulyadi', 'Nasution', 'Nugraha', 'Nugroho', 'Nurcahyo', 'Octavia',
        'Permana', 'Prabowo', 'Prakoso', 'Prasetya', 'Prasetyo', 'Pratama', 'Purnama', 'Purwanto', 'Putra', 'Putri',
        'Rahadian', 'Rahardjo', 'Rahman', 'Ramadhan', 'Riyadi', 'Rohman', 'Rosadi', 'Safitri', 'Santoso', 'Saputra',
        'Sari', 'Sasongko', 'Setiawan', 'Setyawan', 'Simanjuntak', 'Siregar', 'Subagyo', 'Sudrajat', 'Sugiarto', 'Suharto',
        'Sukirman', 'Sulistyo', 'Sumantri', 'Suparman', 'Supriadi', 'Suryadi', 'Suryono', 'Susanto', 'Sutanto', 'Sutrisno',
        'Suwandi', 'Syahputra', 'Tanjung', 'Utami', 'Wahid', 'Wahyudi', 'Wibowo', 'Wicaksono', 'Widodo', 'Wijaya', 'Yulian'
    ];

    private array $departments = [
        'Engineering / IT',
        'Operasional',
        'Finance & Accounting',
        'Human Resources',
        'Sales & Marketing',
        'General Affairs',
        'Customer Service',
        'Procurement & Supply Chain'
    ];

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $branchCount = (int) $this->option('branches');
        $mode = strtolower((string) $this->option('mode'));
        $password = (string) $this->option('password');
        $isClean = (bool) $this->option('clean');
        $testQueries = (bool) $this->option('test-queries') || true;

        $this->info("================================================================================");
        $this->info("             EXPENSEFLOW — DUMMY USER GENERATOR & BENCHMARK                     ");
        $this->info("================================================================================");
        $this->line("Target Akun     : <comment>{$count} akun</comment>");
        $this->line("Target Cabang   : <comment>{$branchCount} cabang</comment>");
        $this->line("Mode Pembuatan  : <comment>" . ($mode === 'both' ? 'Komparasi Keduanya (Sequential + Batch)' : ($mode === 'sequential' ? 'Sequential' : 'Batch')) . "</comment>");
        $this->line("Default Password: <comment>{$password}</comment>");
        $this->line("Waktu Mulai     : " . now('Asia/Jakarta')->toDateTimeString() . " WIB\n");

        // 1. Pastikan Company ada
        $company = Company::first();
        if (!$company) {
            $company = Company::create([
                'name'      => 'PT Maju Bersama',
                'email'     => 'info@majubersama.co.id',
                'phone'     => '021-5555-1234',
                'address'   => 'Jl. Jendral Sudirman No. 10, Jakarta Selatan',
                'is_active' => true,
            ]);
            $this->info("Perusahaan baru dibuat: {$company->name} (ID: {$company->id})");
        }

        // 2. Setup 3 Cabang Kantor (attendance_settings)
        $branches = $this->ensureBranches($company, $branchCount);
        $this->info("Cabang terdaftar ({$branches->count()} kantor):");
        foreach ($branches as $idx => $b) {
            $this->line("  [" . ($idx + 1) . "] {$b->office_name} (ID: {$b->id}, Lat: {$b->office_latitude}, Lng: {$b->office_longitude})");
        }
        $this->newLine();

        // 3. Bersihkan akun dummy lama jika ada request --clean atau mode both
        if ($isClean || $mode === 'both') {
            $deleted = User::where('company_id', $company->id)
                ->where(function ($q) {
                    $q->where('email', 'like', 'dummy.%@%')
                      ->orWhere('employee_code', 'like', 'EMP-DUMMY-%')
                      ->orWhere('email', 'like', 'bench.%@%')
                      ->orWhere('employee_code', 'like', 'EMP-BENCH-%');
                })
                ->forceDelete();
            if ($deleted > 0) {
                $this->warn("Membersihkan {$deleted} akun dummy lama.");
            }
        }

        // 4. Hitung pembagian akun ke cabang
        $quotas = [];
        $baseQuota = intdiv($count, $branches->count());
        $remainder = $count % $branches->count();
        foreach ($branches as $i => $branch) {
            $quotas[$branch->id] = $baseQuota + ($i < $remainder ? 1 : 0);
        }

        $this->info("Distribusi Kuota 1000 Akun ke 3 Cabang:");
        foreach ($branches as $branch) {
            $this->line("  • {$branch->office_name} : <info>{$quotas[$branch->id]} akun</info>");
        }
        $this->newLine();

        // 5. Benchmark Eksekusi
        $results = [];

        if ($mode === 'both' || $mode === 'sequential') {
            // Jalankan sample sequential (misal 50 akun untuk mengukur single-account Bcrypt overhead tanpa membuang waktu terlalu lama)
            $sampleSize = ($mode === 'both') ? 50 : $count;
            $this->info("--- [Uji 1] Single-Account Creation (Model::create + Realtime Bcrypt Hash) ---");
            $this->line("Menguji {$sampleSize} akun dengan hash Bcrypt individu...");
            
            $startSeq = microtime(true);
            $memSeqStart = memory_get_usage();
            $seqCreated = $this->generateSequentialSample($company, $branches->first()->id, $sampleSize, $password);
            $endSeq = microtime(true);
            $memSeqPeak = memory_get_peak_usage();
            
            $seqElapsed = $endSeq - $startSeq;
            $seqRate = $seqElapsed > 0 ? ($seqCreated / $seqElapsed) : 0;
            $seqMsPerAccount = $seqCreated > 0 ? (($seqElapsed * 1000) / $seqCreated) : 0;

            $results['sequential'] = [
                'count'        => $seqCreated,
                'elapsed_sec'  => $seqElapsed,
                'rate_per_sec' => $seqRate,
                'ms_per_acc'   => $seqMsPerAccount,
                'mem_mb'       => ($memSeqPeak - $memSeqStart) / (1024 * 1024),
            ];

            $this->line(sprintf("  -> Waktu: <comment>%.3f detik</comment> | Kecepatan: <info><options=bold>%.2f akun / detik</options=bold></info> (%.2f ms / akun)", $seqElapsed, $seqRate, $seqMsPerAccount));
            
            // Hapus sample sequential jika di mode both agar tidak dobel
            if ($mode === 'both') {
                User::where('company_id', $company->id)->where('employee_code', 'like', 'EMP-BENCH-%')->forceDelete();
            }
            $this->newLine();
        }

        if ($mode === 'both' || $mode === 'batch') {
            $this->info("--- [Uji 2] High-Throughput Batch Ingestion (Pre-Hashed + Chunked SQL Insert) ---");
            $this->line("Membuat {$count} akun terbagi rata ke {$branches->count()} cabang...");
            
            $startBatch = microtime(true);
            $memBatchStart = memory_get_usage();
            $batchCreated = $this->generateBatch($company, $branches, $quotas, $password);
            $endBatch = microtime(true);
            $memBatchPeak = memory_get_peak_usage();

            $batchElapsed = $endBatch - $startBatch;
            $batchRate = $batchElapsed > 0 ? ($batchCreated / $batchElapsed) : 0;
            $batchMsPerAccount = $batchCreated > 0 ? (($batchElapsed * 1000) / $batchCreated) : 0;

            $results['batch'] = [
                'count'        => $batchCreated,
                'elapsed_sec'  => $batchElapsed,
                'rate_per_sec' => $batchRate,
                'ms_per_acc'   => $batchMsPerAccount,
                'mem_mb'       => ($memBatchPeak - $memBatchStart) / (1024 * 1024),
            ];

            $this->line(sprintf("  -> Waktu: <comment>%.3f detik</comment> | Kecepatan: <info><options=bold>%.2f akun / detik</options=bold></info> (%.3f ms / akun)", $batchElapsed, $batchRate, $batchMsPerAccount));
            $this->newLine();
        }

        // 6. Ringkasan Performa Pembuatan Akun
        $this->info("================================================================================");
        $this->info("                       HASIL BENCHMARK PERFORMA                                 ");
        $this->info("================================================================================");
        if (isset($results['sequential'])) {
            $r = $results['sequential'];
            $this->line("1. Single Account Creation (Bcrypt Individu + Model Events):");
            $this->line(sprintf("   • Kecepatan        : <info><options=bold>%.2f akun / detik</options=bold></info>", $r['rate_per_sec']));
            $this->line(sprintf("   • Rata-rata Durasi : <comment>%.2f ms / akun</comment>", $r['ms_per_acc']));
            $this->line(sprintf("   • Karakteristik    : CPU-bound (Bcrypt Cost 12 untuk keamanan enkripsi password)"));
        }
        if (isset($results['batch'])) {
            $r = $results['batch'];
            $this->line("2. High-Speed Batch Ingestion (Pre-computed Hash + Bulk Multi-Row Insert):");
            $this->line(sprintf("   • Kecepatan        : <info><options=bold>%.2f akun / detik</options=bold></info>", $r['rate_per_sec']));
            $this->line(sprintf("   • Rata-rata Durasi : <comment>%.3f ms / akun</comment>", $r['ms_per_acc']));
            $this->line(sprintf("   • Total Waktu      : <comment>%.3f detik</comment> untuk 1,000 akun", $r['elapsed_sec']));
            $this->line(sprintf("   • Penggunaan RAM   : <comment>%.2f MB</comment>", $r['mem_mb']));
        }
        $this->info("================================================================================");

        // 7. Rekap Database Terkini
        $totalInDb = User::where('company_id', $company->id)->count();
        $this->newLine();
        $this->info("Rekap Karyawan per Cabang di Database Saat Ini (Total: {$totalInDb} user):");
        foreach ($branches as $branch) {
            $branchUsersCount = User::where('company_id', $company->id)
                ->where('attendance_setting_id', $branch->id)
                ->count();
            $this->line(sprintf("  • %-32s : %d karyawan", $branch->office_name, $branchUsersCount));
        }

        $unassignedCount = User::where('company_id', $company->id)
            ->whereNull('attendance_setting_id')
            ->count();
        if ($unassignedCount > 0) {
            $this->line(sprintf("  • %-32s : %d karyawan", "(Tanpa Cabang / Default)", $unassignedCount));
        }

        // 8. Uji Performa Query Endpoint yang digunakan Web UI dengan 1000 user
        if ($testQueries) {
            $this->newLine();
            $this->testUIEndpointsPerformance($company);
        }

        $this->newLine();
        $this->info("✅ Sukses! 1000 akun dummy siap digunakan untuk menguji UI & performa sistem.");
        return Command::SUCCESS;
    }

    /**
     * Uji performa pemrosesan query backend saat UI meminta data 1000 karyawan.
     */
    private function testUIEndpointsPerformance(Company $company)
    {
        $this->info("================================================================================");
        $this->info("          BENCHMARK PEMROSESAN DATA OLEH BACKEND UNTUK WEB UI                   ");
        $this->info("================================================================================");
        $admin = User::where('company_id', $company->id)->where('role', 'super_admin')->first()
            ?? User::where('company_id', $company->id)->first();

        $endpoints = [
            [
                'name'     => 'GET /api/v1/admin/users (Tabel Karyawan Page 1)',
                'callback' => function () use ($admin) {
                    $req = Request::create('/api/v1/admin/users', 'GET');
                    $req->setUserResolver(fn () => $admin);
                    return app(UserController::class)->index($req);
                },
            ],
            [
                'name'     => 'GET /api/v1/dashboard/attendance/users (Tabel Status Presensi)',
                'callback' => function () use ($admin) {
                    $req = Request::create('/api/v1/dashboard/attendance/users', 'GET');
                    $req->setUserResolver(fn () => $admin);
                    return app(AttendanceController::class)->listUsers($req);
                },
            ],
            [
                'name'     => 'GET /api/v1/dashboard/attendance/today (Dashboard Kehadiran Hari Ini)',
                'callback' => function () use ($admin) {
                    $req = Request::create('/api/v1/dashboard/attendance/today', 'GET');
                    $req->setUserResolver(fn () => $admin);
                    return app(AttendanceController::class)->today($req);
                },
            ],
            [
                'name'     => 'GET /api/v1/dashboard/attendance/leave-balances (Rekap Saldo Cuti)',
                'callback' => function () use ($admin) {
                    $req = Request::create('/api/v1/dashboard/attendance/leave-balances', 'GET');
                    $req->setUserResolver(fn () => $admin);
                    return app(AttendanceController::class)->listLeaveBalances($req);
                },
            ],
            [
                'name'     => 'GET /api/v1/dashboard/attendance/shifts/roster (Roster Jadwal Shift)',
                'callback' => function () use ($admin) {
                    $req = Request::create('/api/v1/dashboard/attendance/shifts/roster', 'GET', ['date' => now()->toDateString()]);
                    $req->setUserResolver(fn () => $admin);
                    return app(ShiftController::class)->roster($req);
                },
            ],
            [
                'name'     => 'GET /api/v1/dashboard/attendance/report (Laporan Bulanan 31 Hari x 1000 User)',
                'callback' => function () use ($admin) {
                    $req = Request::create('/api/v1/dashboard/attendance/report', 'GET', [
                        'start_date' => now()->startOfMonth()->toDateString(),
                        'end_date'   => now()->toDateString(),
                    ]);
                    $req->setUserResolver(fn () => $admin);
                    return app(AttendanceController::class)->reportAttendance($req);
                },
            ],
            [
                'name'     => 'GET /api/v1/dashboard/attendance/device-changes (Persetujuan Pindah Device)',
                'callback' => function () use ($admin) {
                    $req = Request::create('/api/v1/dashboard/attendance/device-changes', 'GET');
                    $req->setUserResolver(fn () => $admin);
                    return app(AttendanceController::class)->listDeviceChanges($req);
                },
            ],
        ];

        foreach ($endpoints as $ep) {
            $t0 = microtime(true);
            $response = call_user_func($ep['callback']);
            $dt = (microtime(true) - $t0) * 1000;
            $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            
            $statusLabel = $statusCode === 200 ? '<info>200 OK</info>' : "<error>{$statusCode}</error>";
            $this->line(sprintf("  • %-60s : %6.2f ms [%s]", $ep['name'], $dt, $statusLabel));
        }

        $this->info("================================================================================");
    }

    private function ensureBranches(Company $company, int $targetCount)
    {
        $existing = AttendanceSetting::where('company_id', $company->id)->get();

        $defaultBranches = [
            [
                'office_name'            => 'Kantor Pusat Jakarta',
                'office_latitude'        => -6.20880000,
                'office_longitude'       => 106.84560000,
                'radius_meters'          => 150,
                'work_start_time'        => '08:00:00',
                'work_end_time'          => '17:00:00',
                'late_tolerance_minutes' => 15,
                'require_selfie'         => false,
                'allow_wfh'              => true,
                'default_leave_quota'    => 12,
            ],
            [
                'office_name'            => 'Cabang Bandung (Dago)',
                'office_latitude'        => -6.89150000,
                'office_longitude'       => 107.61070000,
                'radius_meters'          => 120,
                'work_start_time'        => '08:30:00',
                'work_end_time'          => '17:30:00',
                'late_tolerance_minutes' => 15,
                'require_selfie'         => false,
                'allow_wfh'              => true,
                'default_leave_quota'    => 12,
            ],
            [
                'office_name'            => 'Cabang Surabaya (Gubeng)',
                'office_latitude'        => -7.26540000,
                'office_longitude'       => 112.75190000,
                'radius_meters'          => 100,
                'work_start_time'        => '08:00:00',
                'work_end_time'          => '17:00:00',
                'late_tolerance_minutes' => 15,
                'require_selfie'         => false,
                'allow_wfh'              => true,
                'default_leave_quota'    => 12,
            ],
        ];

        $needed = $targetCount - $existing->count();
        if ($needed > 0) {
            for ($i = 0; $i < $needed; $i++) {
                $templateIndex = ($existing->count() + $i) % count($defaultBranches);
                $data = $defaultBranches[$templateIndex];
                if ($existing->contains('office_name', $data['office_name'])) {
                    $data['office_name'] .= ' ' . ($i + 2);
                }
                $data['company_id'] = $company->id;
                AttendanceSetting::create($data);
            }
            $existing = AttendanceSetting::where('company_id', $company->id)->get();
        }

        return $existing->take($targetCount);
    }

    private function generateSequentialSample(Company $company, int $branchId, int $count, string $password): int
    {
        $created = 0;
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 1; $i <= $count; $i++) {
            $fn = $this->firstNames[($i * 5 + 3) % count($this->firstNames)];
            $ln = $this->lastNames[($i * 9 + 11) % count($this->lastNames)];
            $codeNum = str_pad((string) $i, 4, '0', STR_PAD_LEFT);

            User::create([
                'company_id'            => $company->id,
                'employee_code'         => "EMP-BENCH-{$codeNum}",
                'identity_number'       => sprintf("317101%02d%02d78%04d", ($i % 28) + 1, ($i % 12) + 1, 1000 + $i),
                'name'                  => "{$fn} {$ln}",
                'email'                 => "bench.user{$codeNum}@majubersama.co.id",
                'password'              => Hash::make($password),
                'role'                  => 'employee',
                'department'            => 'Engineering / IT',
                'phone'                 => sprintf("0812%08d", 10000000 + $i),
                'attendance_setting_id' => $branchId,
                'monthly_claim_limit'   => 5000000,
                'is_active'             => true,
                'attendance_enabled'    => true,
                'wfh_enabled'           => true,
                'radius_enabled'        => true,
                'employment_type'       => 'PKWTT',
                'joined_date'           => '2024-01-15',
            ]);
            $created++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        return $created;
    }

    private function generateBatch(Company $company, $branches, array $quotas, string $password): int
    {
        $hashedPassword = Hash::make($password);
        $records = [];
        $counter = 1;
        $totalTarget = array_sum($quotas);
        $bar = $this->output->createProgressBar($totalTarget);
        $bar->start();

        $now = now();

        foreach ($branches as $branch) {
            $branchQuota = $quotas[$branch->id];
            for ($k = 0; $k < $branchQuota; $k++) {
                $userData = $this->buildUserData($company->id, $branch->id, $counter, $hashedPassword, true);
                $userData['created_at'] = $now;
                $userData['updated_at'] = $now;
                $records[] = $userData;
                $counter++;
            }
        }

        $chunks = array_chunk($records, 200);
        foreach ($chunks as $chunk) {
            DB::table('users')->insert($chunk);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();
        return count($records);
    }

    private function buildUserData(int $companyId, int $branchId, int $index, string $passwordOrHash, bool $isAlreadyHashed = false): array
    {
        $fn = $this->firstNames[($index * 7 + 13) % count($this->firstNames)];
        $ln = $this->lastNames[($index * 11 + 37) % count($this->lastNames)];
        $fullName = "{$fn} {$ln}";

        $codeNum = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $employeeCode = "EMP-DUMMY-{$codeNum}";
        $email = "dummy.emp{$codeNum}@" . Str::slug(Company::find($companyId)?->name ?? 'majubersama') . ".co.id";

        $provCodes = ['31', '32', '35'];
        $prov = $provCodes[$index % 3];
        $day2d = ($index % 28) + 1;
        $month2d = ($index % 12) + 1;
        $year2d = ($index % 30) + 70;
        $unique4d = 1000 + ($index % 9000);
        $nikKtp = sprintf("%s7101%02d%02d%02d%04d", $prov, $day2d, $month2d, $year2d, $unique4d);

        $prefixes = ['0812', '0813', '0857', '0878', '0821'];
        $phonePrefix = $prefixes[$index % count($prefixes)];
        $phone = sprintf("%s%08d", $phonePrefix, ($index * 1234567) % 90000000 + 10000000);

        $dept = $this->departments[$index % count($this->departments)];

        $empTypeIdx = $index % 20;
        $empType = match (true) {
            $empTypeIdx < 12 => 'PKWTT',     // 60%
            $empTypeIdx < 17 => 'PKWT',      // 25%
            $empTypeIdx < 19 => 'Probation', // 10%
            default          => 'Internship' // 5%
        };

        $joinedYear = 2023 + ($index % 4);
        $joinedMonth = ($index % 12) + 1;
        $joinedDay = ($index % 28) + 1;
        $joinedDate = sprintf("%04d-%02d-%02d", $joinedYear, $joinedMonth, $joinedDay);

        $contractStart = $empType !== 'PKWTT' ? $joinedDate : null;
        $contractEnd = $empType === 'PKWT' ? date('Y-m-d', strtotime($joinedDate . ' +1 year'))
            : ($empType === 'Probation' ? date('Y-m-d', strtotime($joinedDate . ' +3 months'))
            : ($empType === 'Internship' ? date('Y-m-d', strtotime($joinedDate . ' +6 months')) : null));

        $role = 'employee';
        if ($index === 1) $role = 'hrd';
        elseif ($index === 2) $role = 'finance';
        elseif ($index === 3) $role = 'admin';

        $claimLimit = match ($role) {
            'admin' => 20000000,
            'hrd', 'finance' => 10000000,
            default => 5000000,
        };

        return [
            'company_id'            => $companyId,
            'employee_code'         => $employeeCode,
            'identity_number'       => $nikKtp,
            'name'                  => $fullName,
            'email'                 => $email,
            'password'              => $isAlreadyHashed ? $passwordOrHash : Hash::make($passwordOrHash),
            'role'                  => $role,
            'department'            => $dept,
            'phone'                 => $phone,
            'attendance_setting_id' => $branchId,
            'monthly_claim_limit'   => $claimLimit,
            'is_active'             => true,
            'attendance_enabled'    => true,
            'wfh_enabled'           => ($index % 3 !== 0),
            'radius_enabled'        => true,
            'employment_type'       => $empType,
            'joined_date'           => $joinedDate,
            'contract_start_date'   => $contractStart,
            'contract_end_date'     => $contractEnd,
        ];
    }
}
