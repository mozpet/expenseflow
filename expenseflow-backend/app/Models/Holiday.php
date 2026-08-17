<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = [
        'company_id',
        'attendance_setting_id',
        'date',
        'name',
        'is_national',
        'is_collective',
    ];

    protected function casts(): array
    {
        return [
            'date'          => 'date',
            'is_national'   => 'boolean',
            'is_collective' => 'boolean',
        ];
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
