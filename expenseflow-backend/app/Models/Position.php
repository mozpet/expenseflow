<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'division_id',
        'name',
        'is_supervisor',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_supervisor' => 'boolean',
            'is_active'     => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

