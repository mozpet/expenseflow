<?php

use App\Http\Controllers\API\ActivityLogController;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CompanyTaxProfileController;
use App\Http\Controllers\API\CurrencyRateController;
use App\Http\Controllers\API\DivisionController;
use App\Http\Controllers\API\ExpenseReportController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\JobLevelController;
use App\Http\Controllers\API\EmployeeBankAccountController;
use App\Http\Controllers\API\EmployeeLoanController;
use App\Http\Controllers\API\EmployeeSalaryController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\PayrollAdjustmentController;
use App\Http\Controllers\API\PayrollComponentController;
use App\Http\Controllers\API\PayrollController;
use App\Http\Controllers\API\PayrollGlController;
use App\Http\Controllers\API\PayrollGroupController;
use App\Http\Controllers\API\PayrollPaymentController;
use App\Http\Controllers\API\PayrollSecurityController;
use App\Http\Controllers\API\PayrollTaxController;
use App\Http\Controllers\API\PayrollTraceController;
use App\Http\Controllers\API\PayslipController;
use App\Http\Controllers\API\PositionController;
use App\Http\Controllers\API\PublicRecruitmentController;
use App\Http\Controllers\API\ReceiptController;
use App\Http\Controllers\API\RecruitmentController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\SalaryGradeController;
use App\Http\Controllers\API\SettingsController;
use App\Http\Controllers\API\SeveranceCaseController;
use App\Http\Controllers\API\ShiftController;
use App\Http\Controllers\API\SyncVersionController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\UserDocumentController;
use App\Http\Controllers\API\VendorController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// === STANDAR RESPONS KESALAHAN HTTP 429 ===
// Format respons JSON seragam dengan header HTTP standar:
//   - message: Pesan ramah dalam Bahasa Indonesia dengan waktu tunggu eksplisit (detik)
//   - retry_after_seconds: Waktu tunggu dalam detik (di-clamp <= 30 detik)
//   - retry_after: Alias kompatibilitas klien
//   - rate_limit: true
// Headers:
//   - Retry-After: Detik tunggu di-clamp <= 30 detik
//   - X-RateLimit-Limit & X-RateLimit-Remaining: Diteruskan dari Laravel RateLimiter
$buildRateLimitResponse = function (string $message, array $headers, int $window = 30) {
    $raw = (int) ($headers['Retry-After'] ?? 0);
    $seconds = max(1, min($raw > 0 ? $raw : $window, 30));

    $clampedHeaders = $headers;
    $clampedHeaders['Retry-After'] = (string) $seconds;

    // Pastikan pesan selalu menampilkan sisa detik tunggu
    $baseMessage = preg_replace('/\.?\s*(Silakan|Mohon|Coba)\s+tunggu.*$/i', '', trim($message));
    $formattedMessage = rtrim($baseMessage, '.') . ". Silakan tunggu {$seconds} detik sebelum mencoba kembali.";

    return response()->json([
        'message'             => $formattedMessage,
        'retry_after_seconds' => $seconds,
        'retry_after'         => $seconds,
        'rate_limit'          => true,
    ], 429, $clampedHeaders);
};

// Rate limiter untuk login: dua batasan jalan serentak.
//   - Per akun: 5 percobaan per menit (dibedakan per email)  → penjaga anti brute-force utama
//   - Per IP  : 120 percobaan per menit (sangat longgar untuk kantor NAT & CGNAT seluler)
$loginErrorResponse = function (Request $request, array $headers, int $window = 30) use ($buildRateLimitResponse) {
    $raw = (int) ($headers['Retry-After'] ?? 0);
    $seconds = max(1, min($raw > 0 ? $raw : $window, 30));

    return $buildRateLimitResponse(
        "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
        $headers,
        30
    );
};

// === RATE LIMITER TIER 4: Public Auth & Security ===
RateLimiter::for('login', function (Request $request) use ($loginErrorResponse) {
    $email = (string) $request->input('email'); // fallback aman ke string kosong

    return [
        Limit::perMinute(5)
            ->by($email)
            ->response(fn (Request $req, array $h) => $loginErrorResponse($req, $h, 30)),
        Limit::perMinute(120)
            ->by($request->ip())
            ->response(fn (Request $req, array $h) => $loginErrorResponse($req, $h, 30)),
    ];
});

// Tier 4: Forgot Password OTP Flow
RateLimiter::for('otp_send', function (Request $request) use ($buildRateLimitResponse) {
    $key = (string) ($request->input('email') ?: $request->ip());

    return Limit::perMinutes(10, 5)
        ->by($key)
        ->response(fn (Request $req, array $h) => $buildRateLimitResponse(
            "Terlalu banyak permintaan OTP.",
            $h,
            30
        ));
});

RateLimiter::for('otp_verify', function (Request $request) use ($buildRateLimitResponse) {
    $key = (string) ($request->input('email') ?: $request->ip());

    return Limit::perMinutes(5, 10)
        ->by($key)
        ->response(fn (Request $req, array $h) => $buildRateLimitResponse(
            "Terlalu banyak percobaan verifikasi OTP.",
            $h,
            30
        ));
});

RateLimiter::for('otp_reset', function (Request $request) use ($buildRateLimitResponse) {
    $key = (string) ($request->input('email') ?: $request->ip());

    return Limit::perMinutes(10, 5)
        ->by($key)
        ->response(fn (Request $req, array $h) => $buildRateLimitResponse(
            "Terlalu banyak permintaan reset password.",
            $h,
            30
        ));
});

