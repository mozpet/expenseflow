<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu langkah jejak perhitungan payslip (calculation trace).
 * Menjelaskan bagaimana tiap angka slip diperoleh (audit & transparansi).
 */
class PayslipCalculationStep extends Model
{
    use HasFactory;

    // Kode tahapan standar.
    public const STEP_GROSS          = 'GROSS';
    public const STEP_ADJUSTMENT     = 'ADJUSTMENT';
    public const STEP_OVERTIME       = 'OVERTIME';
    public const STEP_ATTENDANCE     = 'ATTENDANCE';
    public const STEP_REIMBURSEMENT  = 'REIMBURSEMENT'; // reimburse struk approved (non-objek PPh 21)
    public const STEP_BPJS_KES       = 'BPJS_KES';
    public const STEP_BPJS_JKK       = 'BPJS_JKK';
    public const STEP_BPJS_JKM       = 'BPJS_JKM';
    public const STEP_BPJS_JHT       = 'BPJS_JHT';
    public const STEP_BPJS_JP        = 'BPJS_JP';
    public const STEP_PPH21_TER      = 'PPH21_TER';
    public const STEP_PPH21_PASAL17  = 'PPH21_PASAL17';
    public const STEP_PPH21_THR      = 'PPH21_THR';
    public const STEP_NETT           = 'NETT';
    public const STEP_THR            = 'THR';
    public const STEP_FORMULA        = 'FORMULA';        // Fase 6 §6.A — komponen dari formula DSL.
    public const STEP_FORMULA_ERROR  = 'FORMULA_ERROR';  // Diagnostik: formula gagal dievaluasi saat run.

    // ── Fase 6 §9 — Exit Settlement (PP 35/2021 & PP 68/2009) ────────────────
    public const STEP_SEVERANCE_PRORATE = 'SEVERANCE_PRORATE'; // gaji terakhir prorate hari kerja aktif
    public const STEP_SEVERANCE_UP      = 'SEVERANCE_UP';      // Uang Pesangon (Pasal 40 ayat 2)
    public const STEP_SEVERANCE_UPMK    = 'SEVERANCE_UPMK';    // Uang Penghargaan Masa Kerja (Pasal 40 ayat 3)
    public const STEP_SEVERANCE_UPH     = 'SEVERANCE_UPH';     // Uang Penggantian Hak (Pasal 40 ayat 4)
    public const STEP_PKWT_COMPENSATION = 'PKWT_COMPENSATION'; // Uang Kompensasi PKWT (Pasal 15–17)
    public const STEP_PPH21_FINAL       = 'PPH21_FINAL';       // PPh 21 Final atas pesangon (PP 68/2009)

    // ── Fase 6 — PPh 26 tenaga kerja ekspatriat ──────────────────────────────
    public const STEP_PPH26     = 'PPH26';     // pemotongan PPh 26 (20% / tarif P3B)
    public const STEP_CURRENCY  = 'CURRENCY';  // konversi valuta asing → Rupiah (kurs terkunci)

    protected $fillable = [
        'payslip_id',
        'step_code',
        'step_sequence',
        'formula_version',
        'input_payload',
        'raw_result',
        'rounding_diff',
        'final_result',
        'rule_reference',
    ];

    protected function casts(): array
    {
        return [
            'step_sequence' => 'integer',
            'input_payload' => 'array',
            'raw_result'    => 'decimal:2',
            'rounding_diff' => 'decimal:2',
            'final_result'  => 'decimal:2',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
