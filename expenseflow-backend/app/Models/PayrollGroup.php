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
        // Pengaturan tanggal (2026-10-12): sumber default rentang cut-off presensi
        // & rencana tanggal gajian untuk batch yang memakai grup ini.
        // NULL = belum diatur → bulan kalender penuh; 0 = akhir bulan; 1-31 = tanggal tetap.
        'cutoff_day',
        'payment_day',
        'payment_month_offset', // 0 = gajian bulan periode, 1 = bulan berikutnya
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_day'           => 'integer',
            'payment_day'          => 'integer',
            'payment_month_offset' => 'integer',
            'is_active'            => 'boolean',
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
