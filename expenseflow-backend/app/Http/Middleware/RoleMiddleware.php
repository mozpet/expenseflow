<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  Daftar role yang diizinkan (contoh: finance,admin)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role === 'super_admin') {
            return $next($request);
        }

        // Cek apakah role user termasuk dalam daftar role yang diizinkan langsung
        if (in_array($user->role, $roles)) {
            return $next($request);
        }

        // Jika user memiliki custom role (role_id terisi), periksa izin berbasis modul
        if ($user->role_id && $this->checkCustomRolePermission($user, $request, $roles)) {
            return $next($request);
        }

        $allowed = implode(', ', $roles);

        return response()->json([
            'message' => "Akses ditolak. Role yang diizinkan: {$allowed}.",
        ], 403);
    }

    private function checkCustomRolePermission(User $user, Request $request, array $roles): bool
    {
        $method = $request->method();
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']);
        $level = $isWrite ? 'manage' : 'read';

        // 1. Coba pemetaan presisi berbasis Controller & Action
        $route = $request->route();
        $controller = $route ? $route->getController() : null;
        $action = $route ? $route->getActionMethod() : null;

        if ($controller) {
            $permission = $this->evaluateControllerPermission($user, $controller, $action, $level, $isWrite, $roles);
            if ($permission !== null) {
                return $permission;
            }
        }

        // 2. Pemetaan berbasis Path Matching berjenjang (Spesifik -> Umum)
        return $this->evaluatePathPermission($user, $request->path(), $level, $isWrite, $roles);
    }

    private function evaluateControllerPermission(
        User $user,
        object $controller,
        ?string $action,
        string $level,
        bool $isWrite,
        array $roles
    ): ?bool {
        if ($controller instanceof \App\Http\Controllers\API\NotificationController
            || $controller instanceof \App\Http\Controllers\API\SyncVersionController) {
            return true;
        }

        if ($controller instanceof \App\Http\Controllers\API\AttendanceController) {
            // Cuti / Leave
            if (in_array($action, [
                'listLeaves', 'leaveDocument', 'approveLeave', 'rejectLeave',
                'listLeaveBalances', 'setLeaveBalance', 'listLeaveBalanceHistories',
                'collectiveLeaveDetail',
            ])) {
                if (in_array($action, ['approveLeave', 'rejectLeave'])) {
                    return $user->hasPermission(Role::MODULE_LEAVE, 'spv') || $user->hasPermission(Role::MODULE_LEAVE, 'hrd');
                }
                return $user->hasPermission(Role::MODULE_LEAVE, $level);
            }

            // Lembur / Overtime
            if (in_array($action, ['listOvertimeApprovals', 'approveOvertime', 'rejectOvertime'])) {
                if (in_array($action, ['approveOvertime', 'rejectOvertime'])) {
                    return $user->hasPermission(Role::MODULE_OVERTIME, 'spv') || $user->hasPermission(Role::MODULE_OVERTIME, 'hrd');
                }
                return $user->hasPermission(Role::MODULE_OVERTIME, $level);
            }

            // Kalender Libur (bagian dari Shift & Penjadwalan atau Presensi Kehadiran)
            if (in_array($action, [
                'previewNationalHolidays', 'syncNationalHolidays', 'listHolidays',
                'previewCollectiveLeave', 'storeHolidays', 'updateHolidays', 'destroyHolidays',
            ])) {
                return $user->hasPermission(Role::MODULE_SHIFT, $level)
                    || $user->hasPermission(Role::MODULE_ATTENDANCE, $level);
            }

            // Pengaturan cabang / kantor
            if ($action === 'listSettings') {
                return $this->canAccessSharedBranchSettings($user, $isWrite);
            }

            if (in_array($action, ['storeSettings', 'showSettings', 'updateSettings', 'destroySettings', 'resetOfficeLeaveBalances'])) {
                if ($action === 'showSettings') {
                    return $this->canAccessSharedBranchSettings($user, false);
                }
                return $user->hasPermission(Role::MODULE_SETTINGS, 'manage')
                    || $user->hasPermission(Role::MODULE_ATTENDANCE, 'manage');
            }

            // Presensi Core (listUsers, listAllUsers, toggleAttendance, updateMobilePolicy, toggleWfh, dll.)
            return $user->hasPermission(Role::MODULE_ATTENDANCE, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\ShiftController) {
            return $user->hasPermission(Role::MODULE_SHIFT, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\UserController
            || $controller instanceof \App\Http\Controllers\API\UserDocumentController) {
            if (! in_array('hrd', $roles) && in_array('admin', $roles)) {
                return $user->hasPermission(Role::MODULE_USER, 'manage');
            }
            return $user->hasPermission(Role::MODULE_USER, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\RoleController) {
            return $user->hasPermission(Role::MODULE_ROLE_MANAGEMENT, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\ReceiptController) {
            return $user->hasPermission(Role::MODULE_RECEIPT, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\ExpenseReportController) {
            return $user->hasPermission(Role::MODULE_EXPENSE_REPORT, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\InvoiceController) {
            return $user->hasPermission(Role::MODULE_INVOICE, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\VendorController) {
            return $user->hasPermission(Role::MODULE_VENDOR, $level);
        }

        if ($controller instanceof \App\Http\Controllers\API\SettingsController) {
            if (! $isWrite) {
                return $user->hasPermission(Role::MODULE_SETTINGS, 'read')
                    || $user->hasPermission(Role::MODULE_RECEIPT, 'read');
            }

            return $user->hasPermission(Role::MODULE_SETTINGS, 'manage')
                || $user->hasPermission(Role::MODULE_RECEIPT, 'manage');
        }

        if ($controller instanceof \App\Http\Controllers\API\ActivityLogController) {
            return $user->hasPermission(Role::MODULE_AUDIT_LOG, 'read');
        }

        if ($controller instanceof \App\Http\Controllers\API\RecruitmentController) {
            return $user->hasPermission(Role::MODULE_USER, $level)
                || $user->hasPermission(Role::MODULE_ATTENDANCE, $level);
        }

        return null;
    }

    private function canAccessSharedBranchSettings(User $user, bool $isWrite): bool
    {
        if (! $isWrite) {
            return $user->hasPermission(Role::MODULE_SETTINGS, 'read')
                || $user->hasPermission(Role::MODULE_ATTENDANCE, 'read')
                || $user->hasPermission(Role::MODULE_RECEIPT, 'read')
                || $user->hasPermission(Role::MODULE_USER, 'read')
                || $user->hasPermission(Role::MODULE_SHIFT, 'read')
                || $user->hasPermission(Role::MODULE_INVOICE, 'read')
                || $user->hasPermission(Role::MODULE_EXPENSE_REPORT, 'read');
        }

        return $user->hasPermission(Role::MODULE_SETTINGS, 'manage')
            || $user->hasPermission(Role::MODULE_ATTENDANCE, 'manage');
    }

    private function evaluatePathPermission(
        User $user,
        string $path,
        string $level,
        bool $isWrite,
        array $roles
    ): bool {
        // 1. Shared endpoints
        if (! $isWrite && (str_contains($path, 'attendance/settings') || str_contains($path, 'offices'))) {
            return $this->canAccessSharedBranchSettings($user, false);
        }

        if (str_contains($path, 'notifications') || str_contains($path, 'sync-versions')) {
            return true;
        }

        // 2. Custom Role Management
        if (str_contains($path, 'admin/roles')) {
            return $user->hasPermission(Role::MODULE_ROLE_MANAGEMENT, $level);
        }

        // 3. Shift & Penjadwalan (WAJIB sebelum presensi dan sebelum generic users)
        // Menangani shifts/{id}/users, shift-patterns/{id}/users, users/{id}/shift-history
        if (
            str_contains($path, 'shifts')
            || str_contains($path, 'roster')
            || str_contains($path, 'shift-patterns')
            || str_contains($path, 'assign-shift')
            || str_contains($path, 'bulk-assign')
            || str_contains($path, 'assignments')
            || str_contains($path, 'effective-schedule')
            || str_contains($path, 'shift-history')
        ) {
            return $user->hasPermission(Role::MODULE_SHIFT, $level);
        }

        // Kalender Libur (Shift atau Presensi)
        if (str_contains($path, 'holidays')) {
            return $user->hasPermission(Role::MODULE_SHIFT, $level)
                || $user->hasPermission(Role::MODULE_ATTENDANCE, $level);
        }

        // 4. Cuti & Izin (WAJIB sebelum presensi)
        if (
            str_contains($path, 'leaves')
            || str_contains($path, 'leave-balances')
            || str_contains($path, 'leave-balance-history')
            || str_contains($path, 'collective-leaves')
        ) {
            return $user->hasPermission(Role::MODULE_LEAVE, $level);
        }

        // 5. Lembur (Overtime)
        if (str_contains($path, 'overtime')) {
            return $user->hasPermission(Role::MODULE_OVERTIME, $level);
        }

        // 6. Mutasi Pengaturan Kantor di attendance (POST, PUT, DELETE /settings)
        if ($isWrite && str_contains($path, 'attendance/settings')) {
            return $this->canAccessSharedBranchSettings($user, true);
        }

        // 7. Modul Presensi & Kehadiran (Attendance Core)
        // Menangani dashboard/attendance/users, dashboard/attendance/today, dll.
        // WAJIB SEBELUM admin/users agar /dashboard/attendance/users tidak tertabrak!
        if (str_contains($path, 'attendance')) {
            return $user->hasPermission(Role::MODULE_ATTENDANCE, $level);
        }

        // 8. Modul Data Karyawan (User Management)
        // Khusus admin/users atau users non-attendance
        if (str_contains($path, 'admin/users') || str_contains($path, 'users') || str_contains($path, 'karyawan')) {
            if (! in_array('hrd', $roles) && in_array('admin', $roles)) {
                return $user->hasPermission(Role::MODULE_USER, 'manage');
            }
            return $user->hasPermission(Role::MODULE_USER, $level);
        }

        // 9. Finance & Dokumen Keuangan
        if (str_contains($path, 'receipts')) {
            return $user->hasPermission(Role::MODULE_RECEIPT, $level);
        }

        if (str_contains($path, 'expense-reports')) {
            return $user->hasPermission(Role::MODULE_EXPENSE_REPORT, $level);
        }

        if (str_contains($path, 'invoices')) {
            return $user->hasPermission(Role::MODULE_INVOICE, $level);
        }

        if (str_contains($path, 'vendors')) {
            return $user->hasPermission(Role::MODULE_VENDOR, $level);
        }

        if (str_contains($path, 'settings')) {
            if (! $isWrite) {
                return $user->hasPermission(Role::MODULE_SETTINGS, 'read')
                    || $user->hasPermission(Role::MODULE_RECEIPT, 'read');
            }

            return $user->hasPermission(Role::MODULE_SETTINGS, 'manage')
                || $user->hasPermission(Role::MODULE_RECEIPT, 'manage');
        }

        // 10. Audit Log
        if (str_contains($path, 'activity-logs')) {
            return $user->hasPermission(Role::MODULE_AUDIT_LOG, 'read');
        }

        // 11. Recruitment
        if (str_contains($path, 'recruitment')) {
            return $user->hasPermission(Role::MODULE_USER, $level)
                || $user->hasPermission(Role::MODULE_ATTENDANCE, $level);
        }

        // 12. Fallback peran standar jika belum cocok dengan modul spesifik
        foreach ($roles as $role) {
            if ($role === 'admin' && $user->roleRelation?->slug === 'admin') {
                return true;
            }
            if ($role === 'hrd' && (
                $user->hasPermission(Role::MODULE_ATTENDANCE, $level) ||
                $user->hasPermission(Role::MODULE_USER, $level)
            )) {
                return true;
            }
            if ($role === 'finance' && (
                $user->hasPermission(Role::MODULE_RECEIPT, $level) ||
                $user->hasPermission(Role::MODULE_INVOICE, $level)
            )) {
                return true;
            }
        }

        return false;
    }
}
