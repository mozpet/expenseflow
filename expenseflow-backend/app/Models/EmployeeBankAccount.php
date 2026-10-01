<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekening bank karyawan dengan proteksi maker-checker (Fase 3).
 * Nomor rekening disimpan terenkripsi & hanya ditampilkan termasking
 * (plaintext tidak pernah dikirim ke response — di-hide + append masked).
 */
class EmployeeBankAccount extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending_verification';
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_SUPERSEDED  = 'superseded';
    public const STATUS_REJECTED    = 'rejected';

    protected $fillable = [
        'company_id',
        'user_id',
        'bank_name',
        'bank_account_no',
        'bank_account_holder',
        'bank_branch',
        'swift_code',
        'status',
        'is_primary',
        'requested_by',
        'verified_by',
        'verified_at',
        'reject_reason',
        'notes',
    ];

    /** Sembunyikan nomor rekening plaintext dari serialisasi; kirim versi termasking. */
    protected $hidden = ['bank_account_no'];

    protected $appends = ['bank_account_no_masked'];

    protected function casts(): array
    {
        return [
            'bank_account_no' => 'encrypted',
            'is_primary'      => 'boolean',
            'verified_at'     => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Masking nomor rekening (jangan kirim plaintext ke response). */
    private function mask(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value);
        if ($digits === '') {
            return null;
        }
        $last4 = substr($digits, -4);

        return str_repeat('•', max(0, strlen($digits) - 4)) . $last4;
    }

    public function maskedAccountNo(): ?string
    {
        return $this->mask($this->bank_account_no);
    }

    public function getBankAccountNoMaskedAttribute(): ?string
    {
        return $this->maskedAccountNo();
    }
}
