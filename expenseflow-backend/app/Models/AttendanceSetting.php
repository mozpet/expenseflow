<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSetting extends Model
{
    protected $fillable = [
        'company_id',
        'office_name',
        'office_latitude',
        'office_longitude',
        // zona waktu bisnis cabang (WIB/WITA/WIT) — lihat migrasi 2026_10_08_000001
        'timezone',
        'radius_meters',
        'work_start_time',
        'work_end_time',
        'break_minutes',
        'work_days',
        'custom_schedules',
        'late_tolerance_minutes',
        // denda keterlambatan — migrasi 2026_10_09_000001
        'late_fine_per_minute',
        'late_count_mode',
        'late_fine_max_per_day',
        // aturan upah lembur — migrasi 2026_10_10_000001
        'overtime_rule',
        'overtime_rate_amount',
        'overtime_rate_unit',
        'overtime_rate_taxable',
        'late_checkin_cutoff_minutes',
        'require_selfie',
        'allow_wfh',
        'wfh_checkin_window_minutes',
        'overtime_enabled',
        'overtime_multi_approval_enabled',
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
        'leave_multi_approval_enabled',
        'leave_reset_date',
        'last_leave_reset_on',
        // limit klaim & toleransi variansi struk khusus cabang (null = fallback ke company_settings)
        'variance_limit',
        'max_claim_limit',
        // konfigurasi jam kerja fleksibel (flexitime) cabang
        'flex_arrival_start',
        'flex_arrival_end',
        'flex_core_start',
        'flex_core_end',
        'flex_target_minutes',
    ];

    protected function casts(): array
    {
        return [
            'office_latitude'               => 'decimal:8',
            'office_longitude'              => 'decimal:8',
            'radius_meters'                 => 'integer',
            'break_minutes'                 => 'integer',
            'late_tolerance_minutes'           => 'integer',
            'late_checkin_cutoff_minutes'      => 'integer',
            'late_fine_per_minute'          => 'decimal:2',
            'late_fine_max_per_day'         => 'decimal:2',
            'overtime_rate_amount'          => 'decimal:2',
            'overtime_rate_taxable'         => 'boolean',
            'require_selfie'               => 'boolean',
            'allow_wfh'                     => 'boolean',
            'wfh_checkin_window_minutes'    => 'integer',
            'overtime_enabled'              => 'boolean',
            'overtime_multi_approval_enabled'=> 'boolean',
            'min_overtime_minutes'          => 'integer',
            'early_leave_tolerance_minutes' => 'integer',
            'min_checkout_interval_minutes' => 'integer',
            'work_days'                     => 'array',
            'custom_schedules'              => 'array',
            'checkout_reminder_minutes'     => 'integer',
            'auto_checkout_grace_minutes'   => 'integer',
            'flex_target_minutes'           => 'integer',
            'enforce_weekly_hours'          => 'boolean',
            'max_weekly_hours'              => 'integer',
            'shift_notice_days'             => 'integer',
            'default_leave_quota'           => 'integer',
            'leave_multi_approval_enabled'  => 'boolean',
            // leave_reset_date string 'MM-DD' (anniversary tanpa tahun) — tidak di-cast date
            'last_leave_reset_on'           => 'date:Y-m-d',
            'variance_limit'                => 'integer',
            'max_claim_limit'               => 'decimal:2',
        ];
    }

    /**
     * Field turunan zona yang SELALU menyertai setiap serialisasi kantor.
     *
     * Di-append di level model (bukan dirakit per endpoint) karena baris
     * attendance_settings diserialisasi dari belasan tempat — listSettings,
     * showSettings, updateSettings, relasi office pada payload karyawan. Satu
     * endpoint yang terlewat berarti frontend menerima jam tanpa label zona dan
     * menampilkannya seolah WIB.
     */
    protected $appends = [
        'timezone_label',
        'timezone_offset_minutes',
    ];

    /**
     * Zona waktu bisnis cabang ini — OTORITAS untuk jadwal kerja, status telat,
     * dan tanggal presensi karyawan yang ditempatkan di sini.
     *
     * NULL (baris lama / cabang belum dikonfigurasi) → zona penyimpanan (WIB),
     * sehingga perilaku identik dengan sebelum fitur multi-zona.
     */
    public function effectiveTimezone(): string
    {
        return \App\Support\Timezone::normalize($this->timezone);
    }

    /**
     * 'WIB' | 'WITA' | 'WIT' — label siap tampil untuk jam-jam kantor ini.
     *
     * NULL bila kolom `timezone` tidak ikut di-select (beberapa jalur daftar
     * meng-eager-load `office:id,office_name`). SENGAJA null, bukan 'WIB':
     * mengembalikan label default di situ akan memberi tahu frontend bahwa cabang
     * Makassar memakai WIB — keliru tanpa error. Frontend yang butuh label wajib
     * memakai endpoint/relasi yang memuat kolomnya.
     */
    public function getTimezoneLabelAttribute(): ?string
    {
        return array_key_exists('timezone', $this->attributes)
            ? \App\Support\Timezone::label($this->timezone)
            : null;
    }

    /** Offset dari UTC dalam menit (420 | 480 | 540). NULL mengikuti aturan di atas. */
    public function getTimezoneOffsetMinutesAttribute(): ?int
    {
        return array_key_exists('timezone', $this->attributes)
            ? \App\Support\Timezone::offsetMinutes($this->timezone)
            : null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'attendance_setting_id');
    }

    public function leaveTypeSettings(): HasMany
    {
        return $this->hasMany(LeaveTypeSetting::class, 'attendance_setting_id');
    }
}
