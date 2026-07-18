<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
// AttendanceSetting berada di namespace yang sama (App\Models)

class Shift extends Model
{
    protected $fillable = [
        'company_id',
        'attendance_setting_id',
        'name',
        'description',
        'is_active',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Relasi ke perusahaan pemilik shift
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Relasi ke cabang (attendance_settings) pemilik shift. Null = company-wide.
    public function office(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    // Jadwal harian (7 baris: Minggu–Sabtu)
    public function schedules(): HasMany
    {
        return $this->hasMany(ShiftSchedule::class)->orderBy('day_of_week');
    }

    // Semua assignment karyawan yang menggunakan shift ini
    public function userShifts(): HasMany
    {
        return $this->hasMany(UserShift::class);
    }
}
