<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftPatternItem extends Model
{
    protected $fillable = [
        'shift_pattern_id',
        'day_order',
        'shift_id',
        'is_off',
        'work_start_time',
        'work_end_time',
        'break_minutes',
        'is_cross_day',
    ];

    protected function casts(): array
    {
        return [
            'day_order'     => 'integer',
            'is_off'        => 'boolean',
            'break_minutes' => 'integer',
            'is_cross_day'  => 'boolean',
        ];
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(ShiftPattern::class, 'shift_pattern_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
