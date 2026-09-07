<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncVersionController extends Controller
{
    /**
     * Mengembalikan snapshot timestamp / versi data terbaru per modul untuk sinkronisasi Smart Cache.
     * Request ini sangat ringan (< 2ms) karena hanya mengecek agregasi index (MAX) tanpa membaca isi baris data.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;
        $today = now('Asia/Jakarta')->toDateString();

        // 1. Presensi hari ini (check-in, check-out, perubahan status)
        $attendanceQuery = DB::table('attendances')->where('date', $today);
        if ($companyId) {
            $attendanceQuery->where('company_id', $companyId);
        }
        $attendanceVersion = $attendanceQuery->max('updated_at') ?? '0';

        // 2. Cuti & Izin
        $leavesQuery = DB::table('leave_requests');
        if ($companyId) {
            $leavesQuery->where('company_id', $companyId);
        }
        $leavesVersion = $leavesQuery->max('updated_at') ?? '0';

        // 3. Approval Lembur
        $overtimeQuery = DB::table('overtime_approvals');
        if ($companyId) {
            $overtimeQuery->where('company_id', $companyId);
        }
        $overtimeVersion = $overtimeQuery->max('updated_at') ?? '0';

        // 4. Reset Device
        $deviceQuery = DB::table('device_change_requests');
        if ($companyId) {
            $deviceQuery->where('company_id', $companyId);
        }
        $deviceVersion = $deviceQuery->max('updated_at') ?? '0';

        // 5. Struk Reimbursement
        $receiptsQuery = DB::table('receipts');
        if ($companyId) {
            $receiptsQuery->where('company_id', $companyId);
        }
        $receiptsVersion = $receiptsQuery->max('updated_at') ?? '0';

        // 6. Invoices
        $invoicesQuery = DB::table('invoices');
        if ($companyId) {
            $invoicesQuery->where('company_id', $companyId);
        }
        $invoicesVersion = $invoicesQuery->max('updated_at') ?? '0';

        // 7. Notifikasi user
        $notifVersion = DB::table('notifications')
            ->where('user_id', $user->id)
            ->max('created_at') ?? '0';

        return response()->json([
            'attendance'     => (string) $attendanceVersion,
            'leaves'         => (string) $leavesVersion,
            'overtime'       => (string) $overtimeVersion,
            'device_changes' => (string) $deviceVersion,
            'receipts'       => (string) $receiptsVersion,
            'invoices'       => (string) $invoicesVersion,
            'notifications'  => (string) $notifVersion,
            'synced_at'      => now('Asia/Jakarta')->toDateTimeString(),
        ]);
    }
}
