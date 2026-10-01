<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\SeveranceCase;
use App\Services\AuditLogger;
use App\Services\Payroll\Severance\SeveranceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Berkas Perhitungan Akhir (Exit Settlement) — Fase 6.
 *
 * Menyimpan PARAMETER pengakhiran hubungan kerja per karyawan: dasar PHK,
 * tanggal berakhir, faktor pengali UP/UPMK (PP 35/2021 Pasal 40–57), sisa cuti,
 * uang pisah, dan potongan aset. Nominalnya sendiri dihitung oleh
 * SeveranceCalculatorService dan tersimpan sebagai payslip pada batch payroll
 * bertipe `severance`.
 *
 * Prinsip dipertahankan:
 *  - gate `read` untuk baca & `manage` untuk tulis;
 *  - scoping `company_id` di setiap query (lintas-company → 404);
 *  - berkas yang batch-nya sudah `approved`/`paid` bersifat immutable (→422);
 *  - `preview` murni-baca (tidak menulis apa pun), uang → JSON string;
 *  - seluruh mutasi ter-audit (CATEGORY_FINANCE).
 */
class SeveranceCaseController extends Controller
{
    public function __construct(
        private readonly SeveranceCalculatorService $calculator = new SeveranceCalculatorService(),
    ) {}

    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola berkas pesangon.'], 403);
    }

    /** GET /dashboard/payroll/severance-cases */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $cases = SeveranceCase::with([
                'user:id,name,employee_code,position_id',
                'payroll:id,period_month,period_year,run_type,status',
            ])
            ->where('company_id', $user->company_id)
            ->when($request->filled('payroll_id'), fn ($q) => $q->where('payroll_id', (int) $request->input('payroll_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('termination_type'), fn ($q) => $q->where('termination_type', (string) $request->input('termination_type')))
            ->orderByDesc('termination_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json(['data' => $cases]);
    }

    /** GET /dashboard/payroll/severance-cases/{severanceCase} */
    public function show(Request $request, SeveranceCase $severanceCase): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $severanceCase->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Berkas pesangon tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => $severanceCase->load([
                'user:id,name,employee_code,position_id',
                'payroll:id,period_month,period_year,run_type,status',
            ]),
        ]);
    }

    /**
     * GET /dashboard/payroll/severance-cases/{severanceCase}/preview
     *
     * Simulasi murni-baca: menampilkan rincian UP/UPMK/UPH, kompensasi PKWT,
     * PPh 21 Final, dan pelunasan pinjaman TANPA menyentuh basis data. Dipakai
     * HR untuk memeriksa angka sebelum batch dihitung/disetujui.
     */
    public function preview(Request $request, SeveranceCase $severanceCase): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $severanceCase->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Berkas pesangon tidak ditemukan.'], 404);
        }

        $preview = $this->calculator->preview($severanceCase);
        if ($preview === null) {
            return response()->json([
                'message' => 'Simulasi tidak dapat dibuat: data upah karyawan pada tanggal pengakhiran belum tersedia.',
            ], 422);
        }

        return response()->json(['data' => $preview]);
    }

    /** POST /dashboard/payroll/severance-cases */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;
        $validated = $request->validate($this->rules($companyId));

        if ($error = $this->guardPayroll($companyId, $validated['payroll_id'] ?? null)) {
            return $error;
        }
        if ($error = $this->guardDuplicate($companyId, (int) $validated['user_id'], $validated['payroll_id'] ?? null)) {
            return $error;
        }

        $case = SeveranceCase::create($this->payload($companyId, $validated) + [
            'status'     => SeveranceCase::STATUS_DRAFT,
            'created_by' => $user->id,
        ]);

        AuditLogger::log(
            action: 'SEVERANCE_CASE_CREATED',
            description: "Membuat berkas pesangon ({$case->termination_type}) untuk karyawan #{$case->user_id} per {$case->termination_date->format('Y-m-d')}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'SeveranceCase',
            entityId: $case->id,
            newValues: $case->toArray(),
        );

        return response()->json([
            'message' => 'Berkas pesangon berhasil dibuat.',
            'data'    => $case->load('user:id,name,employee_code,position_id'),
        ], 201);
    }

    /** PUT /dashboard/payroll/severance-cases/{severanceCase} */
    public function update(Request $request, SeveranceCase $severanceCase): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $severanceCase->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Berkas pesangon tidak ditemukan.'], 404);
        }
        if ($error = $this->guardImmutable($severanceCase)) {
            return $error;
        }

        $companyId = (int) $user->company_id;
        $validated = $request->validate($this->rules($companyId));

        if ($error = $this->guardPayroll($companyId, $validated['payroll_id'] ?? null)) {
            return $error;
        }
        if ($error = $this->guardDuplicate($companyId, (int) $validated['user_id'], $validated['payroll_id'] ?? null, $severanceCase->id)) {
            return $error;
        }

        $old = $severanceCase->toArray();
        // Perubahan parameter membatalkan status "calculated": angka lama sudah usang
        // sampai batch dihitung ulang.
        $severanceCase->update($this->payload($companyId, $validated) + [
            'status' => SeveranceCase::STATUS_DRAFT,
        ]);

        AuditLogger::log(
            action: 'SEVERANCE_CASE_UPDATED',
            description: "Memperbarui berkas pesangon #{$severanceCase->id} (karyawan #{$severanceCase->user_id})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'SeveranceCase',
            entityId: $severanceCase->id,
            oldValues: $old,
            newValues: $severanceCase->toArray(),
        );

        return response()->json([
            'message' => 'Berkas pesangon berhasil diperbarui.',
            'data'    => $severanceCase->load('user:id,name,employee_code,position_id'),
        ]);
    }

    /** DELETE /dashboard/payroll/severance-cases/{severanceCase} */
    public function destroy(Request $request, SeveranceCase $severanceCase): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $severanceCase->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Berkas pesangon tidak ditemukan.'], 404);
        }
        if ($error = $this->guardImmutable($severanceCase)) {
            return $error;
        }

        $id = $severanceCase->id;
        $userId = $severanceCase->user_id;
        $severanceCase->delete();

        AuditLogger::log(
            action: 'SEVERANCE_CASE_DELETED',
            description: "Menghapus berkas pesangon #{$id} (karyawan #{$userId})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'SeveranceCase',
            entityId: $id,
        );

        return response()->json(['message' => 'Berkas pesangon berhasil dihapus.']);
    }

    /**
     * Berkas yang batch-nya sudah disetujui/dibayar tidak boleh berubah —
     * sejalan dengan immutability payroll `approved`/`paid`.
     */
    private function guardImmutable(SeveranceCase $case): ?JsonResponse
    {
        if ($case->payroll_id === null) {
            return null;
        }

        $payroll = Payroll::find($case->payroll_id);
        if ($payroll && in_array($payroll->status, [Payroll::STATUS_APPROVED, Payroll::STATUS_PAID], true)) {
            return response()->json([
                'message' => 'Berkas tidak dapat diubah karena batch pesangon sudah disetujui/dibayar.',
            ], 422);
        }

        return null;
    }

    /** Batch tujuan wajib milik perusahaan yang sama, bertipe `severance`, dan belum final. */
    private function guardPayroll(int $companyId, mixed $payrollId): ?JsonResponse
    {
        if ($payrollId === null) {
            return null;
        }

        $payroll = Payroll::where('company_id', $companyId)->find((int) $payrollId);
        if (! $payroll) {
            return response()->json([
                'message' => 'Batch payroll tidak ditemukan.',
                'errors'  => ['payroll_id' => ['Batch payroll tidak ditemukan.']],
            ], 422);
        }
        if (! $payroll->isSeverance()) {
            return response()->json([
                'message' => 'Berkas pesangon hanya dapat dilampirkan pada batch bertipe pesangon (severance).',
                'errors'  => ['payroll_id' => ['Batch bukan bertipe pesangon.']],
            ], 422);
        }
        if (in_array($payroll->status, [Payroll::STATUS_APPROVED, Payroll::STATUS_PAID], true)) {
            return response()->json([
                'message' => 'Batch pesangon sudah disetujui/dibayar dan tidak dapat menerima berkas baru.',
                'errors'  => ['payroll_id' => ['Batch sudah final.']],
            ], 422);
        }

        return null;
    }

    /** Satu karyawan hanya boleh punya satu berkas per batch (mencegah slip ganda). */
    private function guardDuplicate(int $companyId, int $userId, mixed $payrollId, ?int $ignoreId = null): ?JsonResponse
    {
        if ($payrollId === null) {
            return null;
        }

        $exists = SeveranceCase::where('company_id', $companyId)
            ->where('payroll_id', (int) $payrollId)
            ->where('user_id', $userId)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Karyawan tersebut sudah memiliki berkas pesangon pada batch ini.',
                'errors'  => ['user_id' => ['Berkas untuk karyawan ini sudah ada di batch tersebut.']],
            ], 422);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(int $companyId, array $v): array
    {
        $isPkwt = $v['termination_type'] === SeveranceCase::TYPE_PKWT_END;

        return [
            'company_id'                => $companyId,
            'user_id'                   => (int) $v['user_id'],
            'payroll_id'                => isset($v['payroll_id']) ? (int) $v['payroll_id'] : null,
            'termination_type'          => $v['termination_type'],
            'termination_reason'        => $v['termination_reason'] ?? null,
            'termination_date'          => $v['termination_date'],
            'last_working_date'         => $v['last_working_date'] ?? $v['termination_date'],
            'employment_type'           => $isPkwt ? 'pkwt' : ($v['employment_type'] ?? 'pkwtt'),
            'contract_start_date'       => $v['contract_start_date'] ?? null,
            'contract_end_date'         => $v['contract_end_date'] ?? null,
            'up_multiplier'             => round((float) ($v['up_multiplier'] ?? 1), 2),
            'upmk_multiplier'           => round((float) ($v['upmk_multiplier'] ?? 1), 2),
            'include_uph'               => $v['include_uph'] ?? true,
            'annual_leave_balance_days' => round((float) ($v['annual_leave_balance_days'] ?? 0), 2),
            'relocation_cost'           => round((float) ($v['relocation_cost'] ?? 0), 2),
            'other_compensation'        => round((float) ($v['other_compensation'] ?? 0), 2),
            'separation_pay'            => round((float) ($v['separation_pay'] ?? 0), 2),
            'asset_deduction'           => round((float) ($v['asset_deduction'] ?? 0), 2),
            'settle_loans'              => $v['settle_loans'] ?? true,
            'notes'                     => $v['notes'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function rules(int $companyId): array
    {
        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'payroll_id'         => 'nullable|integer',
            'termination_type'   => ['required', Rule::in(SeveranceCase::TYPES)],
            'termination_reason' => 'nullable|string|max:255',
            'termination_date'   => 'required|date',
            'last_working_date'  => 'nullable|date',
            'employment_type'    => ['nullable', Rule::in(['pkwt', 'pkwtt'])],
            'contract_start_date' => 'nullable|date',
            'contract_end_date'   => 'nullable|date|after_or_equal:contract_start_date',
            // Faktor pengali PP 35/2021: 0,5× (mis. efisiensi/pailit tertentu) s.d. 2×
            // (mis. meninggal dunia, Pasal 57). Di luar itu bukan skema yang dikenal UU.
            'up_multiplier'             => 'nullable|numeric|min:0|max:2',
            'upmk_multiplier'           => 'nullable|numeric|min:0|max:2',
            'include_uph'               => 'boolean',
            'annual_leave_balance_days' => 'nullable|numeric|min:0|max:365',
            'relocation_cost'           => 'nullable|numeric|min:0|max:999999999999',
            'other_compensation'        => 'nullable|numeric|min:0|max:999999999999',
            'separation_pay'            => 'nullable|numeric|min:0|max:999999999999',
            'asset_deduction'           => 'nullable|numeric|min:0|max:999999999999',
            'settle_loans'              => 'boolean',
            'notes'                     => 'nullable|string|max:2000',
        ];
    }
}
