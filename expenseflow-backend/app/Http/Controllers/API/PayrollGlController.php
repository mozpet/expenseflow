<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\PayrollGlAccount;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\Payroll\Gl\PayrollGlComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jurnal Akuntansi / General Ledger payroll (Fase 4 lanjutan).
 *
 * Dua kelompok endpoint:
 *  1. Pemetaan akun (chart-of-accounts) per-perusahaan — EDITABLE:
 *     accounts (baca) / saveAccounts (tulis) / resetAccounts (revert ke default config).
 *  2. Jurnal per run — ON-DEMAND, tanpa tabel dokumen:
 *     preview (baca JSON) / export (unduh CSV/JSON, teraudit).
 *
 * Prinsip yang dipertahankan (mirror PayrollPaymentController):
 *  - Izin `read` untuk baca, `manage` untuk tulis/ekspor; scoping company (lintas-company → 404).
 *  - Uang `decimal:2`; aksi tulis/ekspor ter-audit (AuditLogger, CATEGORY_FINANCE).
 *  - Ekspor = peristiwa BACA → AuditLogger saja (tanpa hash-chain; chain-log khusus mutasi).
 *  - Jurnal disusun PayrollGlComposer & DIJAMIN seimbang (debit == kredit).
 */
class PayrollGlController extends Controller
{
    public function __construct(private readonly PayrollGlComposer $composer = new PayrollGlComposer())
    {
    }

    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang atas jurnal akuntansi payroll.'], 403);
    }

    /** Daftar key pos jurnal kanonik (dari config). */
    private function canonicalKeys(): array
    {
        return array_keys((array) config('payroll_gl.buckets', []));
    }

    /** GET /dashboard/payroll/gl-accounts — pemetaan akun efektif (override/default) untuk tabel editable FE. */
    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $accounts = array_values($this->composer->resolveAccounts((int) $user->company_id));

        return response()->json(['data' => $accounts]);
    }

    /** PUT /dashboard/payroll/gl-accounts — simpan (upsert) override akun per-perusahaan. */
    public function saveAccounts(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $validated = $request->validate([
            'accounts'                => ['required', 'array', 'min:1'],
            'accounts.*.key'          => ['required', 'string', Rule::in($this->canonicalKeys())],
            'accounts.*.account_code' => ['required', 'string', 'max:40'],
            'accounts.*.account_name' => ['required', 'string', 'max:150'],
        ]);

        DB::transaction(function () use ($validated, $user) {
            foreach ($validated['accounts'] as $acc) {
                PayrollGlAccount::updateOrCreate(
                    ['company_id' => (int) $user->company_id, 'key' => $acc['key']],
                    ['account_code' => trim($acc['account_code']), 'account_name' => trim($acc['account_name'])],
                );
            }
        });

        AuditLogger::log(
            action: 'PAYROLL_GL_ACCOUNTS_SAVED',
            description: 'Pemetaan akun jurnal payroll diperbarui (' . count($validated['accounts']) . ' pos).',
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'PayrollGlAccount',
            entityId: (int) $user->company_id,
            newValues: ['keys' => array_column($validated['accounts'], 'key')],
        );

        $accounts = array_values($this->composer->resolveAccounts((int) $user->company_id));

        return response()->json([
            'message' => 'Pemetaan akun jurnal tersimpan.',
            'data'    => $accounts,
        ]);
    }

    /** POST /dashboard/payroll/gl-accounts/reset — hapus override company (kembali ke default config). */
    public function resetAccounts(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $deleted = PayrollGlAccount::where('company_id', (int) $user->company_id)->delete();

        AuditLogger::log(
            action: 'PAYROLL_GL_ACCOUNTS_RESET',
            description: "Pemetaan akun jurnal payroll direset ke default ({$deleted} override dihapus).",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'PayrollGlAccount',
            entityId: (int) $user->company_id,
        );

        $accounts = array_values($this->composer->resolveAccounts((int) $user->company_id));

        return response()->json([
            'message' => 'Pemetaan akun jurnal dikembalikan ke default.',
            'data'    => $accounts,
        ]);
    }

    /**
     * Validasi & petakan parameter `?group_by` → dimensi composer.
     * none → null (jurnal flat); division → 'division'; branch → 'attendance_setting'.
     *
     * @return array{0: string, 1: ?string}  [groupBy, dimension|null]  (dimension null bila none/invalid)
     */
    private function resolveGroupBy(Request $request): array
    {
        $groupBy = strtolower((string) $request->query('group_by', 'none'));
        if (! in_array($groupBy, ['none', 'division', 'branch'], true)) {
            return [$groupBy, 'invalid'];
        }
        $dim = match ($groupBy) {
            'division' => 'division',
            'branch'   => 'attendance_setting',
            default    => null,
        };

        return [$groupBy, $dim];
    }

    /** GET /dashboard/payroll/runs/{payroll}/gl-preview?group_by=none|division|branch — pratinjau jurnal (JSON). */
    public function preview(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Payroll run tidak ditemukan.'], 404);
        }

        [$groupBy, $dim] = $this->resolveGroupBy($request);
        if ($dim === 'invalid') {
            return response()->json(['message' => 'Parameter group_by tidak valid. Gunakan none, division, atau branch.'], 422);
        }

        // none → jurnal flat (byte-identik dgn perilaku sebelumnya); lainnya → berdimensi.
        $journal = $dim === null
            ? $this->composer->compose($payroll)
            : $this->composer->composeGrouped($payroll, $dim);

        return response()->json([
            'data' => array_merge($journal, [
                'group_by' => $groupBy,
                'payroll' => [
                    'id'                 => $payroll->id,
                    'period_month'       => $payroll->period_month,
                    'period_year'        => $payroll->period_year,
                    'period_label'       => $payroll->period_label,
                    'status'             => $payroll->status,
                    'total_gross'        => $payroll->total_gross,
                    'total_deduction'    => $payroll->total_deduction,
                    'total_bpjs_company' => $payroll->total_bpjs_company,
                    'total_net'          => $payroll->total_net,
                ],
            ]),
        ]);
    }

    /** GET /dashboard/payroll/runs/{payroll}/gl-export?format=csv|json&group_by=none|division|branch — unduh jurnal (teraudit). */
    public function export(Request $request, Payroll $payroll): Response
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Payroll run tidak ditemukan.'], 404);
        }

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'json'], true)) {
            return response()->json(['message' => 'Format tidak didukung. Gunakan csv atau json.'], 422);
        }

        [$groupBy, $dim] = $this->resolveGroupBy($request);
        if ($dim === 'invalid') {
            return response()->json(['message' => 'Parameter group_by tidak valid. Gunakan none, division, atau branch.'], 422);
        }

        // none → jurnal flat (byte-identik); division/branch → berdimensi (grand total wajib seimbang).
        $grouped = $dim !== null;
        $journal = $grouped ? $this->composer->composeGrouped($payroll, $dim) : $this->composer->compose($payroll);
        $totalDebit  = $grouped ? $journal['grand_total_debit']  : $journal['total_debit'];
        $totalCredit = $grouped ? $journal['grand_total_credit'] : $journal['total_credit'];

        $period = sprintf('%04d-%02d', (int) $payroll->period_year, (int) $payroll->period_month);
        $baseName = "jurnal-gaji-{$payroll->company_id}-{$period}" . ($grouped ? "-{$groupBy}" : '');

        AuditLogger::log(
            action: 'PAYROLL_GL_EXPORTED',
            description: "Jurnal akuntansi payroll #{$payroll->id} diekspor ({$format}, group_by={$groupBy}); seimbang=" . ($journal['balanced'] ? 'ya' : 'tidak') . '.',
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Payroll',
            entityId: (int) $payroll->id,
            newValues: [
                'format'       => $format,
                'group_by'     => $groupBy,
                'total_debit'  => (string) $totalDebit,
                'total_credit' => (string) $totalCredit,
                'balanced'     => $journal['balanced'],
            ],
        );

        if ($format === 'json') {
            $payload = array_merge($journal, [
                'group_by' => $groupBy,
                'payroll' => [
                    'id'           => $payroll->id,
                    'company_id'   => $payroll->company_id,
                    'period_month' => $payroll->period_month,
                    'period_year'  => $payroll->period_year,
                ],
                'generated_at' => now()->toIso8601String(),
            ]);

            return response(
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                200,
                [
                    'Content-Type'        => 'application/json; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $baseName . '.json"',
                ],
            );
        }

        $csv = $grouped
            ? $this->composer->toCsvGrouped($payroll, $journal)
            : $this->composer->toCsv($payroll, $journal);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $baseName . '.csv"',
        ]);
    }
}
