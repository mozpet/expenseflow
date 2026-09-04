<?php

use App\Http\Controllers\API\ActivityLogController;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\PublicRecruitmentController;
use App\Http\Controllers\API\ReceiptController;
use App\Http\Controllers\API\RecruitmentController;
use App\Http\Controllers\API\SettingsController;
use App\Http\Controllers\API\ShiftController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\VendorController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Rate limiter untuk login: dua batasan jalan serentak.
//   - Per akun: 5 percobaan per menit (dibedakan per email)  → penjaga anti brute-force utama
//   - Per IP  : 120 percobaan per menit (sangat longgar untuk kantor NAT & CGNAT seluler)
// Request diblokir (429) jika SALAH SATU batasan terlampaui.
// Response custom menyertakan retry_after (detik) agar web & mobile bisa
// menampilkan waktu tunggu dengan andal.
//
// PENGAMAN: nilai `retry_after` dari header DICLAMP ke `$window` detik.
// Rate limiter seharusnya mengembalikan sisa window (<=60s), tetapi bila
// cache/timer korup (pernah terjadi: mengembalikan ~74.000 detik / 20 jam),
// clamp memastikan user tidak dikunci login lebih dari durasi window itu.
// Batas percobaan tetap berlaku penuh (5/menit email, 120/menit IP).
$loginErrorResponse = function (Request $request, array $headers, int $window) {
    $raw = (int) ($headers['Retry-After'] ?? 0);
    $seconds = max(1, min($raw > 0 ? $raw : $window, $window));
    $minutes = max(1, (int) ceil($seconds / 60));

    $clampedHeaders = $headers;
    $clampedHeaders['Retry-After'] = (string) $seconds;

    return response()->json([
        'message'     => "Terlalu banyak percobaan login. Coba lagi dalam {$minutes} menit ({$seconds} detik).",
        'retry_after' => $seconds,
        'rate_limit'  => true,
    ], 429, $clampedHeaders);
};

