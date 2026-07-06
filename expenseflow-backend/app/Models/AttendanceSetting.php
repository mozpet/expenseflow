<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSetting extends Model
{
    protected $fillable = [
        'company_id',
        'office_name',
        'office_latitude',
        'office_longitude',
        'radius_meters',
        'work_start_time',
        'work_end_time',
        'late_tolerance_minutes',
        'require_selfie',
        'allow_wfh',
        'wfh_checkin_window_minutes',
        'overtime_enabled',
        'min_overtime_minutes',
        'early_leave_tolerance_minutes',
    ];

    protected function casts(): array
    {
        return [
            'office_latitude'            => 'decimal:8',
            'office_longitude'           => 'decimal:8',
            'radius_meters'              => 'integer',
            'late_tolerance_minutes'     => 'integer',
            'require_selfie'             => 'boolean',
            'allow_wfh'                  => 'boolean',
            'wfh_checkin_window_minutes' => 'integer',
            'overtime_enabled'                => 'boolean',
            'min_overtime_minutes'            => 'integer',
            'early_leave_tolerance_minutes'   => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
