<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplication extends Model
{
    protected $fillable = [
        'job_posting_id',
        'company_id',
        'full_name',
        'gender',
        'birth_place',
        'birth_date',
        'nationality',
        'email',
        'phone',
        'postal_code',
        'province',
        'city',
        'district',
        'subdistrict',
        'address',
        'education',
        'institution_name',
        'experience_years',
        'notice_period',
        'expected_salary',
        'portfolio_url',
        'cover_letter',
        'resume_path',
        'status',
        'notes',
        'offering_details',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'birth_date'       => 'date',
        'experience_years' => 'integer',
        'expected_salary'  => 'integer',
        'offering_details' => 'array',
        'reviewed_at'      => 'datetime',
    ];



    // ── Relasi ──────────────────────────────────────────────────────────────

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Label status dalam bahasa Indonesia. */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'new'         => 'Baru',
            'reviewed'    => 'Ditinjau',
            'shortlisted' => 'Shortlist',
            'rejected'    => 'Ditolak',
            'hired'       => 'Diterima',
            default       => $this->status,
        };
    }

    /** Apakah ada file CV yang diupload. */
    public function hasResume(): bool
    {
        return ! empty($this->resume_path);
    }
}
