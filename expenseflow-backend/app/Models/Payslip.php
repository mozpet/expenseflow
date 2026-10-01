<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_id',
        'company_id',
        'user_id',
        'period_month',
        'period_year',
        'employee_name',
        'employee_code',
        'position_name',
        'department_name',
        'division_id',            // snapshot cost center (Item 4) — dimensi divisi/departemen
        'attendance_setting_id',  // snapshot cost center (Item 4) — dimensi cabang/kantor
        'npwp_masked',
        'ptkp_status',
        'currency',        // mata uang kontrak (Fase 6) — kolom uang lain TETAP Rupiah
        'exchange_rate',   // kurs ke IDR yang dipakai saat batch dihitung
        'gross_currency',  // bruto dalam mata uang kontrak (informasional)
        'net_currency',    // neto dalam mata uang kontrak (dasar transfer valas)
        'bank_name',
        'bank_account_no',
        'bank_account_holder',
        'basic_salary',
        'total_earning',
        'gross',
        'taxable_income',
        'pph21',
        'bpjs_company_total',
        'bpjs_employee_total',
        'total_deduction',
        'net',
        'working_days',
        'present_days',
        'absent_days',
        'overtime_hours',
        'status',
        'pdf_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_month'    => 'integer',
            'period_year'     => 'integer',
            'division_id'           => 'integer',
            'attendance_setting_id' => 'integer',
            'exchange_rate'   => 'decimal:6',
            'gross_currency'  => 'decimal:2',
            'net_currency'    => 'decimal:2',
            'basic_salary'    => 'decimal:2',
            'total_earning'   => 'decimal:2',
            'gross'           => 'decimal:2',
            'taxable_income'  => 'decimal:2',
            'pph21'           => 'decimal:2',
            'bpjs_company_total'  => 'decimal:2',
            'bpjs_employee_total' => 'decimal:2',
            'total_deduction' => 'decimal:2',
            'net'             => 'decimal:2',
            'working_days'    => 'integer',
            'present_days'    => 'integer',
            'absent_days'     => 'integer',
            'overtime_hours'  => 'decimal:2',
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

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class)->orderBy('sort_order');
    }

    /** Jejak langkah perhitungan slip (audit & transparansi). */
    public function calculationSteps(): HasMany
    {
        return $this->hasMany(PayslipCalculationStep::class)->orderBy('step_sequence');
    }
}
