<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    /**
     * Slug yang direservasi karena merupakan built-in role bawaan sistem.
     * Membuat custom role dengan slug ini menyebabkan privilege escalation
     * karena RoleMiddleware dan User::hasPermission mengecek string role.
     */
    private const RESERVED_SLUGS = [
        'admin',
        'super_admin',
        'hrd',
        'finance',
        'employee',
    ];
    /**
     * Kembalikan daftar modul yang tersedia untuk pengaturan hak akses di form UI.
     * GET /api/v1/admin/roles/modules
     */
    public function modules(): JsonResponse
    {
        return response()->json([
            'modules'       => array_values(Role::AVAILABLE_MODULES),
            'access_levels' => [
                ['id' => 'none',   'name' => 'Tidak Ada Akses', 'desc' => 'Menu disembunyikan & akses API diblokir'],
                ['id' => 'read',   'name' => 'Hanya Lihat',     'desc' => 'Hanya bisa melihat daftar & detail data'],
                ['id' => 'manage', 'name' => 'Kelola Penuh',    'desc' => 'Bisa melihat, menambah, mengubah, dan menghapus'],
            ],
            'platforms' => [
                ['id' => 'both',        'name' => 'Mobile & Web Dashboard', 'desc' => 'Presensi online di mobile dan akses dashboard web'],
                ['id' => 'mobile_only', 'name' => 'Khusus Mobile Saja',      'desc' => 'Hanya aplikasi mobile (staff pelaksana/lapangan)'],
            ],
            'branch_scopes' => [
                ['id' => 'all',      'name' => 'Semua Cabang', 'desc' => 'Dapat mengakses seluruh kantor cabang'],
                ['id' => 'specific', 'name' => 'Cabang Tertentu', 'desc' => 'Hanya cabang yang dicentang'],
                ['id' => 'self',     'name' => 'Sesuai Penempatan', 'desc' => 'Otomatis mengikuti kantor penempatan karyawan'],
            ],
        ]);
    }

    /**
     * List semua role (built-in + custom role perusahaan).
     * GET /api/v1/admin/roles
     *
     * Jika request dari Super Admin:
     * - Parameter ?company_id=X memfilter built-in roles + custom roles milik company X.
     * - Tanpa parameter ?company_id, mengembalikan built-in roles + seluruh custom roles dari semua tenant.
     * Jika request dari Admin/Tenant Admin biasa:
     * - Hanya mengembalikan built-in roles + custom roles milik company miliknya (user->company_id).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = ($user->role === 'super_admin');

        if ($isSuperAdmin) {
            $companyId = $request->filled('company_id') ? (int) $request->query('company_id') : null;
        } else {
            $companyId = (int) $user->company_id;
        }

        $query = Role::query()
            ->with([
                'permissions:id,role_id,module,access_level',
                'branches:id,office_name',
                'company:id,name',
            ]);

        if (! ($isSuperAdmin && $companyId === null)) {
            $query->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId) {
                    $q->orWhere('company_id', $companyId);
                }
            });
        }

        $roles = $query
            ->withCount([
                'users' => function ($q) use ($companyId) {
                    if ($companyId) {
                        $q->where('company_id', $companyId);
                    }
                },
            ])
            ->orderByRaw('is_builtin DESC, id ASC')
            ->get();

        return response()->json([
            'data' => $roles,
        ]);
    }

    /**
     * Buat custom role baru.
     * POST /api/v1/admin/roles
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_ROLE_MANAGEMENT, 'manage')) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang untuk membuat role baru.'], 403);
        }

        $isSuperAdmin = ($user->role === 'super_admin');
        if ($isSuperAdmin) {
            $companyId = $request->filled('company_id')
                ? (int) $request->input('company_id')
                : ($user->company_id ? (int) $user->company_id : null);
        } else {
            $companyId = (int) $user->company_id;
        }

        $availableModules = array_keys(Role::AVAILABLE_MODULES);
        $this->normalizePermissions($request);

        $rules = [
            'name'         => 'required|string|max:100',
            'description'  => 'nullable|string|max:500',
            'platform'     => ['required', Rule::in(['mobile_only', 'both'])],
            'branch_scope' => ['required', Rule::in(['all', 'specific', 'self'])],
            'branch_ids'   => [
                'nullable',
                'array',
                Rule::requiredIf(fn () => $request->input('branch_scope') === 'specific'),
            ],
            'branch_ids.*' => [
                Rule::exists('attendance_settings', 'id')->where(function ($query) use ($companyId) {
                    if ($companyId) {
                        $query->where('company_id', $companyId);
                    }
                }),
            ],
            'permissions'   => 'nullable|array',
            'permissions.*' => [Rule::in(['none', 'read', 'manage'])],
        ];

        if ($isSuperAdmin && empty($user->company_id)) {
            $rules['company_id'] = ['required', 'integer', 'exists:companies,id'];
        } elseif ($isSuperAdmin && $request->filled('company_id')) {
            $rules['company_id'] = ['integer', 'exists:companies,id'];
        }

        $validated = $request->validate($rules);
        if ($isSuperAdmin && isset($validated['company_id'])) {
            $companyId = (int) $validated['company_id'];
        }

        // Generate slug unik per company — WAJIB hindari slug built-in
        // agar tidak terjadi privilege escalation via RoleMiddleware.
        $baseSlug = Str::slug($validated['name'], '_');
        if (empty($baseSlug)) {
            $baseSlug = 'custom_role';
        }

        // Jika base slug adalah reserved slug, langsung tambahkan suffix
        // agar tidak pernah collision dengan built-in roles.
        if (in_array($baseSlug, self::RESERVED_SLUGS, true)) {
            $baseSlug = "{$baseSlug}_custom";
        }

        $slug = $baseSlug;
        $counter = 1;
        // Cek keunikan terhadap SEMUA role: built-in (company_id=NULL) + company sendiri.
        while (
            Role::where('slug', $slug)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->exists()
        ) {
            $slug = "{$baseSlug}_{$counter}";
            $counter++;
        }

        $role = DB::transaction(function () use ($companyId, $validated, $slug, $availableModules) {
            $role = Role::create([
                'company_id'   => $companyId,
                'name'         => $validated['name'],
                'slug'         => $slug,
                'description'  => $validated['description'] ?? null,
                'platform'     => $validated['platform'],
                'branch_scope' => $validated['branch_scope'],
                'is_builtin'   => false,
                'is_active'    => true,
            ]);

            // Simpan relasi cabang jika specific
            if ($validated['branch_scope'] === 'specific' && ! empty($validated['branch_ids'])) {
                $role->branches()->sync($validated['branch_ids']);
            }

            // Simpan matriks permission per modul
            $inputPermissions = $validated['permissions'] ?? [];
            foreach ($availableModules as $mod) {
                $level = $inputPermissions[$mod] ?? 'none';
                RolePermission::create([
                    'role_id'      => $role->id,
                    'module'       => $mod,
                    'access_level' => $level,
                ]);
            }

            return $role->load(['permissions', 'branches:id,office_name']);
        });

        AuditLogger::log(
            action: 'ROLE_CREATED',
            description: "Membuat custom role: {$role->name} (Platform: {$role->platform}, Scope: {$role->branch_scope})",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Role',
            entityId: $role->id,
            newValues: $role->toArray()
        );

        return response()->json([
            'message' => "Custom Role '{$role->name}' berhasil dibuat.",
            'data'    => $role,
        ], 201);
    }

    /**
     * Detail role beserta permission dan cabangnya.
     * GET /api/v1/admin/roles/{role}
     */
    public function show(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_ROLE_MANAGEMENT, 'read')) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki izin melihat detail role.'], 403);
        }

        $isSuperAdmin = ($user->role === 'super_admin');
        if ($role->company_id !== null && (int) $role->company_id !== (int) $user->company_id && ! $isSuperAdmin) {
            return response()->json(['message' => 'Role tidak ditemukan atau bukan milik perusahaan Anda.'], 403);
        }

        $role->load(['permissions', 'branches:id,office_name', 'company:id,name']);

        $targetCompanyId = $isSuperAdmin
            ? ($request->filled('company_id') ? (int) $request->query('company_id') : $role->company_id)
            : $user->company_id;

        $role->users_count = User::where('role_id', $role->id)
            ->when($targetCompanyId, fn ($q) => $q->where('company_id', $targetCompanyId))
            ->count();

        return response()->json([
            'data' => $role,
        ]);
    }

    /**
     * Update custom role.
     * PUT /api/v1/admin/roles/{role}
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_ROLE_MANAGEMENT, 'manage')) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang untuk mengubah role.'], 403);
        }

        $isSuperAdmin = ($user->role === 'super_admin');
        if ($role->company_id !== null && (int) $role->company_id !== (int) $user->company_id && ! $isSuperAdmin) {
            return response()->json(['message' => 'Role tidak ditemukan atau bukan milik perusahaan Anda.'], 403);
        }

        if ($role->is_builtin) {
            return response()->json([
                'message' => "Role bawaan sistem '{$role->name}' tidak dapat diubah atau dihapus. Silakan buat Custom Role baru jika memerlukan kombinasi hak akses khusus.",
            ], 422);
        }

        $targetCompanyId = $role->company_id ?? ($isSuperAdmin ? null : $user->company_id);

        $availableModules = array_keys(Role::AVAILABLE_MODULES);
        $this->normalizePermissions($request);

        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'description'  => 'nullable|string|max:500',
            'platform'     => ['required', Rule::in(['mobile_only', 'both'])],
            'branch_scope' => ['required', Rule::in(['all', 'specific', 'self'])],
            'branch_ids'   => [
                'nullable',
                'array',
                Rule::requiredIf(fn () => $request->input('branch_scope') === 'specific'),
            ],
            'branch_ids.*' => [
                Rule::exists('attendance_settings', 'id')->where(function ($query) use ($targetCompanyId) {
                    if ($targetCompanyId) {
                        $query->where('company_id', $targetCompanyId);
                    }
                }),
            ],
            'permissions'   => 'nullable|array',
            'permissions.*' => [Rule::in(['none', 'read', 'manage'])],
            'is_active'     => 'sometimes|boolean',
        ]);

        $oldValues = $role->load(['permissions', 'branches'])->toArray();

        DB::transaction(function () use ($role, $validated, $availableModules) {
            $role->update([
                'name'         => $validated['name'],
                'description'  => $validated['description'] ?? null,
                'platform'     => $validated['platform'],
                'branch_scope' => $validated['branch_scope'],
                'is_active'    => $validated['is_active'] ?? $role->is_active,
            ]);

            // Sinkronisasi cabang
            if ($validated['branch_scope'] === 'specific') {
                $role->branches()->sync($validated['branch_ids'] ?? []);
            } else {
                $role->branches()->detach();
            }

            // Sinkronisasi permissions
            if (isset($validated['permissions'])) {
                foreach ($availableModules as $mod) {
                    $level = $validated['permissions'][$mod] ?? 'none';
                    RolePermission::updateOrCreate(
                        ['role_id' => $role->id, 'module' => $mod],
                        ['access_level' => $level]
                    );
                }
            }
        });

        $role->refresh()->load(['permissions', 'branches:id,office_name', 'company:id,name']);

        AuditLogger::log(
            action: 'ROLE_UPDATED',
            description: "Memperbarui custom role: {$role->name}",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Role',
            entityId: $role->id,
            oldValues: $oldValues,
            newValues: $role->toArray()
        );

        return response()->json([
            'message' => "Custom Role '{$role->name}' berhasil diperbarui.",
            'data'    => $role,
        ]);
    }

    /**
     * Hapus custom role.
     * DELETE /api/v1/admin/roles/{role}
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_ROLE_MANAGEMENT, 'manage')) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang untuk menghapus role.'], 403);
        }

        $isSuperAdmin = ($user->role === 'super_admin');
        if ($role->company_id !== null && (int) $role->company_id !== (int) $user->company_id && ! $isSuperAdmin) {
            return response()->json(['message' => 'Role tidak ditemukan atau bukan milik perusahaan Anda.'], 403);
        }

        if ($role->is_builtin) {
            return response()->json([
                'message' => "Role bawaan sistem '{$role->name}' tidak dapat dihapus.",
            ], 422);
        }

        // Cek apakah masih ada user yang memakai role ini (baik via role_id maupun role slug)
        $assignedQuery = User::where('role_id', $role->id)
            ->orWhere(function ($q) use ($role) {
                $q->where('role', $role->slug)
                    ->where(function ($sub) use ($role) {
                        $sub->whereNull('role_id')
                            ->orWhere('role_id', $role->id);
                    });
            });

        $assignedCount = $assignedQuery->count();
        if ($assignedCount > 0) {
            return response()->json([
                'message' => "Role '{$role->name}' tidak dapat dihapus karena masih digunakan oleh {$assignedCount} karyawan. Alihkan karyawan ke role lain terlebih dahulu.",
            ], 422);
        }

        $roleName = $role->name;
        $roleId = $role->id;

        DB::transaction(function () use ($role) {
            $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();
            $employeeRoleId = $employeeRole?->id;

            // Pastikan jika ada user terasosiasi disinkronkan ke role employee agar tidak terjadi zombie state
            User::where('role_id', $role->id)
                ->orWhere(function ($q) use ($role) {
                    $q->where('role', $role->slug)
                        ->where(function ($sub) use ($role) {
                            $sub->whereNull('role_id')
                                ->orWhere('role_id', $role->id);
                        });
                })
                ->update([
                    'role_id' => $employeeRoleId,
                    'role'    => 'employee',
                ]);

            $role->branches()->detach();
            $role->permissions()->delete();
            $role->delete();
        });

        AuditLogger::log(
            action: 'ROLE_DELETED',
            description: "Menghapus custom role: {$roleName}",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'Role',
            entityId: $roleId
        );

        return response()->json([
            'message' => "Custom Role '{$roleName}' berhasil dihapus.",
        ]);
    }

    /**
     * Normalisasi format permissions: mendukung map ['module' => 'level'] maupun array of objects [['module' => '...', 'access_level' => '...']]
     */
    private function normalizePermissions(Request $request): void
    {
        if ($request->has('permissions') && is_array($request->input('permissions'))) {
            $rawPerms = $request->input('permissions');
            $normalized = [];
            foreach ($rawPerms as $key => $val) {
                if (is_array($val) && isset($val['module'])) {
                    $normalized[$val['module']] = $val['access_level'] ?? 'none';
                } else {
                    $normalized[$key] = $val;
                }
            }
            $request->merge(['permissions' => $normalized]);
        }
    }
}
