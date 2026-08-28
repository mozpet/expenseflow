<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    protected $fillable = [
        'company_id',
        'created_by',
        'title',
        'department',
        'location',
        'employment_type',
        'description',
        'requirements',
        'salary_min',
        'show_salary',
        'max_applicants',
        'contact_email',
        'status',
        'deadline',
        'published_at',
    ];

    protected $casts = [
        'show_salary'    => 'boolean',
        'max_applicants' => 'integer',
        'salary_min'     => 'integer',
        'salary_max'     => 'integer',
        'deadline'       => 'date',
        'published_at'   => 'datetime',
    ];


    // ── Relasi ──────────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Label tipe pekerjaan dalam bahasa Indonesia. */
    public function getEmploymentTypeLabelAttribute(): string
    {
        return match ($this->employment_type) {
            'full_time'  => 'Full Time',
            'part_time'  => 'Part Time',
            'contract'   => 'Kontrak',
            'internship' => 'Magang',
            default      => $this->employment_type,
        };
    }

    /** Jumlah pelamar yang masuk. */
    public function getApplicationCountAttribute(): int
    {
        return $this->applications()->count();
    }
}
