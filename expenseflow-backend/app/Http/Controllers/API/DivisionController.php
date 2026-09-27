<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DivisionController extends Controller
{
    /**
     * List semua divisi dalam perusahaan.
     * GET /api/v1/admin/divisions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = ($user->role === 'super_admin');

        $companyId = ($isSuperAdmin && $request->filled('company_id'))
            ? (int) $request->query('company_id')
            : (int) $user->company_id;

        $query = Division::query()
            ->withCount(['users', 'positions'])
            ->orderBy('name');

        if (! ($isSuperAdmin && ! $request->filled('company_id'))) {
            $query->where('company_id', $companyId);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $divisions = $query->get();

        return response()->json([
            'success'   => true,
            'data'      => $divisions,
            'divisions' => $divisions,
        ]);
    }

    /**
     * Tambah divisi baru.
     * POST /api/v1/admin/divisions
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validated = $request->validate([
            'name'        => [
                'required',
                'string',
                'max:100',
                Rule::unique('divisions')->where('company_id', $companyId),
            ],
            'code'        => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('divisions')->where('company_id', $companyId),
            ],
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'nullable|boolean',
        ], [
            'name.required' => 'Nama divisi wajib diisi.',
            'name.unique'   => 'Nama divisi sudah digunakan di perusahaan ini.',
            'code.unique'   => 'Kode divisi sudah digunakan oleh divisi lain di perusahaan ini.',
        ]);

        $division = Division::create([
            'company_id'  => $companyId,
            'name'        => trim($validated['name']),
            'code'        => isset($validated['code']) && trim($validated['code']) !== '' ? strtoupper(trim($validated['code'])) : null,
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log(
            action: 'DIVISION_CREATED',
            description: "Menambahkan divisi baru: {$division->name}",
            category: AuditLogger::CATEGORY_SETTINGS,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Division',
            entityId: $division->id,
            newValues: $division->toArray()
        );

        $loaded = $division->loadCount(['users', 'positions']);

        return response()->json([
            'success'  => true,
            'message'  => 'Divisi berhasil ditambahkan.',
            'data'     => $loaded,
            'division' => $loaded,
        ], 201);
    }

    /**
     * Detail satu divisi beserta posisinya.
     * GET /api/v1/admin/divisions/{division}
     */
    public function show(Request $request, Division $division): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'super_admin' && $division->company_id !== $user->company_id) {
            return response()->json(['message' => 'Divisi tidak ditemukan.'], 404);
        }

        $division->load(['positions' => function ($q) {
            $q->withCount('users')->orderBy('name');
        }])->loadCount(['users', 'positions']);

        return response()->json([
            'success'  => true,
            'data'     => $division,
            'division' => $division,
        ]);
    }

    /**
     * Update data divisi.
     * PUT /api/v1/admin/divisions/{division}
     */
    public function update(Request $request, Division $division): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'super_admin' && $division->company_id !== $user->company_id) {
            return response()->json(['message' => 'Divisi tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'name'        => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('divisions')->where('company_id', $division->company_id)->ignore($division->id),
            ],
            'code'        => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('divisions')->where('company_id', $division->company_id)->ignore($division->id),
            ],
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'nullable|boolean',
        ], [
            'name.required' => 'Nama divisi wajib diisi.',
            'name.unique'   => 'Nama divisi sudah digunakan di perusahaan ini.',
            'code.unique'   => 'Kode divisi sudah digunakan oleh divisi lain di perusahaan ini.',
        ]);

        $oldValues = $division->toArray();

        $data = [];
        if (array_key_exists('name', $validated)) {
            $data['name'] = trim($validated['name']);
        }
        if (array_key_exists('code', $validated)) {
            $data['code'] = ! empty($validated['code']) ? strtoupper(trim($validated['code'])) : null;
        }
        if (array_key_exists('description', $validated)) {
            $data['description'] = $validated['description'];
        }
        if (array_key_exists('is_active', $validated)) {
            $data['is_active'] = (bool) $validated['is_active'];
        }

        $division->update($data);

        // Jika nama divisi berubah, sinkronkan ke users.department
        if (isset($data['name']) && $data['name'] !== $oldValues['name']) {
            \App\Models\User::where('division_id', $division->id)
                ->update(['department' => $data['name']]);
        }

        AuditLogger::log(
            action: 'DIVISION_UPDATED',
            description: "Memperbarui divisi: {$division->name}",
            category: AuditLogger::CATEGORY_SETTINGS,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Division',
            entityId: $division->id,
            oldValues: $oldValues,
            newValues: $division->toArray()
        );

        $loaded = $division->loadCount(['users', 'positions']);

        return response()->json([
            'success'  => true,
            'message'  => 'Divisi berhasil diperbarui.',
            'data'     => $loaded,
            'division' => $loaded,
        ]);
    }

    /**
     * Hapus divisi.
     * DELETE /api/v1/admin/divisions/{division}
     */
    public function destroy(Request $request, Division $division): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'super_admin' && $division->company_id !== $user->company_id) {
            return response()->json(['message' => 'Divisi tidak ditemukan.'], 404);
        }

        $usersCount = $division->users()->count();
        if ($usersCount > 0) {
            return response()->json([
                'message' => "Divisi \"{$division->name}\" tidak dapat dihapus karena masih memiliki {$usersCount} karyawan yang terikat. Harap pindahkan karyawan ke divisi lain terlebih dahulu.",
                'users_count' => $usersCount,
            ], 422);
        }

        $positionsCount = $division->positions()->count();
        if ($positionsCount > 0) {
            return response()->json([
                'message' => "Divisi \"{$division->name}\" tidak dapat dihapus karena masih memiliki {$positionsCount} jabatan terkait. Harap hapus atau ubah divisi pada jabatan tersebut terlebih dahulu.",
                'positions_count' => $positionsCount,
            ], 422);
        }

        $oldValues = $division->toArray();
        $division->delete();

        AuditLogger::log(
            action: 'DIVISION_DELETED',
            description: "Menghapus divisi: {$division->name}",
            category: AuditLogger::CATEGORY_SETTINGS,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'Division',
            entityId: $division->id,
            oldValues: $oldValues
        );

        return response()->json([
            'success' => true,
            'message' => "Divisi \"{$division->name}\" berhasil dihapus.",
        ]);
    }
}
