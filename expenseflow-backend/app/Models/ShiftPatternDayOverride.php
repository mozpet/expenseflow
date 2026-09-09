<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftPatternDayOverride extends Model
{
    protected $fillable = [
        'shift_pattern_id',
        'day_of_week',
        'work_start_time',
        'work_end_time',
        'break_minutes',
        'late_tolerance_minutes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week'            => 'integer',
            'break_minutes'          => 'integer',
            'late_tolerance_minutes' => 'integer',
        ];
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(ShiftPattern::class, 'shift_pattern_id');
    }
}
