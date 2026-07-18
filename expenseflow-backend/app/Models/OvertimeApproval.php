<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeApproval extends Model
{
    protected $fillable = [
        'attendance_id',
        'user_id',
        'company_id',
        'overtime_minutes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'notes',
        'is_auto_checkout',
        'overtime_reason',
    ];

    protected function casts(): array
    {
        return [
            'overtime_minutes' => 'integer',
            'reviewed_at'      => 'datetime',
            'is_auto_checkout' => 'boolean',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
