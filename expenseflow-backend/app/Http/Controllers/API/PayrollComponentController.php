<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Services\AuditLogger;
use App\Services\Payroll\Formula\FormulaEngine;
use App\Services\Payroll\Formula\FormulaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master komponen gaji (tunjangan / potongan) per perusahaan.
 * Semua endpoint butuh izin modul Payroll level 'manage'.
 */
class PayrollComponentController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola komponen gaji.'], 403);
    }

    /** GET /dashboard/payroll/components */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $components = SalaryComponent::where('company_id', $user->company_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $components]);
    }

    /** POST /dashboard/payroll/components */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'code'        => [
                'required', 'string', 'max:50',
                Rule::unique('salary_components', 'code')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'name'        => 'required|string|max:120',
            'type'        => ['required', Rule::in([SalaryComponent::TYPE_EARNING, SalaryComponent::TYPE_DEDUCTION])],
            'calc_type'   => ['required', Rule::in([SalaryComponent::CALC_FIXED, SalaryComponent::CALC_MANUAL, SalaryComponent::CALC_AUTO, SalaryComponent::CALC_FORMULA])],
            'formula_dsl' => 'nullable|string|max:' . FormulaEngine::MAX_LENGTH,
            'category'    => 'nullable|string|max:50',
            'is_taxable'  => 'boolean',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0|max:9999',
        ]);

        $code = strtoupper(trim($validated['code']));
        if ($resp = $this->checkReservedCode($code)) {
            return $resp;
        }

        $formulaDsl = null;
        if ($validated['calc_type'] === SalaryComponent::CALC_FORMULA) {
            $formulaDsl = trim((string) ($validated['formula_dsl'] ?? ''));
            if ($formulaDsl === '') {
                return response()->json(['message' => 'Formula wajib diisi untuk komponen bertipe formula.'], 422);
            }
            if ($resp = $this->checkFormula($companyId, $code, $formulaDsl, null)) {
                return $resp;
            }
        }

        $component = SalaryComponent::create([
            'company_id'  => $companyId,
            'code'        => $code,
            'name'        => $validated['name'],
            'type'        => $validated['type'],
            'calc_type'   => $validated['calc_type'],
            'formula_dsl' => $formulaDsl,
            'category'    => $validated['category'] ?? null,
            'is_taxable'  => $validated['is_taxable'] ?? true,
            'is_active'   => $validated['is_active'] ?? true,
            'sort_order'  => $validated['sort_order'] ?? 0,
        ]);

        AuditLogger::log(
            action: 'PAYROLL_COMPONENT_CREATED',
            description: "Membuat komponen gaji: {$component->name} ({$component->code})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'SalaryComponent',
            entityId: $component->id,
            newValues: $component->toArray(),
        );

        return response()->json(['message' => 'Komponen gaji berhasil dibuat.', 'data' => $component], 201);
    }

    /** PUT /dashboard/payroll/components/{component} */
    public function update(Request $request, SalaryComponent $component): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $component->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Komponen tidak ditemukan.'], 404);
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'code'        => [
                'required', 'string', 'max:50',
                Rule::unique('salary_components', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($component->id),
            ],
            'name'        => 'required|string|max:120',
            'type'        => ['required', Rule::in([SalaryComponent::TYPE_EARNING, SalaryComponent::TYPE_DEDUCTION])],
            'calc_type'   => ['required', Rule::in([SalaryComponent::CALC_FIXED, SalaryComponent::CALC_MANUAL, SalaryComponent::CALC_AUTO, SalaryComponent::CALC_FORMULA])],
            'formula_dsl' => 'nullable|string|max:' . FormulaEngine::MAX_LENGTH,
            'category'    => 'nullable|string|max:50',
            'is_taxable'  => 'boolean',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0|max:9999',
        ]);

        $code = strtoupper(trim($validated['code']));
        if ($resp = $this->checkReservedCode($code)) {
            return $resp;
        }

        // formula_dsl hanya dipertahankan bila calc_type = formula; selain itu di-null-kan.
        $formulaDsl = null;
        if ($validated['calc_type'] === SalaryComponent::CALC_FORMULA) {
            $formulaDsl = trim((string) ($validated['formula_dsl'] ?? ''));
            if ($formulaDsl === '') {
                return response()->json(['message' => 'Formula wajib diisi untuk komponen bertipe formula.'], 422);
            }
            if ($resp = $this->checkFormula($companyId, $code, $formulaDsl, $component->id)) {
                return $resp;
            }
        }

        $old = $component->toArray();
        $component->update([
            'code'        => $code,
            'name'        => $validated['name'],
            'type'        => $validated['type'],
            'calc_type'   => $validated['calc_type'],
            'formula_dsl' => $formulaDsl,
            'category'    => $validated['category'] ?? null,
            'is_taxable'  => $validated['is_taxable'] ?? $component->is_taxable,
            'is_active'   => $validated['is_active'] ?? $component->is_active,
            'sort_order'  => $validated['sort_order'] ?? $component->sort_order,
        ]);

        AuditLogger::log(
            action: 'PAYROLL_COMPONENT_UPDATED',
            description: "Memperbarui komponen gaji: {$component->name} ({$component->code})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'SalaryComponent',
            entityId: $component->id,
            oldValues: $old,
            newValues: $component->toArray(),
        );

        return response()->json(['message' => 'Komponen gaji berhasil diperbarui.', 'data' => $component]);
    }

    /** DELETE /dashboard/payroll/components/{component} */
    public function destroy(Request $request, SalaryComponent $component): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $component->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Komponen tidak ditemukan.'], 404);
        }

        // Cegah hapus komponen yang masih dipasang ke karyawan (FK restrictOnDelete).
        if ($component->employeeComponents()->exists()) {
            return response()->json([
                'message' => 'Komponen tidak dapat dihapus karena masih dipakai karyawan. Nonaktifkan saja bila tidak terpakai lagi.',
            ], 422);
        }

        $name = $component->name;
        $id = $component->id;
        $component->delete();

        AuditLogger::log(
            action: 'PAYROLL_COMPONENT_DELETED',
            description: "Menghapus komponen gaji: {$name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'SalaryComponent',
            entityId: $id,
        );

        return response()->json(['message' => "Komponen gaji '{$name}' berhasil dihapus."]);
    }

    /**
     * POST /dashboard/payroll/components/validate-formula
     *
     * Alat bantu editor formula HRD: validasi sebuah rumus DSL terhadap whitelist
     * (tanpa menyimpan), sekaligus daftar variabel/fungsi yang tersedia, dan uji
     * hitung opsional dengan konteks contoh. TIDAK pernah mengeksekusi kode —
     * murni lewat FormulaEngine (anti-eval, §6.A).
     */
    public function validateFormula(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $validated = $request->validate([
            'formula'  => 'required|string|max:1000',
            'code'     => 'nullable|string|max:50',
            'sample'   => 'nullable|array',
            'sample.*' => 'numeric',
        ]);

        $companyId = (int) $user->company_id;
        $engine = new FormulaEngine();

        // Variabel tambahan yang diizinkan = KODE komponen lain di perusahaan.
        $extraVars = SalaryComponent::where('company_id', $companyId)
            ->pluck('code')
            ->all();

        $result = $engine->validate($validated['formula'], $extraVars);

        $data = [
            'ok'                  => $result['ok'],
            'error'               => $result['error'],
            'position'            => $result['position'],
            'variables'           => $result['variables'],
            'functions'           => $result['functions'],
            'system_variables'    => FormulaEngine::SYSTEM_VARIABLES,
            'component_codes'      => array_values(array_filter($extraVars)),
            'available_functions' => array_keys(FormulaEngine::FUNCTIONS),
        ];

        // Uji hitung opsional (bila valid & konteks contoh diberikan).
        if ($result['ok'] && ! empty($validated['sample'])) {
            try {
                $data['sample_result'] = round($engine->evaluate($validated['formula'], $validated['sample']));
            } catch (FormulaException $e) {
                $data['sample_result'] = null;
                $data['sample_error']  = $e->getMessage();
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Tolak kode komponen yang bentrok dengan nama variabel sistem (mencegah
     * ambiguitas saat formula merujuk KODE komponen vs variabel sistem).
     */
    private function checkReservedCode(string $code): ?JsonResponse
    {
        if (array_key_exists($code, FormulaEngine::SYSTEM_VARIABLES)) {
            return response()->json([
                'message' => "Kode '{$code}' adalah nama variabel sistem yang dipesan dan tidak boleh dipakai sebagai kode komponen.",
            ], 422);
        }

        return null;
    }

    /**
     * Validasi rumus DSL sebuah komponen formula terhadap whitelist perusahaan
     * dan pastikan tidak menimbulkan ketergantungan melingkar dengan komponen
     * formula lain. Mengembalikan JsonResponse 422 bila gagal, null bila lolos.
     */
    private function checkFormula(int $companyId, string $code, string $formulaDsl, ?int $ignoreId): ?JsonResponse
    {
        $engine = new FormulaEngine();

        $siblings = SalaryComponent::where('company_id', $companyId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'code', 'calc_type', 'formula_dsl']);

        // Variabel tambahan yang diizinkan = seluruh KODE komponen lain.
        $extraVars = $siblings->pluck('code')->all();

        $result = $engine->validate($formulaDsl, $extraVars);
        if (! $result['ok']) {
            return response()->json([
                'message'  => 'Formula tidak valid: ' . $result['error'],
                'position' => $result['position'],
            ], 422);
        }

        // Bangun peta KODE → formula (komponen formula lain + kandidat ini) untuk cek siklus.
        $formulaMap = [];
        foreach ($siblings as $s) {
            if ($s->calc_type === SalaryComponent::CALC_FORMULA && ! empty($s->formula_dsl)) {
                $formulaMap[strtoupper($s->code)] = $s->formula_dsl;
            }
        }
        $formulaMap[$code] = $formulaDsl;

        $cycle = $engine->detectCycles($formulaMap);
        if ($cycle['has_cycle']) {
            $path = implode(' → ', $cycle['cycle']);

            return response()->json([
                'message' => "Formula menimbulkan ketergantungan melingkar antar komponen: {$path}.",
            ], 422);
        }

        return null;
    }
}
