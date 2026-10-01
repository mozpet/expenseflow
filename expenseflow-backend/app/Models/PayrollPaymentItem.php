<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian pembayaran per karyawan dalam satu batch disbursement — Fase 4.
 *
 * Nomor rekening di sini SELALU termasking (bank_account_no_masked). Nomor penuh
 * hanya berada di dalam berkas transfer privat (tidak pernah dipersistensi di tabel
 * dan tidak pernah dikembalikan di JSON).
 */
class PayrollPaymentItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_SUCCESS  = 'success';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_REJECTED = 'rejected_by_bank';

    protected $fillable = [
        'payment_batch_id',
        'payslip_id',
        'user_id',
        'bank_name',
        'bank_account_no_masked',
        'bank_account_holder',
        'amount',
        'status',
        'bank_reference_no',
        'failure_reason',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollPaymentBatch::class, 'payment_batch_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
