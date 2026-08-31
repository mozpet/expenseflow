<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ProcessAttendanceBackgroundJob
 *
 * Job latar belakang untuk operasi berat setelah check-in / check-out:
 *   1. Insert activity_log (audit trail)
 *   2. Kirim push notification FCM ke user terkait (opsional)
 *
 * Tujuan: memastikan response API check-in/check-out tetap instan (< 30ms)
 * saat peak hour (07:50–08:00 WIB) dengan memindahkan operasi I/O berat
 * ke antrean background worker.
 */
class ProcessAttendanceBackgroundJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maksimal retry sebelum masuk failed_jobs.
     */
    public int $tries = 3;

    /**
     * Delay antar retry (detik).
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     *
     * @param int         $userId      ID user yang melakukan aksi
     * @param int|null    $companyId   ID perusahaan
     * @param string      $action      Jenis aksi (e.g. 'attendance_check_in')
     * @param string      $description Deskripsi aksi untuk audit log
     * @param string|null $entityType  Tipe entitas terkait (e.g. 'attendance')
     * @param int|null    $entityId    ID entitas terkait
     * @param array|null  $notifyData  Data notifikasi FCM (null = tidak kirim notif)
     *                                 Format: ['user_id' => int, 'type' => string, 'data' => array]
     */
    public function __construct(
        public int     $userId,
        public ?int    $companyId,
        public string  $action,
        public string  $description,
        public ?string $entityType = null,
        public ?int    $entityId = null,
        public ?array  $notifyData = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // ─── 1. Insert Activity Log (Audit Trail) ────────────────────
        try {
            DB::table('activity_logs')->insert([
                'company_id'  => $this->companyId,
                'user_id'     => $this->userId,
                'action'      => $this->action,
                'description' => $this->description,
                'entity_type' => $this->entityType,
                'entity_id'   => $this->entityId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessAttendanceBackgroundJob: gagal insert activity_log', [
                'user_id' => $this->userId,
                'action'  => $this->action,
                'error'   => $e->getMessage(),
            ]);
            // Jangan throw — lanjutkan ke langkah berikutnya
        }

        // ─── 2. Kirim Push Notification FCM (opsional) ───────────────
        if ($this->notifyData) {
            $this->sendFcmNotification();
        }
    }

    /**
     * Kirim push notification FCM ke user tertentu.
     */
    private function sendFcmNotification(): void
    {
        try {
            $targetUserId = $this->notifyData['user_id'] ?? null;
            $type         = $this->notifyData['type'] ?? 'attendance_notification';
            $data         = $this->notifyData['data'] ?? [];

            if (! $targetUserId) {
                return;
            }

            // Insert ke tabel notifications (agar tampil di inbox notif web/mobile)
            DB::table('notifications')->insert([
                'id'              => Str::uuid()->toString(),
                'type'            => $type,
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $targetUserId,
                'user_id'         => $targetUserId,
                'data'            => json_encode($data),
                'entity_type'     => $this->entityType,
                'entity_id'       => $this->entityId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Kirim FCM push notification
            $user = User::find($targetUserId);
            if ($user && $user->fcm_token) {
                $title = $data['title'] ?? '🔔 Pemberitahuan Presensi';
                $body  = $data['message'] ?? 'Ada pemberitahuan presensi baru.';

                app(FcmService::class)->send($user->fcm_token, $title, $body, [
                    'type'        => $type,
                    'entity_type' => (string) ($this->entityType ?? ''),
                    'entity_id'   => (string) ($this->entityId ?? ''),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("ProcessAttendanceBackgroundJob: gagal kirim FCM ke user #{$this->notifyData['user_id']}: {$e->getMessage()}");
            // Jangan throw — FCM gagal bukan alasan untuk retry seluruh job
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessAttendanceBackgroundJob FAILED', [
            'user_id'   => $this->userId,
            'action'    => $this->action,
            'error'     => $exception?->getMessage(),
        ]);
    }
}
