<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalanceHistory extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'attendance_setting_id',
        'period_label',
        'period_start',
        'period_end',
        'reset_date',
        'cuti_quota',
        'cuti_used',
        'cuti_remaining',
        'izin_sakit_used',
        'leave_types_snapshot',
        'notes',
        // Takdir sisa cuti periode ini — dibawa / dicairkan / hangus.
        // cuti_remaining = carried_over_days + encashed_days + forfeited_days.
        'carried_over_days',
        'carry_over_expired_at',
        'encashed_days',
        'encashment_rate_per_day',
        'encashment_amount',
        'encashment_adjustment_id',
        'forfeited_days',
        'policy_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'period_start'    => 'date',
            'period_end'      => 'date',
            'reset_date'      => 'date',
            'cuti_quota'      => 'integer',
            'cuti_used'       => 'integer',
            'cuti_remaining'  => 'integer',
            'izin_sakit_used' => 'integer',
            'leave_types_snapshot' => 'array',
            'carried_over_days'       => 'integer',
            'carry_over_expired_at'   => 'date',
            'encashed_days'           => 'integer',
            'encashment_rate_per_day' => 'decimal:2',
            'encashment_amount'       => 'decimal:2',
            'forfeited_days'          => 'integer',
            'policy_snapshot'         => 'array',
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

    public function office(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    /**
     * Penyesuaian payroll yang mencairkan sisa cuti periode ini.
     *
     * Bisa NULL untuk dua alasan berbeda yang keduanya normal: tidak ada
     * pencairan (encashed_days = 0), atau penyesuaiannya dihapus setelah
     * terbit (FK nullOnDelete). encashment_amount tetap tersimpan di baris ini
     * sebagai catatan nominal yang pernah diterbitkan.
     */
    public function encashmentAdjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class, 'encashment_adjustment_id');
    }
}
