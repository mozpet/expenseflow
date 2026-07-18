<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOcrJob;
use App\Models\Receipt;
use App\Models\ReceiptApproval;
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

    // ═══════════════════════════════════════════════════════════
    // 3. submit() — ubah status menjadi submitted.
    //    Cek ownership, cek OCR status, lalu submit.
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

        // 3. Ubah status dan simpan
        $receipt->status = 'submitted';
        $receipt->submitted_at = now();
        $receipt->save();

        // 4. Hitung ulang variance setelah submit
        $receipt->refresh()->recalculateVariance();

        // 5. Catat ke activity_logs
        $this->logActivity($request->user()->id, $receipt->company_id, 'receipt_submitted', 'Submit struk ' . $receipt->receipt_number, $receipt->id, 'receipt', $receipt->id);

        // 6. Return response
        return response()->json([
            'message' => 'Struk berhasil disubmit.',
            'receipt' => [
                'id'            => $receipt->id,
                'status'        => $receipt->status,
                'submitted_at'  => $receipt->submitted_at,
                'variance_flag' => $receipt->variance_flag,
                'variance_pct'  => $receipt->variance_pct,
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
    // 4. approve() — finance approve, catat ke receipt_approvals,
    //    activity_logs, dan notifications
    // ═══════════════════════════════════════════════════════════
    public function approve(Request $request, Receipt $receipt): JsonResponse
    {
        if (! in_array($receipt->status, ['submitted', 'pending'])) {
            return response()->json(['message' => 'Hanya struk submitted yang bisa diapprove.'], 403);
        }

        $user = $request->user();

        $receipt->update(['status' => 'approved']);

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
            'receipt_approved', 'Approve struk ' . $receipt->receipt_number,
            $receipt->id,
            'receipt', $receipt->id
        );

        // Kirim notifikasi ke user yang submit struk
        $this->notifyUser($receipt->user_id, 'receipt_approved', [
            'message'        => 'Struk Anda telah diapprove: ' . $receipt->receipt_number,
            'receipt_id'     => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'status'         => 'approved',
        ], 'receipt', $receipt->id);

        return response()->json([
            'message' => 'Struk berhasil diapprove.',
            'receipt' => $receipt->only(['id', 'receipt_number', 'status', 'variance_flag', 'variance_pct']),
            'approved_by' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'approved_at' => now()->toIso8601String(),
        ]);
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
    // 6. show() — detail satu struk lengkap dengan semua field OCR.
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

        $receipt->load(['approvals.user:id,name,role']);

        return response()->json([
            'receipt' => [
                'id'                => $receipt->id,
                'receipt_number'    => $receipt->receipt_number,
                'sha256_hash'       => $receipt->sha256_hash,
                'image_path'        => $receipt->image_path,
                'vendor_name'       => $receipt->vendor_name,
                'total_amount'      => $receipt->total_amount,
                'claimed_amount'    => $receipt->claimed_amount,
                'receipt_date'      => $receipt->receipt_date,
                'currency'          => $receipt->currency,
                'status'            => $receipt->status,
                'submitted_at'      => $receipt->submitted_at,
                'ocr_status'        => $receipt->ocr_status,
                'ocr_raw_amount'    => $receipt->ocr_raw_amount,
                'ocr_raw_merchant'  => $receipt->ocr_raw_merchant,
                'ocr_raw_date'      => $receipt->ocr_raw_date,
                'ocr_attempts'       => $receipt->ocr_attempts,
                'ocr_error'         => $receipt->ocr_error,
                'variance_flag'     => $receipt->variance_flag,
                'variance_pct'      => $receipt->variance_pct,
                'category'          => $receipt->category,
                'notes'             => $receipt->notes,
                'approvals'         => $receipt->approvals,
                'created_at'        => $receipt->created_at,
                'updated_at'        => $receipt->updated_at,
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
                'claimed_amount', 'ocr_raw_amount', 'ocr_raw_merchant', 'ocr_raw_date',
                'receipt_date', 'status', 'submitted_at', 'ocr_status',
                'category', 'notes', 'variance_flag', 'variance_pct', 'created_at',
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

        $receipts = Receipt::where('company_id', $companyId)
            ->where('status', 'submitted')
            ->with(['user:id,name,email'])
            ->select(['id', 'user_id', 'receipt_number', 'vendor_name', 'ocr_raw_merchant', 'total_amount', 'claimed_amount', 'ocr_raw_amount', 'receipt_date', 'status', 'ocr_status', 'category', 'variance_flag', 'variance_pct', 'submitted_at', 'created_at'])
            ->latest()
            ->paginate(20);

        return response()->json($receipts);
    }

    // ═══════════════════════════════════════════════════════════
    // 9. dashboardReceipts() — list SEMUA struk dengan filter status
    //    GET /api/v1/dashboard/receipts/all?status=submitted|approved|rejected
    //    Tanpa ?status= → tampilkan semua (submitted + approved + rejected)
    // ═══════════════════════════════════════════════════════════
    public function dashboardReceipts(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $status    = $request->query('status');

        // Valid status values
        $validStatuses = ['submitted', 'approved', 'rejected'];

        $query = Receipt::where('company_id', $companyId)
            ->with(['user:id,name,email', 'approvals.user:id,name,email,role'])
            ->select(['id', 'user_id', 'receipt_number', 'vendor_name', 'ocr_raw_merchant', 'total_amount', 'claimed_amount', 'ocr_raw_amount', 'receipt_date', 'status', 'ocr_status', 'category', 'variance_flag', 'variance_pct', 'submitted_at', 'created_at']);

        // Filter by status jika parameter diberikan dan valid
        if ($status && in_array($status, $validStatuses)) {
            $query->where('status', $status);
        } else {
            // Default: tampilkan submitted + approved + rejected (bukan draft)
            $query->whereIn('status', $validStatuses);
        }

        $receipts = $query->latest()->paginate(20);

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
                'rejected'  => $summary['rejected'] ?? 0,
            ],
            'receipts' => $receipts,
        ]);
    }
}
