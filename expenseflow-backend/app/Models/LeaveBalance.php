<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'year',
        'leave_type',
        'quota',
        'used',
        // Bagian dari `quota` yang merupakan bawaan periode lalu, bukan saldo
        // terpisah — lihat migrasi 2026_10_13_000002. Karena itu getRemaining
        // di bawah tetap (quota - used) dan tidak perlu menambahkannya.
        'carried_over_quota',
        'carry_over_expired_at',
    ];

    protected function casts(): array
    {
        return [
            'year'                  => 'integer',
            'quota'                 => 'integer',
            'used'                  => 'integer',
            'carried_over_quota'    => 'integer',
            'carry_over_expired_at' => 'date',
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

    public function getRemainingAttribute(): int
    {
        return $this->quota - $this->used;
    }
}
