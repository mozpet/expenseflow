<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grup Payroll (grouping + scoping MVP).
 *
 * Wadah pengelompokan karyawan untuk penjadwalan batch. Keanggotaan disimpan pada
 * `users.payroll_group_id`; sebuah batch (`payrolls.payroll_group_id`) opsional
 * membatasi kalkulasi hanya ke anggota grup. NULL grup = perilaku lama (backward-compatible).
 */
class PayrollGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Anggota grup (keanggotaan MVP via kolom nullable di users). */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Batch payroll yang di-scope ke grup ini. */
    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }
}
