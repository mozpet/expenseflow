<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'vendor_id',
        'user_id',
        'invoice_number',
        'po_number',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'due_date',
        'invoice_date',
        'currency',
        'status',
        'max_approval_level',
        'current_approval_level',
        'source',
        'category',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date'          => 'date',
            'due_date'              => 'date',
            'subtotal'              => 'decimal:2',
            'tax_amount'            => 'decimal:2',
            'discount_amount'       => 'decimal:2',
            'total_amount'          => 'decimal:2',
            'max_approval_level'    => 'integer',
            'current_approval_level'=> 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(InvoiceApproval::class);
    }

    /**
     * Tentukan max_approval_level berdasarkan total_amount.
     */
    public static function determineApprovalLevel(float $totalAmount): int
    {
        if ($totalAmount < 10_000_000) {
            return 1; // Finance Manager
        } elseif ($totalAmount <= 50_000_000) {
            return 2; // + Direksi
        } else {
            return 3; // + Komisaris
        }
    }
}
