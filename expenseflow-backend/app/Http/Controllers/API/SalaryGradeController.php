<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSalary;
use App\Models\Role;
use App\Models\SalaryGrade;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Golongan Upah (salary grades) — Struktur & Skala Upah, Fase 6.
 *
 * Tiap golongan menyimpan rentang upah minimum–maksimum (opsional titik tengah)
 * untuk satu jenjang jabatan. Rentang ini menjadi RAMBU saat menetapkan gaji
 * karyawan: EmployeeSalaryController menolak (422) nominal di luar rentang
 * golongan yang dipilih — lihat Permenaker 1/2017.
 *
 * Prinsip dipertahankan: gate `read`/`manage`; scoping `company_id` (lintas-company
 * → 404); kolom uang `decimal:2` → JSON string; mutasi ter-audit (CATEGORY_FINANCE).
 */
class SalaryGradeController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola golongan upah.'], 403);
    }

    /** GET /dashboard/payroll/salary-grades */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $grades = SalaryGrade::with('jobLevel:id,name,rank')
            ->where('company_id', $user->company_id)
            ->when($request->filled('job_level_id'), fn ($q) => $q->where('job_level_id', (int) $request->input('job_level_id')))
            ->orderBy('min_salary')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $grades]);
    }

    /** POST /dashboard/payroll/salary-grades */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;
        $validated = $request->validate($this->rules($companyId));

        if ($error = $this->rangeError($validated)) {
            return $error;
        }

        $grade = SalaryGrade::create($this->payload($companyId, $validated));

        AuditLogger::log(
            action: 'SALARY_GRADE_CREATED',
            description: "Membuat golongan upah: {$grade->name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'SalaryGrade',
            entityId: $grade->id,
            newValues: $grade->toArray(),
        );

        return response()->json(['message' => 'Golongan upah berhasil dibuat.', 'data' => $grade->load('jobLevel:id,name,rank')], 201);
    }

    /** PUT /dashboard/payroll/salary-grades/{salaryGrade} */
    public function update(Request $request, SalaryGrade $salaryGrade): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $salaryGrade->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Golongan upah tidak ditemukan.'], 404);
        }

        $companyId = (int) $user->company_id;
        $validated = $request->validate($this->rules($companyId, $salaryGrade->id));

        if ($error = $this->rangeError($validated)) {
            return $error;
        }

        $old = $salaryGrade->toArray();
        $salaryGrade->update($this->payload($companyId, $validated, $salaryGrade));

        AuditLogger::log(
            action: 'SALARY_GRADE_UPDATED',
            description: "Memperbarui golongan upah: {$salaryGrade->name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'SalaryGrade',
            entityId: $salaryGrade->id,
            oldValues: $old,
            newValues: $salaryGrade->toArray(),
        );

        return response()->json([
            'message' => 'Golongan upah berhasil diperbarui.',
            'data'    => $salaryGrade->load('jobLevel:id,name,rank'),
        ]);
    }

    /** DELETE /dashboard/payroll/salary-grades/{salaryGrade} */
    public function destroy(Request $request, SalaryGrade $salaryGrade): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $salaryGrade->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Golongan upah tidak ditemukan.'], 404);
        }

        // Golongan yang melekat pada riwayat gaji tidak dihapus (jejak historis).
        if (EmployeeSalary::where('salary_grade_id', $salaryGrade->id)->exists()) {
            return response()->json([
                'message' => 'Golongan tidak dapat dihapus karena melekat pada riwayat gaji karyawan. Nonaktifkan saja.',
            ], 422);
        }

        $name = $salaryGrade->name;
        $id = $salaryGrade->id;
        $salaryGrade->delete();

        AuditLogger::log(
            action: 'SALARY_GRADE_DELETED',
            description: "Menghapus golongan upah: {$name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'SalaryGrade',
            entityId: $id,
        );

        return response()->json(['message' => "Golongan upah '{$name}' berhasil dihapus."]);
    }

    /** Rentang harus koheren: min ≤ mid ≤ max. */
    private function rangeError(array $v): ?JsonResponse
    {
        $min = (float) $v['min_salary'];
        $max = (float) $v['max_salary'];
        $mid = isset($v['mid_salary']) && $v['mid_salary'] !== null ? (float) $v['mid_salary'] : null;

        if ($max < $min) {
            return response()->json([
                'message' => 'Upah maksimum tidak boleh lebih kecil dari upah minimum.',
                'errors'  => ['max_salary' => ['Upah maksimum tidak boleh lebih kecil dari upah minimum.']],
            ], 422);
        }
        if ($mid !== null && ($mid < $min || $mid > $max)) {
            return response()->json([
                'message' => 'Titik tengah harus berada di antara upah minimum dan maksimum.',
                'errors'  => ['mid_salary' => ['Titik tengah harus berada di antara upah minimum dan maksimum.']],
            ], 422);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(int $companyId, array $v, ?SalaryGrade $existing = null): array
    {
        return [
            'company_id'   => $companyId,
            'job_level_id' => $v['job_level_id'] ?? null,
            'name'         => trim($v['name']),
            'code'         => isset($v['code']) ? strtoupper(trim($v['code'])) : null,
            'min_salary'   => round((float) $v['min_salary'], 2),
            'mid_salary'   => isset($v['mid_salary']) && $v['mid_salary'] !== null ? round((float) $v['mid_salary'], 2) : null,
            'max_salary'   => round((float) $v['max_salary'], 2),
            'currency'     => strtoupper($v['currency'] ?? ($existing->currency ?? 'IDR')),
            'description'  => $v['description'] ?? null,
            'is_active'    => $v['is_active'] ?? ($existing->is_active ?? true),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('salary_grades', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('salary_grades', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'job_level_id' => [
                'nullable', 'integer',
                Rule::exists('job_levels', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'min_salary'  => 'required|numeric|min:0|max:999999999999',
            'mid_salary'  => 'nullable|numeric|min:0|max:999999999999',
            'max_salary'  => 'required|numeric|min:0|max:999999999999',
            'currency'    => 'nullable|string|size:3|alpha',
            'description' => 'nullable|string|max:2000',
            'is_active'   => 'boolean',
        ];
    }
}
