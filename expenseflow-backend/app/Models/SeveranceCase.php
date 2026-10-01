<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Berkas perhitungan akhir (exit settlement) satu karyawan — Fase 6, roadmap §9.
 *
 * Menyimpan PARAMETER pengakhiran hubungan kerja yang tidak dapat disimpulkan
 * otomatis dari data kepegawaian (alasan PHK → faktor pengali UP/UPMK, sisa cuti,
 * uang pisah, potongan aset). Nominal hasil perhitungan TIDAK disimpan di sini —
 * hasilnya berupa payslip pada batch payroll bertipe `severance`.
 */
class SeveranceCase extends Model
{
    use HasFactory;

    // ── Dasar pengakhiran hubungan kerja (PP 35/2021) ───────────────────────
    /** PHK oleh pengusaha — berhak UP + UPMK + UPH (faktor sesuai alasan). */
    public const TYPE_PHK = 'phk';
    /** Berakhirnya PKWT — berhak Uang Kompensasi (Pasal 15–17), bukan pesangon. */
    public const TYPE_PKWT_END = 'pkwt_end';
    /** Pengunduran diri — UPH + uang pisah (bila diatur PK/PP/PKB). */
    public const TYPE_RESIGN = 'resign';
    /** Pensiun — UP/UPMK/UPH sesuai Pasal 56. */
    public const TYPE_RETIREMENT = 'retirement';
    /** Meninggal dunia — Pasal 57 (2× UP + 1× UPMK + UPH). */
    public const TYPE_DEATH = 'death';

    public const TYPES = [
        self::TYPE_PHK,
        self::TYPE_PKWT_END,
        self::TYPE_RESIGN,
        self::TYPE_RETIREMENT,
        self::TYPE_DEATH,
    ];

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_CLOSED     = 'closed';

    protected $fillable = [
        'company_id',
        'user_id',
        'payroll_id',
        'termination_type',
        'termination_reason',
        'termination_date',
        'last_working_date',
        'employment_type',
        'contract_start_date',
        'contract_end_date',
        'up_multiplier',
        'upmk_multiplier',
        'include_uph',
        'annual_leave_balance_days',
        'relocation_cost',
        'other_compensation',
        'separation_pay',
        'asset_deduction',
        'settle_loans',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'termination_date'          => 'date:Y-m-d',
            'last_working_date'         => 'date:Y-m-d',
            'contract_start_date'       => 'date:Y-m-d',
            'contract_end_date'         => 'date:Y-m-d',
            'up_multiplier'             => 'decimal:2',
            'upmk_multiplier'           => 'decimal:2',
            'include_uph'               => 'boolean',
            'annual_leave_balance_days' => 'decimal:2',
            'relocation_cost'           => 'decimal:2',
            'other_compensation'        => 'decimal:2',
            'separation_pay'            => 'decimal:2',
            'asset_deduction'           => 'decimal:2',
            'settle_loans'              => 'boolean',
        ];
    }

    /** True bila kasus ini memakai skema Uang Kompensasi PKWT (bukan pesangon). */
    public function isPkwt(): bool
    {
        return $this->termination_type === self::TYPE_PKWT_END;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
