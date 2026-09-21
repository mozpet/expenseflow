<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleBranch extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_id',
        'attendance_setting_id',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }
}
