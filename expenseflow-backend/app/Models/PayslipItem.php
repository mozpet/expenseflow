<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipItem extends Model
{
    use HasFactory;

    public const TYPE_EARNING   = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    protected $fillable = [
        'payslip_id',
        'label',
        'code',
        'type',
        'amount',
        'is_taxable',
        'is_statutory',
        'source',
        'salary_component_id',
        'ref_type',
        'ref_id',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'is_taxable'   => 'boolean',
            'is_statutory' => 'boolean',
            'sort_order'   => 'integer',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