// === RATE LIMITER TIER 1: General & Navigation (Read) ===
// 120 request / menit per user_id (fallback ke IP jika belum login), cooldown clamp 30 detik
RateLimiter::for('api', function (Request $request) use ($buildRateLimitResponse) {
    $key = $request->user() ? (string) $request->user()->id : $request->ip();

    return Limit::perMinute(120)
        ->by($key)
        ->response(fn (Request $req, array $h) => $buildRateLimitResponse(
            "Terlalu banyak permintaan.",
            $h,
            30
        ));
});

// === RATE LIMITER TIER 2: Actions & Mutations (Write) ===
// 45 request / menit per user_id (fallback ke IP jika belum login), cooldown clamp 30 detik
RateLimiter::for('actions', function (Request $request) use ($buildRateLimitResponse) {
    $key = $request->user() ? (string) $request->user()->id : $request->ip();

    return Limit::perMinute(45)
        ->by($key)
        ->response(fn (Request $req, array $h) => $buildRateLimitResponse(
            "Terlalu banyak aksi.",
            $h,
            30
        ));
});

// === RATE LIMITER TIER 3: Heavy Resource & Processing ===
// 15 request / menit per user_id (fallback ke IP jika belum login), cooldown clamp 30 detik
RateLimiter::for('heavy', function (Request $request) use ($buildRateLimitResponse) {
    $key = $request->user() ? (string) $request->user()->id : $request->ip();

    return Limit::perMinute(15)
        ->by($key)
        ->response(fn (Request $req, array $h) => $buildRateLimitResponse(
            "Terlalu banyak pemrosesan berat.",
            $h,
            30
        ));
});

