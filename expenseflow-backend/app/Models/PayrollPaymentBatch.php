<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch pembayaran gaji (disbursement) — Fase 4.
 *
 * Lihat migrasi 2026_09_28_100019 untuk catatan desain (dipisah dari mark-paid).
 * `file_path` disembunyikan dari JSON (menunjuk berkas privat berisi nomor rekening
 * penuh) — unduh hanya via endpoint download dengan izin `manage`.
 */
class PayrollPaymentBatch extends Model
{
    use HasFactory;

    // Format berkas bank yang didukung (arsitektur driver — lihat app/Services/Payroll/Bank).
    public const BANK_BCA     = 'bca_klikbisnis';
    public const BANK_MANDIRI = 'mandiri_mcm';
    public const BANK_BRI     = 'bri_cms';
    public const BANK_BNI     = 'bni_direct';
    public const BANK_GENERIC = 'generic_csv';

    // Siklus status batch.
    public const STATUS_PREPARED          = 'prepared';
    public const STATUS_FILE_GENERATED    = 'file_generated';
    public const STATUS_UPLOADED          = 'uploaded';
    public const STATUS_PARTIALLY_SETTLED = 'partially_settled';
    public const STATUS_SETTLED           = 'settled';
    public const STATUS_RECONCILED        = 'reconciled';

    protected $fillable = [
        'payroll_id',
        'company_id',
        'batch_reference',
        'bank_format',
        'total_records',
        'total_amount',
        'file_path',
        'file_checksum',
        'status',
        'generated_by',
        'reconciled_by',
        'reconciled_at',
    ];

    /** Jangan bocorkan path berkas privat (berisi nomor rekening penuh) ke JSON. */
    protected $hidden = [
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'total_records' => 'integer',
            'total_amount'  => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollPaymentItem::class, 'payment_batch_id');
    }
}
