<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris jejak audit tamper-evident payroll (hash-chain SHA-256).
 * Immutable: hanya ditulis via PayrollAuditLogger, tidak pernah di-update.
 * before_state/after_state disimpan sebagai TEXT berisi JSON kanonik.
 */
class PayrollLog extends Model
{
    public $timestamps = false; // hanya created_at (diisi manual oleh logger)

    protected $fillable = [
        'company_id',
        'payroll_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'notes',
        'before_state',
        'after_state',
        'ip_address',
        'user_agent',
        'prev_hash',
        'record_hash',
        'sequence',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence'   => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
