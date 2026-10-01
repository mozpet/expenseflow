<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaxProfile extends Model
{
    use HasFactory;

    /** Subjek Pajak Dalam Negeri — dipotong PPh 21 (TER/Pasal 17). */
    public const SUBJECT_DOMESTIC = 'domestic';
    /** Subjek Pajak Luar Negeri (ekspatriat) — dipotong PPh 26 final. */
    public const SUBJECT_FOREIGN = 'foreign';

    public const SUBJECT_TYPES = [self::SUBJECT_DOMESTIC, self::SUBJECT_FOREIGN];

    protected $fillable = [
        'company_id',
        'user_id',
        'npwp',
        'has_npwp',
        'ptkp_status',
        'tax_method',
        'tax_subject_type', // domestic | foreign (Fase 6 — PPh 26 ekspatriat)
        'treaty_country',
        'treaty_rate',
        'foreign_tax_id',
    ];

    protected function casts(): array
    {
        return [
            // NPWP tersimpan terenkripsi di DB, otomatis didekripsi saat dibaca.
            'npwp'     => 'encrypted',
            'has_npwp' => 'boolean',
            // TIN/NPWP negara asal juga PII → terenkripsi (UU PDP 27/2022).
            'foreign_tax_id' => 'encrypted',
            'treaty_rate'    => 'decimal:4',
        ];
    }

    /** True bila karyawan ini Subjek Pajak Luar Negeri (dipotong PPh 26). */
    public function isForeignSubject(): bool
    {
        return $this->tax_subject_type === self::SUBJECT_FOREIGN;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * NPWP termasking untuk ditampilkan (mis. •••••••••••1234).
     * Jangan pernah mengirim NPWP plaintext ke response API.
     */
    public function maskedNpwp(): ?string
    {
        return $this->maskTail($this->npwp);
    }

    /** TIN/NPWP negara asal termasking (PII ekspatriat — Fase 6). */
    public function maskedForeignTaxId(): ?string
    {
        return $this->maskTail($this->foreign_tax_id, digitsOnly: false);
    }

    /** Sisakan 4 karakter terakhir, sisanya diganti bullet. */
    private function maskTail(mixed $value, bool $digitsOnly = true): ?string
    {
        if (empty($value)) {
            return null;
        }

        $clean = $digitsOnly
            ? preg_replace('/\D/', '', (string) $value)
            : preg_replace('/\s+/', '', (string) $value);
        if ($clean === '' || $clean === null) {
            return null;
        }

        $last4 = substr($clean, -4);

        return str_repeat('•', max(0, strlen($clean) - 4)) . $last4;
    }
}
