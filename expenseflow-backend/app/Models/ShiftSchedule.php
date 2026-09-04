<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSchedule extends Model
{
    protected $fillable = [
        'shift_id',
        'effective_date',
        'day_of_week',
        'work_start_time',
        'work_end_time',
        'break_minutes',
        'is_off',
        'is_wfh',
        'is_field',
        'is_cross_day',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'day_of_week'    => 'integer',
            'break_minutes'  => 'integer',
            'is_off'         => 'boolean',
            'is_wfh'         => 'boolean',
            'is_field'       => 'boolean',
            'is_cross_day'   => 'boolean',
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

    /**
     * Cek apakah waktu kerja beririsan dengan jendela shift malam (23:00 - 07:00)
     * sesuai standar K3 UU Ketenagakerjaan No. 13/2003 Pasal 76.
     */
    public static function isNightSchedule(?string $startTime, ?string $endTime, bool $isCrossDay = false): bool
    {
        if (! $startTime || ! $endTime) {
            return false;
        }

        // Cross-day shift (melewati tengah malam, misal 22:00 -> 06:00 atau 23:00 -> 07:00)
        if ($isCrossDay) {
            return true;
        }

        $start = substr($startTime, 0, 5);
        $end   = substr($endTime, 0, 5);

        // Jika jam mulai sebelum 07:00 atau jam pulang setelah 23:00
        return ($start < '07:00') || ($end > '23:00');
    }

    /**
     * Cek apakah jadwal baris ini merupakan jadwal malam.
     */
    public function getIsNightAttribute(): bool
    {
        if ($this->is_off) {
            return false;
        }

        return self::isNightSchedule($this->work_start_time, $this->work_end_time, (bool) $this->is_cross_day);
    }
}
