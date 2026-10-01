<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Golongan upah (salary grade) — Struktur & Skala Upah, Fase 6.
 *
 * Menyimpan rentang upah min–mid–maks yang menjadi acuan penempatan gaji pokok
 * karyawan (Permenaker 1/2017). Validasi rentang ditegakkan saat penetapan gaji.
 */
class SalaryGrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'job_level_id',
        'name',
        'code',
        'min_salary',
        'mid_salary',
        'max_salary',
        'currency',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'job_level_id' => 'integer',
            'min_salary'   => 'decimal:2',
            'mid_salary'   => 'decimal:2',
            'max_salary'   => 'decimal:2',
            'is_active'    => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    /** True bila nominal berada dalam rentang golongan (inklusif kedua ujung). */
    public function contains(float $amount): bool
    {
        return $amount >= (float) $this->min_salary && $amount <= (float) $this->max_salary;
    }
}