Route::prefix('v1')->group(function () {
    Route::get('/ping', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Auth — public (rate limited: 5 attempts/min)
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Forgot Password OTP flow — public (rate limited Tier 4)
    Route::prefix('auth/forgot-password')->group(function () {
        Route::post('/send-otp', [ForgotPasswordController::class, 'sendOtp'])
            ->middleware('throttle:otp_send'); // Max 5 request / 10 menit
        Route::post('/verify-otp', [ForgotPasswordController::class, 'verifyOtp'])
            ->middleware('throttle:otp_verify'); // Max 10 verifikasi / 5 menit
        Route::post('/reset', [ForgotPasswordController::class, 'resetPassword'])
            ->middleware('throttle:otp_reset');
    });

    // Auth — authenticated
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Receipt mobile — semua role boleh scan & submit struk via mobile
    Route::middleware(['auth:sanctum', 'throttle:api', 'company'])
        ->prefix('employee')
        ->group(function () {
            Route::middleware('receipt_access')->group(function () {
                Route::post('/receipts', [ReceiptController::class, 'store'])->middleware('throttle:heavy');
                Route::get('/receipts', [ReceiptController::class, 'myReceipts']);
                Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
                Route::patch('/receipts/{receipt}/claim', [ReceiptController::class, 'updateClaim'])->middleware('throttle:actions');
                Route::post('/receipts/{receipt}/retake', [ReceiptController::class, 'retake']);
                Route::get('/receipts/{receipt}/image', [ReceiptController::class, 'image']);
                Route::post('/receipts/{receipt}/submit', [ReceiptController::class, 'submit']);
                Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy']);

                // Laporan Pengeluaran Dinas (Expense Reports / Bundling)
                Route::get('/expense-reports', [ExpenseReportController::class, 'index']);
                Route::post('/expense-reports', [ExpenseReportController::class, 'store']);
                Route::get('/expense-reports/{expenseReport}', [ExpenseReportController::class, 'show']);
                Route::post('/expense-reports/{expenseReport}/receipts', [ExpenseReportController::class, 'addReceipts']);
                Route::delete('/expense-reports/{expenseReport}/receipts/{receipt}', [ExpenseReportController::class, 'removeReceipt']);
                Route::post('/expense-reports/{expenseReport}/submit', [ExpenseReportController::class, 'submit']);
                Route::delete('/expense-reports/{expenseReport}', [ExpenseReportController::class, 'destroy']);
            });

            // Jadwal shift karyawan
            Route::get('/my-schedule', [ShiftController::class, 'mySchedule']);

            // Slip gaji pribadi (semua karyawan boleh lihat & unduh slip sendiri)
            Route::get('/payslips', [PayslipController::class, 'myPayslips']);
            Route::get('/payslips/{payslip}', [PayslipController::class, 'showMine']);
            Route::get('/payslips/{payslip}/pdf', [PayslipController::class, 'pdfMine'])->middleware('throttle:heavy');
        });

    // Finance / HRD / Admin / Super Admin routes — hanya web
    Route::middleware(['auth:sanctum', 'throttle:api', 'role:finance,hrd,admin,super_admin', 'company'])
        ->prefix('dashboard')
        ->group(function () {
            // ─── Fitur Finance (Khusus Finance, Admin, Super Admin — HRD Dikecualikan) ───
            Route::middleware('role:finance,admin,super_admin')->group(function () {
                // Receipt approval & disbursement
                Route::get('/receipts', [ReceiptController::class, 'inbox']);
                Route::get('/receipts/all', [ReceiptController::class, 'dashboardReceipts']);
                Route::post('/receipts/bulk-approve', [ReceiptController::class, 'bulkApprove'])->middleware('throttle:actions');
                Route::post('/receipts/bulk-pay', [ReceiptController::class, 'bulkDisburse'])->middleware('throttle:actions');
                Route::get('/receipts/export-disbursement', [ReceiptController::class, 'exportDisbursement'])->middleware('throttle:heavy');
                Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
                Route::get('/receipts/{receipt}/image', [ReceiptController::class, 'image']);
                Route::post('/receipts/{receipt}/approve', [ReceiptController::class, 'approve'])->middleware('throttle:actions');
                Route::post('/receipts/{receipt}/reject', [ReceiptController::class, 'reject'])->middleware('throttle:actions');
                Route::post('/receipts/{receipt}/pay', [ReceiptController::class, 'disburse'])->middleware('throttle:actions');

                // Expense Reports (Bundling Laporan Dinas)
                Route::get('/expense-reports', [ExpenseReportController::class, 'dashboardIndex']);
                Route::get('/expense-reports/{expenseReport}', [ExpenseReportController::class, 'show']);
                Route::post('/expense-reports/{expenseReport}/approve', [ExpenseReportController::class, 'approve']);
                Route::post('/expense-reports/{expenseReport}/reject', [ExpenseReportController::class, 'reject']);

                // Vendor management
                Route::get('/vendors', [VendorController::class, 'index']);
                Route::post('/vendors', [VendorController::class, 'store']);
                Route::patch('/vendors/{vendor}', [VendorController::class, 'update']);
                Route::post('/vendors/{vendor}/toggle', [VendorController::class, 'toggleActive']);

                // Invoice
                Route::get('/invoices', [InvoiceController::class, 'index']);
                Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
                Route::post('/invoices', [InvoiceController::class, 'store']);
                Route::post('/invoices/{invoice}/approve', [InvoiceController::class, 'approve']);
                Route::post('/invoices/{invoice}/reject', [InvoiceController::class, 'reject']);

                // Pengaturan threshold & batas klaim (Finance Rules)
                Route::get('/settings', [SettingsController::class, 'index']);
                Route::match(['put', 'patch'], '/settings', [SettingsController::class, 'update']);
                Route::match(['put', 'patch'], '/settings/branches/{attendanceSetting}', [SettingsController::class, 'updateBranch']);
            });

            // ─── Fitur Bersama (Finance, HRD, Admin, Super Admin) ───────────────
            // Notifikasi
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
            Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

            // Audit log (activity logs)
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);

            // Sinkronisasi status/versi data untuk Smart Cache
            Route::get('/sync-versions', [SyncVersionController::class, 'index']);

            // Daftar kantor / cabang perusahaan (read-only untuk filter struk & fitur bersama)
            Route::get('/attendance/settings', [AttendanceController::class, 'listSettings']);
            Route::get('/offices', [AttendanceController::class, 'listSettings']);
        });

    // ── Payroll / Penggajian (Finance, HRD, Admin, Super Admin) ──────────────────
    // Otorisasi granular per level (read/manage) diperiksa di dalam controller
    // via hasPermission(Role::MODULE_PAYROLL, ...). Middleware role hanya gerbang kasar.
    Route::middleware(['auth:sanctum', 'throttle:api', 'role:finance,hrd,admin,super_admin', 'company'])
        ->prefix('dashboard/payroll')
        ->group(function () {
            // Master komponen gaji (tunjangan / potongan)
            Route::get('/components', [PayrollComponentController::class, 'index']);
            Route::post('/components', [PayrollComponentController::class, 'store'])->middleware('throttle:actions');
            // Alat bantu editor formula DSL (Fase 6 §6.A) — validasi tanpa menyimpan.
            Route::post('/components/validate-formula', [PayrollComponentController::class, 'validateFormula'])->middleware('throttle:actions');
            Route::put('/components/{component}', [PayrollComponentController::class, 'update'])->middleware('throttle:actions');
            Route::delete('/components/{component}', [PayrollComponentController::class, 'destroy'])->middleware('throttle:actions');

            // Gaji karyawan: gaji pokok efektif, komponen tetap, profil pajak
            Route::get('/salaries', [EmployeeSalaryController::class, 'index']);
            Route::get('/salaries/{userId}', [EmployeeSalaryController::class, 'show'])->whereNumber('userId');
            Route::post('/salaries/{userId}', [EmployeeSalaryController::class, 'storeSalary'])->whereNumber('userId')->middleware('throttle:actions');
            Route::post('/salaries/{userId}/components', [EmployeeSalaryController::class, 'storeComponent'])->whereNumber('userId')->middleware('throttle:actions');
            Route::put('/salaries/{userId}/tax-profile', [EmployeeSalaryController::class, 'saveTaxProfile'])->whereNumber('userId')->middleware('throttle:actions');
            Route::put('/salaries/{userId}/bpjs-profile', [EmployeeSalaryController::class, 'saveBpjsProfile'])->whereNumber('userId')->middleware('throttle:actions');
            Route::delete('/salary-components/{component}', [EmployeeSalaryController::class, 'destroyComponent'])->middleware('throttle:actions');

            // Profil pajak PERUSAHAAN (NPWP pemberi kerja, terenkripsi). JSON termasking;
            // nilai penuh hanya di berkas ter-stream (PDF 1721-A1 / ekspor e-Bupot draf).
            Route::get('/company-tax-profile', [CompanyTaxProfileController::class, 'show']);
            Route::put('/company-tax-profile', [CompanyTaxProfileController::class, 'update'])->middleware('throttle:actions');

            // Grup payroll (grouping + scoping MVP). Batch ber-grup hanya menghitung anggota grup.
            Route::get('/groups', [PayrollGroupController::class, 'index']);
            Route::post('/groups', [PayrollGroupController::class, 'store'])->middleware('throttle:actions');
            Route::put('/groups/{group}', [PayrollGroupController::class, 'update'])->middleware('throttle:actions');
            Route::delete('/groups/{group}', [PayrollGroupController::class, 'destroy'])->middleware('throttle:actions');

            // Struktur & Skala Upah (Fase 6, Permenaker 1/2017): jenjang jabatan + golongan upah.
            // Rentang min–maks golongan menjadi rambu validasi saat menetapkan gaji karyawan.
            Route::get('/job-levels', [JobLevelController::class, 'index']);
            Route::post('/job-levels', [JobLevelController::class, 'store'])->middleware('throttle:actions');
            Route::put('/job-levels/{jobLevel}', [JobLevelController::class, 'update'])->middleware('throttle:actions');
            Route::delete('/job-levels/{jobLevel}', [JobLevelController::class, 'destroy'])->middleware('throttle:actions');

            Route::get('/salary-grades', [SalaryGradeController::class, 'index']);
            Route::post('/salary-grades', [SalaryGradeController::class, 'store'])->middleware('throttle:actions');
            Route::put('/salary-grades/{salaryGrade}', [SalaryGradeController::class, 'update'])->middleware('throttle:actions');
            Route::delete('/salary-grades/{salaryGrade}', [SalaryGradeController::class, 'destroy'])->middleware('throttle:actions');

            // Master kurs valuta asing bertanggal-efektif (Fase 6). Bila kurs tidak ada,
            // kalkulasi batch ditolak 422 — sistem tidak pernah mengasumsikan 1:1.
            Route::get('/currency-rates', [CurrencyRateController::class, 'index']);
            Route::post('/currency-rates', [CurrencyRateController::class, 'store'])->middleware('throttle:actions');
            Route::put('/currency-rates/{currencyRate}', [CurrencyRateController::class, 'update'])->middleware('throttle:actions');
            Route::delete('/currency-rates/{currencyRate}', [CurrencyRateController::class, 'destroy'])->middleware('throttle:actions');

            // Exit Settlement / berkas pesangon (Fase 6, PP 35/2021). Rute /preview
            // didaftarkan sebagai sufiks agar tidak bentrok dengan show.
            Route::get('/severance-cases', [SeveranceCaseController::class, 'index']);
            Route::post('/severance-cases', [SeveranceCaseController::class, 'store'])->middleware('throttle:actions');
            Route::get('/severance-cases/{severanceCase}', [SeveranceCaseController::class, 'show']);
            Route::get('/severance-cases/{severanceCase}/preview', [SeveranceCaseController::class, 'preview']);
            Route::put('/severance-cases/{severanceCase}', [SeveranceCaseController::class, 'update'])->middleware('throttle:actions');
            Route::delete('/severance-cases/{severanceCase}', [SeveranceCaseController::class, 'destroy'])->middleware('throttle:actions');

            // Kasbon / pinjaman karyawan
            Route::get('/loans', [EmployeeLoanController::class, 'index']);
            Route::post('/loans', [EmployeeLoanController::class, 'store'])->middleware('throttle:actions');
            Route::post('/loans/{loan}/approve', [EmployeeLoanController::class, 'approve'])->middleware('throttle:actions');
            Route::post('/loans/{loan}/cancel', [EmployeeLoanController::class, 'cancel'])->middleware('throttle:actions');

            // Batch proses payroll (draft → calculated → submitted → approved → paid)
            Route::get('/runs', [PayrollController::class, 'index']);
            Route::post('/runs', [PayrollController::class, 'store'])->middleware('throttle:actions');
            Route::get('/runs/{payroll}', [PayrollController::class, 'show']);
            Route::post('/runs/{payroll}/calculate', [PayrollController::class, 'calculate'])->middleware('throttle:heavy');
            Route::post('/runs/{payroll}/submit', [PayrollController::class, 'submit'])->middleware('throttle:actions');
            Route::post('/runs/{payroll}/approve', [PayrollController::class, 'approve'])->middleware('throttle:actions');
            Route::post('/runs/{payroll}/reject', [PayrollController::class, 'reject'])->middleware('throttle:actions');
            Route::post('/runs/{payroll}/mark-paid', [PayrollController::class, 'markPaid'])->middleware('throttle:actions');
            Route::delete('/runs/{payroll}', [PayrollController::class, 'destroy'])->middleware('throttle:actions');
            // Jejak audit tamper-evident (hash-chain) batch + hasil verifikasi integritas
            Route::get('/runs/{payroll}/logs', [PayrollController::class, 'logs']);

            // Penyesuaian gaji & koreksi retroaktif (maker-checker)
            Route::get('/adjustments', [PayrollAdjustmentController::class, 'index']);
            Route::post('/adjustments', [PayrollAdjustmentController::class, 'store'])->middleware('throttle:actions');
            Route::post('/adjustments/{adjustment}/approve', [PayrollAdjustmentController::class, 'approve'])->middleware('throttle:actions');
            Route::post('/adjustments/{adjustment}/void', [PayrollAdjustmentController::class, 'void'])->middleware('throttle:actions');

            // Proteksi perubahan rekening bank karyawan (maker-checker + notifikasi)
            Route::get('/employees/{userId}/bank-accounts', [EmployeeBankAccountController::class, 'index'])->whereNumber('userId');
            Route::post('/employees/{userId}/bank-account', [EmployeeBankAccountController::class, 'store'])->whereNumber('userId')->middleware('throttle:actions');
            Route::post('/bank-accounts/{bankAccount}/verify', [EmployeeBankAccountController::class, 'verify'])->middleware('throttle:actions');
            Route::post('/bank-accounts/{bankAccount}/reject', [EmployeeBankAccountController::class, 'reject'])->middleware('throttle:actions');

            // Step-up PIN keamanan pengguna (persetujuan final payroll)
            Route::get('/security/pin', [PayrollSecurityController::class, 'pinStatus']);
            Route::post('/security/pin', [PayrollSecurityController::class, 'setPin'])->middleware('throttle:actions');

            // Disbursement (Fase 4): ekspor berkas transfer bank + rekonsiliasi.
            // Berkas berisi nomor rekening penuh (disk privat + checksum); item termasking.
            // Lapisan ini terpisah dari mark-paid (tidak mengubah payslip/payroll/kasbon).
            Route::get('/runs/{payroll}/payment-batches', [PayrollPaymentController::class, 'index']);
            Route::post('/runs/{payroll}/payment-batches', [PayrollPaymentController::class, 'generate'])->middleware('throttle:heavy');
            Route::get('/payment-batches/{paymentBatch}', [PayrollPaymentController::class, 'show']);
            Route::get('/payment-batches/{paymentBatch}/download', [PayrollPaymentController::class, 'download'])->middleware('throttle:heavy');
            Route::post('/payment-batches/{paymentBatch}/reconcile', [PayrollPaymentController::class, 'reconcile'])->middleware('throttle:actions');

            // Ekspor Jurnal Akuntansi / General Ledger (Fase 4 lanjutan).
            // Pemetaan akun editable per-perusahaan + jurnal per run (on-demand, dijamin seimbang).
            Route::get('/gl-accounts', [PayrollGlController::class, 'accounts']);
            Route::put('/gl-accounts', [PayrollGlController::class, 'saveAccounts'])->middleware('throttle:actions');
            Route::post('/gl-accounts/reset', [PayrollGlController::class, 'resetAccounts'])->middleware('throttle:actions');
            Route::get('/runs/{payroll}/gl-preview', [PayrollGlController::class, 'preview']);
            Route::get('/runs/{payroll}/gl-export', [PayrollGlController::class, 'export'])->middleware('throttle:heavy');

            // Bukti Potong PPh 21 Formulir 1721-A1 + draf ekspor Coretax (on-demand, tanpa tabel).
            // NPWP penuh HANYA pada berkas PDF/CSV/JSON (izin manage + audit); JSON termasking.
            // Rute /export didaftarkan SEBELUM /{userId} agar tak tertangkap sbg parameter.
            Route::get('/tax/1721a1', [PayrollTaxController::class, 'index1721a1']);
            Route::get('/tax/1721a1/export', [PayrollTaxController::class, 'export1721a1'])->middleware('throttle:heavy');
            Route::get('/tax/1721a1/{userId}', [PayrollTaxController::class, 'show1721a1'])->whereNumber('userId');
            Route::get('/tax/1721a1/{userId}/pdf', [PayrollTaxController::class, 'pdf1721a1'])->whereNumber('userId')->middleware('throttle:heavy');
            // Ekspor e-Bupot XML TERVALIDASI XSD (Fase 6). Skema resmi DJP dipakai bila
            // terpasang (resmi="true"), jika tidak skema internal (resmi="false").
            // Gagal validasi ⇒ 422. NPWP penuh → gated manage + audit.
            Route::get('/tax/ebupot/schema', [PayrollTaxController::class, 'ebupotSchema']);
            Route::get('/tax/ebupot/export', [PayrollTaxController::class, 'exportEbupot'])->middleware('throttle:heavy');

            // Calculation-trace: jejak langkah perhitungan slip (transparansi & audit; read-only).
            Route::get('/payslips/{payslip}/calculation-trace', [PayrollTraceController::class, 'show']);

            // Slip gaji (sisi dashboard)
            Route::get('/payslips', [PayslipController::class, 'index']);
            Route::get('/payslips/{payslip}', [PayslipController::class, 'show']);
            Route::get('/payslips/{payslip}/pdf', [PayslipController::class, 'pdf'])->middleware('throttle:heavy');
        });

    // Super Admin / HRD / Admin routes — akses penuh
    Route::middleware(['auth:sanctum', 'throttle:api', 'role:hrd,admin,super_admin', 'company'])
        ->prefix('admin')
        ->group(function () {
            // Manajemen karyawan — HRD boleh lihat daftar, tapi ubah/buat/nonaktifkan
            // akun hanya admin & super_admin (cegah privilege escalation oleh HRD).
            Route::get('/users', [UserController::class, 'index']);
            Route::get('/users/supervisors', [UserController::class, 'supervisors']);

            // Master Divisi & Jabatan (Read-only untuk HRD, Admin, Super Admin)
            Route::get('/divisions', [DivisionController::class, 'index']);
            Route::get('/divisions/{division}', [DivisionController::class, 'show']);
            Route::get('/positions', [PositionController::class, 'index']);
            Route::get('/positions/{position}', [PositionController::class, 'show']);

            Route::middleware('role:admin,super_admin')->group(function () {
                Route::post('/users', [UserController::class, 'store'])->middleware('throttle:actions');
                Route::post('/users/bulk-import', [UserController::class, 'bulkImport'])->middleware('throttle:heavy');
                Route::put('/users/{user}', [UserController::class, 'update'])->middleware('throttle:actions');
                Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('throttle:actions');
                Route::patch('/users/{user}/activate', [UserController::class, 'activate'])->middleware('throttle:actions');
                Route::delete('/users/{user}', [UserController::class, 'destroy']);

                // Master Divisi (Mutasi)
                Route::post('/divisions', [DivisionController::class, 'store'])->middleware('throttle:actions');
                Route::put('/divisions/{division}', [DivisionController::class, 'update'])->middleware('throttle:actions');
                Route::delete('/divisions/{division}', [DivisionController::class, 'destroy'])->middleware('throttle:actions');

                // Master Jabatan (Mutasi)
                Route::post('/positions', [PositionController::class, 'store'])->middleware('throttle:actions');
                Route::put('/positions/{position}', [PositionController::class, 'update'])->middleware('throttle:actions');
                Route::delete('/positions/{position}', [PositionController::class, 'destroy'])->middleware('throttle:actions');

                // Manajemen Role & Hak Akses (Custom Role Engine)
                Route::get('/roles/modules', [RoleController::class, 'modules']);
                Route::get('/roles', [RoleController::class, 'index']);
                Route::post('/roles', [RoleController::class, 'store'])->middleware('throttle:actions');
                Route::get('/roles/{role}', [RoleController::class, 'show']);
                Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('throttle:actions');
                Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('throttle:actions');
            });

            // Pengarsipan Berkas Digital Karyawan (user_documents)
            Route::get('/users/{userId}/documents', [UserDocumentController::class, 'index']);
            Route::post('/users/{userId}/documents', [UserDocumentController::class, 'store'])->middleware('throttle:heavy');
            Route::get('/users/{userId}/documents/{documentId}/stream', [UserDocumentController::class, 'stream']);
            Route::get('/users/{userId}/documents/{documentId}/download', [UserDocumentController::class, 'download']);
            Route::delete('/users/{userId}/documents/{documentId}', [UserDocumentController::class, 'destroy']);
        });

    // Attendance — manajemen web dashboard
    Route::middleware(['auth:sanctum', 'throttle:api', 'company'])
        ->prefix('dashboard/attendance')
        ->group(function () {
            // Daftar kantor / cabang perusahaan (read-only untuk filter struk & fitur bersama: Finance, HRD, Admin, Super Admin)
            Route::get('/settings', [AttendanceController::class, 'listSettings'])
                ->middleware('role:finance,hrd,admin,super_admin');

            // Approval lembur karyawan (Multi-level: Step 1 SPV, Step 2 HRD — otorisasi per step diperiksa di AttendanceController)
            Route::get('/overtime-approvals', [AttendanceController::class, 'listOvertimeApprovals']);
            Route::post('/overtime-approvals/{id}/approve', [AttendanceController::class, 'approveOvertime']);
            Route::post('/overtime-approvals/{id}/reject', [AttendanceController::class, 'rejectOvertime']);

            // Approval izin/cuti karyawan (Multi-level: Step 1 SPV, Step 2 HRD — otorisasi per step diperiksa di AttendanceController)
            Route::get('/leaves', [AttendanceController::class, 'listLeaves']);
            Route::get('/leaves/{leave}/document', [AttendanceController::class, 'leaveDocument']);
            Route::post('/leaves/{id}/approve', [AttendanceController::class, 'approveLeave']);
            Route::post('/leaves/{id}/reject', [AttendanceController::class, 'rejectLeave']);

            // Fitur manajemen presensi khusus HRD, Admin, Super Admin
            Route::middleware('role:hrd,admin,super_admin')->group(function () {
                Route::get('/users', [AttendanceController::class, 'listUsers']);
                Route::post('/users/{id}/toggle-attendance', [AttendanceController::class, 'toggleAttendance']);
                Route::put('/users/{id}/mobile-policy', [AttendanceController::class, 'updateMobilePolicy']);
                Route::post('/users/{id}/toggle-wfh', [AttendanceController::class, 'toggleWfh']);
                Route::post('/users/{id}/toggle-radius', [AttendanceController::class, 'toggleRadius']);
                Route::post('/users/{id}/toggle-dinas-luar', [AttendanceController::class, 'toggleDinasLuar']);
                Route::post('/users/{id}/toggle-flexitime', [AttendanceController::class, 'toggleFlexitime']);
                Route::get('/attendances/{attendance}/photo', [AttendanceController::class, 'photo']);

                // Semua karyawan (tanpa pagination) untuk dropdown pengecualian libur
                Route::get('/users/all', [AttendanceController::class, 'listAllUsers']);

                // Dashboard hari ini & rekap
                Route::get('/today', [AttendanceController::class, 'today']);
                Route::get('/summary', [AttendanceController::class, 'monthlySummary']);
                Route::get('/report', [AttendanceController::class, 'reportAttendance']);
                Route::get('/report/export', [AttendanceController::class, 'exportReport'])->middleware('throttle:heavy');

                // Saldo / kuota cuti
                Route::get('/leave-balances', [AttendanceController::class, 'listLeaveBalances']);
                Route::post('/leave-balances', [AttendanceController::class, 'setLeaveBalance']);
                Route::get('/leave-balance-history', [AttendanceController::class, 'listLeaveBalanceHistories']);

                // Pengaturan jenis cuti per kantor (toggle on/off & kuota default)
                Route::get('/leave-types', [AttendanceController::class, 'listLeaveTypeSettings']);
                Route::put('/leave-types', [AttendanceController::class, 'updateLeaveTypeSettings']);

                // CRUD pengaturan kantor (lokasi & radius presensi) — mutasi hanya HRD / Admin / Super Admin
                Route::post('/settings', [AttendanceController::class, 'storeSettings']);
                Route::get('/settings/{attendanceSetting}', [AttendanceController::class, 'showSettings']);
                Route::match(['put', 'patch'], '/settings/{attendanceSetting}', [AttendanceController::class, 'updateSettings']);
                Route::delete('/settings/{attendanceSetting}', [AttendanceController::class, 'destroySettings']);
                Route::post('/settings/{id}/reset-leave-balances', [AttendanceController::class, 'resetOfficeLeaveBalances']);

            // Kalender libur nasional / cuti bersama perusahaan
            Route::get('/holidays/preview-national', [AttendanceController::class, 'previewNationalHolidays']);
            Route::post('/holidays/sync-national', [AttendanceController::class, 'syncNationalHolidays']);
            Route::get('/holidays', [AttendanceController::class, 'listHolidays']);
            Route::post('/holidays/collective-preview', [AttendanceController::class, 'previewCollectiveLeave']);
            Route::post('/holidays', [AttendanceController::class, 'storeHolidays']);
            Route::match(['put', 'patch'], '/holidays/{holiday}', [AttendanceController::class, 'updateHolidays']);
            Route::delete('/holidays/{holiday}', [AttendanceController::class, 'destroyHolidays']);

            // Rekap opt-in karyawan per cuti bersama (HRD)
            Route::get('/collective-leaves/{holiday}/detail', [AttendanceController::class, 'collectiveLeaveDetail']);


            // Approval pindah perangkat karyawan (device binding — cegah titip absen)
            Route::get('/device-changes', [AttendanceController::class, 'listDeviceChanges']);
            Route::post('/device-changes/{id}/approve', [AttendanceController::class, 'approveDeviceChange']);
            Route::post('/device-changes/{id}/reject', [AttendanceController::class, 'rejectDeviceChange']);

            // ── Manajemen Shift (Custom Scheduling) ──────────────────────────────
            // Kalender bulanan shift (definisikan SEBELUM /shifts/{id})
            Route::get('/shifts/calendar', [ShiftController::class, 'calendar']);
            // Roster harian: daftar shift aktif karyawan (definisikan SEBELUM /shifts/{id})
            Route::get('/shifts/roster', [ShiftController::class, 'roster']);

            // Template shift: daftar, buat, ubah, hapus
            Route::get('/shifts', [ShiftController::class, 'index']);
            // Daftar karyawan yang terkait sebuah shift (definisikan SEBELUM /shifts/{id})
            Route::get('/shifts/{id}/users', [ShiftController::class, 'shiftUsers']);
            Route::post('/shifts', [ShiftController::class, 'store']);
            Route::match(['put', 'patch'], '/shifts/{id}', [ShiftController::class, 'update']);
            Route::post('/shifts/{id}/toggle-active', [ShiftController::class, 'toggleActive']);
            Route::delete('/shifts/{id}', [ShiftController::class, 'destroy']);

            // Riwayat shift assignment seorang karyawan
            Route::get('/users/{id}/shift-history', [ShiftController::class, 'shiftHistory']);

            // Assign shift ke karyawan (atau null = kembali ke default kantor)
            Route::post('/assign-shift', [ShiftController::class, 'assignShift']);
            // Assign satu shift ke banyak karyawan sekaligus
            Route::post('/bulk-assign', [ShiftController::class, 'bulkAssign']);
            // Ubah / hapus assignment yang sudah ada
            Route::match(['put', 'patch'], '/assignments/{id}', [ShiftController::class, 'updateAssignment']);
            Route::delete('/assignments/{id}', [ShiftController::class, 'destroyAssignment']);

            // Preview jadwal efektif user pada tanggal tertentu (untuk UI HRD)
            Route::get('/effective-schedule', [ShiftController::class, 'effectiveSchedule']);

            // ── Pola Rotasi Shift (Shift Patterns / Recurring Rolling Cycles) ──
            Route::get('/shift-patterns', [ShiftController::class, 'patternIndex']);
            Route::post('/shift-patterns', [ShiftController::class, 'patternStore']);
            Route::get('/shift-patterns/{id}/users', [ShiftController::class, 'patternUsers']);
            Route::post('/shift-patterns/{id}/toggle-active', [ShiftController::class, 'patternToggleActive']);
            Route::get('/shift-patterns/{id}', [ShiftController::class, 'patternShow']);
            Route::match(['put', 'patch'], '/shift-patterns/{id}', [ShiftController::class, 'patternUpdate']);
            Route::delete('/shift-patterns/{id}', [ShiftController::class, 'patternDestroy']);
        });
    });

    // Presensi check-in/out — hanya karyawan yang attendance_enabled = true (gerbang WFH)
    Route::middleware(['auth:sanctum', 'throttle:api', 'company', 'attendance_access'])
        ->prefix('attendance')
        ->group(function () {
            Route::post('/check-in', [AttendanceController::class, 'checkIn'])->middleware('throttle:actions');
            Route::post('/check-out', [AttendanceController::class, 'checkOut'])->middleware('throttle:actions');
        });

    // Status, riwayat presensi & cuti/izin — semua karyawan, tanpa gerbang attendance_access.
    // Karyawan onsite (WFH OFF) tetap bisa baca status WFH/presensi & riwayat presensinya sendiri
    // (misalnya presensi kantor yang dicatat via hardware).
    Route::middleware(['auth:sanctum', 'throttle:api', 'company'])
        ->prefix('attendance')
        ->group(function () {
            Route::get('/status', [AttendanceController::class, 'checkStatus']);
            Route::get('/my', [AttendanceController::class, 'myAttendance']);
            Route::get('/{attendance}/photo', [AttendanceController::class, 'photo']);
            Route::get('/leave-balance', [AttendanceController::class, 'myLeaveBalance']);
            Route::get('/my-leaves', [AttendanceController::class, 'myLeaves']);
            Route::post('/leave-request', [AttendanceController::class, 'requestLeave']);
            // Preview hitungan hari efektif (skip libur/off-day/bentrok) — badge mobile
            Route::get('/leave-preview', [AttendanceController::class, 'leavePreview']);

            // Daftar overtime approval milik karyawan ini
            Route::get('/my-overtime', [AttendanceController::class, 'myOvertimeApprovals']);
            // Klaim / ajukan lembur dari mobile dengan alasan atau batalkan lembur
            Route::post('/{id}/claim-overtime', [AttendanceController::class, 'claimOvertime']);
            Route::post('/{id}/decline-overtime', [AttendanceController::class, 'declineOvertime']);
            // Simpan FCM token device (dipanggil saat login/buka app)
            Route::post('/fcm-token', [AttendanceController::class, 'registerFcmToken']);
            // Notifikasi shift baru (Flutter: banner di beranda)
            Route::get('/shift-updates', [ShiftController::class, 'shiftUpdates']);
            Route::post('/dismiss-shift-update', [ShiftController::class, 'dismissShiftUpdate']);
            // Kalender jadwal kerja bulanan karyawan
            Route::get('/my-schedule-calendar', [ShiftController::class, 'myScheduleCalendar']);

            // Cuti Bersama — karyawan lihat & pilih ikut/tidak
            Route::get('/collective-leaves', [AttendanceController::class, 'listCollectiveLeaves']);
            Route::post('/collective-leave/{holiday}/respond', [AttendanceController::class, 'respondCollectiveLeave']);
            Route::post('/dismiss-cancellation/{id}', [AttendanceController::class, 'dismissCancellation']);

            // ── Persetujuan Lembur & Cuti Mobile Khusus SPV / Atasan Langsung ──
            Route::prefix('spv')->group(function () {
                Route::get('/overtime-approvals', [AttendanceController::class, 'spvListOvertimeApprovals']);
                Route::get('/overtime-approvals/count', [AttendanceController::class, 'spvPendingOvertimeCount']);
                Route::post('/overtime-approvals/{id}/approve', [AttendanceController::class, 'approveOvertime'])->middleware('throttle:actions');
                Route::post('/overtime-approvals/{id}/reject', [AttendanceController::class, 'rejectOvertime'])->middleware('throttle:actions');

                Route::get('/leave-approvals', [AttendanceController::class, 'spvListLeaveApprovals']);
                Route::get('/leave-approvals/count', [AttendanceController::class, 'spvPendingLeaveCount']);
                Route::post('/leave-approvals/{id}/approve', [AttendanceController::class, 'approveLeave'])->middleware('throttle:actions');
                Route::post('/leave-approvals/{id}/reject', [AttendanceController::class, 'rejectLeave'])->middleware('throttle:actions');
            });
        });

    // ── Rekrutmen — Public (tanpa autentikasi) ───────────────────────────────
    // Portal karir publik: lihat lowongan & kirim lamaran
    Route::prefix('public')->group(function () {
        Route::get('/jobs', [PublicRecruitmentController::class, 'jobList']);
        Route::get('/jobs/{id}', [PublicRecruitmentController::class, 'jobDetail']);
        Route::post('/jobs/{id}/apply', [PublicRecruitmentController::class, 'apply']);
        Route::get('/postal-code/{code}', [PublicRecruitmentController::class, 'searchPostalCode']);
    });


    // ── Rekrutmen — HRD / Admin / Super Admin ────────────────────────────────
    Route::middleware(['auth:sanctum', 'throttle:api', 'role:hrd,admin,super_admin', 'company'])
        ->prefix('recruitment')
        ->group(function () {
            // Manajemen lowongan
            Route::get('/postings', [RecruitmentController::class, 'index']);
            Route::post('/postings', [RecruitmentController::class, 'store']);
            Route::get('/postings/{id}', [RecruitmentController::class, 'show']);
            Route::put('/postings/{id}', [RecruitmentController::class, 'update']);
            Route::delete('/postings/{id}', [RecruitmentController::class, 'destroy']);
            Route::patch('/postings/{id}/publish', [RecruitmentController::class, 'publish']);
            Route::patch('/postings/{id}/close', [RecruitmentController::class, 'close']);

            // Pelamar
            Route::get('/applications', [RecruitmentController::class, 'allApplications']);
            Route::get('/postings/{id}/applications', [RecruitmentController::class, 'applications']);
            Route::get('/applications/{id}', [RecruitmentController::class, 'applicationDetail']);
            Route::patch('/applications/{id}/status', [RecruitmentController::class, 'updateApplicationStatus']);
            Route::delete('/applications/{id}', [RecruitmentController::class, 'destroyApplication']);
            Route::get('/applications/{id}/resume', [RecruitmentController::class, 'downloadResume']);
        });
});


