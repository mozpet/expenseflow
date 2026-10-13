<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\PayrollGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Grup Payroll (grouping + scoping MVP) — CRUD master grup per perusahaan.
 *
 * Keanggotaan karyawan disimpan di `users.payroll_group_id`; batch payroll
 * (`payrolls.payroll_group_id`) opsional membatasi kalkulasi hanya ke anggota grup.
 *
 * Prinsip dipertahankan: gate `read` untuk baca & `manage` untuk tulis; scoping
 * `company_id` (lintas-company → 404 sbg fallback; middleware `company` menutup lebih dulu);
 * semua mutasi ter-audit (AuditLogger, CATEGORY_FINANCE).
 */
class PayrollGroupController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola grup payroll.'], 403);
    }

    /** GET /dashboard/payroll/groups — daftar grup + jumlah anggota. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $groups = PayrollGroup::where('company_id', $user->company_id)
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    /** POST /dashboard/payroll/groups */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'name'        => [
                'required', 'string', 'max:120',
                Rule::unique('payroll_groups', 'name')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'code'        => [
                'nullable', 'string', 'max:20',
                Rule::unique('payroll_groups', 'code')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'description' => 'nullable|string|max:2000',
            // Pengaturan tanggal (2026-10-12). 0 = akhir bulan, 1-31 = tanggal
            // tetap (di-clamp ke panjang bulan oleh PayrollPeriodResolver),
            // tidak dikirim/NULL = belum diatur → bulan kalender penuh.
            'cutoff_day'           => 'nullable|integer|min:0|max:31',
            'payment_day'          => 'nullable|integer|min:0|max:31',
            'payment_month_offset' => 'nullable|integer|min:0|max:1',
            'is_active'   => 'boolean',
        ]);

        $group = PayrollGroup::create([
            'company_id'  => $companyId,
            'name'        => trim($validated['name']),
            'code'        => isset($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'description' => $validated['description'] ?? null,
            'cutoff_day'  => $validated['cutoff_day'] ?? null,
            'payment_day' => $validated['payment_day'] ?? null,
            'payment_month_offset' => (int) ($validated['payment_month_offset'] ?? 0),
            'is_active'   => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log(
            action: 'PAYROLL_GROUP_CREATED',
            description: "Membuat grup payroll: {$group->name}" . ($group->code ? " ({$group->code})" : ''),
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'PayrollGroup',
            entityId: $group->id,
            newValues: $group->toArray(),
        );

        return response()->json(['message' => 'Grup payroll berhasil dibuat.', 'data' => $group], 201);
    }

    /** PUT /dashboard/payroll/groups/{group} */
    public function update(Request $request, PayrollGroup $group): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $group->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Grup payroll tidak ditemukan.'], 404);
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'name'        => [
                'required', 'string', 'max:120',
                Rule::unique('payroll_groups', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($group->id),
            ],
            'code'        => [
                'nullable', 'string', 'max:20',
                Rule::unique('payroll_groups', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($group->id),
            ],
            'description' => 'nullable|string|max:2000',
            // Sama dengan store(): 0 = akhir bulan, 1-31 = tanggal tetap.
            'cutoff_day'           => 'nullable|integer|min:0|max:31',
            'payment_day'          => 'nullable|integer|min:0|max:31',
            'payment_month_offset' => 'nullable|integer|min:0|max:1',
            'is_active'   => 'boolean',
        ]);

        $old = $group->toArray();

        // SENGAJA memakai array_key_exists, bukan `?? null` seperti field lain di
        // method ini: frontend grup payroll belum mengenal ketiga kolom tanggal
        // (lihat doc/kekurangnAttendance.md #27). Kalau pakai `?? null`, setiap
        // penyuntingan nama/deskripsi grup dari form lama akan DIAM-DIAM
        // menghapus pengaturan cut-off HRD dan menggeser jendela presensi
        // seluruh batch berikutnya. Key yang tidak dikirim = tidak diubah;
        // mengirim null secara eksplisit tetap bisa mengosongkan.
        $dates = [];
        foreach (['cutoff_day', 'payment_day', 'payment_month_offset'] as $field) {
            if (array_key_exists($field, $validated)) {
                $dates[$field] = $validated[$field];
            }
        }
        if (array_key_exists('payment_month_offset', $dates)) {
            $dates['payment_month_offset'] = (int) ($dates['payment_month_offset'] ?? 0);
        }

        $group->update(array_merge([
            'name'        => trim($validated['name']),
            'code'        => isset($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? $group->is_active,
        ], $dates));

        AuditLogger::log(
            action: 'PAYROLL_GROUP_UPDATED',
            description: "Memperbarui grup payroll: {$group->name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'PayrollGroup',
            entityId: $group->id,
            oldValues: $old,
            newValues: $group->toArray(),
        );

        return response()->json(['message' => 'Grup payroll berhasil diperbarui.', 'data' => $group]);
    }

    /** DELETE /dashboard/payroll/groups/{group} */
    public function destroy(Request $request, PayrollGroup $group): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $group->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Grup payroll tidak ditemukan.'], 404);
        }

        // Jaga integritas: cegah hapus grup yang masih beranggota atau pernah dipakai batch.
        // (FK nullOnDelete membuat hapus "aman" di DB, namun akan menghapus jejak scope
        //  batch historis — lebih baik nonaktifkan grup daripada menghapusnya.)
        if (User::where('payroll_group_id', $group->id)->exists()) {
            return response()->json([
                'message' => 'Grup tidak dapat dihapus karena masih memiliki anggota. Pindahkan anggota atau nonaktifkan grup.',
            ], 422);
        }
        if (Payroll::where('payroll_group_id', $group->id)->exists()) {
            return response()->json([
                'message' => 'Grup tidak dapat dihapus karena pernah dipakai pada batch payroll. Nonaktifkan grup saja.',
            ], 422);
        }

        $name = $group->name;
        $id = $group->id;
        $group->delete();

        AuditLogger::log(
            action: 'PAYROLL_GROUP_DELETED',
            description: "Menghapus grup payroll: {$name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'PayrollGroup',
            entityId: $id,
        );

        return response()->json(['message' => "Grup payroll '{$name}' berhasil dihapus."]);
    }
}
