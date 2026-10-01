<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'salary_component_id',
        'amount',
        'effective_date',
        'end_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'effective_date' => 'date:Y-m-d',
            'end_date'       => 'date:Y-m-d',
            'is_active'      => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
