<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'user_id', 'receipt_number', 'sha256_hash',
        'image_path', 'vendor_name', 'total_amount', 'claimed_amount',
        'receipt_date', 'currency', 'status', 'ocr_status', 'notes', 'category',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date'     => 'date',
            'submitted_at'     => 'datetime',
            'total_amount'     => 'decimal:2',
            'claimed_amount'   => 'decimal:2',
            'ocr_raw_amount'   => 'decimal:2',
            'ocr_raw_date'     => 'date',
            'variance_flag'    => 'boolean',
            'variance_pct'     => 'decimal:2',
            'ocr_attempts'     => 'integer',
        ];
    }

    // ─── Immutable fields ───────────────────────────────────────
    // sha256_hash, ocr_raw_*, ocr_status TIDAK termasuk fillable → tidak bisa mass-assign

    /**
     * Set OCR data — hanya bisa diisi SEKALI.
     * Dipanggil oleh ProcessOcrJob.
     */
    public function setOcrDataOnce(?float $amount, ?string $merchant, ?string $date): bool
    {
        $updated = false;

        if ($amount !== null && $this->ocr_raw_amount === null) {
            $this->ocr_raw_amount = $amount;
            $updated = true;
        }
        if ($merchant !== null && $this->ocr_raw_merchant === null) {
            $this->ocr_raw_merchant = $merchant;
            $updated = true;
        }
        if ($date !== null && $this->ocr_raw_date === null) {
            $this->ocr_raw_date = $date;
            $updated = true;
        }

        if ($updated) {
            static::withoutEvents(fn () => $this->save());
        }

        return $updated;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReceiptImage::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ReceiptApproval::class);
    }

    // ─── Auto-calculate variance flag & percentage ──────────
    public function recalculateVariance(): void
    {
        $claimed  = (float) $this->claimed_amount;
        $ocrAmount = (float) $this->ocr_raw_amount;

        if ($this->ocr_raw_amount !== null && $this->claimed_amount !== null && $ocrAmount > 0) {
            $variancePct = abs($claimed - $ocrAmount) / $ocrAmount * 100;

            $this->variance_pct  = round($variancePct, 2);
            $this->variance_flag = $variancePct > 10;

            static::withoutEvents(fn () => $this->save());
        }
    }
}
