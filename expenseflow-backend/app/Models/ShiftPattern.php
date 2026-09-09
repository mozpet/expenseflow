<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftPattern extends Model
{
    protected $fillable = [
        'company_id',
        'attendance_setting_id',
        'name',
        'description',
        'color',
        'late_tolerance_minutes',
        'cycle_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'attendance_setting_id'  => 'integer',
            'late_tolerance_minutes' => 'integer',
            'cycle_days'             => 'integer',
            'is_active'              => 'boolean',
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

    public function items(): HasMany
    {
        return $this->hasMany(ShiftPatternItem::class)->orderBy('day_order');
    }

    public function dayOverrides(): HasMany
    {
        return $this->hasMany(ShiftPatternDayOverride::class)->orderBy('day_of_week');
    }

    public function userShifts(): HasMany
    {
        return $this->hasMany(UserShift::class);
    }
}
