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
        'company_id', 'user_id', 'attendance_setting_id', 'expense_report_id', 'receipt_number', 'sha256_hash',
        'image_path', 'vendor_name', 'total_amount', 'claimed_amount',
        'approved_amount', 'receipt_date', 'currency', 'status', 'ocr_status',
        'ocr_raw_amount', 'ocr_raw_merchant', 'ocr_raw_date', 'ocr_raw_subtotal',
        'ocr_raw_tax', 'ocr_raw_discount', 'ocr_raw_items', 'ocr_error', 'ocr_attempts',
        'variance_flag', 'variance_pct', 'submitted_at',
        'notes', 'category', 'paid_at', 'paid_by', 'payment_method',
        'payment_ref_no', 'payment_proof_path', 'is_potential_duplicate',
        'duplicate_reference_id', 'duplicate_reason',
    ];

    protected $appends = [
        'display_merchant',
    ];

    public function getDisplayMerchantAttribute(): string
    {
        return !empty($this->vendor_name) ? $this->vendor_name : (!empty($this->ocr_raw_merchant) ? $this->ocr_raw_merchant : '—');
    }

    protected function casts(): array
    {
        return [
            'receipt_date'           => 'date:Y-m-d',
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
            'ocr_raw_date'           => 'date:Y-m-d',
            'variance_flag'          => 'boolean',
            'variance_pct'           => 'decimal:2',
            'is_potential_duplicate' => 'boolean',
            'ocr_attempts'           => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Receipt $receipt) {
            if ($receipt->expense_report_id) {
                ExpenseReport::find($receipt->expense_report_id)?->recalculateTotals();
            }
        });

        static::deleted(function (Receipt $receipt) {
            if ($receipt->expense_report_id) {
                ExpenseReport::find($receipt->expense_report_id)?->recalculateTotals();
            }
        });
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

    public function office(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    public function expenseReport(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class);
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
        $claimed   = (float) ($this->claimed_amount ?? $this->total_amount ?? 0);
        $ocrAmount = (float) $this->ocr_raw_amount;

        if ($this->ocr_raw_amount !== null && $ocrAmount > 0 && $claimed > 0) {
            $diff = abs($claimed - $ocrAmount);
            $variancePct = ($diff / $ocrAmount) * 100;

            $limit = $this->getEffectiveVarianceLimit();

            $this->variance_pct  = round($variancePct, 2);
            $this->variance_flag = $variancePct > $limit;

            static::withoutEvents(fn () => $this->saveQuietly());
        }
    }

    /**
     * Dapatkan effective variance limit (%) untuk struk ini.
     * Prioritas:
     * 1. Batas khusus cabang struk (attendance_setting_id).
     * 2. Batas khusus cabang user pengaju (user.attendance_setting_id).
     * 3. Batas global perusahaan (company_settings.variance_limit).
     * 4. Default fallback 10%.
     */
    public function getEffectiveVarianceLimit(): float
    {
        $branchId = $this->attendance_setting_id ?? $this->user?->attendance_setting_id;
        if ($branchId) {
            $branchLimit = DB::table('attendance_settings')
                ->where('id', $branchId)
                ->where('company_id', $this->company_id)
                ->value('variance_limit');

            if ($branchLimit !== null && is_numeric($branchLimit) && (float) $branchLimit >= 0 && (float) $branchLimit <= 99) {
                return (float) $branchLimit;
            }
        }

        $limitVal = DB::table('company_settings')
            ->where('company_id', $this->company_id)
            ->where('key', 'variance_limit')
            ->value('value');

        return ($limitVal !== null && is_numeric($limitVal) && (float) $limitVal >= 0 && (float) $limitVal <= 99)
            ? (float) $limitVal
            : 10.0;
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

        // 2. Layer 2: Cek kecocokan Metadata (Nominal + Tanggal + Merchant Fuzzy)
        $amount   = (float) ($this->total_amount ?: ($this->claimed_amount ?: $this->ocr_raw_amount));
        $date     = $this->receipt_date ?: $this->ocr_raw_date;
        $merchant = trim((string) ($this->vendor_name ?: $this->ocr_raw_merchant));

        if ($amount > 0 && $date) {
            $dateStr = is_string($date) ? substr($date, 0, 10) : $date->format('Y-m-d');

            $candidates = self::where('company_id', $this->company_id)
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
                ->get();

            $normalize = function (?string $str): string {
                return preg_replace('/[^a-z0-9]/', '', strtolower($str ?? ''));
            };

            $normMerchant = $normalize($merchant);
            $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');

            foreach ($candidates as $metaDuplicate) {
                $cMerchant = trim((string) ($metaDuplicate->vendor_name ?: $metaDuplicate->ocr_raw_merchant));
                $normC = $normalize($cMerchant);

                $isMatch = false;
                $reason = '';

                // Jika salah satu nama merchant tidak terbaca, kesamaan tanggal + nominal sudah cukup kuat
                if (empty($normMerchant) || empty($normC)) {
                    $isMatch = true;
                    $reason = "Kombinasi tanggal ({$dateStr}) dan nominal ({$formattedAmount}) identik dengan struk {$metaDuplicate->receipt_number}.";
                } elseif ($normMerchant === $normC || str_contains($normMerchant, $normC) || str_contains($normC, $normMerchant)) {
                    $isMatch = true;
                    $merchantInfo = !empty($cMerchant) ? " di {$cMerchant}" : '';
                    $reason = "Kombinasi tanggal ({$dateStr}), nominal ({$formattedAmount}){$merchantInfo} serupa dengan struk {$metaDuplicate->receipt_number}.";
                } else {
                    similar_text($normMerchant, $normC, $simPct);
                    if ($simPct >= 60) {
                        $isMatch = true;
                        $merchantInfo = !empty($cMerchant) ? " di {$cMerchant}" : '';
                        $reason = "Kombinasi tanggal ({$dateStr}), nominal ({$formattedAmount}){$merchantInfo} serupa dengan struk {$metaDuplicate->receipt_number}.";
                    }
                }

                if ($isMatch) {
                    $this->is_potential_duplicate = true;
                    $this->duplicate_reference_id = $metaDuplicate->id;
                    $this->duplicate_reason = $reason;
                    $this->saveQuietly();
                    return true;
                }
            }
        }

        return false;
    }
}
