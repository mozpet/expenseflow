<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOcrJob;
use App\Models\Receipt;
use App\Models\ReceiptApproval;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ReceiptController extends Controller
{
    // ─── Helper: catat aktivitas ──────────────────────────────
    private function logActivity(int $userId, int $companyId, string $action, string $description, ?int $subjectId = null, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'   => $companyId,
            'user_id'      => $userId,
            'action'       => $action,
            'description'  => $description,
            'subject_type' => $subjectId ? 'receipt' : null,
            'subject_id'   => $subjectId,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ─── Helper: kirim notifikasi ke user ─────────────────────
    private function notifyUser(int $userId, string $type, array $data, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('notifications')->insert([
            'id'              => \Illuminate\Support\Str::uuid()->toString(),
            'type'            => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $userId,
            'user_id'         => $userId,
            'data'            => json_encode($data),
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ─── Helper: generate nomor receipt ────────────────────────
    private function generateReceiptNumber(): string
    {
        $prefix = 'RCP-' . now()->format('Ymd') . '-';
        // withTrashed() agar nomor yang sudah dipakai (termasuk soft-deleted) tidak di-generate ulang
        $last = Receipt::withTrashed()
            ->where('receipt_number', 'like', $prefix . '%')
            ->orderByDesc('receipt_number')
            ->first();

        $num = $last ? (int) substr($last->receipt_number, -4) + 1 : 1;

        return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }

    // ═══════════════════════════════════════════════════════════
    // 1. store() — upload foto, SHA256, langsung dispatch OCR
    //    Karyawan hanya wajib: image + category.
    //    total_amount, claimed_amount, receipt_date → diisi OCR.
    // ═══════════════════════════════════════════════════════════
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image'    => 'required|file|mimes:jpeg,jpg,png,gif,webp,pdf|max:10240', // max 10 MB
            'category' => 'required|string|max:100',
            'notes'    => 'nullable|string|max:1000',
        ]);

        $user      = $request->user();
        $companyId = $user->company_id;
        $file      = $request->file('image');

        // Hitung SHA-256 hash SEBELUM simpan (immutable)
        $sha256 = hash('sha256', file_get_contents($file->getRealPath()));

        // Simpan file ke storage/app/receipts/
        $imagePath = $file->store('receipts');

        // Buat receipt — field nominal/tanggal kosong dulu, diisi OCR
        $receipt = Receipt::create([
            'company_id'     => $companyId,
            'user_id'        => $user->id,
            'receipt_number' => $this->generateReceiptNumber(),
            'sha256_hash'    => $sha256,
            'image_path'     => $imagePath,
            'currency'       => 'IDR',
            'status'         => 'draft',
            'ocr_status'     => 'pending',
            'category'       => $request->category,
            'notes'          => $request->notes,
        ]);

        // Dispatch OCR job ke queue — semua ocr_raw_* + claimed_amount diisi di sini
        ProcessOcrJob::dispatch($receipt->id);

        $this->logActivity($user->id, $companyId, 'receipt_uploaded', 'Upload struk ' . $receipt->receipt_number, $receipt->id, 'receipt', $receipt->id);

        return response()->json([
            'message' => 'Struk berhasil diunggah. OCR sedang diproses.',
            'receipt' => $receipt->only([
                'id', 'receipt_number', 'sha256_hash', 'image_path',
                'status', 'ocr_status', 'category', 'notes',
            ]),
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════
    // 2. updateClaim() — karyawan update category & notes.
    //    Jika OCR gagal, karyawan boleh isi manual: claimed_amount,
    //    total_amount, receipt_date, vendor_name.
    // ═══════════════════════════════════════════════════════════
    public function updateClaim(Request $request, Receipt $receipt): JsonResponse
    {
        if ($receipt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Anda bukan pemilik struk ini.'], 403);
        }

        if ($receipt->status !== 'draft') {
            return response()->json(['message' => 'Struk sudah tidak bisa diedit.'], 403);
        }

        $rules = [
            'category' => 'sometimes|required|string|max:100',
            'notes'    => 'nullable|string|max:1000',
            'claimed_amount' => 'sometimes|required|numeric|min:0',
        ];

        // Jika OCR gagal — izinkan input manual field yang seharusnya diisi OCR
        if ($receipt->ocr_status === 'failed') {
            $rules['total_amount']   = 'sometimes|required|numeric|min:0';
            $rules['receipt_date']   = 'sometimes|required|date';
            $rules['vendor_name']    = 'nullable|string|max:255';
        }

        $validated = $request->validate($rules);

        $receipt->update($validated);

        // Hitung ulang variance jika claimed_amount diubah
        if (isset($validated['claimed_amount'])) {
            $receipt->refresh()->recalculateVariance();
        }

        $this->logActivity($request->user()->id, $receipt->company_id, 'receipt_updated', 'Update klaim ' . $receipt->receipt_number, $receipt->id, 'receipt', $receipt->id);

        return response()->json([
            'message' => 'Klaim berhasil diperbarui.',
            'receipt' => $receipt->only([
                'id', 'receipt_number', 'category', 'notes',
                'claimed_amount', 'total_amount', 'receipt_date', 'vendor_name',
                'variance_flag', 'variance_pct',
            ]),
        ]);
    }

    // ─── Helper: validasi batas klaim per-transaksi & plafon bulanan ─────
    private function validateClaimLimits(User $user, float $claimedAmount, ?int $ignoreReceiptId = null): ?JsonResponse
    {
        $companyId = $user->company_id;

        // 1. Cek max_claim_limit per transaksi dari company_settings
        $maxPerClaim = (float) (DB::table('company_settings')
            ->where('company_id', $companyId)
            ->where('key', 'max_claim_limit')
            ->value('value') ?? 0);

        if ($maxPerClaim > 0 && $claimedAmount > $maxPerClaim) {
            return response()->json([
                'message'         => 'Nominal klaim (Rp ' . number_format($claimedAmount, 0, ',', '.') . ') melebihi batas maksimum per transaksi (Rp ' . number_format($maxPerClaim, 0, ',', '.') . ').',
                'code'            => 'CLAIM_LIMIT_EXCEEDED',
                'max_claim_limit' => $maxPerClaim,
                'claimed_amount'  => $claimedAmount,
            ], 422);
        }

        // 2. Cek monthly_claim_limit akumulasi bulanan user (fallback ke company setting)
        $monthlyLimit = (float) ($user->monthly_claim_limit ?? 0);
        if ($monthlyLimit <= 0) {
            $monthlyLimit = (float) (DB::table('company_settings')
                ->where('company_id', $companyId)
                ->where('key', 'monthly_claim_limit')
                ->value('value') ?? 0);
        }

        if ($monthlyLimit > 0) {
            $currentMonthSpend = (float) Receipt::where('user_id', $user->id)
                ->whereIn('status', ['submitted', 'approved', 'paid'])
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->when($ignoreReceiptId, fn ($q) => $q->where('id', '!=', $ignoreReceiptId))
                ->sum('claimed_amount');

            if (($currentMonthSpend + $claimedAmount) > $monthlyLimit) {
                $remainingQuota = max(0, $monthlyLimit - $currentMonthSpend);
                return response()->json([
                    'message' => 'Pengajuan klaim melebihi batas anggaran bulanan Anda (Sisa kuota anggaran: Rp ' . number_format($remainingQuota, 0, ',', '.') . ' dari total plafon Rp ' . number_format($monthlyLimit, 0, ',', '.') . ').',
                    'code'                => 'MONTHLY_LIMIT_EXCEEDED',
                    'monthly_limit'       => $monthlyLimit,
                    'current_month_spend' => $currentMonthSpend,
                    'remaining_quota'     => $remainingQuota,
                    'claimed_amount'      => $claimedAmount,
                ], 422);
            }
        }

        return null;
    }

    // ─── Helper: deteksi potensi struk duplikat cerdas ─────────
    private function checkPotentialDuplicate(Receipt $receipt): void
    {
        if (!$receipt->total_amount || !$receipt->receipt_date) {
            return;
        }

        $duplicate = Receipt::where('company_id', $receipt->company_id)
            ->where('id', '!=', $receipt->id)
            ->where('status', '!=', 'rejected')
            ->where('total_amount', $receipt->total_amount)
            ->where('receipt_date', $receipt->receipt_date)
            ->where(function ($q) use ($receipt) {
                $merchant = $receipt->vendor_name ?: $receipt->ocr_raw_merchant;
                if ($merchant) {
                    $q->where('vendor_name', 'like', '%' . $merchant . '%')
                      ->orWhere('ocr_raw_merchant', 'like', '%' . $merchant . '%');
                }
            })
            ->first();

        if ($duplicate) {
            $receipt->is_potential_duplicate = true;
            $receipt->duplicate_reference_id = $duplicate->id;
            $receipt->saveQuietly();
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 3. submit() — ubah status menjadi submitted.
    //    Cek ownership, cek OCR status, validasi limit, cek duplikat lalu submit.
    // ═══════════════════════════════════════════════════════════
    public function submit(Request $request, Receipt $receipt): JsonResponse
    {
        // 1. Cek apakah receipt milik user yang login
        if ($receipt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Anda bukan pemilik struk ini.'], 403);
        }

        // 2. Cek apakah ocr_status sudah selesai
        if ($receipt->ocr_status === 'pending' || $receipt->ocr_status === 'processing') {
            return response()->json([
                'message' => 'OCR masih diproses, tunggu sebentar.',
            ], 400);
        }

        if ($receipt->ocr_status === 'failed') {
            return response()->json([
                'message' => 'OCR gagal, isi data manual dulu.',
            ], 400);
        }

        $claimVal = (float) ($receipt->claimed_amount ?: $receipt->total_amount);

        // 3. Validasi batas klaim per transaksi & plafon bulanan
        if ($limitError = $this->validateClaimLimits($request->user(), $claimVal, $receipt->id)) {
            return $limitError;
        }

        // 4. Ubah status dan simpan
        $receipt->status = 'submitted';
        $receipt->submitted_at = now();
        if ($receipt->claimed_amount === null) {
            $receipt->claimed_amount = $claimVal;
        }
        $receipt->save();

        // 5. Cek potensi duplikat heuristik
        $this->checkPotentialDuplicate($receipt);

        // 6. Hitung ulang variance setelah submit
        $receipt->refresh()->recalculateVariance();

        // 7. Catat ke activity_logs
        $this->logActivity($request->user()->id, $receipt->company_id, 'receipt_submitted', 'Submit struk ' . $receipt->receipt_number, $receipt->id, 'receipt', $receipt->id);

        // 8. Return response
        return response()->json([
            'message' => 'Struk berhasil disubmit.',
            'receipt' => [
                'id'                     => $receipt->id,
                'status'                 => $receipt->status,
                'submitted_at'           => $receipt->submitted_at,
                'variance_flag'          => $receipt->variance_flag,
                'variance_pct'           => $receipt->variance_pct,
                'is_potential_duplicate' => $receipt->is_potential_duplicate,
                'duplicate_reference_id' => $receipt->duplicate_reference_id,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 3b. destroy() — karyawan hapus draft (soft delete).
    //     Hanya boleh jika status == 'draft' dan milik sendiri.
    // ═══════════════════════════════════════════════════════════
    public function destroy(Request $request, Receipt $receipt): JsonResponse
    {
        if ($receipt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Anda bukan pemilik struk ini.'], 403);
        }

        if ($receipt->status !== 'draft') {
            return response()->json([
                'message' => 'Hanya struk berstatus draft yang bisa dihapus.',
            ], 422);
        }

        $receiptNumber = $receipt->receipt_number;
        $receipt->delete(); // soft delete — deleted_at diisi, data tetap ada untuk audit

        $this->logActivity(
            $request->user()->id,
            $receipt->company_id,
            'receipt_deleted',
            'Hapus draft struk ' . $receiptNumber,
            $receipt->id,
            'receipt',
            $receipt->id
        );

        return response()->json(['message' => 'Draft struk berhasil dihapus.']);
    }

    // ═══════════════════════════════════════════════════════════
    // 4. approve() — finance approve (mendukung nominal penyesuaian)
    // ═══════════════════════════════════════════════════════════
    public function approve(Request $request, Receipt $receipt): JsonResponse
    {
        if (! in_array($receipt->status, ['submitted', 'pending'])) {
            return response()->json(['message' => 'Hanya struk submitted yang bisa diapprove.'], 403);
        }

        $user = $request->user();

        $claimed = (float) ($receipt->claimed_amount ?: $receipt->total_amount);
        $approvedAmount = $request->has('approved_amount') && $request->approved_amount !== null
            ? (float) $request->approved_amount
            : $claimed;

        if ($approvedAmount < $claimed && empty($request->notes)) {
            return response()->json([
                'message' => 'Catatan wajib diisi jika menyetujui nominal lebih kecil dari nilai klaim.',
                'errors'  => [
                    'notes' => ['Catatan wajib diisi jika menyetujui nominal lebih kecil dari nilai klaim.'],
                ],
            ], 422);
        }

        $receipt->update([
            'status'          => 'approved',
            'approved_amount' => $approvedAmount,
        ]);

        ReceiptApproval::create([
            'receipt_id' => $receipt->id,
            'user_id'    => $user->id,
            'status'     => 'approved',
            'notes'      => $request->notes,
        ]);

        // Auto-hitung variance
        $receipt->refresh()->recalculateVariance();

        // Catat ke activity_logs dengan entity_type & entity_id
        $this->logActivity(
            $user->id, $receipt->company_id,
            'receipt_approved', 'Approve struk ' . $receipt->receipt_number . ($approvedAmount < $claimed ? ' (disesuaikan: Rp ' . number_format($approvedAmount, 0, ',', '.') . ')' : ''),
            $receipt->id,
            'receipt', $receipt->id
        );

        // Kirim notifikasi ke user yang submit struk
        $this->notifyUser($receipt->user_id, 'receipt_approved', [
            'message'         => 'Struk Anda telah diapprove: ' . $receipt->receipt_number . ($approvedAmount < $claimed ? ' dengan nominal disesuaikan Rp ' . number_format($approvedAmount, 0, ',', '.') : ''),
            'receipt_id'      => $receipt->id,
            'receipt_number'  => $receipt->receipt_number,
            'status'          => 'approved',
            'approved_amount' => $approvedAmount,
        ], 'receipt', $receipt->id);

        return response()->json([
            'message' => 'Struk berhasil diapprove.',
            'receipt' => $receipt->only(['id', 'receipt_number', 'status', 'claimed_amount', 'approved_amount', 'variance_flag', 'variance_pct']),
            'approved_by' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
            'approved_at' => now()->toIso8601String(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 4b. bulkApprove() — finance menyetujui banyak struk sekaligus
    // ═══════════════════════════════════════════════════════════
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'receipt_ids'   => 'required|array|min:1',
            'receipt_ids.*' => 'integer|exists:receipts,id',
            'notes'         => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $companyId = $user->company_id;
        $receiptIds = $request->receipt_ids;

        $receipts = Receipt::where('company_id', $companyId)
            ->whereIn('id', $receiptIds)
            ->whereIn('status', ['submitted', 'pending'])
            ->get();

        if ($receipts->isEmpty()) {
            return response()->json(['message' => 'Tidak ada struk berstatus submitted yang dapat disetujui.'], 422);
        }

        $approvedCount = 0;

        DB::transaction(function () use ($receipts, $user, $request, &$approvedCount) {
            foreach ($receipts as $receipt) {
                $claimed = (float) ($receipt->claimed_amount ?: $receipt->total_amount);

                $receipt->update([
                    'status'          => 'approved',
                    'approved_amount' => $claimed,
                ]);

                ReceiptApproval::create([
                    'receipt_id' => $receipt->id,
                    'user_id'    => $user->id,
                    'status'     => 'approved',
                    'notes'      => $request->notes ?? 'Persetujuan masal',
                ]);

                $receipt->recalculateVariance();

                $this->logActivity(
                    $user->id, $receipt->company_id,
                    'receipt_approved', 'Approve masal struk ' . $receipt->receipt_number,
                    $receipt->id,
                    'receipt', $receipt->id
                );

                $this->notifyUser($receipt->user_id, 'receipt_approved', [
                    'message'        => 'Struk Anda telah diapprove: ' . $receipt->receipt_number,
                    'receipt_id'     => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'status'         => 'approved',
                ], 'receipt', $receipt->id);

                $approvedCount++;
            }
        });

        return response()->json([
            'message'        => "{$approvedCount} struk berhasil disetujui.",
            'approved_count' => $approvedCount,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 4c. disburse() / pay() — finance mencairkan/mentransfer reimbursement
    // ═══════════════════════════════════════════════════════════
    public function disburse(Request $request, Receipt $receipt): JsonResponse
    {
        if ($receipt->status !== 'approved') {
            return response()->json(['message' => 'Hanya struk berstatus approved yang dapat dicairkan.'], 422);
        }

        $request->validate([
            'payment_method' => ['required', Rule::in(['bank_transfer', 'cash', 'payroll'])],
            'payment_ref_no' => 'nullable|string|max:100',
            'payment_proof'  => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
        ]);

        $user = $request->user();
        $proofPath = null;

        if ($request->hasFile('payment_proof')) {
            $proofPath = $request->file('payment_proof')->store('receipts/proofs');
        }

        $receipt->update([
            'status'             => 'paid',
            'paid_at'            => now(),
            'paid_by'            => $user->id,
            'payment_method'     => $request->payment_method,
            'payment_ref_no'     => $request->payment_ref_no,
            'payment_proof_path' => $proofPath ?? $receipt->payment_proof_path,
        ]);

        $this->logActivity(
            $user->id, $receipt->company_id,
            'receipt_paid', 'Pencairan dana struk ' . $receipt->receipt_number . ' (Metode: ' . $request->payment_method . ')',
            $receipt->id,
            'receipt', $receipt->id
        );

        $paidNominal = (float) ($receipt->approved_amount ?: $receipt->claimed_amount ?: $receipt->total_amount);

        $this->notifyUser($receipt->user_id, 'receipt_paid', [
            'message'        => 'Dana reimbursement struk ' . $receipt->receipt_number . ' sebesar Rp ' . number_format($paidNominal, 0, ',', '.') . ' telah dicairkan.',
            'receipt_id'     => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'status'         => 'paid',
            'paid_at'        => $receipt->paid_at->toIso8601String(),
        ], 'receipt', $receipt->id);

        return response()->json([
            'message' => 'Pencairan reimbursement berhasil dicatat.',
            'receipt' => $receipt->only([
                'id', 'receipt_number', 'status', 'claimed_amount', 'approved_amount',
                'paid_at', 'paid_by', 'payment_method', 'payment_ref_no',
            ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 4d. bulkDisburse() — finance mencairkan banyak struk sekaligus
    // ═══════════════════════════════════════════════════════════
    public function bulkDisburse(Request $request): JsonResponse
    {
        $request->validate([
            'receipt_ids'    => 'required|array|min:1',
            'receipt_ids.*'  => 'integer|exists:receipts,id',
            'payment_method' => ['required', Rule::in(['bank_transfer', 'cash', 'payroll'])],
            'payment_ref_no' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $companyId = $user->company_id;
        $receiptIds = $request->receipt_ids;

        $receipts = Receipt::where('company_id', $companyId)
            ->whereIn('id', $receiptIds)
            ->where('status', 'approved')
            ->get();

        if ($receipts->isEmpty()) {
            return response()->json(['message' => 'Tidak ada struk berstatus approved yang dapat dicairkan.'], 422);
        }

        $paidCount = 0;

        DB::transaction(function () use ($receipts, $user, $request, &$paidCount) {
            foreach ($receipts as $receipt) {
                $receipt->update([
                    'status'         => 'paid',
                    'paid_at'        => now(),
                    'paid_by'        => $user->id,
                    'payment_method' => $request->payment_method,
                    'payment_ref_no' => $request->payment_ref_no,
                ]);

                $this->logActivity(
                    $user->id, $receipt->company_id,
                    'receipt_paid', 'Pencairan masal struk ' . $receipt->receipt_number,
                    $receipt->id,
                    'receipt', $receipt->id
                );

                $paidNominal = (float) ($receipt->approved_amount ?: $receipt->claimed_amount ?: $receipt->total_amount);

                $this->notifyUser($receipt->user_id, 'receipt_paid', [
                    'message'        => 'Dana reimbursement struk ' . $receipt->receipt_number . ' sebesar Rp ' . number_format($paidNominal, 0, ',', '.') . ' telah dicairkan.',
                    'receipt_id'     => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'status'         => 'paid',
                ], 'receipt', $receipt->id);

                $paidCount++;
            }
        });

        return response()->json([
            'message'    => "{$paidCount} struk berhasil ditandai sebagai telah dibayar/cair.",
            'paid_count' => $paidCount,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 4e. exportDisbursement() — ekspor rekap transfer bank CSV
    // ═══════════════════════════════════════════════════════════
    public function exportDisbursement(Request $request)
    {
        $companyId = $request->user()->company_id;
        $status = $request->query('status', 'approved');

        $receipts = Receipt::where('company_id', $companyId)
            ->where('status', $status)
            ->with(['user:id,name,employee_code,department,bank_name,bank_account_no,bank_account_holder'])
            ->orderBy('user_id')
            ->get();

        $filename = 'rekap_transfer_reimbursement_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($receipts) {
            $file = fopen('php://output', 'w');
            // Add UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header CSV
            fputcsv($file, [
                'No',
                'Kode Karyawan',
                'Nama Karyawan',
                'Departemen',
                'Nama Bank',
                'Nomor Rekening',
                'Nama Pemilik Rekening',
                'Nomor Struk',
                'Kategori',
                'Nominal Disetujui (IDR)',
                'Tanggal Struk',
                'Status',
            ]);

            $no = 1;
            foreach ($receipts as $r) {
                $u = $r->user;
                $approvedVal = (float) ($r->approved_amount ?: $r->claimed_amount ?: $r->total_amount);

                fputcsv($file, [
                    $no++,
                    $u->employee_code ?? '—',
                    $u->name ?? '—',
                    $u->department ?? '—',
                    $u->bank_name ?? '—',
                    $u->bank_account_no ? "'" . $u->bank_account_no : '—',
                    $u->bank_account_holder ?? $u->name ?? '—',
                    $r->receipt_number,
                    $r->category ?? '—',
                    $approvedVal,
                    $r->receipt_date ? $r->receipt_date->format('Y-m-d') : '—',
                    ucfirst($r->status),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ═══════════════════════════════════════════════════════════
    // 5. reject() — finance reject, catat ke receipt_approvals
    // ═══════════════════════════════════════════════════════════
    public function reject(Request $request, Receipt $receipt): JsonResponse
    {
        if (! in_array($receipt->status, ['submitted', 'pending'])) {
            return response()->json(['message' => 'Hanya struk submitted yang bisa direject.'], 403);
        }

        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $user = $request->user();

        $receipt->update(['status' => 'rejected']);

        ReceiptApproval::create([
            'receipt_id' => $receipt->id,
            'user_id'    => $user->id,
            'status'     => 'rejected',
            'notes'      => $request->notes,
        ]);

        $this->logActivity($user->id, $receipt->company_id, 'receipt_rejected', 'Reject struk ' . $receipt->receipt_number, $receipt->id, 'receipt', $receipt->id);

        // Kirim notifikasi ke user yang submit struk
        $this->notifyUser($receipt->user_id, 'receipt_rejected', [
            'message'        => 'Struk Anda telah direject: ' . $receipt->receipt_number,
            'receipt_id'     => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'status'         => 'rejected',
            'reason'         => $request->notes,
        ], 'receipt', $receipt->id);

        return response()->json([
            'message' => 'Struk berhasil direject.',
            'receipt' => $receipt->only(['id', 'receipt_number', 'status']),
            'rejected_by' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'rejected_at' => now()->toIso8601String(),
            'rejection_reason' => $request->notes,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 5b. image() — sajikan foto struk privat (untuk dashboard web).
    //     File disimpan di disk 'local' (storage/app/private), tidak
    //     bisa diakses publik. Endpoint ini cek akses lalu stream file.
    // ═══════════════════════════════════════════════════════════
    public function image(Request $request, Receipt $receipt)
    {
        $user = $request->user();

        // Employee hanya boleh lihat foto struk milik sendiri.
        if ($user->role === 'employee' && $receipt->user_id !== $user->id) {
            return response()->json(['message' => 'Anda bukan pemilik struk ini.'], 403);
        }

        // Finance/Admin hanya boleh lihat struk di perusahaannya.
        if ($user->role !== 'employee' && $receipt->company_id !== $user->company_id) {
            return response()->json(['message' => 'Struk tidak ditemukan di perusahaan Anda.'], 403);
        }

        if (! $receipt->image_path || ! Storage::disk('local')->exists($receipt->image_path)) {
            return response()->json(['message' => 'File foto struk tidak ditemukan.'], 404);
        }

        // PDF: langsung stream, tidak perlu konversi WebP
        if (str_ends_with(strtolower($receipt->image_path), '.pdf')) {
            $fullPath = Storage::disk('local')->path($receipt->image_path);
            return response()->file($fullPath, ['Content-Type' => 'application/pdf']);
        }

        return $this->serveImageAsWebP($receipt->image_path);
    }

    // ─── Helper: Serve image as WebP untuk ringan/cepat di web ────
    private function serveImageAsWebP(string $imagePath)
    {
        $originalPath = Storage::disk('local')->path($imagePath);
        $webpCachePath = storage_path('app/cache/webp/' . md5($imagePath) . '.webp');

        // Jika cache WebP sudah ada, return dari cache
        if (file_exists($webpCachePath)) {
            return response()->file($webpCachePath, ['Content-Type' => 'image/webp']);
        }

        // Buat folder cache jika belum ada
        @mkdir(dirname($webpCachePath), 0755, true);

        // Load image dan convert ke WebP
        $image = @imagecreatefromstring(file_get_contents($originalPath));
        if ($image === false) {
            // Fallback ke original jika conversion gagal
            return response()->file($originalPath);
        }

        // Convert ke WebP dengan quality 80 untuk balance ukuran & kualitas
        imagewebp($image, $webpCachePath, 80);
        imagedestroy($image);

        if (! file_exists($webpCachePath)) {
            // Fallback ke original jika write gagal
            return response()->file($originalPath);
        }

        return response()->file($webpCachePath, ['Content-Type' => 'image/webp']);
    }

    // ═══════════════════════════════════════════════════════════
    // 6. show() — detail satu struk lengkap dengan semua field OCR & pembayaran.
    //    Employee: hanya boleh lihat struk sendiri.
    //    Finance/Admin: boleh lihat struk apa saja di perusahaan.
    // ═══════════════════════════════════════════════════════════
    public function show(Request $request, Receipt $receipt): JsonResponse
    {
        $user = $request->user();

        // Employee hanya boleh lihat struk milik sendiri
        if ($user->role === 'employee' && $receipt->user_id !== $user->id) {
            return response()->json(['message' => 'Anda bukan pemilik struk ini.'], 403);
        }

        $receipt->load([
            'approvals.user:id,name,role',
            'user:id,name,email,department,bank_name,bank_account_no,bank_account_holder',
            'paidBy:id,name,email,role',
            'duplicateReference:id,receipt_number,total_amount,receipt_date',
        ]);

        return response()->json([
            'receipt' => [
                'id'                     => $receipt->id,
                'receipt_number'         => $receipt->receipt_number,
                'sha256_hash'            => $receipt->sha256_hash,
                'image_path'             => $receipt->image_path,
                'vendor_name'            => $receipt->vendor_name,
                'total_amount'           => $receipt->total_amount,
                'claimed_amount'         => $receipt->claimed_amount,
                'approved_amount'        => $receipt->approved_amount,
                'receipt_date'           => $receipt->receipt_date,
                'currency'               => $receipt->currency,
                'status'                 => $receipt->status,
                'submitted_at'           => $receipt->submitted_at,
                'paid_at'                => $receipt->paid_at,
                'paid_by'                => $receipt->paidBy,
                'payment_method'         => $receipt->payment_method,
                'payment_ref_no'         => $receipt->payment_ref_no,
                'payment_proof_path'     => $receipt->payment_proof_path,
                'is_potential_duplicate' => $receipt->is_potential_duplicate,
                'duplicate_reference'    => $receipt->duplicateReference,
                'ocr_status'             => $receipt->ocr_status,
                'ocr_raw_amount'         => $receipt->ocr_raw_amount,
                'ocr_raw_subtotal'       => $receipt->ocr_raw_subtotal,
                'ocr_raw_tax'            => $receipt->ocr_raw_tax,
                'ocr_raw_discount'       => $receipt->ocr_raw_discount,
                'ocr_raw_items'          => $receipt->ocr_raw_items,
                'ocr_raw_merchant'       => $receipt->ocr_raw_merchant,
                'ocr_raw_date'           => $receipt->ocr_raw_date,
                'ocr_attempts'           => $receipt->ocr_attempts,
                'ocr_error'              => $receipt->ocr_error,
                'variance_flag'          => $receipt->variance_flag,
                'variance_pct'           => $receipt->variance_pct,
                'category'               => $receipt->category,
                'notes'                  => $receipt->notes,
                'user'                   => $receipt->user,
                'approvals'              => $receipt->approvals,
                'created_at'             => $receipt->created_at,
                'updated_at'             => $receipt->updated_at,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 7. myReceipts() — list struk milik karyawan yang login
    // ═══════════════════════════════════════════════════════════
    public function myReceipts(Request $request): JsonResponse
    {
        $receipts = Receipt::where('user_id', $request->user()->id)
            ->select([
                'id', 'receipt_number', 'vendor_name', 'total_amount',
                'claimed_amount', 'approved_amount', 'ocr_raw_amount', 'ocr_raw_subtotal',
                'ocr_raw_tax', 'ocr_raw_discount', 'ocr_raw_items', 'ocr_raw_merchant',
                'ocr_raw_date', 'receipt_date', 'status', 'submitted_at', 'paid_at',
                'payment_method', 'payment_ref_no', 'ocr_status',
                'category', 'notes', 'variance_flag', 'variance_pct',
                'is_potential_duplicate', 'created_at',
            ])
            ->selectRaw(
                "(SELECT notes FROM receipt_approvals WHERE receipt_id = receipts.id AND status = 'rejected' ORDER BY id DESC LIMIT 1) as rejection_reason"
            )
            ->latest()
            ->paginate(20);

        return response()->json($receipts);
    }

    // ═══════════════════════════════════════════════════════════
    // 8. inbox() — list struk submitted (menunggu approval) untuk finance
    // ═══════════════════════════════════════════════════════════
    public function inbox(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $limit = $request->query('per_page') ? (int) $request->query('per_page') : 2000;

        $receipts = Receipt::where('company_id', $companyId)
            ->where('status', 'submitted')
            ->with([
                'user:id,name,email,department,bank_name,bank_account_no,bank_account_holder',
                'duplicateReference:id,receipt_number,total_amount,receipt_date',
            ])
            ->select([
                'id', 'user_id', 'receipt_number', 'vendor_name', 'ocr_raw_merchant',
                'total_amount', 'claimed_amount', 'approved_amount', 'ocr_raw_amount',
                'ocr_raw_subtotal', 'ocr_raw_tax', 'ocr_raw_discount', 'ocr_raw_items',
                'receipt_date', 'status', 'ocr_status', 'category', 'notes',
                'variance_flag', 'variance_pct', 'is_potential_duplicate',
                'duplicate_reference_id', 'submitted_at', 'created_at',
            ])
            ->latest()
            ->paginate($limit);

        return response()->json($receipts);
    }

    // ═══════════════════════════════════════════════════════════
    // 9. dashboardReceipts() — list SEMUA struk dengan filter status
    //    GET /api/v1/dashboard/receipts/all?status=submitted|approved|rejected|paid
    // ═══════════════════════════════════════════════════════════
    public function dashboardReceipts(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $status    = $request->query('status');
        $limit     = $request->query('per_page') ? (int) $request->query('per_page') : 2000;

        // Valid status values
        $validStatuses = ['submitted', 'approved', 'rejected', 'paid'];

        $query = Receipt::where('company_id', $companyId)
            ->with([
                'user:id,name,email,department,bank_name,bank_account_no,bank_account_holder',
                'approvals.user:id,name,email,role',
                'paidBy:id,name,email,role',
                'duplicateReference:id,receipt_number,total_amount,receipt_date',
            ])
            ->select([
                'id', 'user_id', 'receipt_number', 'vendor_name', 'ocr_raw_merchant',
                'total_amount', 'claimed_amount', 'approved_amount', 'ocr_raw_amount',
                'ocr_raw_subtotal', 'ocr_raw_tax', 'ocr_raw_discount', 'ocr_raw_items',
                'receipt_date', 'status', 'ocr_status', 'category', 'notes',
                'variance_flag', 'variance_pct', 'is_potential_duplicate',
                'duplicate_reference_id', 'paid_at', 'paid_by', 'payment_method',
                'payment_ref_no', 'submitted_at', 'created_at',
            ]);

        // Filter by status jika parameter diberikan dan valid
        if ($status && in_array($status, $validStatuses)) {
            $query->where('status', $status);
        } else {
            // Default: tampilkan submitted + approved + rejected + paid (bukan draft)
            $query->whereIn('status', $validStatuses);
        }

        $receipts = $query->latest()->paginate($limit);

        // Tambahkan ringkasan jumlah per status
        $summary = Receipt::where('company_id', $companyId)
            ->whereIn('status', $validStatuses)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'summary' => [
                'submitted' => $summary['submitted'] ?? 0,
                'approved'  => $summary['approved'] ?? 0,
                'paid'      => $summary['paid'] ?? 0,
                'rejected'  => $summary['rejected'] ?? 0,
            ],
            'receipts' => $receipts,
        ]);
    }
}
