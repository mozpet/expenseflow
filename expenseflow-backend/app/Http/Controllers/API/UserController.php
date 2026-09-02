<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Hanya super_admin yang boleh mengelola akun super_admin.
     * Cegah admin menonaktifkan/reset/ubah akun setara/di atasnya.
     */
    private function denyIfProtectedTarget(User $actor, User $target): ?JsonResponse
    {
        if ($target->role === 'super_admin' && $actor->role !== 'super_admin') {
            return response()->json([
                'message' => 'Anda tidak berwenang mengelola akun super admin.',
            ], 403);
        }

        return null;
    }

    /**
     * List semua karyawan dalam satu perusahaan.
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $query = User::where('company_id', $companyId);

        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $limit = $request->query('per_page') ? (int) $request->query('per_page') : 2000;

        $users = $query->with('office:id,office_name')
            ->select([
                'id', 'company_id', 'employee_code', 'name', 'email', 'phone',
                'role', 'department', 'attendance_setting_id', 'monthly_claim_limit',
                'is_active', 'employment_type', 'joined_date', 'identity_number',
                'contract_start_date', 'contract_end_date', 'bank_name',
                'bank_account_no', 'bank_account_holder', 'created_at', 'updated_at',
            ])
            ->latest()
            ->paginate($limit);

        return response()->json($users);
    }

    /**
     * Tambah karyawan baru dengan password hash.
     * POST /api/v1/admin/users
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8',
            'role'                  => ['required', Rule::in(['employee', 'finance', 'hrd', 'admin', 'super_admin'])],
            'employee_code'         => 'nullable|string|max:50|unique:users,employee_code',
            'identity_number'       => 'nullable|string|size:16|unique:users,identity_number',
            'department'            => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:13',
            // Kantor penempatan — harus milik perusahaan yang sama.
            'attendance_setting_id' => [
                'nullable',
                Rule::exists('attendance_settings', 'id')->where('company_id', $companyId),
            ],
            'monthly_claim_limit'   => 'nullable|numeric|min:0',
            // Tipe hubungan kerja
            'employment_type'       => ['nullable', Rule::in(['PKWTT', 'PKWT', 'Probation', 'Internship'])],
            'joined_date'           => 'nullable|date',
            'contract_start_date'   => 'nullable|date',
            'contract_end_date'     => 'nullable|date|after_or_equal:contract_start_date',
            // Data Rekening Bank
            'bank_name'             => 'nullable|string|max:50',
            'bank_account_no'       => 'nullable|string|max:50',
            'bank_account_holder'   => 'nullable|string|max:150',
        ]);

        $user = User::create([
            'company_id'            => $companyId,
            'employee_code'         => $validated['employee_code'] ?? null,
            'name'                  => $validated['name'],
            'email'                 => $validated['email'],
            'password'              => Hash::make($validated['password']),
            'role'                  => $validated['role'],
            'department'            => $validated['department'] ?? null,
            'identity_number'       => $validated['identity_number'] ?? null,
            'phone'                 => $validated['phone'] ?? null,
            'attendance_setting_id' => $validated['attendance_setting_id'] ?? null,
            'monthly_claim_limit'   => $validated['monthly_claim_limit'] ?? null,
            'is_active'             => true,
            'employment_type'       => $validated['employment_type'] ?? null,
            'joined_date'           => $validated['joined_date'] ?? null,
            'contract_start_date'   => $validated['contract_start_date'] ?? null,
            'contract_end_date'     => $validated['contract_end_date'] ?? null,
            'bank_name'             => $validated['bank_name'] ?? null,
            'bank_account_no'       => $validated['bank_account_no'] ?? null,
            'bank_account_holder'   => $validated['bank_account_holder'] ?? null,
        ]);

        AuditLogger::log(
            action: 'EMPLOYEE_CREATED',
            description: "Menambahkan karyawan baru: {$user->name} ({$user->email}) - Role: {$user->role}",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'User',
            entityId: $user->id,
            newValues: $user->only([
                'employee_code', 'name', 'email', 'role', 'department', 'phone',
                'identity_number', 'monthly_claim_limit', 'employment_type',
                'joined_date', 'contract_start_date', 'contract_end_date',
                'bank_name', 'bank_account_no', 'bank_account_holder',
            ])
        );

        return response()->json([
            'message' => 'Karyawan berhasil ditambahkan.',
            'user'    => $user->only([
                'id', 'employee_code', 'name', 'email', 'phone', 'role', 'department',
                'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'company_id',
                'employment_type', 'joined_date', 'contract_start_date', 'contract_end_date',
                'identity_number', 'bank_name', 'bank_account_no', 'bank_account_holder'
            ]),
        ], 201);
    }

    /**
     * Edit data karyawan.
     * PUT /api/v1/admin/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Cegah admin mengubah akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($actor, $user)) {
            return $deny;
        }

        $validated = $request->validate([
            'name'                  => 'sometimes|required|string|max:255',
            'email'                 => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'role'                  => ['sometimes', 'required', Rule::in(['employee', 'finance', 'hrd', 'admin', 'super_admin'])],
            'employee_code'         => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore($user->id)],
            'identity_number'       => ['nullable', 'string', 'size:16', Rule::unique('users')->ignore($user->id)],
            'department'            => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:13',
            // Kantor penempatan — harus milik perusahaan karyawan tsb.
            'attendance_setting_id' => [
                'sometimes',
                'nullable',
                Rule::exists('attendance_settings', 'id')->where('company_id', $user->company_id),
            ],
            'monthly_claim_limit'   => 'nullable|numeric|min:0',
            // Tipe hubungan kerja
            'employment_type'       => ['sometimes', 'nullable', Rule::in(['PKWTT', 'PKWT', 'Probation', 'Internship'])],
            'joined_date'           => 'sometimes|nullable|date',
            'contract_start_date'   => 'sometimes|nullable|date',
            'contract_end_date'     => 'sometimes|nullable|date|after_or_equal:contract_start_date',
            // Data Rekening Bank
            'bank_name'             => 'sometimes|nullable|string|max:50',
            'bank_account_no'       => 'sometimes|nullable|string|max:50',
            'bank_account_holder'   => 'sometimes|nullable|string|max:150',
        ]);

        // Hanya super_admin yang boleh menetapkan role super_admin (cegah escalation).
        if (isset($validated['role']) && $validated['role'] === 'super_admin' && $actor->role !== 'super_admin') {
            return response()->json([
                'message' => 'Hanya super admin yang bisa menetapkan role super admin.',
            ], 403);
        }

        $original = $user->only([
            'name', 'email', 'phone', 'role', 'department', 'employee_code',
            'identity_number', 'attendance_setting_id', 'monthly_claim_limit',
            'employment_type', 'joined_date', 'contract_start_date',
            'contract_end_date', 'bank_name', 'bank_account_no', 'bank_account_holder',
        ]);

        $user->update($validated);

        $updated = $user->only(array_keys($original));

        // Tentukan tingkat severity: jika rekening bank, role, NIK, atau limit klaim berubah -> CRITICAL
        $isCriticalChange = (isset($original['bank_account_no']) && $original['bank_account_no'] != ($updated['bank_account_no'] ?? null))
            || (isset($original['role']) && $original['role'] != ($updated['role'] ?? null))
            || (isset($original['identity_number']) && $original['identity_number'] != ($updated['identity_number'] ?? null))
            || (isset($original['monthly_claim_limit']) && $original['monthly_claim_limit'] != ($updated['monthly_claim_limit'] ?? null));

        $severity = $isCriticalChange ? AuditLogger::SEVERITY_CRITICAL : AuditLogger::SEVERITY_INFO;
        $category = (isset($original['bank_account_no']) && $original['bank_account_no'] != ($updated['bank_account_no'] ?? null))
            ? AuditLogger::CATEGORY_FINANCE
            : AuditLogger::CATEGORY_HR;

        AuditLogger::logModelDiff(
            action: 'EMPLOYEE_UPDATED',
            description: "Memperbarui data profil karyawan {$user->name}" . ($isCriticalChange ? " (Data Sensitif Berubah)" : ""),
            category: $category,
            severity: $severity,
            entityType: 'User',
            entityId: $user->id,
            original: $original,
            updated: $updated
        );

        return response()->json([
            'message' => 'Data karyawan berhasil diperbarui.',
            'user'    => $user->only([
                'id', 'employee_code', 'name', 'email', 'phone', 'role', 'department',
                'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'company_id',
                'employment_type', 'joined_date', 'contract_start_date', 'contract_end_date',
                'identity_number', 'bank_name', 'bank_account_no', 'bank_account_holder'
            ]),
        ]);
    }

    /**
     * Nonaktifkan akun karyawan — set is_active = false + revoke token.
     * PATCH /api/v1/admin/users/{user}/deactivate
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Cegah admin menonaktifkan akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($actor, $user)) {
            return $deny;
        }

        // Cegah menonaktifkan akun sendiri (footgun: bisa terkunci keluar).
        if ($actor->id === $user->id) {
            return response()->json([
                'message' => 'Anda tidak bisa menonaktifkan akun Anda sendiri.',
            ], 403);
        }

        $user->update(['is_active' => false]);

        // Cabut semua token yang aktif
        $user->tokens()->delete();

        AuditLogger::log(
            action: 'EMPLOYEE_DEACTIVATED',
            description: "Menonaktifkan akun karyawan {$user->name} ({$user->email})",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'User',
            entityId: $user->id,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false]
        );

        return response()->json([
            'message' => 'Akun karyawan berhasil dinonaktifkan.',
        ]);
    }

    /**
     * Aktifkan kembali akun karyawan — set is_active = true.
     * PATCH /api/v1/admin/users/{user}/activate
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        // Cegah admin mengaktifkan/mengelola akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($request->user(), $user)) {
            return $deny;
        }

        $user->update(['is_active' => true]);

        AuditLogger::log(
            action: 'EMPLOYEE_ACTIVATED',
            description: "Mengaktifkan kembali akun karyawan {$user->name} ({$user->email})",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'User',
            entityId: $user->id,
            oldValues: ['is_active' => false],
            newValues: ['is_active' => true]
        );

        return response()->json([
            'message' => 'Akun karyawan berhasil diaktifkan kembali.',
        ]);
    }

    /**
     * Hapus akun karyawan (Soft Delete).
     * DELETE /api/v1/admin/users/{user}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Cegah admin menghapus akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($actor, $user)) {
            return $deny;
        }

        // Cegah menghapus akun sendiri
        if ($actor->id === $user->id) {
            return response()->json([
                'message' => 'Anda tidak bisa menghapus akun Anda sendiri.',
            ], 403);
        }

        // Cegah menghapus user yang masih aktif — harus dinonaktifkan terlebih dahulu
        if ($user->is_active) {
            return response()->json([
                'message' => 'Akun karyawan masih aktif. Silakan nonaktifkan akun terlebih dahulu sebelum menghapus.',
            ], 422);
        }

        // Cabut semua token sesi user
        $user->tokens()->delete();

        AuditLogger::log(
            action: 'EMPLOYEE_DELETED',
            description: "Menghapus akun karyawan {$user->name} ({$user->email}) secara soft delete",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'User',
            entityId: $user->id,
            oldValues: $user->only(['name', 'email', 'role', 'department', 'employee_code'])
        );

        // Lakukan soft delete Eloquent (mengisi kolom deleted_at)
        $user->delete();

        return response()->json([
            'message' => 'Akun karyawan berhasil dihapus (soft delete).',
        ]);
    }
}
