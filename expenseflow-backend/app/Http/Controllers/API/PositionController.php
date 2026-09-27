<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    /**
     * List semua jabatan dalam perusahaan.
     * GET /api/v1/admin/positions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = ($user->role === 'super_admin');

        $companyId = ($isSuperAdmin && $request->filled('company_id'))
            ? (int) $request->query('company_id')
            : (int) $user->company_id;

        $query = Position::query()
            ->with('division:id,name,code')
            ->withCount('users')
            ->orderBy('name');

        if (! ($isSuperAdmin && ! $request->filled('company_id'))) {
            $query->where('company_id', $companyId);
        }

        if ($request->filled('division_id')) {
            $divId = (int) $request->query('division_id');
            // Jika ada division_id, sertakan posisi yang spesifik divisi tersebut ATAU posisi global (division_id null)
            $query->where(function ($q) use ($divId) {
                $q->where('division_id', $divId)
                  ->orWhereNull('division_id');
            });
        }

        if ($request->has('is_supervisor')) {
            $query->where('is_supervisor', filter_var($request->query('is_supervisor'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $positions = $query->get();

        return response()->json([
            'success'   => true,
            'data'      => $positions,
            'positions' => $positions,
        ]);
    }

    /**
     * Tambah jabatan baru.
     * POST /api/v1/admin/positions
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'division_id'   => [
                'nullable',
                Rule::exists('divisions', 'id')->where('company_id', $companyId),
            ],
            'is_supervisor' => 'nullable|boolean',
            'description'   => 'nullable|string|max:1000',
            'is_active'     => 'nullable|boolean',
        ], [
            'name.required' => 'Nama jabatan/posisi kerja wajib diisi.',
            'division_id.exists' => 'Divisi yang dipilih tidak valid atau bukan milik perusahaan ini.',
        ]);

        $position = Position::create([
            'company_id'    => $companyId,
            'division_id'   => $validated['division_id'] ?? null,
            'name'          => trim($validated['name']),
            'is_supervisor' => (bool) ($validated['is_supervisor'] ?? false),
            'description'   => $validated['description'] ?? null,
            'is_active'     => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log(
            action: 'POSITION_CREATED',
            description: "Menambahkan jabatan baru: {$position->name}" . ($position->is_supervisor ? ' (Tingkat SPV/Atasan)' : ''),
            category: AuditLogger::CATEGORY_SETTINGS,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Position',
            entityId: $position->id,
            newValues: $position->toArray()
        );

        $loaded = $position->load('division:id,name,code')->loadCount('users');

        return response()->json([
            'success'  => true,
            'message'  => 'Jabatan berhasil ditambahkan.',
            'data'     => $loaded,
            'position' => $loaded,
        ], 201);
    }

    /**
     * Detail satu jabatan.
     * GET /api/v1/admin/positions/{position}
     */
    public function show(Request $request, Position $position): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'super_admin' && $position->company_id !== $user->company_id) {
            return response()->json(['message' => 'Jabatan tidak ditemukan.'], 404);
        }

        $position->load('division:id,name,code')->loadCount('users');

        return response()->json([
            'success'  => true,
            'data'     => $position,
            'position' => $position,
        ]);
    }

    /**
     * Update data jabatan.
     * PUT /api/v1/admin/positions/{position}
     */
    public function update(Request $request, Position $position): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'super_admin' && $position->company_id !== $user->company_id) {
            return response()->json(['message' => 'Jabatan tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:100',
            'division_id'   => [
                'sometimes',
                'nullable',
                Rule::exists('divisions', 'id')->where('company_id', $position->company_id),
            ],
            'is_supervisor' => 'sometimes|nullable|boolean',
            'description'   => 'nullable|string|max:1000',
            'is_active'     => 'nullable|boolean',
        ], [
            'name.required' => 'Nama jabatan/posisi kerja wajib diisi.',
            'division_id.exists' => 'Divisi yang dipilih tidak valid atau bukan milik perusahaan ini.',
        ]);

        $oldValues = $position->toArray();

        $data = [];
        if (array_key_exists('name', $validated)) {
            $data['name'] = trim($validated['name']);
        }
        if (array_key_exists('division_id', $validated)) {
            $data['division_id'] = $validated['division_id'] ?: null;
        }
        if (array_key_exists('is_supervisor', $validated)) {
            $data['is_supervisor'] = (bool) $validated['is_supervisor'];
        }
        if (array_key_exists('description', $validated)) {
            $data['description'] = $validated['description'];
        }
        if (array_key_exists('is_active', $validated)) {
            $data['is_active'] = (bool) $validated['is_active'];
        }

        $position->update($data);

        AuditLogger::log(
            action: 'POSITION_UPDATED',
            description: "Memperbarui jabatan: {$position->name}",
            category: AuditLogger::CATEGORY_SETTINGS,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Position',
            entityId: $position->id,
            oldValues: $oldValues,
            newValues: $position->toArray()
        );

        $loaded = $position->load('division:id,name,code')->loadCount('users');

        return response()->json([
            'success'  => true,
            'message'  => 'Jabatan berhasil diperbarui.',
            'data'     => $loaded,
            'position' => $loaded,
        ]);
    }

    /**
     * Hapus jabatan.
     * DELETE /api/v1/admin/positions/{position}
     */
    public function destroy(Request $request, Position $position): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'super_admin' && $position->company_id !== $user->company_id) {
            return response()->json(['message' => 'Jabatan tidak ditemukan.'], 404);
        }

        $usersCount = $position->users()->count();
        if ($usersCount > 0) {
            return response()->json([
                'message' => "Jabatan \"{$position->name}\" tidak dapat dihapus karena masih digunakan oleh {$usersCount} karyawan. Harap ubah jabatan karyawan terlebih dahulu.",
                'users_count' => $usersCount,
            ], 422);
        }

        $oldValues = $position->toArray();
        $position->delete();

        AuditLogger::log(
            action: 'POSITION_DELETED',
            description: "Menghapus jabatan: {$position->name}",
            category: AuditLogger::CATEGORY_SETTINGS,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'Position',
            entityId: $position->id,
            oldValues: $oldValues
        );

        return response()->json([
            'success' => true,
            'message' => "Jabatan \"{$position->name}\" berhasil dihapus.",
        ]);
    }
}
