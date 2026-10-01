<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\PayrollAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Penyesuaian gaji & koreksi retroaktif (Fase 3, spec §6.C & §10.F).
 *
 * Alur maker-checker: pending → approved (oleh orang berbeda) → applied (otomatis saat
 * batch dihitung) — atau voided. Tidak ada penghapusan fisik (audit trail): pembatalan
 * hanya lewat `void` (butuh alasan). Penyesuaian yang sudah terpakai di batch approved/paid
 * TIDAK dapat di-void (terkunci). Butuh izin modul Payroll ('read' baca, 'manage' ubah).
 */
class PayrollAdjustmentController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola penyesuaian payroll.'], 403);
    }

    /** GET /dashboard/payroll/adjustments */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $adjustments = PayrollAdjustment::with(['user:id,name,employee_code', 'createdBy:id,name', 'approvedBy:id,name'])
            ->where('company_id', $user->company_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->query('user_id')))
            ->when($request->filled('payroll_id'), fn ($q) => $q->where('payroll_id', (int) $request->query('payroll_id')))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $adjustments]);
    }

    /** POST /dashboard/payroll/adjustments */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'user_id'                => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'type'                   => ['required', Rule::in([PayrollAdjustment::TYPE_EARNING, PayrollAdjustment::TYPE_DEDUCTION])],
            'name'                   => 'required|string|max:150',
            'amount'                 => 'required|numeric|min:0.01|max:99999999999',
            'is_taxable'             => 'boolean',
            'reason'                 => 'nullable|string|max:1000',
            'source_document_path'   => 'nullable|string|max:255',
            'retroactive_payroll_id' => [
                'nullable', 'integer',
                Rule::exists('payrolls', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ]);

        $adjustment = PayrollAdjustment::create([
            'company_id'             => $companyId,
            'user_id'                => (int) $validated['user_id'],
            'type'                   => $validated['type'],
            'name'                   => $validated['name'],
            'amount'                 => $validated['amount'],
            // is_taxable hanya bermakna untuk earning; deduction dipaksa false.
            'is_taxable'             => $validated['type'] === PayrollAdjustment::TYPE_EARNING
                ? (bool) ($validated['is_taxable'] ?? true)
                : false,
            'reason'                 => $validated['reason'] ?? null,
            'source_document_path'   => $validated['source_document_path'] ?? null,
            'retroactive_payroll_id' => $validated['retroactive_payroll_id'] ?? null,
            'status'                 => PayrollAdjustment::STATUS_PENDING,
            'created_by'             => $user->id,
        ]);

        AuditLogger::log(
            action: 'PAYROLL_ADJUSTMENT_CREATED',
            description: "Membuat penyesuaian {$adjustment->type} '{$adjustment->name}' untuk user #{$adjustment->user_id} sebesar {$adjustment->amount}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'PayrollAdjustment',
            entityId: $adjustment->id,
            newValues: $adjustment->toArray(),
        );

        PayrollAuditLogger::log('PAYROLL_ADJUSTMENT_CREATED', [
            'company_id'  => $companyId,
            'entity_type' => 'PayrollAdjustment',
            'entity_id'   => (int) $adjustment->id,
            'after'       => [
                'user_id' => (int) $adjustment->user_id,
                'type'    => $adjustment->type,
                'name'    => $adjustment->name,
                'amount'  => (string) $adjustment->amount,
                'status'  => $adjustment->status,
            ],
        ]);

        return response()->json([
            'message' => 'Penyesuaian dibuat (menunggu persetujuan).',
            'data'    => $adjustment->load('user:id,name'),
        ], 201);
    }

    /** POST /dashboard/payroll/adjustments/{adjustment}/approve */
    public function approve(Request $request, PayrollAdjustment $adjustment): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $adjustment->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Penyesuaian tidak ditemukan.'], 404);
        }
        if ($adjustment->status !== PayrollAdjustment::STATUS_PENDING) {
            return response()->json(['message' => 'Hanya penyesuaian berstatus menunggu yang dapat disetujui.'], 422);
        }
        // Maker-checker: penyetuju tidak boleh pembuat penyesuaian ini.
        if ((int) $adjustment->created_by === (int) $user->id) {
            return response()->json([
                'message' => 'Anda tidak dapat menyetujui penyesuaian yang Anda buat sendiri (maker-checker).',
            ], 403);
        }

        $adjustment->update([
            'status'      => PayrollAdjustment::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        AuditLogger::log(
            action: 'PAYROLL_ADJUSTMENT_APPROVED',
            description: "Menyetujui penyesuaian #{$adjustment->id} (user #{$adjustment->user_id})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'PayrollAdjustment',
            entityId: $adjustment->id,
        );

        PayrollAuditLogger::log('PAYROLL_ADJUSTMENT_APPROVED', [
            'company_id'  => (int) $adjustment->company_id,
            'entity_type' => 'PayrollAdjustment',
            'entity_id'   => (int) $adjustment->id,
            'after'       => ['status' => $adjustment->status, 'approved_by' => (int) $adjustment->approved_by],
        ]);

        return response()->json([
            'message' => 'Penyesuaian disetujui & siap diterapkan pada perhitungan payroll.',
            'data'    => $adjustment,
        ]);
    }

    /** POST /dashboard/payroll/adjustments/{adjustment}/void */
    public function void(Request $request, PayrollAdjustment $adjustment): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $adjustment->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Penyesuaian tidak ditemukan.'], 404);
        }
        if ($adjustment->status === PayrollAdjustment::STATUS_VOIDED) {
            return response()->json(['message' => 'Penyesuaian sudah dibatalkan.'], 422);
        }

        // Terkunci: bila sudah terpakai pada batch yang disetujui/dibayar.
        if ($adjustment->payroll_id) {
            $batch = Payroll::find($adjustment->payroll_id);
            if ($batch && in_array($batch->status, [Payroll::STATUS_APPROVED, Payroll::STATUS_PAID], true)) {
                return response()->json([
                    'message' => 'Penyesuaian terkunci: sudah diterapkan pada batch payroll yang telah disetujui/dibayar.',
                ], 422);
            }
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $adjustment->update([
            'status'      => PayrollAdjustment::STATUS_VOIDED,
            'voided_by'   => $user->id,
            'voided_at'   => now(),
            'void_reason' => $validated['reason'],
            // Lepas dari batch (bila menempel di batch belum-final) agar recalc menjatuhkannya.
            'payroll_id'  => null,
        ]);

        AuditLogger::log(
            action: 'PAYROLL_ADJUSTMENT_VOIDED',
            description: "Membatalkan penyesuaian #{$adjustment->id} (user #{$adjustment->user_id}): {$validated['reason']}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'PayrollAdjustment',
            entityId: $adjustment->id,
        );

        PayrollAuditLogger::log('PAYROLL_ADJUSTMENT_VOIDED', [
            'company_id'  => (int) $adjustment->company_id,
            'entity_type' => 'PayrollAdjustment',
            'entity_id'   => (int) $adjustment->id,
            'notes'       => $validated['reason'],
            'after'       => ['status' => $adjustment->status, 'voided_by' => (int) $adjustment->voided_by],
        ]);

        return response()->json(['message' => 'Penyesuaian dibatalkan.', 'data' => $adjustment]);
    }
}
