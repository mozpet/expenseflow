<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Konsolidasi masa pajak PPh21 per karyawan per bulan.
 * Sumber tunggal untuk rekonsiliasi tahunan & Bukti Potong 1721-A1.
 */
class EmployeeTaxPeriodTotal extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'tax_year',
        'tax_month',
        'regular_gross',
        'irregular_gross',
        'taxable_benefit_gross',
        'total_taxable_gross',
        'total_pph21_withheld',
        'regular_pph21',
        'irregular_pph21',
    ];

    protected function casts(): array
    {
        return [
            'tax_year'              => 'integer',
            'tax_month'             => 'integer',
            'regular_gross'         => 'decimal:2',
            'irregular_gross'       => 'decimal:2',
            'taxable_benefit_gross' => 'decimal:2',
            'total_taxable_gross'   => 'decimal:2',
            'total_pph21_withheld'  => 'decimal:2',
            'regular_pph21'         => 'decimal:2',
            'irregular_pph21'       => 'decimal:2',
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
}
