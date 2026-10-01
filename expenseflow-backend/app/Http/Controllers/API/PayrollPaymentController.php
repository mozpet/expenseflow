<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\PayrollPaymentBatch;
use App\Models\PayrollPaymentItem;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FcmService;
use App\Services\Payroll\Bank\BankFileFactory;
use App\Services\PayrollAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Disbursement gaji (Fase 4, spec §12 & §14.D) — ekspor berkas transfer bank + rekonsiliasi.
 *
 * ══════════════════════════ CATATAN DESAIN (penting) ══════════════════════════
 * Lapisan ini SENGAJA DIPISAH (decoupled) dari mesin status payroll & pelunasan kasbon:
 *  - `generate` hanya boleh untuk payroll berstatus approved/paid; ia MEMBACA slip,
 *    membangun berkas transfer (nomor rekening PENUH → disk PRIVAT + checksum SHA-256)
 *    dan menyimpan item termasking. Ia TIDAK mengubah status payslip/payroll.
 *  - `reconcile` memperbarui status per-item (success/failed/rejected_by_bank) + status
 *    batch + notifikasi ke karyawan sukses. Ia TIDAK mengubah payslip/payroll DAN TIDAK
 *    memajukan kasbon — pelunasan & advance-loan tetap SEPENUHNYA di PayrollController::markPaid.
 * Alasan: mencegah dobel-advance kasbon & menjaga nol-regresi pada suite pengujian lama.
 * Controller ini juga MANDIRI (helper PIN/chain/mask/notif sendiri) agar PayrollController
 * tidak tersentuh sama sekali.
 *
 * Prinsip keamanan yang dipertahankan:
 *  - Izin `manage` untuk aksi tulis, `read` untuk baca; scoping company (lintas-company → 404).
 *  - Nomor rekening: termasking di respons/DB; nomor penuh HANYA di berkas privat.
 *  - Step-up PIN (soft) pada generate (berkas berisi PII rekening).
 *  - Uang `decimal:2`; semua mutasi ter-audit (AuditLogger) + rantai tamper-evident (payroll_logs).
 */
