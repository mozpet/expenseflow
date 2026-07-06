<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorController extends Controller
{
    // ─── Helper: catat aktivitas ──────────────────────────────
    private function logActivity(int $userId, int $companyId, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'   => $companyId,
            'user_id'      => $userId,
            'action'       => $action,
            'description'  => $description,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 1. index() — GET list semua vendor milik company yang login
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $vendors = Vendor::where('company_id', $companyId)
            ->select([
                'id', 'name', 'email', 'phone', 'address',
                'contact_person', 'tax_id',
                'bank_name', 'bank_account_no', 'bank_account_name',
                'is_active', 'created_at', 'updated_at',
            ])
            ->orderBy('name')
            ->paginate(20);

        return response()->json($vendors);
    }

    // ═══════════════════════════════════════════════════════════
    // 2. store() — POST tambah vendor baru
    // ═══════════════════════════════════════════════════════════
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'npwp'              => 'nullable|string|max:100',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'nullable|string|max:50',
            'address'           => 'nullable|string|max:1000',
            'contact_person'    => 'nullable|string|max:255',
            'bank_name'         => 'required|string|max:100',
            'bank_account_no'   => 'required|string|max:50',
            'bank_account_name' => 'required|string|max:255',
        ]);

        $companyId = $request->user()->company_id;

        $vendor = Vendor::create([
            'company_id'        => $companyId,
            'name'              => $validated['name'],
            'email'             => $validated['email'] ?? null,
            'phone'             => $validated['phone'] ?? null,
            'address'           => $validated['address'] ?? null,
            'contact_person'    => $validated['contact_person'] ?? null,
            'tax_id'            => $validated['npwp'] ?? null,
            'bank_name'         => $validated['bank_name'],
            'bank_account_no'   => $validated['bank_account_no'],
            'bank_account_name' => $validated['bank_account_name'],
            'is_active'         => true,
        ]);

        $this->logActivity(
            $request->user()->id, $companyId,
            'vendor_created', 'Tambah vendor ' . $vendor->name,
            'vendor', $vendor->id
        );

        return response()->json([
            'message' => 'Vendor berhasil ditambahkan.',
            'vendor'  => $vendor->only([
                'id', 'name', 'email', 'phone', 'address',
                'contact_person', 'tax_id',
                'bank_name', 'bank_account_no', 'bank_account_name',
                'is_active',
            ]),
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════
    // 3. update() — PATCH edit data vendor
    // ═══════════════════════════════════════════════════════════
    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        // Cek ownership: vendor harus milik company yang sama
        if ($vendor->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Vendor tidak ditemukan di perusahaan Anda.'], 403);
        }

        $validated = $request->validate([
            'name'              => 'sometimes|required|string|max:255',
            'npwp'              => 'nullable|string|max:100',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'nullable|string|max:50',
            'address'           => 'nullable|string|max:1000',
            'contact_person'    => 'nullable|string|max:255',
            'bank_name'         => 'sometimes|required|string|max:100',
            'bank_account_no'   => 'sometimes|required|string|max:50',
            'bank_account_name' => 'sometimes|required|string|max:255',
        ]);

        // Map 'npwp' → 'tax_id' untuk penyimpanan
        if (array_key_exists('npwp', $validated)) {
            $validated['tax_id'] = $validated['npwp'];
            unset($validated['npwp']);
        }

        $vendor->update($validated);

        $this->logActivity(
            $request->user()->id, $request->user()->company_id,
            'vendor_updated', 'Update vendor ' . $vendor->name,
            'vendor', $vendor->id
        );

        return response()->json([
            'message' => 'Vendor berhasil diperbarui.',
            'vendor'  => $vendor->only([
                'id', 'name', 'email', 'phone', 'address',
                'contact_person', 'tax_id',
                'bank_name', 'bank_account_no', 'bank_account_name',
                'is_active',
            ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 4. toggleActive() — POST aktifkan/nonaktifkan vendor
    // ═══════════════════════════════════════════════════════════
    public function toggleActive(Request $request, Vendor $vendor): JsonResponse
    {
        // Cek ownership: vendor harus milik company yang sama
        if ($vendor->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Vendor tidak ditemukan di perusahaan Anda.'], 403);
        }

        $vendor->update(['is_active' => ! $vendor->is_active]);

        $action = $vendor->is_active ? 'vendor_activated' : 'vendor_deactivated';
        $status = $vendor->is_active ? 'diaktifkan' : 'dinonaktifkan';

        $this->logActivity(
            $request->user()->id, $request->user()->company_id,
            $action, 'Vendor ' . $vendor->name . ' ' . $status,
            'vendor', $vendor->id
        );

        return response()->json([
            'message'   => 'Vendor ' . $status . '.',
            'vendor_id' => $vendor->id,
            'is_active' => $vendor->is_active,
        ]);
    }
}
