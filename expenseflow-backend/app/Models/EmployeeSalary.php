<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalary extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'salary_grade_id', // Struktur & Skala Upah (Fase 6) — penempatan golongan, ber-riwayat
        'job_level_id',
        'basic_salary',
        'currency',        // mata uang kontrak gaji pokok (default IDR)
        'effective_date',
        'end_date',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'salary_grade_id' => 'integer',
            'job_level_id'    => 'integer',
            'basic_salary'   => 'decimal:2',
            'effective_date' => 'date:Y-m-d',
            'end_date'       => 'date:Y-m-d',
            'is_active'      => 'boolean',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Golongan upah tempat gaji pokok ini ditempatkan (Fase 6). */
    public function salaryGrade(): BelongsTo
    {
        return $this->belongsTo(SalaryGrade::class);
    }

    /** Jenjang jabatan yang melekat pada penempatan ini (Fase 6). */
    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }
}