class PayrollPaymentController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola pembayaran payroll.'], 403);
    }

    /**
     * Step-up PIN (soft rollout, mirror PayrollController): bila pengguna sudah mengatur PIN,
     * aksi sensitif (generate berkas berisi rekening) wajib menyertakan `pin` yang benar.
     * Tanpa PIN terset → dilewati. Tanpa lockout kustom (andalkan throttle rute).
     */
    private function verifyStepUpPin(Request $request, User $user): ?JsonResponse
    {
        if (! $user->security_pin) {
            return null;
        }

        $pin = (string) $request->input('pin', '');
        if ($pin === '' || ! Hash::check($pin, $user->security_pin)) {
            AuditLogger::log(
                action: 'PAYROLL_PIN_FAILED',
                description: 'PIN keamanan salah/kosong saat aksi disbursement payroll.',
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

    /** Catat event ke rantai audit tamper-evident (payroll_logs). */
    private function chainLog(PayrollPaymentBatch $batch, string $action, array $after = []): void
    {
        PayrollAuditLogger::log($action, [
            'company_id'  => (int) $batch->company_id,
            'payroll_id'  => (int) $batch->payroll_id,
            'entity_type' => 'PayrollPaymentBatch',
            'entity_id'   => (int) $batch->id,
            'after'       => $after ?: null,
        ]);
    }

    /** Masking nomor rekening: hanya 4 digit terakhir yang tampak (pola EmployeeBankAccount). */
    private function mask(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if ($digits === '') {
            return '••••';
        }
        $len = strlen($digits);

        return str_repeat('•', max(0, $len - 4)) . substr($digits, -4);
    }

    /** Notifikasi (in-app + FCM terjaga) bahwa gaji telah ditransfer (mirror notifyBankChange). */
    private function notifyDisbursement(PayrollPaymentItem $item, PayrollPaymentBatch $batch): void
    {
        try {
            $amount  = 'Rp ' . number_format((float) $item->amount, 0, ',', '.');
            $message = "Gaji Anda sebesar {$amount} telah ditransfer ke rekening {$item->bank_account_no_masked}.";

            DB::table('notifications')->insert([
                'id'              => Str::uuid()->toString(),
                'type'            => 'payroll_disbursed',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $item->user_id,
                'user_id'         => $item->user_id,
                'data'            => json_encode([
                    'title'             => 'Gaji Telah Ditransfer',
                    'message'           => $message,
                    'batch_reference'   => $batch->batch_reference,
                    'bank_reference_no' => $item->bank_reference_no,
                    'payment_item_id'   => $item->id,
                ]),
                'entity_type'     => 'PayrollPaymentItem',
                'entity_id'       => $item->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $target = User::find($item->user_id);
            if ($target && $target->fcm_token) {
                app(FcmService::class)->send(
                    $target->fcm_token,
                    'Gaji Telah Ditransfer',
                    $message,
                    ['type' => 'payroll_disbursed', 'entity_type' => 'PayrollPaymentItem', 'entity_id' => (string) $item->id],
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Gagal kirim notif disbursement ke user #{$item->user_id}: {$e->getMessage()}");
        }
    }

    /** GET /dashboard/payroll/runs/{payroll}/payment-batches — daftar batch untuk sebuah run. */
    public function index(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Payroll run tidak ditemukan.'], 404);
        }

        $batches = PayrollPaymentBatch::with(['generatedBy:id,name', 'reconciledBy:id,name'])
            ->withCount('items')
            ->where('payroll_id', $payroll->id)
            ->where('company_id', $user->company_id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data'          => $batches,
            'bank_formats'  => BankFileFactory::options(),
        ]);
    }

    /** POST /dashboard/payroll/runs/{payroll}/payment-batches — buat berkas transfer bank. */
    public function generate(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $payroll->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Payroll run tidak ditemukan.'], 404);
        }
        if (! in_array($payroll->status, [Payroll::STATUS_APPROVED, Payroll::STATUS_PAID], true)) {
            return response()->json([
                'message' => 'Berkas transfer hanya dapat dibuat untuk payroll yang sudah disetujui atau dibayar.',
            ], 422);
        }

        // Membuat berkas berisi nomor rekening penuh = aksi sensitif → step-up PIN (soft).
        if ($pinError = $this->verifyStepUpPin($request, $user)) {
            return $pinError;
        }

        $validated = $request->validate([
            'bank_format' => ['required', 'string', Rule::in(BankFileFactory::keys())],
            'value_date'  => ['nullable', 'date'],
        ]);

        $format    = $validated['bank_format'];
        $valueDate = ! empty($validated['value_date'])
            ? Carbon::parse($validated['value_date'])->format('Y-m-d')
            : now()->format('Y-m-d');

        // Susun baris berkas (nomor rekening PENUH) + item DB (termasking).
        $rows          = [];
        $items         = [];
        $seq           = 0;
        $skippedNoBank = 0;

        foreach ($payroll->payslips()->orderBy('employee_name')->get() as $slip) {
            $net     = (float) $slip->net;
            $account = trim((string) $slip->bank_account_no);

            if ($net <= 0) {
                continue; // tak ada nominal yang ditransfer
            }
            if ($account === '') {
                $skippedNoBank++; // tidak bisa transfer tanpa nomor rekening
                continue;
            }

            $seq++;
            $holder = (string) ($slip->bank_account_holder ?: $slip->employee_name);
            $rows[] = [
                'sequence'            => $seq,
                'employee_name'       => (string) $slip->employee_name,
                'employee_code'       => $slip->employee_code,
                'bank_name'           => $slip->bank_name,
                'bank_account_no'     => $account,
                'bank_account_holder' => $holder,
                'amount'              => round($net, 2),
            ];
            $items[] = [
                'payslip_id'             => $slip->id,
                'user_id'                => $slip->user_id,
                'bank_name'              => (string) $slip->bank_name,
                'bank_account_no_masked' => $this->mask($account),
                'bank_account_holder'    => $holder,
                'amount'                 => round($net, 2),
                'status'                 => PayrollPaymentItem::STATUS_PENDING,
            ];
        }

        if (empty($items)) {
            return response()->json([
                'message' => 'Tidak ada slip dengan neto > 0 dan rekening bank valid untuk ditransfer.',
            ], 422);
        }

        $totalRecords = count($items);
        $totalAmount  = round(array_sum(array_column($items, 'amount')), 2);

        $companyName    = (string) ($payroll->company?->name ?? '');
        $period         = sprintf('%04d-%02d', (int) $payroll->period_year, (int) $payroll->period_month);
        $batchReference = 'PB-' . $payroll->company_id . '-' . $payroll->id . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

        $formatter = BankFileFactory::make($format);
        $content   = $formatter->format($rows, [
            'batch_reference' => $batchReference,
            'company_name'    => $companyName,
            'period'          => $period,
            'value_date'      => $valueDate,
            'total_records'   => $totalRecords,
            'total_amount'    => $totalAmount,
            'currency'        => 'IDR',
        ]);
        $checksum = hash('sha256', $content);
        $filePath = 'payroll/payment-batches/' . $batchReference . '.' . $formatter->extension();

        $batch = DB::transaction(function () use (
            $payroll, $user, $batchReference, $format, $totalRecords, $totalAmount, $filePath, $checksum, $items, $content
        ) {
            $batch = PayrollPaymentBatch::create([
                'payroll_id'      => $payroll->id,
                'company_id'      => $payroll->company_id,
                'batch_reference' => $batchReference,
                'bank_format'     => $format,
                'total_records'   => $totalRecords,
                'total_amount'    => $totalAmount,
                'file_path'       => $filePath,
                'file_checksum'   => $checksum,
                'status'          => PayrollPaymentBatch::STATUS_FILE_GENERATED,
                'generated_by'    => $user->id,
            ]);

            foreach ($items as $item) {
                $item['payment_batch_id'] = $batch->id;
                PayrollPaymentItem::create($item);
            }

            // Simpan berkas privat berisi nomor rekening PENUH (di luar respons/JSON).
            Storage::disk('local')->put($filePath, $content);

            return $batch;
        });

        AuditLogger::log(
            action: 'PAYROLL_BANK_FILE_GENERATED',
            description: "Berkas transfer bank ({$format}) dibuat untuk payroll #{$payroll->id}: {$totalRecords} record, total {$totalAmount}.",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'PayrollPaymentBatch',
            entityId: (int) $batch->id,
            newValues: [
                'batch_reference' => $batchReference,
                'bank_format'     => $format,
                'total_records'   => $totalRecords,
                'total_amount'    => (string) $totalAmount,
                'file_checksum'   => $checksum,
            ],
        );
        $this->chainLog($batch, 'PAYROLL_BANK_FILE_GENERATED', [
            'batch_reference' => $batchReference,
            'bank_format'     => $format,
            'total_records'   => $totalRecords,
            'total_amount'    => (string) $totalAmount,
            'file_checksum'   => $checksum,
        ]);

        $batch->load(['items', 'generatedBy:id,name']);

        return response()->json([
            'message'         => 'Berkas transfer bank berhasil dibuat.',
            'skipped_no_bank' => $skippedNoBank,
            'data'            => $batch,
        ], 201);
    }

    /** GET /dashboard/payroll/payment-batches/{paymentBatch} — detail batch + item. */
    public function show(Request $request, PayrollPaymentBatch $paymentBatch): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if ((int) $paymentBatch->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch pembayaran tidak ditemukan.'], 404);
        }

        $paymentBatch->load([
            'items.user:id,name',
            'generatedBy:id,name',
            'reconciledBy:id,name',
            'payroll:id,period_month,period_year,status',
        ]);

        return response()->json(['data' => $paymentBatch]);
    }

    /** GET /dashboard/payroll/payment-batches/{paymentBatch}/download — unduh berkas transfer (nomor penuh). */
    public function download(Request $request, PayrollPaymentBatch $paymentBatch): StreamedResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            abort(403, 'Akses ditolak. Anda tidak memiliki wewenang mengunduh berkas transfer.');
        }
        if ((int) $paymentBatch->company_id !== (int) $user->company_id) {
            abort(404, 'Batch pembayaran tidak ditemukan.');
        }
        if (! $paymentBatch->file_path || ! Storage::disk('local')->exists($paymentBatch->file_path)) {
            abort(404, 'Berkas transfer belum tersedia.');
        }

        // Mengunduh berkas berisi nomor rekening penuh = peristiwa sensitif → audit.
        AuditLogger::log(
            action: 'PAYROLL_BANK_FILE_DOWNLOADED',
            description: "Berkas transfer bank diunduh untuk batch {$paymentBatch->batch_reference}.",
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'PayrollPaymentBatch',
            entityId: (int) $paymentBatch->id,
        );

        $filename = $paymentBatch->batch_reference . '.' . pathinfo($paymentBatch->file_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($paymentBatch->file_path, $filename);
    }

    /** POST /dashboard/payroll/payment-batches/{paymentBatch}/reconcile — rekonsiliasi hasil transfer. */
    public function reconcile(Request $request, PayrollPaymentBatch $paymentBatch): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $paymentBatch->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Batch pembayaran tidak ditemukan.'], 404);
        }
        if (! in_array($paymentBatch->status, [
            PayrollPaymentBatch::STATUS_FILE_GENERATED,
            PayrollPaymentBatch::STATUS_UPLOADED,
            PayrollPaymentBatch::STATUS_PARTIALLY_SETTLED,
        ], true)) {
            return response()->json([
                'message' => 'Batch belum siap direkonsiliasi atau sudah selesai.',
            ], 422);
        }

        $validated = $request->validate([
            'results'                     => ['required', 'array', 'min:1'],
            'results.*.payment_item_id'   => ['required', 'integer', Rule::exists('payroll_payment_items', 'id')->where('payment_batch_id', $paymentBatch->id)],
            'results.*.status'            => ['required', Rule::in([
                PayrollPaymentItem::STATUS_SUCCESS,
                PayrollPaymentItem::STATUS_FAILED,
                PayrollPaymentItem::STATUS_REJECTED,
            ])],
            'results.*.bank_reference_no' => ['nullable', 'string', 'max:100'],
            'results.*.failure_reason'    => ['nullable', 'string', 'max:255'],
        ]);

        $newlySuccess = [];

        DB::transaction(function () use ($paymentBatch, $validated, $user, &$newlySuccess) {
            foreach ($validated['results'] as $r) {
                $item = PayrollPaymentItem::where('id', $r['payment_item_id'])
                    ->where('payment_batch_id', $paymentBatch->id)
                    ->lockForUpdate()
                    ->first();
                if (! $item) {
                    continue;
                }

                $wasSuccess   = $item->status === PayrollPaymentItem::STATUS_SUCCESS;
                $item->status = $r['status'];

                if ($r['status'] === PayrollPaymentItem::STATUS_SUCCESS) {
                    $item->bank_reference_no = $r['bank_reference_no'] ?? $item->bank_reference_no;
                    $item->failure_reason    = null;
                    $item->settled_at        = now();
                    if (! $wasSuccess) {
                        $newlySuccess[] = $item->id;
                    }
                } else {
                    $item->failure_reason = $r['failure_reason'] ?? null;
                    $item->settled_at     = null;
                }

                $item->save();
            }

            // Hitung ulang status batch dari agregat item (tanpa raw SQL).
            $statuses = PayrollPaymentItem::where('payment_batch_id', $paymentBatch->id)->pluck('status');
            $pending  = $statuses->filter(fn ($s) => $s === PayrollPaymentItem::STATUS_PENDING)->count();

            if ($pending === 0) {
                // Semua item sudah berstatus akhir (sukses/gagal/ditolak) → rekonsiliasi tuntas.
                $paymentBatch->status        = PayrollPaymentBatch::STATUS_RECONCILED;
                $paymentBatch->reconciled_by = $user->id;
                $paymentBatch->reconciled_at = now();
            } else {
                $paymentBatch->status = PayrollPaymentBatch::STATUS_PARTIALLY_SETTLED;
            }

            $paymentBatch->save();
        });

        // Notifikasi hanya untuk item yang BARU menjadi sukses (hindari notif ganda).
        if (! empty($newlySuccess)) {
            foreach (PayrollPaymentItem::whereIn('id', $newlySuccess)->get() as $item) {
                $this->notifyDisbursement($item, $paymentBatch);
            }
        }

        AuditLogger::log(
            action: 'PAYROLL_RECONCILED',
            description: "Rekonsiliasi batch {$paymentBatch->batch_reference}: status kini {$paymentBatch->status}.",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'PayrollPaymentBatch',
            entityId: (int) $paymentBatch->id,
            newValues: [
                'batch_status'  => $paymentBatch->status,
                'success_count' => count($newlySuccess),
            ],
        );
        $this->chainLog($paymentBatch, 'PAYROLL_RECONCILED', [
            'batch_status'     => $paymentBatch->status,
            'reconciled_count' => count($validated['results']),
        ]);

        $paymentBatch->load(['items.user:id,name', 'reconciledBy:id,name']);

        return response()->json([
            'message' => 'Rekonsiliasi pembayaran tersimpan.',
            'data'    => $paymentBatch,
        ]);
    }
}
