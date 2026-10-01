<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryRuleVersion extends Model
{
    use HasFactory;

    public const TYPE_PTKP      = 'ptkp';
    public const TYPE_PPH21_TER = 'pph21_ter';
    public const TYPE_PASAL17   = 'pasal17';
    // Fase 2 — BPJS effective-dated (tarif & cap upah).
    public const TYPE_BPJS_KES  = 'bpjs_kes'; // BPJS Kesehatan
    public const TYPE_BPJS_TK   = 'bpjs_tk';  // BPJS Ketenagakerjaan (JKK/JKM/JHT/JP/JKP)
    // Fase 6 — Exit Settlement & ekspatriat.
    public const TYPE_PESANGON_FINAL = 'pesangon_final'; // PPh 21 Final atas pesangon (PP 68/2009)
    public const TYPE_SEVERANCE_TABLE = 'severance_table'; // tabel UP & UPMK per masa kerja (PP 35/2021)
    public const TYPE_PPH26          = 'pph26';          // tarif PPh 26 (Pasal 26 UU PPh & P3B)

    /** Semua tipe aturan yang dikenali (kolom `type` kini string, validasi di aplikasi). */
    public const TYPES = [
        self::TYPE_PTKP,
        self::TYPE_PPH21_TER,
        self::TYPE_PASAL17,
        self::TYPE_BPJS_KES,
        self::TYPE_BPJS_TK,
        self::TYPE_PESANGON_FINAL,
        self::TYPE_SEVERANCE_TABLE,
        self::TYPE_PPH26,
    ];

    protected $fillable = [
        'company_id',
        'type',
        'name',
        'effective_date',
        'end_date',
        'payload',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date:Y-m-d',
            'end_date'       => 'date:Y-m-d',
            'payload'        => 'array',
            'is_active'      => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Ambil aturan yang berlaku untuk suatu tipe pada tanggal tertentu.
     * Prioritaskan aturan milik perusahaan (override), fallback ke aturan global (company_id NULL).
     */
    public static function resolve(string $type, string $onDate, ?int $companyId = null): ?self
    {
        return static::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->whereDate('effective_date', '<=', $onDate)
            ->where(function (Builder $q) use ($onDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $onDate);
            })
            ->where(function (Builder $q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            // Aturan spesifik perusahaan menang atas global; lalu yang paling baru berlaku.
            ->orderByRaw('company_id IS NULL')
            ->orderByDesc('effective_date')
            ->first();
    }
}
