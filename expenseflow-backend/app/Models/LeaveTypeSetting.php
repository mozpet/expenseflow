<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveTypeSetting extends Model
{
    protected $fillable = [
        'attendance_setting_id',
        'leave_type',
        'is_enabled',
        'quota_days',
        'requires_document',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled'        => 'boolean',
            'quota_days'        => 'integer',
            'requires_document' => 'boolean',
        ];
    }

    public function attendanceSetting(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class);
    }
}
