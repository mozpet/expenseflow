<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penyesuaian gaji & koreksi retroaktif (Fase 3).
 *
 * Dikonsumsi oleh PayrollCalculator saat calculate: yang berstatus approved & belum
 * terpakai (payroll_id null) diklaim ke batch (payroll_id di-set, status→applied) dan
 * dilepas kembali saat recalc/hapus batch. Beku setelah batch disetujui.
 */
class PayrollAdjustment extends Model
{
    use HasFactory;

    public const TYPE_EARNING   = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_APPLIED    = 'applied';
    public const STATUS_VOIDED     = 'voided';

    protected $fillable = [
        'company_id',
        'user_id',
        'payroll_id',
        'retroactive_payroll_id',
        'type',
        'name',
        'amount',
        'is_taxable',
        'reason',
        'source_document_path',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'applied_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'is_taxable'  => 'boolean',
            'approved_at' => 'datetime',
            'applied_at'  => 'datetime',
            'voided_at'   => 'datetime',
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

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function retroactivePayroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'retroactive_payroll_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
