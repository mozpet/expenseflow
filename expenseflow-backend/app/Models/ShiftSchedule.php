<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSchedule extends Model
{
    protected $fillable = [
        'shift_id',
        'day_of_week',
        'work_start_time',
        'work_end_time',
        'is_off',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_off'      => 'boolean',
        ];
    }

    // Relasi ke template shift
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    // Accessor: nama hari dalam Bahasa Indonesia
    public function getDayNameAttribute(): string
    {
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        return $hari[$this->day_of_week] ?? 'Unknown';
    }
}
