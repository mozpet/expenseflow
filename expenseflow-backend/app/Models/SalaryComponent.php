<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryComponent extends Model
{
    use HasFactory;

    public const TYPE_EARNING   = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    public const CALC_FIXED   = 'fixed';
    public const CALC_MANUAL  = 'manual';
    public const CALC_AUTO    = 'auto';
    public const CALC_FORMULA = 'formula'; // Fase 6 §6.A — dihitung dari formula_dsl via FormulaEngine.

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'calc_type',
        'formula_dsl',
        'category',
        'is_taxable',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employeeComponents(): HasMany
    {
        return $this->hasMany(EmployeeSalaryComponent::class);
    }
}
