<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'year',
        'leave_type',
        'quota',
        'used',
    ];

    protected function casts(): array
    {
        return [
            'year'  => 'integer',
            'quota' => 'integer',
            'used'  => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getRemainingAttribute(): int
    {
        return $this->quota - $this->used;
    }
}
