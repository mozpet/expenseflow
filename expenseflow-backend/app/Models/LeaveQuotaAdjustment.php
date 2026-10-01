<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveQuotaAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'adjusted_by_id',
        'leave_type',
        'year',
        'old_quota',
        'new_quota',
        'difference',
        'reason',
    ];

    protected $casts = [
        'year'        => 'integer',
        'old_quota'   => 'integer',
        'new_quota'   => 'integer',
        'difference'  => 'integer',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
