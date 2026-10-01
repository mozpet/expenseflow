<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kurs valuta asing bertanggal-efektif — Penggajian Multi-Mata Uang, Fase 6.
 *
 * Pola resolusi identik dengan StatutoryRuleVersion: baris berlaku bila
 * `effective_date <= onDate`; yang terbaru menang; baris milik perusahaan
 * mengalahkan baris global (`company_id` NULL).
 */
class CurrencyRate extends Model
{
    use HasFactory;

    /** Mata uang dasar sistem — kurs terhadapnya selalu 1. */
    public const BASE_CURRENCY = 'IDR';

    protected $fillable = [
        'company_id',
        'currency',
        'rate_to_idr',
        'effective_date',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'rate_to_idr'    => 'decimal:6',
            'effective_date' => 'date:Y-m-d',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Kurs 1 unit `$currency` ke Rupiah yang berlaku pada `$onDate`.
     * Mengembalikan null bila tidak ada kurs terdaftar (pemanggil wajib menolak
     * perhitungan, jangan pernah mengasumsikan 1:1).
     */
    public static function resolve(string $currency, string $onDate, ?int $companyId = null): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === self::BASE_CURRENCY) {
            return 1.0;
        }

        $row = static::query()
            ->where('currency', $currency)
            ->whereDate('effective_date', '<=', $onDate)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->orderByRaw('company_id IS NULL')  // baris ber-company lebih dulu
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) $row->rate_to_idr : null;
    }
}
