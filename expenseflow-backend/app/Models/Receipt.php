<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Receipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'user_id', 'receipt_number', 'sha256_hash',
        'image_path', 'vendor_name', 'total_amount', 'claimed_amount',
        'approved_amount', 'receipt_date', 'currency', 'status', 'ocr_status',
        'notes', 'category', 'paid_at', 'paid_by', 'payment_method',
        'payment_ref_no', 'payment_proof_path', 'is_potential_duplicate',
        'duplicate_reference_id', 'duplicate_reason',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date'           => 'date',
            'submitted_at'           => 'datetime',
            'paid_at'                => 'datetime',
            'total_amount'           => 'decimal:2',
            'claimed_amount'         => 'decimal:2',
            'approved_amount'        => 'decimal:2',
            'ocr_raw_amount'         => 'decimal:2',
            'ocr_raw_subtotal'       => 'decimal:2',
            'ocr_raw_tax'            => 'decimal:2',
            'ocr_raw_discount'       => 'decimal:2',
            'ocr_raw_items'          => 'array',
            'ocr_raw_date'           => 'date',
            'variance_flag'          => 'boolean',
            'variance_pct'           => 'decimal:2',
            'is_potential_duplicate' => 'boolean',
            'ocr_attempts'           => 'integer',
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

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function duplicateReference(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'duplicate_reference_id');
    }

    // ─── Auto-calculate variance flag & percentage ──────────
    public function recalculateVariance(): void
    {
        $claimed  = (float) $this->claimed_amount;
        $ocrAmount = (float) $this->ocr_raw_amount;

        if ($this->ocr_raw_amount !== null && $this->claimed_amount !== null && $ocrAmount > 0) {
            $variancePct = abs($claimed - $ocrAmount) / $ocrAmount * 100;

            $limit = (float) (DB::table('company_settings')
                ->where('company_id', $this->company_id)
                ->where('key', 'variance_limit')
                ->value('value') ?? 10);

            $this->variance_pct  = round($variancePct, 2);
            $this->variance_flag = $variancePct > $limit;

            static::withoutEvents(fn () => $this->save());
        }
    }

    /**
     * Deteksi potensi struk duplikat (multi-layer: SHA-256 hash & Metadata).
     */
    public function detectPotentialDuplicate(): bool
    {
        // 1. Layer 1: Cek kecocokan SHA-256 Hash Foto Identik 100%
        if (!empty($this->sha256_hash)) {
            $hashDuplicate = self::where('company_id', $this->company_id)
                ->where('id', '!=', $this->id)
                ->where('status', '!=', 'rejected')
                ->where('sha256_hash', $this->sha256_hash)
                ->first();

            if ($hashDuplicate) {
                $this->is_potential_duplicate = true;
                $this->duplicate_reference_id = $hashDuplicate->id;
                $this->duplicate_reason = "Foto struk identik 100% (SHA-256 hash sama) dengan struk {$hashDuplicate->receipt_number}.";
                $this->saveQuietly();
                return true;
            }
        }

        // 2. Layer 2: Cek kecocokan Metadata (Nominal + Tanggal + Merchant)
        $amount   = (float) ($this->total_amount ?: ($this->claimed_amount ?: $this->ocr_raw_amount));
        $date     = $this->receipt_date ?: $this->ocr_raw_date;
        $merchant = trim((string) ($this->vendor_name ?: $this->ocr_raw_merchant));

        if ($amount > 0 && $date) {
            $dateStr = is_string($date) ? substr($date, 0, 10) : $date->format('Y-m-d');

            $metaDuplicate = self::where('company_id', $this->company_id)
                ->where('id', '!=', $this->id)
                ->where('status', '!=', 'rejected')
                ->where(function ($q) use ($amount) {
                    $q->where('total_amount', $amount)
                      ->orWhere('claimed_amount', $amount)
                      ->orWhere('ocr_raw_amount', $amount);
                })
                ->where(function ($q) use ($dateStr) {
                    $q->whereDate('receipt_date', $dateStr)
                      ->orWhereDate('ocr_raw_date', $dateStr);
                })
                ->when(!empty($merchant), function ($q) use ($merchant) {
                    $q->where(function ($sub) use ($merchant) {
                        $sub->where('vendor_name', 'like', '%' . $merchant . '%')
                            ->orWhere('ocr_raw_merchant', 'like', '%' . $merchant . '%');
                    });
                })
                ->first();

            if ($metaDuplicate) {
                $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');
                $merchantInfo    = !empty($merchant) ? " di {$merchant}" : '';

                $this->is_potential_duplicate = true;
                $this->duplicate_reference_id = $metaDuplicate->id;
                $this->duplicate_reason = "Kombinasi tanggal ({$dateStr}), nominal ({$formattedAmount}){$merchantInfo} serupa dengan struk {$metaDuplicate->receipt_number}.";
                $this->saveQuietly();
                return true;
            }
        }

        return false;
    }
}
