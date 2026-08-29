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
        'notes',
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
}
