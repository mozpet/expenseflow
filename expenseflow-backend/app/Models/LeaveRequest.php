<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'approved_by',
        'approved_at',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    /**
     * Otomatis menolak (decline) cuti bersama yang masih 'pending' saat tanggal cuti bersama
     * sudah memasuki Hari H atau lewat (start_date <= today).
     * Dapat dipanggil oleh command scheduler maupun secara on-the-fly saat controller diakses.
     *
     * @param int|null $companyId
     * @param int|null $holidayId
     * @return int Jumlah leave_request yang di-decline
     */
    public static function autoDeclineExpiredCollectiveLeaves(?int $companyId = null, ?int $holidayId = null): int
    {
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
