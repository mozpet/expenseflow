<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Services\OcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOcrJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maksimal retry sebelum masuk failed_jobs.
     */
    public int $tries = 3;

    /**
     * Delay antar retry (detik).
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $receiptId,
    ) {}

    /**
     * Execute the job — kirim foto ke Google Cloud Vision,
     * parse hasil OCR, dan simpan hasilnya.
     */
    public function handle(OcrService $ocr): void
    {
        $receipt = Receipt::find($this->receiptId);

        if (! $receipt || ! $receipt->image_path) {
            Log::warning('ProcessOcrJob: receipt tidak ditemukan / tidak ada gambar', [
                'receipt_id' => $this->receiptId,
            ]);
            return;
        }

        // Tandai mulai proses
        $this->updateOcrStatus($receipt->id, 'processing', $this->attempts());

        try {
            // ─── 1. Kirim ke Google Cloud Vision API ──────────────
            $ocrResult = $ocr->analyze($receipt->image_path);

            // ─── 2. Simpan hasil OCR (immutable — hanya sekali) ─
            $this->saveOcrData($receipt, $ocrResult);

        } catch (\Throwable $e) {
            Log::error('ProcessOcrJob gagal', [
                'receipt_id' => $this->receiptId,
                'attempt'    => $this->attempts(),
                'error'      => $e->getMessage(),
            ]);

            // Update status gagal + simpan error
            $this->updateOcrStatus($receipt->id, 'failed', $this->attempts(), $e->getMessage());

            // Notifikasi gagal via activity_logs
            $this->logNotification($receipt, 'ocr_failed', 'OCR gagal: ' . $e->getMessage(), 'receipt', $receipt->id);

            // Re-throw agar Laravel retry mechanism bekerja
            throw $e;
        }
    }

    /**
     * Simpan data OCR — hanya bisa diisi SEKALI (immutable).
     */
    private function saveOcrData(Receipt $receipt, array $ocrResult): void
    {
        // Gunakan raw query untuk bypass Eloquent mass-assignment protection
        $updates = [];
        $now     = now()->toDateTimeString();

        if ($receipt->ocr_raw_amount === null && $ocrResult['amount'] !== null) {
            $updates['ocr_raw_amount'] = $ocrResult['amount'];
        }
        if ($receipt->ocr_raw_merchant === null && $ocrResult['merchant'] !== null) {
            $updates['ocr_raw_merchant'] = $ocrResult['merchant'];
        }
        if ($receipt->ocr_raw_date === null && $ocrResult['date'] !== null) {
            $updates['ocr_raw_date'] = $ocrResult['date'];
        }

        $updates['ocr_status']   = 'done';
        $updates['ocr_attempts'] = $this->attempts();
        $updates['ocr_error']    = null;
        $updates['updated_at']   = $now;

        if (! empty($updates)) {
            DB::table('receipts')->where('id', $receipt->id)->update($updates);
        }

        // ─── Pre-fill claimed_amount jika masih kosong ──────────
        $receipt->refresh();
        if ($receipt->claimed_amount === null && $receipt->ocr_raw_amount !== null) {
            DB::table('receipts')->where('id', $receipt->id)->update([
                'claimed_amount' => $receipt->ocr_raw_amount,
                'updated_at'     => $now,
            ]);
        }

        // Hitung ulang variance flag
        $receipt->refresh();
        $receipt->recalculateVariance();

        // ─── Notifikasi sukses ─────────────────────────────────
        $this->logNotification($receipt, 'ocr_completed', 'OCR berhasil. Nominal: ' . ($ocrResult['amount'] ?? 'N/A'), 'receipt', $receipt->id);

        Log::info('ProcessOcrJob sukses', [
            'receipt_id' => $receipt->id,
            'ocr_data'   => $updates,
        ]);
    }

    /**
     * Update ocr_status di database.
     */
    private function updateOcrStatus(int $receiptId, string $status, int $attempts, ?string $error = null): void
    {
        $data = [
            'ocr_status'   => $status,
            'ocr_attempts' => $attempts,
            'updated_at'   => now()->toDateTimeString(),
        ];

        if ($error !== null) {
            $data['ocr_error'] = mb_substr($error, 0, 500);
        }

        DB::table('receipts')->where('id', $receiptId)->update($data);
    }

    /**
     * Catat notifikasi ke activity_logs (sekaligus berfungsi
     * sebagai placeholder untuk push notification ke Flutter).
     */
    private function logNotification(Receipt $receipt, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'   => $receipt->company_id,
            'user_id'      => $receipt->user_id,
            'action'       => $action,
            'description'  => $description,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'created_at'   => now()->toDateTimeString(),
            'updated_at'   => now()->toDateTimeString(),
        ]);

        // TODO: Kirim push notification ke Flutter via Firebase FCM
        // $receipt->user->notify(new OcrResultNotification($receipt, $action));
    }

    /**
     * Handle a job failure — dipanggil setelah 3 retry gagal.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ProcessOcrJob MASUK failed_jobs setelah 3 retry', [
            'receipt_id' => $this->receiptId,
            'error'      => $e->getMessage(),
        ]);

        // Final update status
        $this->updateOcrStatus($this->receiptId, 'failed', 3, 'Max retry: ' . $e->getMessage());
    }
}
