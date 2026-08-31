<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSetting extends Model
{
    protected $fillable = [
        'company_id',
        'office_name',
        'office_latitude',
        'office_longitude',
        'radius_meters',
        'work_start_time',
        'work_end_time',
        'work_days',
        'custom_schedules',
        'late_tolerance_minutes',
        'late_checkin_cutoff_minutes',
        'require_selfie',
        'allow_wfh',
        'wfh_checkin_window_minutes',
        'overtime_enabled',
        'min_overtime_minutes',
        'early_leave_tolerance_minutes',
        'min_checkout_interval_minutes',
        // ditambahkan: ada di DB (migrasi 2026_07_02) tapi belum di fillable
        'checkout_reminder_minutes',
        'auto_checkout_grace_minutes',
        // validasi jam kerja mingguan
        'enforce_weekly_hours',
        'max_weekly_hours',
        'shift_notice_days',
        // kebijakan saldo cuti per kantor (kuota default & tanggal reset tahunan)
        'default_leave_quota',
        'leave_reset_date',
        'last_leave_reset_on',
    ];

    protected function casts(): array
    {
        return [
            'office_latitude'               => 'decimal:8',
            'office_longitude'              => 'decimal:8',
            'radius_meters'                 => 'integer',
            'late_tolerance_minutes'           => 'integer',
            'late_checkin_cutoff_minutes'      => 'integer',
            'require_selfie'                => 'boolean',
            'allow_wfh'                     => 'boolean',
            'wfh_checkin_window_minutes'    => 'integer',
            'overtime_enabled'              => 'boolean',
            'min_overtime_minutes'          => 'integer',
            'early_leave_tolerance_minutes' => 'integer',
            'min_checkout_interval_minutes' => 'integer',
            'work_days'                     => 'array',
            'custom_schedules'              => 'array',
            'checkout_reminder_minutes'     => 'integer',
            'auto_checkout_grace_minutes'   => 'integer',
            'enforce_weekly_hours'          => 'boolean',
            'max_weekly_hours'              => 'integer',
            'shift_notice_days'             => 'integer',
            'default_leave_quota'           => 'integer',
            // leave_reset_date string 'MM-DD' (anniversary tanpa tahun) — tidak di-cast date
            'last_leave_reset_on'           => 'date:Y-m-d',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
