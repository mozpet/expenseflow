<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ExpenseReport;
use App\Models\Receipt;
use App\Models\ReceiptApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseReportController extends Controller
{
    private function generateReportNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));
        return "EXP-{$date}-{$random}";
    }

    private function logActivity(int $userId, int $companyId, string $action, string $description, ?int $subjectId = null, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'   => $companyId,
            'user_id'      => $userId,
            'action'       => $action,
            'description'  => $description,
            'subject_type' => $subjectId ? 'expense_report' : null,
            'subject_id'   => $subjectId,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function notifyUser(int $userId, string $type, array $data, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('notifications')->insert([
            'id'              => Str::uuid()->toString(),
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

    // ═══════════════════════════════════════════════════════════
    // EMPLOYEE ENDPOINTS
    // ═══════════════════════════════════════════════════════════

    /**
     * List laporan pengeluaran milik karyawan yang login.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $reports = ExpenseReport::where('user_id', $user->id)
            ->withCount('receipts')
            ->with([
                'office:id,office_name',
                'receipts' => function ($q) {
                    $q->select('id', 'expense_report_id', 'receipt_number', 'vendor_name', 'ocr_raw_merchant', 'claimed_amount', 'total_amount', 'status', 'ocr_status', 'ocr_error', 'receipt_date', 'category', 'image_path', 'ocr_raw_amount', 'variance_flag', 'variance_pct', 'is_potential_duplicate', 'duplicate_reference_id', 'duplicate_reason')
                      ->with('duplicateReference:id,receipt_number,total_amount,claimed_amount,receipt_date');
                },
            ])
            ->latest()
            ->paginate(20);

        return response()->json($reports);
    }

    /**
     * Buat laporan pengeluaran baru (draft).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'purpose'     => 'nullable|string|max:1000',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'receipt_ids' => 'nullable|array',
            'receipt_ids.*' => 'integer|exists:receipts,id',
        ]);

        $user = $request->user();

        $report = DB::transaction(function () use ($request, $user) {
            $report = ExpenseReport::create([
                'company_id'            => $user->company_id,
                'user_id'               => $user->id,
                'attendance_setting_id' => $user->attendance_setting_id,
                'report_number'         => $this->generateReportNumber(),
                'title'                 => $request->title,
                'description'           => $request->description ?? $request->purpose,
                'start_date'            => $request->start_date,
                'end_date'              => $request->end_date,
                'status'                => 'draft',
            ]);

            if ($request->has('receipt_ids') && !empty($request->receipt_ids)) {
                $receiptsToUpdate = Receipt::where('user_id', $user->id)
                    ->whereIn('id', $request->receipt_ids)
                    ->where('status', 'draft')
                    ->get();

                $oldReportIds = $receiptsToUpdate->pluck('expense_report_id')->filter()->unique();

                foreach ($receiptsToUpdate as $r) {
                    $r->update(['expense_report_id' => $report->id]);
                }

                foreach ($oldReportIds as $oldId) {
                    ExpenseReport::find($oldId)?->recalculateTotals();
                }

                $report->recalculateTotals();
            }

            return $report;
        });

        $this->logActivity(
            $user->id,
            $user->company_id,
            'expense_report_created',
            "Membuat laporan pengeluaran: {$report->report_number} ({$report->title})",
            $report->id,
            'expense_report',
            $report->id
        );

        return response()->json([
            'message' => 'Laporan pengeluaran berhasil dibuat.',
            'report'  => $report->load(['receipts:id,expense_report_id,receipt_number,vendor_name,ocr_raw_merchant,claimed_amount,total_amount,status,ocr_status,ocr_error,image_path,receipt_date,category']),
        ], 201);
    }

    /**
     * Rincian laporan pengeluaran beserta daftar struk di dalamnya.
     */
    public function show(Request $request, ExpenseReport $expenseReport): JsonResponse
    {
        $user = $request->user();

        // Employee hanya boleh lihat laporan milik sendiri
        if ($user->role === 'employee' && $expenseReport->user_id !== $user->id) {
            return response()->json(['message' => 'Anda bukan pemilik laporan ini.'], 403);
        }

        // Dashboard roles hanya boleh lihat laporan di perusahaannya
        if ($user->role !== 'employee' && $expenseReport->company_id !== $user->company_id) {
            return response()->json(['message' => 'Laporan tidak ditemukan di perusahaan Anda.'], 403);
        }

        $expenseReport->load([
            'user:id,name,email,department,attendance_setting_id',
            'office:id,office_name,variance_limit,max_claim_limit',
            'approvedBy:id,name,email',
            'paidBy:id,name,email',
            'receipts' => function ($q) {
                $q->with([
                    'images:id,receipt_id,file_path,file_name,file_size,mime_type,image_type',
                    'approvals.user:id,name,role',
                    'duplicateReference:id,receipt_number,total_amount,claimed_amount,receipt_date',
                ]);
            },
        ]);

        return response()->json(['report' => $expenseReport]);
    }

    /**
     * Tambahkan struk ke laporan pengeluaran.
     */
    public function addReceipts(Request $request, ExpenseReport $expenseReport): JsonResponse
    {
        $user = $request->user();

        if ($expenseReport->user_id !== $user->id) {
            return response()->json(['message' => 'Anda bukan pemilik laporan ini.'], 403);
        }

        if ($expenseReport->status !== 'draft') {
            return response()->json(['message' => 'Laporan yang sudah diajukan tidak dapat diubah.'], 422);
        }

        $request->validate([
            'receipt_ids'   => 'required|array|min:1',
            'receipt_ids.*' => 'integer|exists:receipts,id',
        ]);

        $receiptsToUpdate = Receipt::where('user_id', $user->id)
            ->whereIn('id', $request->receipt_ids)
            ->where('status', 'draft')
            ->get();

        $oldReportIds = $receiptsToUpdate->pluck('expense_report_id')->filter(fn ($id) => $id !== $expenseReport->id)->unique();

        foreach ($receiptsToUpdate as $r) {
            $r->update(['expense_report_id' => $expenseReport->id]);
        }

        foreach ($oldReportIds as $oldId) {
            ExpenseReport::find($oldId)?->recalculateTotals();
        }

        $expenseReport->recalculateTotals();

        return response()->json([
            'message'       => "{$receiptsToUpdate->count()} struk berhasil ditambahkan ke laporan.",
            'report'        => $expenseReport->fresh(['receipts']),
            'updated_count' => $receiptsToUpdate->count(),
        ]);
    }

    /**
     * Hapus struk dari laporan pengeluaran.
     */
    public function removeReceipt(Request $request, ExpenseReport $expenseReport, Receipt $receipt): JsonResponse
    {
        $user = $request->user();

        if ($expenseReport->user_id !== $user->id || $receipt->user_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($expenseReport->status !== 'draft') {
            return response()->json(['message' => 'Laporan yang sudah diajukan tidak dapat diubah.'], 422);
        }

        if ($receipt->expense_report_id !== $expenseReport->id) {
            return response()->json(['message' => 'Struk tidak berada di dalam laporan ini.'], 422);
        }

        $receipt->update(['expense_report_id' => null]);
        $expenseReport->recalculateTotals();

        return response()->json([
            'message' => 'Struk berhasil dilepas dari laporan.',
            'report'  => $expenseReport->fresh(['receipts']),
        ]);
    }

    /**
     * Submit seluruh laporan beserta seluruh struk di dalamnya.
     */
    public function submit(Request $request, ExpenseReport $expenseReport): JsonResponse
    {
        $user = $request->user();

        if ($expenseReport->user_id !== $user->id) {
            return response()->json(['message' => 'Anda bukan pemilik laporan ini.'], 403);
        }

        if ($expenseReport->status !== 'draft') {
            return response()->json(['message' => 'Laporan sudah diajukan sebelumnya.'], 422);
        }

        $receipts = $expenseReport->receipts()->get();

        if ($receipts->isEmpty()) {
            return response()->json(['message' => 'Laporan tidak memiliki struk. Tambahkan struk terlebih dahulu.'], 422);
        }

        // Pastikan semua struk sudah selesai OCR dan valid
        foreach ($receipts as $r) {
            if ($r->ocr_status === 'pending' || $r->ocr_status === 'processing') {
                return response()->json([
                    'message' => "Struk {$r->receipt_number} masih dalam proses OCR. Harap tunggu hingga selesai.",
                ], 422);
            }

            if ($r->ocr_status === 'failed') {
                $ocrError = strtolower($r->ocr_error ?? '');
                $isBlurry = str_contains($ocrError, 'buram') ||
                    str_contains($ocrError, 'blur') ||
                    str_contains($ocrError, 'bergoyang') ||
                    str_contains($ocrError, 'tidak terbaca') ||
                    str_contains($ocrError, 'tidak terdeteksi');

                if ($isBlurry) {
                    return response()->json([
                        'message' => "Foto struk {$r->receipt_number} buram atau tidak terbaca jelas. Harap perbarui foto sebelum mengajukan laporan.",
                        'code'    => 'RECEIPT_IMAGE_BLURRY',
                    ], 422);
                }

                if ($r->total_amount === null && $r->claimed_amount === null) {
                    return response()->json([
                        'message' => "OCR gagal pada struk {$r->receipt_number}. Harap lengkapi nominal struk terlebih dahulu.",
                    ], 422);
                }
            }
        }

        $companyId = $user->company_id;

        // Cek max_claim_limit per transaksi untuk setiap struk
        $maxPerClaim = null;
        if ($user->attendance_setting_id) {
            $branchLimit = DB::table('attendance_settings')
                ->where('id', $user->attendance_setting_id)
                ->where('company_id', $companyId)
                ->value('max_claim_limit');
            if ($branchLimit !== null && (float) $branchLimit > 0) {
                $maxPerClaim = (float) $branchLimit;
            }
        }
        if ($maxPerClaim === null) {
            $maxPerClaim = (float) (DB::table('company_settings')
                ->where('company_id', $companyId)
                ->where('key', 'max_claim_limit')
                ->value('value') ?? 0);
        }

        $bundleTotalClaim = 0.0;
        foreach ($receipts as $r) {
            $claimVal = (float) ($r->claimed_amount ?: $r->total_amount);
            if ($maxPerClaim > 0 && $claimVal > $maxPerClaim) {
                return response()->json([
                    'message' => "Struk {$r->receipt_number} dengan nominal Rp " . number_format($claimVal, 0, ',', '.') . " melebihi batas maksimum per transaksi (Maks: Rp " . number_format($maxPerClaim, 0, ',', '.') . ").",
                    'code'    => 'CLAIM_LIMIT_EXCEEDED',
                ], 422);
            }
            $bundleTotalClaim += $claimVal;
        }

        // Cek monthly_claim_limit akumulasi bulanan user
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
                ->whereNotIn('id', $receipts->pluck('id'))
                ->sum('claimed_amount');

            if (($currentMonthSpend + $bundleTotalClaim) > $monthlyLimit) {
                $remainingQuota = max(0, $monthlyLimit - $currentMonthSpend);
                return response()->json([
                    'message' => 'Total pengajuan laporan pengeluaran (Rp ' . number_format($bundleTotalClaim, 0, ',', '.') . ') melebihi sisa anggaran bulanan Anda (Sisa kuota: Rp ' . number_format($remainingQuota, 0, ',', '.') . ' dari total plafon Rp ' . number_format($monthlyLimit, 0, ',', '.') . ').',
                    'code'                => 'MONTHLY_LIMIT_EXCEEDED',
                    'monthly_limit'       => $monthlyLimit,
                    'current_month_spend' => $currentMonthSpend,
                    'remaining_quota'     => $remainingQuota,
                    'claimed_amount'      => $bundleTotalClaim,
                ], 422);
            }
        }

        DB::transaction(function () use ($expenseReport, $receipts, $user) {
            // Ubah semua struk menjadi submitted
            foreach ($receipts as $r) {
                $claimVal = (float) ($r->claimed_amount ?: $r->total_amount);
                $r->update([
                    'status'         => 'submitted',
                    'submitted_at'   => now(),
                    'claimed_amount' => $claimVal,
                ]);
                $r->detectPotentialDuplicate();
                $r->refresh()->recalculateVariance();
            }

            // Ubah status laporan
            $expenseReport->update([
                'status'       => 'submitted',
                'submitted_at' => now(),
            ]);

            $expenseReport->recalculateTotals();
        });

        $this->logActivity(
            $user->id,
            $expenseReport->company_id,
            'expense_report_submitted',
            "Submit laporan pengeluaran: {$expenseReport->report_number} dengan {$receipts->count()} struk",
            $expenseReport->id,
            'expense_report',
            $expenseReport->id
        );

        return response()->json([
            'message' => 'Laporan pengeluaran berhasil diajukan untuk ditinjau finance.',
            'report'  => $expenseReport->fresh(['receipts']),
        ]);
    }

    /**
     * Hapus draft laporan pengeluaran.
     */
    public function destroy(Request $request, ExpenseReport $expenseReport): JsonResponse
    {
        $user = $request->user();

        if ($expenseReport->user_id !== $user->id) {
            return response()->json(['message' => 'Anda bukan pemilik laporan ini.'], 403);
        }

        if ($expenseReport->status !== 'draft') {
            return response()->json(['message' => 'Hanya draft laporan yang dapat dihapus.'], 422);
        }

        DB::transaction(function () use ($expenseReport) {
            // Lepaskan struk agar tidak terhapus
            $expenseReport->receipts()->update(['expense_report_id' => null]);
            $expenseReport->delete();
        });

        return response()->json(['message' => 'Draft laporan pengeluaran berhasil dihapus.']);
    }

    // ═══════════════════════════════════════════════════════════
    // FINANCE / DASHBOARD ENDPOINTS
    // ═══════════════════════════════════════════════════════════

    /**
     * Daftar laporan pengeluaran untuk dashboard Finance.
     */
    public function dashboardIndex(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $branchId  = $request->query('attendance_setting_id') ?? $request->query('branch_id');
        $status    = $request->query('status');

        $query = ExpenseReport::where('company_id', $companyId)
            ->withCount('receipts')
            ->with([
                'user:id,name,email,department,attendance_setting_id',
                'office:id,office_name',
                'approvedBy:id,name,email',
                'paidBy:id,name,email',
                'receipts' => function ($q) {
                    $q->select('id', 'expense_report_id', 'receipt_number', 'vendor_name', 'ocr_raw_merchant', 'claimed_amount', 'total_amount', 'status', 'ocr_status', 'ocr_error', 'receipt_date', 'category', 'image_path', 'ocr_raw_amount', 'ocr_raw_subtotal', 'ocr_raw_tax', 'ocr_raw_discount', 'ocr_raw_items', 'variance_flag', 'variance_pct', 'is_potential_duplicate', 'duplicate_reference_id', 'duplicate_reason')
                      ->with([
                          'images:id,receipt_id,file_path,file_name,file_size,mime_type,image_type',
                          'duplicateReference:id,receipt_number,total_amount,claimed_amount,receipt_date',
                      ]);
                },
            ]);

        if ($branchId !== null && $branchId !== '' && $branchId !== 'all') {
            $query->where('attendance_setting_id', $branchId);
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        } else {
            // Default tampilkan yang aktif (submitted lebih dahulu)
            $query->whereIn('status', ['submitted', 'approved', 'paid', 'rejected']);
        }

        $reports = $query->latest('submitted_at')->latest('id')->paginate(20);

        return response()->json($reports);
    }

    /**
     * Setujui seluruh laporan pengeluaran sekaligus (Approve All in Bundle).
     */
    public function approve(Request $request, ExpenseReport $expenseReport): JsonResponse
    {
        if ($expenseReport->status !== 'submitted') {
            return response()->json(['message' => 'Hanya laporan berstatus submitted yang dapat disetujui.'], 422);
        }

        $user = $request->user();
        $approvalNote = $request->input('notes') ?: "Disetujui bersama bundel laporan {$expenseReport->report_number}";
        $approvedReceiptIds = $request->input('approved_receipt_ids');
        $hasSpecificSelection = is_array($approvedReceiptIds);

        DB::transaction(function () use ($expenseReport, $user, $approvalNote, $approvedReceiptIds, $hasSpecificSelection, $request) {
            $allReceipts = $expenseReport->receipts()->whereIn('status', ['submitted', 'pending'])->get();

            if ($hasSpecificSelection) {
                $approvedIds = collect($approvedReceiptIds)->map(fn ($id) => (int) $id)->all();
                $toApprove = $allReceipts->whereIn('id', $approvedIds);
                $toReject  = $allReceipts->whereNotIn('id', $approvedIds);
            } else {
                $toApprove = $allReceipts;
                $toReject  = collect([]);
            }

            // 1. Setujui struk yang dipilih
            foreach ($toApprove as $receipt) {
                $approvedVal = (float) ($receipt->approved_amount ?: $receipt->claimed_amount ?: $receipt->total_amount);

                $receipt->update([
                    'status'          => 'approved',
                    'approved_amount' => $approvedVal,
                ]);

                ReceiptApproval::create([
                    'receipt_id' => $receipt->id,
                    'user_id'    => $user->id,
                    'status'     => 'approved',
                    'notes'      => $approvalNote,
                ]);
            }

            // 2. Tolak struk yang tidak dipilih (partial approval)
            foreach ($toReject as $receipt) {
                $rejectNote = $request->input('notes') ?: "Ditolak pada verifikasi parsial laporan dinas {$expenseReport->report_number}";

                $receipt->update([
                    'status'           => 'rejected',
                    'approved_amount'  => 0,
                    'rejection_reason' => $rejectNote,
                ]);

                ReceiptApproval::create([
                    'receipt_id' => $receipt->id,
                    'user_id'    => $user->id,
                    'status'     => 'rejected',
                    'notes'      => $rejectNote,
                ]);
            }

            // Hapus notifikasi pending struk untuk para approver
            DB::table('notifications')
                ->where('entity_type', 'receipt')
                ->whereIn('entity_id', $allReceipts->pluck('id'))
                ->whereIn('type', ['receipt_submitted', 'receipt_pending'])
                ->delete();

            // Status laporan: approved jika minimal 1 struk disetujui, rejected jika semua ditolak
            $finalStatus = $toApprove->count() > 0 ? 'approved' : 'rejected';

            $expenseReport->update([
                'status'           => $finalStatus,
                'approved_at'      => $finalStatus === 'approved' ? now() : null,
                'approved_by'      => $finalStatus === 'approved' ? $user->id : null,
                'notes'            => $approvalNote,
                'rejection_reason' => $finalStatus === 'rejected' ? ($request->input('notes') ?: 'Seluruh struk dalam laporan ditolak.') : null,
            ]);

            $expenseReport->recalculateTotals();
        });

        $expenseReport->refresh();

        $logMsg = $expenseReport->status === 'approved'
            ? "Menyetujui laporan pengeluaran: {$expenseReport->report_number} (Disetujui: Rp " . number_format($expenseReport->total_approved_amount, 0, ',', '.') . ")"
            : "Menolak seluruh struk pada laporan pengeluaran: {$expenseReport->report_number}";

        $this->logActivity(
            $user->id,
            $expenseReport->company_id,
            'expense_report_' . $expenseReport->status,
            $logMsg,
            $expenseReport->id,
            'expense_report',
            $expenseReport->id
        );

        $notifMsg = $expenseReport->status === 'approved'
            ? "Laporan pengeluaran Anda '{$expenseReport->title}' ({$expenseReport->report_number}) telah disetujui (Total: Rp " . number_format($expenseReport->total_approved_amount, 0, ',', '.') . ")."
            : "Laporan pengeluaran Anda '{$expenseReport->title}' ({$expenseReport->report_number}) ditolak.";

        $this->notifyUser($expenseReport->user_id, 'expense_report_' . $expenseReport->status, [
            'message'         => $notifMsg,
            'report_id'       => $expenseReport->id,
            'report_number'   => $expenseReport->report_number,
            'approved_amount' => $expenseReport->total_approved_amount,
            'status'          => $expenseReport->status,
        ], 'expense_report', $expenseReport->id);

        return response()->json([
            'message' => $expenseReport->status === 'approved' ? 'Laporan pengeluaran berhasil disetujui.' : 'Laporan pengeluaran telah ditolak.',
            'report'  => $expenseReport->fresh(['receipts.duplicateReference']),
        ]);
    }

    /**
     * Tolak laporan pengeluaran.
     */
    public function reject(Request $request, ExpenseReport $expenseReport): JsonResponse
    {
        if ($expenseReport->status !== 'submitted') {
            return response()->json(['message' => 'Hanya laporan berstatus submitted yang dapat ditolak.'], 422);
        }

        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $user = $request->user();

        DB::transaction(function () use ($expenseReport, $user, $request) {
            // Tolak semua struk di dalamnya
            $receipts = $expenseReport->receipts()->whereIn('status', ['submitted', 'pending'])->get();

            foreach ($receipts as $receipt) {
                $receipt->update([
                    'status'           => 'rejected',
                    'rejection_reason' => $request->notes,
                ]);

                ReceiptApproval::create([
                    'receipt_id' => $receipt->id,
                    'user_id'    => $user->id,
                    'status'     => 'rejected',
                    'notes'      => $request->notes,
                ]);
            }

            // Hapus notifikasi pending struk untuk para approver
            DB::table('notifications')
                ->where('entity_type', 'receipt')
                ->whereIn('entity_id', $receipts->pluck('id'))
                ->whereIn('type', ['receipt_submitted', 'receipt_pending'])
                ->delete();

            $expenseReport->update([
                'status'           => 'rejected',
                'rejection_reason' => $request->notes,
            ]);
        });

        $this->logActivity(
            $user->id,
            $expenseReport->company_id,
            'expense_report_rejected',
            "Menolak laporan pengeluaran: {$expenseReport->report_number} (Alasan: {$request->notes})",
            $expenseReport->id,
            'expense_report',
            $expenseReport->id
        );

        $this->notifyUser($expenseReport->user_id, 'expense_report_rejected', [
            'message'        => "Laporan pengeluaran Anda '{$expenseReport->title}' ({$expenseReport->report_number}) ditolak.",
            'report_id'      => $expenseReport->id,
            'report_number'  => $expenseReport->report_number,
            'status'         => 'rejected',
            'reason'         => $request->notes,
        ], 'expense_report', $expenseReport->id);

        return response()->json([
            'message' => 'Laporan pengeluaran berhasil ditolak.',
            'report'  => $expenseReport->fresh(['receipts']),
        ]);
    }
}
