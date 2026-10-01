<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil pajak PERUSAHAAN (pemberi kerja): NPWP terenkripsi.
 *
 * NPWP pemberi kerja dipakai di Draf 1721-A1 dan ekspor e-Bupot draf. Disimpan
 * terenkripsi (cast `encrypted` pada model Company) dan HANYA dikirim termasking
 * ke response JSON; nilai penuh muncul hanya di berkas ter-stream (PDF/XML) yang
 * di-gate izin `manage` + audit. Butuh izin modul Payroll ('read' / 'manage').
 */
class CompanyTaxProfileController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola profil pajak perusahaan.'], 403);
    }

    /** Perusahaan milik user aktif. */
    private function resolveCompany(Request $request): Company
    {
        return Company::findOrFail($request->user()->company_id);
    }

    /**
     * GET /dashboard/payroll/company-tax-profile
     * Profil pajak perusahaan (NPWP termasking saja).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $company = $this->resolveCompany($request);

        return response()->json([
            'data' => [
                'name'        => $company->name,
                'address'     => $company->address,
                'has_npwp'    => ! empty($company->npwp),
                'npwp_masked' => $company->maskedNpwp(),
            ],
        ]);
    }

    /**
     * PUT /dashboard/payroll/company-tax-profile
     * Simpan NPWP perusahaan (terenkripsi). Dikirim balik termasking.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $validated = $request->validate([
            // NPWP opsional. Absen (null) = tidak mengubah; string kosong "" = menghapus.
            'npwp' => 'nullable|string|max:30',
        ]);

        $company = $this->resolveCompany($request);

        // Update NPWP hanya jika field dikirim (present di request).
        if ($request->has('npwp')) {
            $raw = (string) ($validated['npwp'] ?? '');
            $digits = preg_replace('/\D/', '', $raw);

            if ($digits === '') {
                $company->npwp = null;
            } else {
                if (strlen($digits) < 15 || strlen($digits) > 16) {
                    return response()->json([
                        'message' => 'NPWP tidak valid. Masukkan 15 digit (lama) atau 16 digit (baru).',
                        'errors'  => ['npwp' => ['Panjang NPWP harus 15 atau 16 digit.']],
                    ], 422);
                }
                $company->npwp = $digits;
            }
        }

        $company->save();

        AuditLogger::log(
            action: 'COMPANY_TAX_PROFILE_UPDATED',
            description: "Menyimpan NPWP pemberi kerja untuk perusahaan {$company->name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Company',
            entityId: $company->id,
            newValues: ['has_npwp' => ! empty($company->npwp)],
        );

        return response()->json([
            'message' => 'Profil pajak perusahaan berhasil disimpan.',
            'data'    => [
                'name'        => $company->name,
                'address'     => $company->address,
                'has_npwp'    => ! empty($company->npwp),
                'npwp_masked' => $company->maskedNpwp(),
            ],
        ]);
    }
}
