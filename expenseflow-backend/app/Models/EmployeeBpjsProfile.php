<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Profil kepesertaan BPJS karyawan (Kesehatan & Ketenagakerjaan).
 * Nomor kepesertaan disimpan terenkripsi & hanya ditampilkan termasking.
 */
class EmployeeBpjsProfile extends Model
{
    use HasFactory;

    /** Kelas risiko JKK → tarif iuran (dibayar perusahaan) sesuai PP 44/2015. */
    public const JKK_CLASS_MIN = 1;
    public const JKK_CLASS_MAX = 5;

    protected $fillable = [
        'company_id',
        'user_id',
        'bpjs_kes_no',
        'bpjs_tk_no',
        'has_bpjs_kes',
        'has_bpjs_tk',
        'has_jkp',
        'jkk_risk_class',
    ];

    protected function casts(): array
    {
        return [
            // Nomor kepesertaan tersimpan terenkripsi di DB.
            'bpjs_kes_no'    => 'encrypted',
            'bpjs_tk_no'     => 'encrypted',
            'has_bpjs_kes'   => 'boolean',
            'has_bpjs_tk'    => 'boolean',
            'has_jkp'        => 'boolean',
            'jkk_risk_class' => 'integer',
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

    /** Kelas risiko JKK yang valid (1..5), default 1 bila di luar rentang. */
    public function jkkRiskClass(): int
    {
        $class = (int) $this->jkk_risk_class;

        return ($class >= self::JKK_CLASS_MIN && $class <= self::JKK_CLASS_MAX) ? $class : self::JKK_CLASS_MIN;
    }

    /** Masking nomor kepesertaan (jangan kirim plaintext ke response). */
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

    public function maskedBpjsKesNo(): ?string
    {
        return $this->mask($this->bpjs_kes_no);
    }

    public function maskedBpjsTkNo(): ?string
    {
        return $this->mask($this->bpjs_tk_no);
    }
}
