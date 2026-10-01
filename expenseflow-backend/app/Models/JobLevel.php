<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenjang jabatan (job level) — Struktur & Skala Upah, Fase 6.
 *
 * `rank` bersifat ordinal: nilai kecil = jenjang lebih rendah.
 */
class JobLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'rank',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rank'      => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(SalaryGrade::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class);
    }
}
