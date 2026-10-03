<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\PayslipItem;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FcmService;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Severance\SeveranceCalculatorService;
use App\Services\Payroll\Thr\ThrCalculatorService;
use App\Services\PayrollAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Batch proses payroll bertahap:
 *   draft → calculated → submitted → approved → paid   (atau → rejected)
 *
 * Prinsip keamanan:
 *  - Maker-checker: penyetuju (approved_by) TIDAK boleh sama dengan penyiap (prepared_by).
 *  - Immutability: status approved/paid mengunci batch (tidak bisa hitung ulang / hapus).
 *  - Semua mutasi ter-audit via AuditLogger (kategori FINANCE).
 */
class PayrollController extends Controller
{
    private const MONTHS_ID = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly ThrCalculatorService $thrCalculator,
        private readonly SeveranceCalculatorService $severanceCalculator,
    ) {
    }

    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola payroll.'], 403);
    }

    private function periodLabel(int $month, int $year): string
    {
        return (self::MONTHS_ID[$month] ?? $month) . ' ' . $year;
    }

    /**
     * Step-up PIN (soft rollout, Fase 3): jika penyetuju SUDAH mengatur PIN, aksi uang
     * kritikal (approve/mark-paid) wajib menyertakan `pin` yang benar. Bila PIN belum
     * diatur → dilewati (non-breaking bagi FE lama & 442 tes lama). Tidak ada lockout
     * kustom di sini (andalkan throttle:actions pada rute) untuk mencegah regresi lockout.
     *
     * @return JsonResponse|null  Response 422 bila PIN salah/kosong, atau null bila lolos/di-skip.
     */
    private function verifyStepUpPin(Request $request, $user): ?JsonResponse
    {
        if (! $user->security_pin) {
            return null; // PIN belum diatur → soft rollout, lanjut normal.
        }

        $pin = (string) $request->input('pin', '');
        if ($pin === '' || ! Hash::check($pin, $user->security_pin)) {
            AuditLogger::log(
                action: 'PAYROLL_PIN_FAILED',
                description: 'PIN keamanan salah/kosong saat aksi final payroll.',
                category: AuditLogger::CATEGORY_SECURITY,
                severity: AuditLogger::SEVERITY_WARNING,
                entityType: 'User',
                entityId: (int) $user->id,
            );

            return response()->json([
                'message' => 'PIN keamanan salah atau belum diisi.',
                'errors'  => ['pin' => ['PIN keamanan salah atau belum diisi.']],
            ], 422);
        }

        return null;
    }

    /** Catat event lifecycle batch ke rantai audit tamper-evident (payroll_logs). */
    private function payrollChainLog(Payroll $payroll, string $action, array $after = []): void
    {
        PayrollAuditLogger::log($action, [
            'company_id'  => (int) $payroll->company_id,
            'payroll_id'  => (int) $payroll->id,
            'entity_type' => 'Payroll',
            'entity_id'   => (int) $payroll->id,
            'after'       => $after ?: null,
        ]);
    }

    /**
     * Notifikasi (in-app + FCM terjaga) bahwa gaji telah dibayarkan (Fase 5 mobile).
     * Mirror `PayrollPaymentController::notifyDisbursement()`: per-slip, dibungkus try/catch
     * agar kegagalan kirim FCM ke satu karyawan tidak menggagalkan slip lain atau respons markPaid.
     */
    private function notifyPayrollPaid(Payroll $payroll): void
    {
        foreach ($payroll->payslips()->get() as $slip) {
            try {
                $amount  = 'Rp ' . number_format((float) $slip->net, 0, ',', '.');
                $message = "Gaji periode {$payroll->period_label} sebesar {$amount} telah dibayarkan.";

                DB::table('notifications')->insert([
                    'id'              => Str::uuid()->toString(),
                    'type'            => 'payroll_paid',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id'   => $slip->user_id,
                    'user_id'         => $slip->user_id,
                    'data'            => json_encode([
                        'title'      => 'Gaji Telah Dibayarkan',
                        'message'    => $message,
                        'payslip_id' => $slip->id,
                        'payroll_id' => $payroll->id,
                    ]),
                    'entity_type'     => 'Payslip',
                    'entity_id'       => $slip->id,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                $target = User::find($slip->user_id);
                if ($target && $target->fcm_token) {
                    app(FcmService::class)->send(
                        $target->fcm_token,
                        'Gaji Telah Dibayarkan',
                        $message,
                        ['type' => 'payroll_paid', 'entity_type' => 'Payslip', 'entity_id' => (string) $slip->id],
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("Gagal kirim notif payroll_paid ke user #{$slip->user_id}: {$e->getMessage()}");
            }
        }
    }

    /** GET /dashboard/payroll/runs */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $runs = Payroll::with(['branch:id,office_name', 'preparedBy:id,name', 'approvedBy:id,name'])
            ->where('company_id', $user->company_id)
            ->when($request->filled('year'), fn ($q) => $q->where('period_year', (int) $request->query('year')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $runs]);
    }

    /** POST /dashboard/payroll/runs */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'period_month'          => 'required|integer|min:1|max:12',
            'period_year'           => 'required|integer|min:2020|max:2100',
            'attendance_setting_id' => [
                'nullable', 'integer',
                Rule::exists('attendance_settings', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            // Grup payroll opsional: batch hanya menghitung anggota grup tsb (harus milik company ini).
            'payroll_group_id'      => [
                'nullable', 'integer',
                Rule::exists('payroll_groups', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            // Jenis batch: 'regular' (default), 'thr' (Tunjangan Hari Raya terpisah),
            // atau 'severance' (exit settlement: pesangon PHK & kompensasi PKWT).
            'run_type'              => ['nullable', Rule::in(Payroll::RUN_TYPES)],
            'is_year_end'           => 'boolean',
            'notes'                 => 'nullable|string|max:1000',
        ]);

        $month    = (int) $validated['period_month'];
        $year     = (int) $validated['period_year'];
        $branchId = $validated['attendance_setting_id'] ?? null;
        $groupId  = $validated['payroll_group_id'] ?? null;
        $runType  = $validated['run_type'] ?? Payroll::RUN_TYPE_REGULAR;

        // Batch THR & pesangon tidak pernah menjadi batch akhir tahun (penyetahunan
        // Pasal 17 hanya pada run reguler Desember — THR memakai TER marginal, dan
        // pesangon memakai PPh 21 Final PP 68/2009 yang tidak direkonsiliasi).
        $isYearEnd = in_array($runType, [Payroll::RUN_TYPE_THR, Payroll::RUN_TYPE_SEVERANCE], true)
            ? false
            : ($validated['is_year_end'] ?? ($month === 12));

        // Cegah duplikat periode dalam RUANG-LINGKUP sama (jenis batch + grup + cabang).
        // Guard aplikatif ini OTORITATIF: unique index DB tak dapat menegakkan saat kolom
        // NULL (MySQL menganggap NULL distinct) — lihat hazard NULL-distinct pada rencana.
        $exists = Payroll::where('company_id', $companyId)
            ->where('run_type', $runType)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->when($branchId === null, fn ($q) => $q->whereNull('attendance_setting_id'))
            ->when($branchId !== null, fn ($q) => $q->where('attendance_setting_id', $branchId))
            ->when($groupId === null, fn ($q) => $q->whereNull('payroll_group_id'))
            ->when($groupId !== null, fn ($q) => $q->where('payroll_group_id', $groupId))
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Batch payroll untuk periode, jenis, grup & cabang tersebut sudah ada.',
            ], 422);
        }

        $payroll = Payroll::create([
            'company_id'            => $companyId,
            'attendance_setting_id' => $branchId,
            'run_type'              => $runType,
            'payroll_group_id'      => $groupId,
            'period_month'          => $month,
            'period_year'           => $year,
            'period_label'          => $this->periodLabel($month, $year),
            'is_year_end'           => $isYearEnd,
            'status'                => Payroll::STATUS_DRAFT,
            'prepared_by'           => $user->id,
            'notes'                 => $validated['notes'] ?? null,
        ]);

        AuditLogger::log(
            action: 'PAYROLL_RUN_CREATED',
            description: "Membuat batch payroll {$payroll->period_label}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Payroll',
            entityId: $payroll->id,
            newValues: $payroll->toArray(),
        );

        $this->payrollChainLog($payroll, 'PAYROLL_RUN_CREATED', [
            'status'       => $payroll->status,
            'period_label' => $payroll->period_label,
        ]);

        return response()->json(['message' => 'Batch payroll dibuat.', 'data' => $payroll], 201);
    }

    /** GET /dashboard/payroll/runs/{payroll} */
    public function show(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }

        $payroll->load([
            'branch:id,office_name',
            'preparedBy:id,name',
            'submittedBy:id,name',
            'approvedBy:id,name',
            'paidBy:id,name',
            'payslips' => fn ($q) => $q->orderBy('employee_name')
                ->select('id', 'payroll_id', 'user_id', 'employee_name', 'employee_code', 'gross', 'total_deduction', 'pph21', 'net', 'status'),
        ]);

        return response()->json(['data' => $payroll]);
    }

    /** POST /dashboard/payroll/runs/{payroll}/calculate */
    public function calculate(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }
        if (! in_array($payroll->status, [Payroll::STATUS_DRAFT, Payroll::STATUS_CALCULATED, Payroll::STATUS_REJECTED], true)) {
            return response()->json(['message' => 'Batch pada status ini tidak dapat dihitung ulang.'], 422);
        }

        $options = $request->validate([
            'pph21'                 => 'boolean',
            'bpjs'                  => 'boolean',
            'overtime'              => 'boolean',
            'attendance_deduction'  => 'boolean',
            'receipt_reimbursement' => 'boolean',
            'loan_installment'      => 'boolean',
            'adjustments'           => 'boolean',
            'working_days_divisor'  => 'nullable|integer|min:1|max:31',
        ]);

        try {
            DB::transaction(function () use ($payroll, $options) {
                // Bila sebelumnya rejected, kembalikan ke alur normal.
                if ($payroll->status === Payroll::STATUS_REJECTED) {
                    $payroll->update(['status' => Payroll::STATUS_DRAFT, 'rejected_by' => null, 'rejected_at' => null, 'reject_reason' => null]);
                }
                // Percabangan jenis batch:
                //  - THR       → slip ringkas + PPh21 TER marginal
                //  - severance → exit settlement (UP/UPMK/UPH + PPh 21 Final PP 68/2009)
                //  - selainnya → alur gaji reguler.
                if ($payroll->isThr()) {
                    $this->thrCalculator->calculate($payroll, $options);
                } elseif ($payroll->isSeverance()) {
                    $this->severanceCalculator->calculate($payroll, $options);
                } else {
                    $this->calculator->calculate($payroll, $options);
                }
            });
        } catch (\RuntimeException $e) {
            // Kurs valuta asing belum tersedia (Fase 6). Transaksi sudah di-rollback:
            // batch tetap pada status semula — lebih baik gagal daripada memakai 1:1.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payroll->refresh();

        AuditLogger::log(
            action: 'PAYROLL_CALCULATED',
            description: "Menghitung payroll {$payroll->period_label}: {$payroll->employee_count} karyawan, neto {$payroll->total_net}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Payroll',
            entityId: $payroll->id,
        );

        $this->payrollChainLog($payroll, 'PAYROLL_CALCULATED', [
            'employee_count' => (int) $payroll->employee_count,
            'total_net'      => (string) $payroll->total_net,
            'total_gross'    => (string) $payroll->total_gross,
        ]);

        return response()->json([
            'message' => "Perhitungan selesai untuk {$payroll->employee_count} karyawan.",
            'data'    => $payroll,
        ]);
    }

    /** POST /dashboard/payroll/runs/{payroll}/submit */
    public function submit(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }
        if ($payroll->status !== Payroll::STATUS_CALCULATED) {
            return response()->json(['message' => 'Hanya batch yang sudah dihitung yang dapat diajukan.'], 422);
        }
        if ($payroll->payslips()->count() === 0) {
            return response()->json(['message' => 'Batch tidak memiliki slip. Hitung terlebih dahulu.'], 422);
        }

        $payroll->update([
            'status'       => Payroll::STATUS_SUBMITTED,
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ]);

        AuditLogger::log(
            action: 'PAYROLL_SUBMITTED',
            description: "Mengajukan payroll {$payroll->period_label} untuk persetujuan",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'Payroll',
            entityId: $payroll->id,
        );

        $this->payrollChainLog($payroll, 'PAYROLL_SUBMITTED', ['status' => $payroll->status]);

        return response()->json(['message' => 'Payroll diajukan untuk persetujuan.', 'data' => $payroll]);
    }

    /** POST /dashboard/payroll/runs/{payroll}/approve */
    public function approve(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }
        if ($payroll->status !== Payroll::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Hanya batch yang diajukan yang dapat disetujui.'], 422);
        }
        // Maker-checker: penyetuju tidak boleh penyiap batch ini.
        if ((int) $payroll->prepared_by === (int) $user->id) {
            return response()->json([
                'message' => 'Anda tidak dapat menyetujui payroll yang Anda siapkan sendiri. Persetujuan wajib oleh orang berbeda (maker-checker).',
            ], 403);
        }
        // Step-up PIN (soft): wajib hanya bila penyetuju sudah mengatur PIN.
        if ($resp = $this->verifyStepUpPin($request, $user)) {
            return $resp;
        }

        DB::transaction(function () use ($payroll, $user) {
            $payroll->update([
                'status'      => Payroll::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
            $payroll->payslips()->update(['status' => 'approved']);
        });

        AuditLogger::log(
            action: 'PAYROLL_APPROVED',
            description: "Menyetujui payroll {$payroll->period_label} (neto {$payroll->total_net})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'Payroll',
            entityId: $payroll->id,
        );

        $this->payrollChainLog($payroll, 'PAYROLL_APPROVED', [
            'status'      => $payroll->status,
            'approved_by' => (int) $payroll->approved_by,
            'total_net'   => (string) $payroll->total_net,
        ]);

        return response()->json(['message' => 'Payroll disetujui.', 'data' => $payroll]);
    }

    /** POST /dashboard/payroll/runs/{payroll}/reject */
    public function reject(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }
        if ($payroll->status !== Payroll::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Hanya batch yang diajukan yang dapat ditolak.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $payroll->update([
            'status'        => Payroll::STATUS_REJECTED,
            'rejected_by'   => $user->id,
            'rejected_at'   => now(),
            'reject_reason' => $validated['reason'],
        ]);

        AuditLogger::log(
            action: 'PAYROLL_REJECTED',
            description: "Menolak payroll {$payroll->period_label}: {$validated['reason']}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'Payroll',
            entityId: $payroll->id,
        );

        $this->payrollChainLog($payroll, 'PAYROLL_REJECTED', [
            'status' => $payroll->status,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['message' => 'Payroll ditolak & dikembalikan untuk perbaikan.', 'data' => $payroll]);
    }

    /** POST /dashboard/payroll/runs/{payroll}/mark-paid */
    public function markPaid(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }
        if ($payroll->status !== Payroll::STATUS_APPROVED) {
            return response()->json(['message' => 'Hanya batch yang disetujui yang dapat ditandai dibayar.'], 422);
        }
        // Step-up PIN (soft): wajib hanya bila pelaksana sudah mengatur PIN.
        if ($resp = $this->verifyStepUpPin($request, $user)) {
            return $resp;
        }

        $paidReceipts = [];

        DB::transaction(function () use ($payroll, $user, &$paidReceipts) {
            $payroll->update([
                'status'  => Payroll::STATUS_PAID,
                'paid_by' => $user->id,
                'paid_at' => now(),
            ]);
            $payroll->payslips()->update(['status' => 'paid']);

            // Majukan cicilan kasbon SATU KALI (saat pembayaran final).
            $this->advanceLoans($payroll);

            // Tandai struk reimbursement yang dibayar lewat slip gaji ini sebagai lunas,
            // agar tidak bisa dicairkan ulang lewat alur transfer bank (anti dobel bayar).
            $paidReceipts = $this->settleReimbursedReceipts($payroll, $user);
        });

        // Notifikasi (in-app + FCM terjaga) ke tiap karyawan bahwa gajinya sudah dibayar.
        $this->notifyPayrollPaid($payroll);

        // Notifikasi pencairan struk via gaji (di luar transaksi, konsisten dgn notifikasi lain).
        $this->notifyReceiptsSettledViaPayroll($payroll, $paidReceipts);

        AuditLogger::log(
            action: 'PAYROLL_PAID',
            description: "Menandai payroll {$payroll->period_label} sebagai dibayar (neto {$payroll->total_net})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'Payroll',
            entityId: $payroll->id,
        );

        $this->payrollChainLog($payroll, 'PAYROLL_PAID', [
            'status'            => $payroll->status,
            'paid_by'           => (int) $payroll->paid_by,
            'total_net'         => (string) $payroll->total_net,
            'receipts_settled'  => count($paidReceipts),
        ]);

        $message = 'Payroll ditandai sudah dibayar.';
        if ($paidReceipts !== []) {
            $message .= ' ' . count($paidReceipts) . ' struk reimbursement ikut ditandai lunas (dibayar via gaji).';
        }

        return response()->json([
            'message' => $message,
            'data'    => $payroll,
            'meta'    => ['receipts_settled' => count($paidReceipts)],
        ]);
    }

    /** DELETE /dashboard/payroll/runs/{payroll} */
    public function destroy(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }
        // Hanya draft / rejected yang boleh dihapus. Approved & paid terkunci.
        if (! in_array($payroll->status, [Payroll::STATUS_DRAFT, Payroll::STATUS_CALCULATED, Payroll::STATUS_REJECTED], true)) {
            return response()->json(['message' => 'Batch yang sudah diajukan/disetujui/dibayar tidak dapat dihapus.'], 422);
        }

        $label = $payroll->period_label;
        $id = $payroll->id;
        $companyId = (int) $payroll->company_id;

        DB::transaction(function () use ($payroll) {
            // Lepas klaim penyesuaian batch ini agar bisa dipakai batch lain (kembali approved).
            PayrollAdjustment::where('payroll_id', $payroll->id)
                ->where('status', PayrollAdjustment::STATUS_APPLIED)
                ->update([
                    'payroll_id' => null,
                    'status'     => PayrollAdjustment::STATUS_APPROVED,
                    'applied_at' => null,
                ]);

            $payroll->payslips()->delete(); // items ikut cascade
            $payroll->delete();
        });

        AuditLogger::log(
            action: 'PAYROLL_RUN_DELETED',
            description: "Menghapus batch payroll {$label}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'Payroll',
            entityId: $id,
        );

        // payroll_id = null (batch sudah terhapus; FK payroll_logs SET NULL) — id disimpan di notes.
        PayrollAuditLogger::log('PAYROLL_RUN_DELETED', [
            'company_id'  => $companyId,
            'payroll_id'  => null,
            'entity_type' => 'Payroll',
            'entity_id'   => $id,
            'notes'       => "Batch {$label} (id {$id}) dihapus.",
        ]);

        return response()->json(['message' => "Batch payroll {$label} dihapus."]);
    }

    /** GET /dashboard/payroll/runs/{payroll}/logs — jejak audit tamper-evident batch + status integritas. */
    public function logs(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch payroll tidak ditemukan.'], 404);
        }

        $logs = DB::table('payroll_logs')
            ->where('company_id', $payroll->company_id)
            ->where('payroll_id', $payroll->id)
            ->orderBy('sequence')
            ->get(['id', 'action', 'user_id', 'notes', 'before_state', 'after_state', 'ip_address', 'sequence', 'prev_hash', 'record_hash', 'created_at'])
            ->map(function ($row) {
                // before/after tersimpan sebagai TEXT JSON kanonik → decode agar rapi di FE.
                $row->before_state = $row->before_state !== null ? json_decode($row->before_state, true) : null;
                $row->after_state  = $row->after_state !== null ? json_decode($row->after_state, true) : null;
                return $row;
            });

        // Integritas rantai per-perusahaan (bukan hanya batch ini — rantai bersifat global company).
        $integrity = PayrollAuditLogger::verifyChain((int) $payroll->company_id);

        return response()->json(['data' => ['logs' => $logs, 'integrity' => $integrity]]);
    }

    /**
     * Kurangi saldo kasbon berdasarkan cicilan yang benar-benar dipotong pada batch ini.
     * Dipanggil hanya saat markPaid (transisi approved → paid) agar tidak dobel potong.
     */
    private function advanceLoans(Payroll $payroll): void
    {
        $loanItems = PayslipItem::query()
            ->join('payslips', 'payslips.id', '=', 'payslip_items.payslip_id')
            ->where('payslips.payroll_id', $payroll->id)
            ->where('payslip_items.source', 'loan')
            ->where('payslip_items.ref_type', EmployeeLoan::class)
            ->whereNotNull('payslip_items.ref_id')
            ->get(['payslip_items.ref_id as loan_id', 'payslip_items.amount as amount']);

        // Gabungkan per pinjaman (harusnya 1 baris per pinjaman per batch).
        $byLoan = [];
        foreach ($loanItems as $it) {
            $byLoan[(int) $it->loan_id] = ($byLoan[(int) $it->loan_id] ?? 0) + (float) $it->amount;
        }

        foreach ($byLoan as $loanId => $amount) {
            $loan = EmployeeLoan::where('id', $loanId)
                ->where('company_id', $payroll->company_id)
                ->first();
            if (! $loan || $loan->status !== EmployeeLoan::STATUS_ACTIVE) {
                continue;
            }

            $remaining = max(0, (float) $loan->remaining_amount - $amount);
            $loan->remaining_amount = $remaining;
            $loan->installments_paid = (int) $loan->installments_paid + 1;
            if ($remaining <= 0) {
                $loan->status = EmployeeLoan::STATUS_PAID;
            }
            $loan->save();
        }
    }

    /**
     * Tandai struk reimbursement yang ikut dibayarkan lewat slip gaji batch ini
     * sebagai LUNAS (`status=paid`, `payment_method='payroll'`).
     *
     * Dipanggil hanya saat markPaid (transisi approved → paid), di dalam transaksi
     * yang sama agar status payroll & struk tidak pernah terpisah. Struk yang
     * sudah lunas lewat alur lain (`paid_at` terisi) dilewati — pencairan ganda
     * dicegah di dua arah: di sini dan di `PayrollCalculator` (filter `paid_at` null).
     *
     * @return array<int, array{id: int, receipt_number: string, user_id: int, amount: float}>
     */
    private function settleReimbursedReceipts(Payroll $payroll, User $actor): array
    {
        $receiptItems = PayslipItem::query()
            ->join('payslips', 'payslips.id', '=', 'payslip_items.payslip_id')
            ->where('payslips.payroll_id', $payroll->id)
            ->where('payslip_items.source', 'receipt')
            ->where('payslip_items.ref_type', Receipt::class)
            ->whereNotNull('payslip_items.ref_id')
            ->get(['payslip_items.ref_id as receipt_id', 'payslip_items.amount as amount']);

        if ($receiptItems->isEmpty()) {
            return [];
        }

        // Gabungkan per struk (normalnya 1 baris slip per struk per batch).
        $byReceipt = [];
        foreach ($receiptItems as $it) {
            $id = (int) $it->receipt_id;
            $byReceipt[$id] = ($byReceipt[$id] ?? 0) + (float) $it->amount;
        }

        $settled = [];
        $refNo = 'PAYROLL-' . $payroll->id;

        foreach ($byReceipt as $receiptId => $amount) {
            $receipt = Receipt::where('id', $receiptId)
                ->where('company_id', $payroll->company_id)
                ->lockForUpdate()
                ->first();

            // Lewati struk yang hilang, sudah lunas, atau statusnya sudah bergeser
            // (mis. di-reject setelah batch dihitung) — jangan paksa jadi paid.
            if (! $receipt || $receipt->paid_at !== null || $receipt->status !== 'approved') {
                continue;
            }

            $receipt->update([
                'status'         => 'paid',
                'paid_at'        => now(),
                'paid_by'        => $actor->id,
                'payment_method' => 'payroll',
                'payment_ref_no' => $refNo,
            ]);

            DB::table('activity_logs')->insert([
                'company_id'   => $payroll->company_id,
                'user_id'      => $actor->id,
                'action'       => 'receipt_paid',
                'description'  => 'Pencairan dana struk ' . $receipt->receipt_number
                    . ' via slip gaji ' . $payroll->period_label . ' (Metode: payroll, Ref: ' . $refNo . ')',
                'subject_type' => 'receipt',
                'subject_id'   => $receipt->id,
                'entity_type'  => 'receipt',
                'entity_id'    => $receipt->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $settled[] = [
                'id'             => (int) $receipt->id,
                'receipt_number' => (string) $receipt->receipt_number,
                'user_id'        => (int) $receipt->user_id,
                'amount'         => $amount,
            ];
        }

        return $settled;
    }

    /**
     * Notifikasi ke karyawan bahwa struknya dicairkan lewat gaji (in-app + FCM terjaga).
     * Pola sama dengan notifyPayrollPaid(): per-struk & dibungkus try/catch agar
     * kegagalan kirim tidak menggagalkan respons markPaid (uang sudah tercatat lunas).
     *
     * @param array<int, array{id: int, receipt_number: string, user_id: int, amount: float}> $settled
     */
    private function notifyReceiptsSettledViaPayroll(Payroll $payroll, array $settled): void
    {
        foreach ($settled as $row) {
            try {
                $amount = 'Rp ' . number_format($row['amount'], 0, ',', '.');
                $message = 'Dana reimbursement struk ' . $row['receipt_number'] . ' sebesar ' . $amount
                    . ' telah dicairkan melalui slip gaji ' . $payroll->period_label . '.';

                DB::table('notifications')->insert([
                    'id'              => Str::uuid()->toString(),
                    'type'            => 'receipt_paid',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id'   => $row['user_id'],
                    'user_id'         => $row['user_id'],
                    'data'            => json_encode([
                        'title'          => 'Reimbursement Dicairkan via Gaji',
                        'message'        => $message,
                        'receipt_id'     => $row['id'],
                        'receipt_number' => $row['receipt_number'],
                        'status'         => 'paid',
                        'payment_method' => 'payroll',
                        'payroll_id'     => $payroll->id,
                    ]),
                    'entity_type'     => 'receipt',
                    'entity_id'       => $row['id'],
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                $target = User::find($row['user_id']);
                if ($target && $target->fcm_token) {
                    app(FcmService::class)->send(
                        $target->fcm_token,
                        'Reimbursement Dicairkan via Gaji',
                        $message,
                        ['type' => 'receipt_paid', 'entity_type' => 'receipt', 'entity_id' => (string) $row['id']],
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("Gagal kirim notif receipt_paid (via payroll) ke user #{$row['user_id']}: {$e->getMessage()}");
            }
        }
    }
}
