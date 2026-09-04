<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserShift extends Model
{
    protected $fillable = [
        'user_id',
        'shift_id',
        'shift_pattern_id',
        'anchor_day_order',
        'start_date',
        'end_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'shift_pattern_id' => 'integer',
            'anchor_day_order' => 'integer',
            'start_date'       => 'date',
            'end_date'         => 'date',
        ];
    }

    // Relasi ke karyawan
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relasi ke template shift (nullable: null = default kantor atau pola rotasi)
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    // Relasi ke pola rotasi siklus (nullable)
    public function shiftPattern(): BelongsTo
    {
        return $this->belongsTo(ShiftPattern::class, 'shift_pattern_id');
    }
}
