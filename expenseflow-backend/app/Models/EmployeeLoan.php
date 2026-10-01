<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoan extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_PAID       = 'paid';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $fillable = [
        'company_id',
        'user_id',
        'title',
        'principal',
        'installment_amount',
        'tenor_months',
        'installments_paid',
        'remaining_amount',
        'start_period_month',
        'start_period_year',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'principal'          => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'remaining_amount'   => 'decimal:2',
            'tenor_months'       => 'integer',
            'installments_paid'  => 'integer',
            'start_period_month' => 'integer',
            'start_period_year'  => 'integer',
            'approved_at'        => 'datetime',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
