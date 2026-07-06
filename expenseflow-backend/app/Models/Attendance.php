<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'date',
        'check_in_time',
        'check_in_lat',
        'check_in_lng',
        'check_in_distance_meters',
        'check_in_type',
        'check_in_photo',
        'check_out_time',
        'check_out_lat',
        'check_out_lng',
        'check_out_type',
        'status',
        'work_minutes',
        'overtime_minutes',
        'is_holiday',
        'notes',
        'auto_checkout_at',
        'is_auto_checkout',
    ];

    protected function casts(): array
    {
        return [
            'date'                     => 'date',
            'check_in_time'            => 'datetime',
            'check_out_time'           => 'datetime',
            'auto_checkout_at'         => 'datetime',
            'check_in_lat'             => 'decimal:8',
            'check_in_lng'             => 'decimal:8',
            'check_out_lat'            => 'decimal:8',
            'check_out_lng'            => 'decimal:8',
            'check_in_distance_meters' => 'integer',
            'work_minutes'             => 'integer',
            'overtime_minutes'         => 'integer',
            'is_holiday'               => 'boolean',
            'is_auto_checkout'         => 'boolean',
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

    public function overtimeApproval()
    {
        return $this->hasOne(\App\Models\OvertimeApproval::class);
    }
}
