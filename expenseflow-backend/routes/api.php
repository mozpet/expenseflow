<?php

use App\Http\Controllers\API\ActivityLogController;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\ReceiptController;
use App\Http\Controllers\API\SettingsController;
use App\Http\Controllers\API\ShiftController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\VendorController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Rate limiter untuk login: 5 attempt per menit per IP
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
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
        });

    // Finance / HRD / Admin / Super Admin routes — hanya web
    Route::middleware(['auth:sanctum', 'role:finance,hrd,admin,super_admin', 'company'])
        ->prefix('dashboard')
        ->group(function () {
            // Receipt approval — struk karyawan khusus FINANCE (HRD dikecualikan).
            // Dibungkus role tambahan tanpa 'hrd'; admin & super_admin tetap bisa.
            Route::middleware('role:finance,admin,super_admin')->group(function () {
                Route::get('/receipts', [ReceiptController::class, 'inbox']);
                Route::get('/receipts/all', [ReceiptController::class, 'dashboardReceipts']);
                Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
                Route::get('/receipts/{receipt}/image', [ReceiptController::class, 'image']);
                Route::post('/receipts/{receipt}/approve', [ReceiptController::class, 'approve']);
                Route::post('/receipts/{receipt}/reject', [ReceiptController::class, 'reject']);
            });

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

            // Notifikasi
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
            Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

            // Audit log (activity logs)
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);

            // Pengaturan threshold & batas klaim
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::match(['put', 'patch'], '/settings', [SettingsController::class, 'update']);
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
                Route::put('/users/{user}', [UserController::class, 'update']);
                Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate']);
                Route::patch('/users/{user}/activate', [UserController::class, 'activate']);
                Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
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

            // Dashboard hari ini & rekap
            Route::get('/today', [AttendanceController::class, 'today']);
            Route::get('/summary', [AttendanceController::class, 'monthlySummary']);
            Route::get('/report', [AttendanceController::class, 'reportAttendance']);
            Route::get('/report/export', [AttendanceController::class, 'exportReport']);

            // Saldo / kuota cuti
            Route::get('/leave-balances', [AttendanceController::class, 'listLeaveBalances']);
            Route::post('/leave-balances', [AttendanceController::class, 'setLeaveBalance']);

            // CRUD pengaturan kantor (lokasi & radius presensi)
            Route::get('/settings', [AttendanceController::class, 'listSettings']);
            Route::post('/settings', [AttendanceController::class, 'storeSettings']);
            Route::get('/settings/{attendanceSetting}', [AttendanceController::class, 'showSettings']);
            Route::match(['put', 'patch'], '/settings/{attendanceSetting}', [AttendanceController::class, 'updateSettings']);
            Route::delete('/settings/{attendanceSetting}', [AttendanceController::class, 'destroySettings']);

            // Kalender libur nasional / cuti bersama perusahaan
            Route::get('/holidays', [AttendanceController::class, 'listHolidays']);
            Route::post('/holidays', [AttendanceController::class, 'storeHolidays']);
            Route::match(['put', 'patch'], '/holidays/{holiday}', [AttendanceController::class, 'updateHolidays']);
            Route::delete('/holidays/{holiday}', [AttendanceController::class, 'destroyHolidays']);

            // Approval lembur karyawan (sistem auto-checkout & reminder)
            Route::get('/overtime-approvals', [AttendanceController::class, 'listOvertimeApprovals']);
            Route::post('/overtime-approvals/{id}/approve', [AttendanceController::class, 'approveOvertime']);
            Route::post('/overtime-approvals/{id}/reject', [AttendanceController::class, 'rejectOvertime']);

            // Approval pindah perangkat karyawan (device binding — cegah titip absen)
            Route::get('/device-changes', [AttendanceController::class, 'listDeviceChanges']);
            Route::post('/device-changes/{id}/approve', [AttendanceController::class, 'approveDeviceChange']);
            Route::post('/device-changes/{id}/reject', [AttendanceController::class, 'rejectDeviceChange']);

            // ── Manajemen Shift (Custom Scheduling) ──────────────────────────────
            // Roster harian: daftar shift aktif karyawan (definisikan SEBELUM /shifts/{id})
            Route::get('/shifts/roster', [ShiftController::class, 'roster']);

            // Template shift: daftar, buat, ubah, hapus
            Route::get('/shifts', [ShiftController::class, 'index']);
            Route::post('/shifts', [ShiftController::class, 'store']);
            Route::match(['put', 'patch'], '/shifts/{id}', [ShiftController::class, 'update']);
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
        });

    // Presensi check-in/out — hanya karyawan yang attendance_enabled = true (gerbang WFH)
    Route::middleware(['auth:sanctum', 'company', 'attendance_access'])
        ->prefix('attendance')
        ->group(function () {
            Route::post('/check-in', [AttendanceController::class, 'checkIn']);
            Route::post('/check-out', [AttendanceController::class, 'checkOut']);
            Route::get('/status', [AttendanceController::class, 'checkStatus']);
        });

    // Riwayat presensi & cuti/izin — semua karyawan, tanpa gerbang attendance_access.
    // Karyawan onsite (WFH OFF) tetap bisa lihat riwayat presensinya sendiri
    // (misalnya presensi kantor yang dicatat via hardware).
    Route::middleware(['auth:sanctum', 'company'])
        ->prefix('attendance')
        ->group(function () {
            Route::get('/my', [AttendanceController::class, 'myAttendance']);
            Route::get('/leave-balance', [AttendanceController::class, 'myLeaveBalance']);
            Route::get('/my-leaves', [AttendanceController::class, 'myLeaves']);
            Route::post('/leave-request', [AttendanceController::class, 'requestLeave']);
            // Kalender libur (read-only) — untuk tampilan kalender di mobile
            Route::get('/holidays', [AttendanceController::class, 'listHolidays']);
            // Daftar overtime approval milik karyawan ini
            Route::get('/my-overtime', [AttendanceController::class, 'myOvertimeApprovals']);
            // Simpan FCM token device (dipanggil saat login/buka app)
            Route::post('/fcm-token', [AttendanceController::class, 'registerFcmToken']);
        });
});
