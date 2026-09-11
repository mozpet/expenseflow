<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseReport extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'attendance_setting_id',
        'report_number',
        'title',
        'description',
        'start_date',
        'end_date',
        'status',
        'total_claimed_amount',
        'total_approved_amount',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'paid_at',
        'paid_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date'            => 'date:Y-m-d',
            'end_date'              => 'date:Y-m-d',
            'submitted_at'          => 'datetime',
            'approved_at'           => 'datetime',
            'paid_at'               => 'datetime',
            'total_claimed_amount'  => 'decimal:2',
            'total_approved_amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * Hitung ulang total claimed amount & total approved amount dari struk di dalamnya,
     * serta sinkronisasi status jika semua struk telah selesai ditinjau.
     */
    public function recalculateTotals(): void
    {
        $claimed = (float) $this->receipts()
            ->selectRaw('COALESCE(SUM(claimed_amount), SUM(total_amount), 0) as total')
            ->value('total');

        $approved = (float) $this->receipts()
            ->whereIn('status', ['approved', 'paid'])
            ->selectRaw('COALESCE(SUM(approved_amount), SUM(claimed_amount), 0) as total')
            ->value('total');

        $updates = [
            'total_claimed_amount'  => $claimed,
            'total_approved_amount' => $approved > 0 ? $approved : null,
        ];

        // Jika laporan berstatus submitted dan seluruh struk sudah selesai di-review
        if ($this->status === 'submitted') {
            $totalCount = $this->receipts()->count();
            $approvedCount = $this->receipts()->whereIn('status', ['approved', 'paid'])->count();
            $rejectedCount = $this->receipts()->where('status', 'rejected')->count();

            if ($totalCount > 0 && $approvedCount > 0 && ($approvedCount + $rejectedCount === $totalCount)) {
                $updates['status'] = 'approved';
                $updates['approved_at'] = now();
            } elseif ($totalCount > 0 && $rejectedCount === $totalCount) {
                $updates['status'] = 'rejected';
            }
        } elseif ($this->status === 'approved') {
            $totalCount = $this->receipts()->count();
            $paidCount = $this->receipts()->where('status', 'paid')->count();
            if ($totalCount > 0 && $paidCount === $totalCount) {
                $updates['status'] = 'paid';
                $updates['paid_at'] = now();
            }
        }

        $this->updateQuietly($updates);
    }
}
