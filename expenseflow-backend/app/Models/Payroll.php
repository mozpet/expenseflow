<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    use HasFactory;

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_SUBMITTED  = 'submitted';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_PAID       = 'paid';
    public const STATUS_REJECTED   = 'rejected';

    // Jenis batch (diskriminator ruang-lingkup) — item lanjutan Payroll.
    public const RUN_TYPE_REGULAR   = 'regular';   // batch gaji bulanan biasa
    public const RUN_TYPE_THR       = 'thr';       // batch Tunjangan Hari Raya (terpisah)
    public const RUN_TYPE_SEVERANCE = 'severance'; // batch pembayaran akhir (pesangon/PHK & kompensasi PKWT)

    public const RUN_TYPES = [
        self::RUN_TYPE_REGULAR,
        self::RUN_TYPE_THR,
        self::RUN_TYPE_SEVERANCE,
    ];

    protected $fillable = [
        'company_id',
        'attendance_setting_id',
        'run_type',           // regular | thr (diskriminator batch)
        'payroll_group_id',   // opsional: batasi batch ke anggota satu grup payroll
        'period_month',
        'period_year',
        'period_label',
        'is_year_end',
        'exchange_rates',     // snapshot kurs valas {KODE: kurs ke IDR} saat batch dihitung
        'status',
        'prepared_by',
        'submitted_by',
        'approved_by',
        'paid_by',
        'rejected_by',
        'calculated_at',
        'submitted_at',
        'approved_at',
        'paid_at',
        'rejected_at',
        'reject_reason',
        'total_gross',
        'total_deduction',
        'total_tax',
        'total_bpjs_company',
        'total_bpjs_employee',
        'total_net',
        'employee_count',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_month'    => 'integer',
            'period_year'     => 'integer',
            'payroll_group_id' => 'integer',
            'is_year_end'     => 'boolean',
            'exchange_rates'  => 'array',
            'calculated_at'   => 'datetime',
            'submitted_at'    => 'datetime',
            'approved_at'     => 'datetime',
            'paid_at'         => 'datetime',
            'rejected_at'     => 'datetime',
            'total_gross'     => 'decimal:2',
            'total_deduction' => 'decimal:2',
            'total_tax'       => 'decimal:2',
            'total_bpjs_company'  => 'decimal:2',
            'total_bpjs_employee' => 'decimal:2',
            'total_net'       => 'decimal:2',
            'employee_count'  => 'integer',
        ];
    }

    /** Status yang mengunci batch (tidak boleh diubah / dihapus). */
    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PAID], true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    /** Grup payroll yang membatasi ruang-lingkup batch (nullable = seluruh perusahaan). */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }

    /** True bila batch ini adalah batch THR (bukan gaji reguler). */
    public function isThr(): bool
    {
        return $this->run_type === self::RUN_TYPE_THR;
    }

    /** True bila batch ini adalah batch pembayaran akhir (pesangon/kompensasi PKWT). */
    public function isSeverance(): bool
    {
        return $this->run_type === self::RUN_TYPE_SEVERANCE;
    }

    /** Kasus exit settlement yang terikat pada batch ini (hanya untuk run severance). */
    public function severanceCases(): HasMany
    {
        return $this->hasMany(SeveranceCase::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
