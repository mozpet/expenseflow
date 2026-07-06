<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
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

        $users = User::where('company_id', $companyId)
            ->with('office:id,office_name')
            ->select(['id', 'company_id', 'employee_code', 'name', 'email', 'role', 'department', 'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'created_at', 'updated_at'])
            ->latest()
            ->paginate(20);

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
            'department'            => 'nullable|string|max:100',
            // Kantor penempatan — harus milik perusahaan yang sama.
            'attendance_setting_id' => [
                'nullable',
                Rule::exists('attendance_settings', 'id')->where('company_id', $companyId),
            ],
            'monthly_claim_limit'   => 'nullable|numeric|min:0',
        ]);

        $user = User::create([
            'company_id'            => $companyId,
            'employee_code'         => $validated['employee_code'] ?? null,
            'name'                  => $validated['name'],
            'email'                 => $validated['email'],
            'password'              => Hash::make($validated['password']),
            'role'                  => $validated['role'],
            'department'            => $validated['department'] ?? null,
            'attendance_setting_id' => $validated['attendance_setting_id'] ?? null,
            'monthly_claim_limit'   => $validated['monthly_claim_limit'] ?? 0,
            'is_active'             => true,
        ]);

        return response()->json([
            'message' => 'Karyawan berhasil ditambahkan.',
            'user'    => $user->only(['id', 'employee_code', 'name', 'email', 'role', 'department', 'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'company_id']),
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
            'department'            => 'nullable|string|max:100',
            // Kantor penempatan — harus milik perusahaan karyawan tsb.
            'attendance_setting_id' => [
                'sometimes',
                'nullable',
                Rule::exists('attendance_settings', 'id')->where('company_id', $user->company_id),
            ],
            'monthly_claim_limit'   => 'nullable|numeric|min:0',
        ]);

        // Hanya super_admin yang boleh menetapkan role super_admin (cegah escalation).
        if (isset($validated['role']) && $validated['role'] === 'super_admin' && $actor->role !== 'super_admin') {
            return response()->json([
                'message' => 'Hanya super admin yang bisa menetapkan role super admin.',
            ], 403);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Data karyawan berhasil diperbarui.',
            'user'    => $user->only(['id', 'employee_code', 'name', 'email', 'role', 'department', 'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'company_id']),
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

        return response()->json([
            'message' => 'Akun karyawan berhasil diaktifkan kembali.',
        ]);
    }

    /**
     * HR reset password karyawan — set password baru + revoke token.
     * POST /api/v1/admin/users/{user}/reset-password
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        // Cegah admin mereset password akun super_admin (account takeover).
        if ($deny = $this->denyIfProtectedTarget($request->user(), $user)) {
            return $deny;
        }

        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Cabut semua token — user harus login ulang
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password berhasil direset.',
        ]);
    }
}
