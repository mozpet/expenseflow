<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSalary;
use App\Models\JobLevel;
use App\Models\Role;
use App\Models\SalaryGrade;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jenjang Jabatan (job levels) — master Struktur & Skala Upah, Fase 6.
 *
 * Jenjang menyusun hierarki jabatan (Staf → Supervisor → Manajer → …) yang
 * menjadi induk dari golongan upah (`salary_grades`). Permenaker 1/2017
 * mewajibkan pengusaha menyusun struktur & skala upah; modul ini menyediakan
 * masternya, sedangkan penerapannya melekat pada baris gaji karyawan.
 *
 * Prinsip dipertahankan: gate `read` untuk baca & `manage` untuk tulis; scoping
 * `company_id` (lintas-company → 404); seluruh mutasi ter-audit (CATEGORY_FINANCE).
 */
class JobLevelController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola jenjang jabatan.'], 403);
    }

    /** GET /dashboard/payroll/job-levels */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $levels = JobLevel::where('company_id', $user->company_id)
            ->withCount('grades')
            ->orderBy('rank')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $levels]);
    }

    /** POST /dashboard/payroll/job-levels */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;
        $validated = $request->validate($this->rules($companyId));

        $level = JobLevel::create([
            'company_id'  => $companyId,
            'name'        => trim($validated['name']),
            'code'        => isset($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'rank'        => (int) ($validated['rank'] ?? 1),
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log(
            action: 'JOB_LEVEL_CREATED',
            description: "Membuat jenjang jabatan: {$level->name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'JobLevel',
            entityId: $level->id,
            newValues: $level->toArray(),
        );

        return response()->json(['message' => 'Jenjang jabatan berhasil dibuat.', 'data' => $level], 201);
    }

    /** PUT /dashboard/payroll/job-levels/{jobLevel} */
    public function update(Request $request, JobLevel $jobLevel): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $jobLevel->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Jenjang jabatan tidak ditemukan.'], 404);
        }

        $companyId = (int) $user->company_id;
        $validated = $request->validate($this->rules($companyId, $jobLevel->id));

        $old = $jobLevel->toArray();
        $jobLevel->update([
            'name'        => trim($validated['name']),
            'code'        => isset($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'rank'        => (int) ($validated['rank'] ?? $jobLevel->rank),
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? $jobLevel->is_active,
        ]);

        AuditLogger::log(
            action: 'JOB_LEVEL_UPDATED',
            description: "Memperbarui jenjang jabatan: {$jobLevel->name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'JobLevel',
            entityId: $jobLevel->id,
            oldValues: $old,
            newValues: $jobLevel->toArray(),
        );

        return response()->json(['message' => 'Jenjang jabatan berhasil diperbarui.', 'data' => $jobLevel]);
    }

    /** DELETE /dashboard/payroll/job-levels/{jobLevel} */
    public function destroy(Request $request, JobLevel $jobLevel): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $jobLevel->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Jenjang jabatan tidak ditemukan.'], 404);
        }

        // Jaga integritas historis: jenjang yang masih dipakai golongan upah atau
        // melekat pada baris gaji tidak boleh dihapus (nonaktifkan saja).
        if (SalaryGrade::where('job_level_id', $jobLevel->id)->exists()) {
            return response()->json([
                'message' => 'Jenjang tidak dapat dihapus karena masih dipakai golongan upah. Nonaktifkan saja.',
            ], 422);
        }
        if (EmployeeSalary::where('job_level_id', $jobLevel->id)->exists()) {
            return response()->json([
                'message' => 'Jenjang tidak dapat dihapus karena melekat pada riwayat gaji karyawan. Nonaktifkan saja.',
            ], 422);
        }

        $name = $jobLevel->name;
        $id = $jobLevel->id;
        $jobLevel->delete();

        AuditLogger::log(
            action: 'JOB_LEVEL_DELETED',
            description: "Menghapus jenjang jabatan: {$name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'JobLevel',
            entityId: $id,
        );

        return response()->json(['message' => "Jenjang jabatan '{$name}' berhasil dihapus."]);
    }

    /** @return array<string, mixed> */
    private function rules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('job_levels', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('job_levels', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'rank'        => 'nullable|integer|min:1|max:999',
            'description' => 'nullable|string|max:2000',
            'is_active'   => 'boolean',
        ];
    }
}
