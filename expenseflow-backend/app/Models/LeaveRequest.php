<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LeaveRequest extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'leave_type',
        'start_date',
        'end_date',
        'total_days',
        'holiday_compensated_days',
        'reason',
        'document_path',
        'status',
        'current_step',
        'spv_id',
        'spv_approved_at',
        'spv_notes',
        'approved_by',
        'approved_at',
        'notes',
        'rejection_reason',
        'holiday_id',
        'collective_status',
    ];

    protected function casts(): array
    {
        return [
            'start_date'               => 'date:Y-m-d',
            'end_date'                 => 'date:Y-m-d',
            'total_days'               => 'integer',
            'holiday_compensated_days' => 'integer',
            'spv_approved_at'          => 'datetime',
            'approved_at'              => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function spv(): BelongsTo
    {
        return $this->belongsTo(User::class, 'spv_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    /**
     * Otomatis menolak (auto-reject) semua jenis permohonan izin/cuti/sakit/wfh yang masih berstatus 'pending'
     * jika HRD tidak melakukan aksi approval hingga tanggal hari H tiba atau telah lewat (start_date <= today).
     * Berlaku untuk pengajuan mandiri maupun cuti bersama.
     *
     * @param int|null $companyId
     * @return int Jumlah leave_request yang di-reject otomatis
     */
    public static function autoRejectExpiredLeaves(?int $companyId = null): int
    {
        $today = now('Asia/Jakarta')->toDateString();

        $query = static::where('status', 'pending')
            ->whereDate('start_date', '<=', $today);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $pending = $query->with('user')->get();
        if ($pending->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($pending as $leave) {
            $isCollective = $leave->holiday_id !== null;
            $reason = $isCollective
                ? 'Tidak merespons sebelum batas waktu (hari H tiba) — otomatis ditolak oleh sistem.'
                : 'Tidak ada aksi approval dari HRD hingga hari H (otomatis ditolak oleh sistem).';

            $updateData = [
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'approved_at'      => now(),
            ];

            if ($isCollective) {
                $updateData['collective_status'] = 'declined';
            }

            $leave->update($updateData);

            // Hapus notifikasi pending permohonan cuti untuk para approver (HRD/Admin)
            DB::table('notifications')
                ->where('entity_type', 'leave_request')
                ->where('entity_id', $leave->id)
                ->where('type', 'leave_requested')
                ->delete();

            // Kirim notifikasi ke karyawan
            $leaveTypeLabel = match ($leave->leave_type) {
                'cuti'  => 'Cuti Tahunan',
                'izin'  => 'Izin',
                'sakit' => 'Sakit',
                'wfh'   => 'WFH',
                default => ucfirst($leave->leave_type),
            };

            $dateFormatted = Carbon::parse($leave->start_date)->translatedFormat('d M Y');

            $notifData = [
                'title'            => "Pengajuan {$leaveTypeLabel} Ditolak Otomatis",
                'name'             => $leaveTypeLabel,
                'date'             => (string) $leave->start_date,
                'date_label'       => $dateFormatted,
                'message'          => "Pengajuan {$leaveTypeLabel} Anda pada {$dateFormatted} otomatis ditolak oleh sistem karena tidak ada aksi approval dari HRD hingga hari H.",
                'leave_id'         => $leave->id,
                'leave_type'       => $leave->leave_type,
                'status'           => 'rejected',
                'rejection_reason' => $reason,
            ];

            DB::table('notifications')->insert([
                'id'              => (string) Str::uuid(),
                'type'            => 'personal_leave_cancelled',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $leave->user_id,
                'user_id'         => $leave->user_id,
                'data'            => json_encode($notifData),
                'entity_type'     => 'leave_request',
                'entity_id'       => $leave->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Push FCM bila user memiliki token perangkat
            if ($leave->user && $leave->user->fcm_token) {
                try {
                    app(\App\Services\FcmService::class)->send(
                        $leave->user->fcm_token,
                        $notifData['title'],
                        $notifData['message'],
                        [
                            'type'        => 'personal_leave_cancelled',
                            'entity_type' => 'leave_request',
                            'entity_id'   => (string) $leave->id,
                            'date'        => (string) $leave->start_date,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning("Gagal kirim FCM auto-reject leave #{$leave->id}: {$e->getMessage()}");
                }
            }

            $count++;
        }

        return $count;
    }

    /**
     * Otomatis menolak (decline) cuti bersama yang masih 'pending' saat tanggal cuti bersama
     * sudah memasuki Hari H atau lewat (start_date <= today).
     *
     * @param int|null $companyId
     * @param int|null $holidayId
     * @return int Jumlah leave_request yang di-decline
     */
    public static function autoDeclineExpiredCollectiveLeaves(?int $companyId = null, ?int $holidayId = null): int
    {
        // Jalankan autoRejectExpiredLeaves terlebih dahulu
        static::autoRejectExpiredLeaves($companyId);

        $today = now('Asia/Jakarta')->toDateString();

        $query = static::where('collective_status', 'pending')
            ->whereNotNull('holiday_id')
            ->whereDate('start_date', '<=', $today);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }
        if ($holidayId) {
            $query->where('holiday_id', $holidayId);
        }

        $pending = $query->get();
        if ($pending->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($pending as $leave) {
            $leave->update([
                'collective_status' => 'declined',
                'status'            => 'rejected',
                'rejection_reason'  => 'Tidak merespons sebelum batas waktu (hari H tiba) — otomatis ditolak oleh sistem.',
            ]);
            $count++;
        }

        return $count;
    }
}