RateLimiter::for('login', function (Request $request) use ($loginErrorResponse) {
    $email = (string) $request->input('email'); // fallback aman ke string kosong

    return [
        Limit::perMinute(5)
            ->by($email)
            ->response(fn (Request $req, array $h) => $loginErrorResponse($req, $h, 60)),
        Limit::perMinute(120)
            ->by($request->ip())
            ->response(fn (Request $req, array $h) => $loginErrorResponse($req, $h, 60)),
    ];
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

    // Forgot Password OTP flow — public (rate limited)
    Route::prefix('auth/forgot-password')->group(function () {
        Route::post('/send-otp', [ForgotPasswordController::class, 'sendOtp'])
            ->middleware('throttle:5,10'); // Max 5 request / 10 menit per IP
        Route::post('/verify-otp', [ForgotPasswordController::class, 'verifyOtp'])
            ->middleware('throttle:10,5'); // Max 10 verifikasi / 5 menit per IP
        Route::post('/reset', [ForgotPasswordController::class, 'resetPassword'])
            ->middleware('throttle:5,10');
    });

    // Auth — authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Receipt mobile — semua role boleh scan & submit struk via mobile
    Route::middleware(['auth:sanctum', 'company'])
        ->prefix('employee')
        ->group(function () {
            Route::middleware('receipt_access')->group(function () {
                Route::post('/receipts', [ReceiptController::class, 'store']);
                Route::get('/receipts', [ReceiptController::class, 'myReceipts']);
                Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
                Route::patch('/receipts/{receipt}/claim', [ReceiptController::class, 'updateClaim']);
                Route::post('/receipts/{receipt}/submit', [ReceiptController::class, 'submit']);
                Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy']);
            });

            // Jadwal shift karyawan
            Route::get('/my-schedule', [ShiftController::class, 'mySchedule']);
        });

    // Finance / HRD / Admin / Super Admin routes — hanya web
    Route::middleware(['auth:sanctum', 'role:finance,hrd,admin,super_admin', 'company'])
        ->prefix('dashboard')
        ->group(function () {
            // ─── Fitur Finance (Khusus Finance, Admin, Super Admin — HRD Dikecualikan) ───
            Route::middleware('role:finance,admin,super_admin')->group(function () {
                // Receipt approval & disbursement
                Route::get('/receipts', [ReceiptController::class, 'inbox']);
                Route::get('/receipts/all', [ReceiptController::class, 'dashboardReceipts']);
                Route::post('/receipts/bulk-approve', [ReceiptController::class, 'bulkApprove']);
                Route::post('/receipts/bulk-pay', [ReceiptController::class, 'bulkDisburse']);
                Route::get('/receipts/export-disbursement', [ReceiptController::class, 'exportDisbursement']);
                Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
                Route::get('/receipts/{receipt}/image', [ReceiptController::class, 'image']);
                Route::post('/receipts/{receipt}/approve', [ReceiptController::class, 'approve']);
                Route::post('/receipts/{receipt}/reject', [ReceiptController::class, 'reject']);
                Route::post('/receipts/{receipt}/pay', [ReceiptController::class, 'disburse']);

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
            });

            // ─── Fitur Bersama (Finance, HRD, Admin, Super Admin) ───────────────
            // Notifikasi
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
            Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

            // Audit log (activity logs)
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        });

    // Super Admin / HRD / Admin routes — akses penuh
    Route::middleware(['auth:sanctum', 'role:hrd,admin,super_admin', 'company'])
        ->prefix('admin')
        ->group(function () {
            // Manajemen karyawan — HRD boleh lihat daftar, tapi ubah/buat/nonaktifkan
            // akun hanya admin & super_admin (cegah privilege escalation oleh HRD).
            Route::get('/users', [UserController::class, 'index']);
            Route::middleware('role:admin,super_admin')->group(function () {
                Route::post('/users', [UserController::class, 'store']);
                Route::post('/users/bulk-import', [UserController::class, 'bulkImport']);
                Route::put('/users/{user}', [UserController::class, 'update']);
                Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate']);
                Route::patch('/users/{user}/activate', [UserController::class, 'activate']);
                Route::delete('/users/{user}', [UserController::class, 'destroy']);
            });
        });

    // Attendance — manajemen oleh HRD / Admin / Super Admin (web dashboard)
    Route::middleware(['auth:sanctum', 'role:hrd,admin,super_admin', 'company'])
        ->prefix('dashboard/attendance')
        ->group(function () {
            Route::get('/users', [AttendanceController::class, 'listUsers']);
            Route::post('/users/{id}/toggle-wfh', [AttendanceController::class, 'toggleWfh']);
            Route::post('/users/{id}/toggle-radius', [AttendanceController::class, 'toggleRadius']);
            Route::get('/leaves', [AttendanceController::class, 'listLeaves']);
            Route::get('/leaves/{leave}/document', [AttendanceController::class, 'leaveDocument']);
            Route::post('/leaves/{id}/approve', [AttendanceController::class, 'approveLeave']);
            Route::post('/leaves/{id}/reject', [AttendanceController::class, 'rejectLeave']);

            // Semua karyawan (tanpa pagination) untuk dropdown pengecualian libur
            Route::get('/users/all', [AttendanceController::class, 'listAllUsers']);

            // Dashboard hari ini & rekap
            Route::get('/today', [AttendanceController::class, 'today']);
            Route::get('/summary', [AttendanceController::class, 'monthlySummary']);
            Route::get('/report', [AttendanceController::class, 'reportAttendance']);
            Route::get('/report/export', [AttendanceController::class, 'exportReport']);

            // Saldo / kuota cuti
            Route::get('/leave-balances', [AttendanceController::class, 'listLeaveBalances']);
            Route::post('/leave-balances', [AttendanceController::class, 'setLeaveBalance']);
            Route::get('/leave-balance-history', [AttendanceController::class, 'listLeaveBalanceHistories']);

            // CRUD pengaturan kantor (lokasi & radius presensi)
            Route::get('/settings', [AttendanceController::class, 'listSettings']);
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

            // Approval lembur karyawan (sistem auto-checkout & reminder)
            Route::get('/overtime-approvals', [AttendanceController::class, 'listOvertimeApprovals']);
            Route::post('/overtime-approvals/{id}/approve', [AttendanceController::class, 'approveOvertime']);
            Route::post('/overtime-approvals/{id}/reject', [AttendanceController::class, 'rejectOvertime']);

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
            Route::get('/shift-patterns/{id}', [ShiftController::class, 'patternShow']);
            Route::match(['put', 'patch'], '/shift-patterns/{id}', [ShiftController::class, 'patternUpdate']);
            Route::delete('/shift-patterns/{id}', [ShiftController::class, 'patternDestroy']);
        });

    // Presensi check-in/out — hanya karyawan yang attendance_enabled = true (gerbang WFH)
    Route::middleware(['auth:sanctum', 'company', 'attendance_access'])
        ->prefix('attendance')
        ->group(function () {
            Route::post('/check-in', [AttendanceController::class, 'checkIn']);
            Route::post('/check-out', [AttendanceController::class, 'checkOut']);
            Route::post('/sync-offline', [AttendanceController::class, 'syncOffline']);
        });

    // Status, riwayat presensi & cuti/izin — semua karyawan, tanpa gerbang attendance_access.
    // Karyawan onsite (WFH OFF) tetap bisa baca status WFH/presensi & riwayat presensinya sendiri
    // (misalnya presensi kantor yang dicatat via hardware).
    Route::middleware(['auth:sanctum', 'company'])
        ->prefix('attendance')
        ->group(function () {
            Route::get('/status', [AttendanceController::class, 'checkStatus']);
            Route::get('/my', [AttendanceController::class, 'myAttendance']);
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
    Route::middleware(['auth:sanctum', 'role:hrd,admin,super_admin', 'company'])
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


