<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kasbon / pinjaman karyawan + cicilan otomatis (dipotong saat proses payroll).
 * Butuh izin modul Payroll level 'manage'.
 */
class EmployeeLoanController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola kasbon.'], 403);
    }

    /** GET /dashboard/payroll/loans */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $loans = EmployeeLoan::with(['user:id,name,employee_code', 'approvedBy:id,name'])
            ->where('company_id', $user->company_id)
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->query('user_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $loans]);
    }

    /** POST /dashboard/payroll/loans */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'user_id'            => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'title'              => 'nullable|string|max:150',
            'principal'          => 'required|numeric|min:1|max:99999999999',
            'installment_amount' => 'required|numeric|min:1|max:99999999999',
            'tenor_months'       => 'required|integer|min:1|max:120',
            'start_period_month' => 'required|integer|min:1|max:12',
            'start_period_year'  => 'required|integer|min:2020|max:2100',
            'notes'              => 'nullable|string|max:1000',
        ]);

        if ((float) $validated['installment_amount'] > (float) $validated['principal']) {
            return response()->json([
                'message' => 'Nominal cicilan tidak boleh melebihi pokok pinjaman.',
                'errors'  => ['installment_amount' => ['Cicilan melebihi pokok pinjaman.']],
            ], 422);
        }

        $loan = EmployeeLoan::create([
            'company_id'         => $companyId,
            'user_id'            => (int) $validated['user_id'],
            'title'              => $validated['title'] ?? null,
            'principal'          => $validated['principal'],
            'installment_amount' => $validated['installment_amount'],
            'tenor_months'       => (int) $validated['tenor_months'],
            'installments_paid'  => 0,
            'remaining_amount'   => $validated['principal'],
            'start_period_month' => (int) $validated['start_period_month'],
            'start_period_year'  => (int) $validated['start_period_year'],
            'status'             => EmployeeLoan::STATUS_PENDING,
            'notes'              => $validated['notes'] ?? null,
        ]);

        AuditLogger::log(
            action: 'LOAN_CREATED',
            description: "Membuat kasbon untuk user #{$loan->user_id} sebesar {$loan->principal}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'EmployeeLoan',
            entityId: $loan->id,
            newValues: $loan->toArray(),
        );

        return response()->json(['message' => 'Kasbon berhasil dibuat (menunggu persetujuan).', 'data' => $loan->load('user:id,name')], 201);
    }

    /** POST /dashboard/payroll/loans/{loan}/approve */
    public function approve(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $loan->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Kasbon tidak ditemukan.'], 404);
        }
        if ($loan->status !== EmployeeLoan::STATUS_PENDING) {
            return response()->json(['message' => 'Hanya kasbon berstatus menunggu yang dapat disetujui.'], 422);
        }

        $loan->update([
            'status'      => EmployeeLoan::STATUS_ACTIVE,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        AuditLogger::log(
            action: 'LOAN_APPROVED',
            description: "Menyetujui kasbon #{$loan->id} (user #{$loan->user_id})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'EmployeeLoan',
            entityId: $loan->id,
        );

        return response()->json(['message' => 'Kasbon disetujui & aktif untuk pemotongan cicilan.', 'data' => $loan]);
    }

    /** POST /dashboard/payroll/loans/{loan}/cancel */
    public function cancel(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $loan->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Kasbon tidak ditemukan.'], 404);
        }
        if (in_array($loan->status, [EmployeeLoan::STATUS_PAID, EmployeeLoan::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'Kasbon yang sudah lunas / dibatalkan tidak dapat diubah.'], 422);
        }

        $loan->update(['status' => EmployeeLoan::STATUS_CANCELLED]);

        AuditLogger::log(
            action: 'LOAN_CANCELLED',
            description: "Membatalkan kasbon #{$loan->id} (user #{$loan->user_id})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'EmployeeLoan',
            entityId: $loan->id,
        );

        return response()->json(['message' => 'Kasbon dibatalkan.', 'data' => $loan]);
    }
}
