<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['company_id', 'employee_code', 'identity_number', 'name', 'email', 'password', 'role', 'department', 'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'attendance_enabled', 'overtime_enabled', 'wfh_enabled', 'radius_enabled', 'dinas_luar_enabled', 'flexitime_enabled', 'allow_attendance', 'allow_wfh', 'allow_radius', 'fcm_token', 'device_id', 'device_name', 'device_bound_at', 'phone', 'gender', 'birth_place', 'birth_date', 'is_pregnant', 'employment_type', 'bank_name', 'bank_account_no', 'bank_account_holder', 'joined_date', 'contract_start_date', 'contract_end_date', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone', 'emergency_contact_address', 'ktp_address', 'ktp_postal_code', 'ktp_city', 'ktp_province', 'domicile_address', 'is_domicile_same_as_ktp', 'religion', 'marital_status', 'number_of_dependents', 'blood_type', 'medical_conditions', 'education_level', 'institution_name', 'major', 'graduation_year', 'exit_date', 'exit_reason', 'exit_notes', 'severance_status', 'clearance_status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The accessors to append to the model's array and JSON form.
     *
     * @var array<int, string>
     */
    protected $appends = ['age', 'shift_locks'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'is_active'            => 'boolean',
            'attendance_enabled'   => 'boolean',
            'overtime_enabled'     => 'boolean',
            'wfh_enabled'          => 'boolean',
            'radius_enabled'       => 'boolean',
            'dinas_luar_enabled'   => 'boolean',
            'flexitime_enabled'    => 'boolean',
            'allow_attendance'     => 'boolean',
            'allow_wfh'            => 'boolean',
            'allow_radius'         => 'boolean',
            'monthly_claim_limit'  => 'decimal:2',
            'device_bound_at'      => 'datetime',
            'birth_date'               => 'date:Y-m-d',
            'is_pregnant'              => 'boolean',
            'is_domicile_same_as_ktp'  => 'boolean',
            'number_of_dependents'     => 'integer',
            'graduation_year'          => 'integer',
            'joined_date'              => 'date:Y-m-d',
            'contract_start_date'      => 'date:Y-m-d',
            'contract_end_date'        => 'date:Y-m-d',
            'exit_date'                => 'date:Y-m-d',
        ];
    }

    /**
     * Hitung umur saat ini (tahun) berdasarkan birth_date.
     */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date ? \Illuminate\Support\Carbon::parse($this->birth_date)->age : null;
    }

    public function canAccessReceipts(): bool
    {
        return (bool) $this->is_active; // semua role aktif bisa scan & submit struk via mobile
    }

    public function hasApprovedWfhToday(?string $date = null): bool
    {
        if (! $this->id) {
            return false;
        }

        $targetDate = $date ?? now('Asia/Jakarta')->toDateString();

        return LeaveRequest::where('user_id', $this->id)
            ->where('status', 'approved')
            ->where('leave_type', 'wfh')
            ->whereDate('start_date', '<=', $targetDate)
            ->whereDate('end_date', '>=', $targetDate)
            ->exists();
    }

    public function canAccessAttendance(): bool
    {
        if ($this->allow_attendance === false && ! $this->hasApprovedWfhToday()) {
            return false;
        }

        return (bool) ($this->attendance_enabled || $this->hasApprovedWfhToday());
    }

    /** true → karyawan boleh presensi mobile (WFH, dinas luar, atau lapangan), diatur HRD atau pengajuan WFH approved. */
    public function canWfh(): bool
    {
        if (! $this->canAccessAttendance()) {
            return false;
        }

        // Izin master WFH harus aktif (kecuali jika dinas luar aktif atau ada pengajuan WFH approved hari ini)
        if ($this->allow_wfh === false && ! $this->dinas_luar_enabled && ! $this->hasApprovedWfhToday()) {
            return false;
        }

        return (bool) ($this->wfh_enabled || $this->dinas_luar_enabled || $this->hasApprovedWfhToday());
    }

    /** true → presensi mobile wajib dalam radius lokasi kerja (mode lapangan). Bebas radius jika dinas luar atau WFH. */
    public function hasRadiusEnabled(): bool
    {
        if (! $this->canAccessAttendance()) {
            return false;
        }

        // Izin master radius geofence harus aktif
        if ($this->allow_radius === false) {
            return false;
        }

        // Radius lapangan HANYA bisa aktif jika mode WFH/mobile diaktifkan oleh HRD (wfh_enabled = true).
        // Jika WFH nonaktif (onsite biasa), dinas luar aktif, atau ada WFH approved hari ini, maka radius lapangan = false.
        if (! $this->wfh_enabled || $this->dinas_luar_enabled || $this->hasApprovedWfhToday()) {
            return false;
        }

        return (bool) $this->radius_enabled;
    }

    /** true → karyawan diizinkan presensi mode Dinas Luar / Kunjungan Klien. */
    public function canDinasLuar(): bool
    {
        return (bool) $this->dinas_luar_enabled;
    }

    /** true → karyawan memiliki hak akses jam kerja fleksibel (flexitime). */
    public function canFlexitime(): bool
    {
        return (bool) $this->flexitime_enabled;
    }

    /** true → karyawan saat ini memiliki jadwal shift atau pola rotasi aktif. */
    public function hasActiveShift(?string $date = null): bool
    {
        return $this->activeShiftAssignment($date) !== null;
    }

    /** Ambil penugasan shift atau pola rotasi aktif karyawan pada tanggal tertentu. */
    public function activeShiftAssignment(?string $date = null): ?UserShift
    {
        $targetDate = $date ?? now('Asia/Jakarta')->toDateString();

        return $this->userShifts()
            ->with(['shift', 'shiftPattern'])
            ->where(function ($q) {
                $q->whereNotNull('shift_id')->orWhereNotNull('shift_pattern_id');
            })
            ->whereDate('start_date', '<=', $targetDate)
            ->where(function ($q) use ($targetDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $targetDate);
            })
            ->orderByDesc('start_date')
            ->first();
    }

    /** Accessor untuk serialization: shift_locks */
    public function getShiftLocksAttribute(): array
    {
        return $this->getActiveShiftRequirements();
    }

    /**
     * Evaluasi apakah karyawan saat ini terikat shift atau pola rotasi aktif/mendatang
     * yang membutuhkan hak akses tertentu (Akses Mobile, WFH, atau Radius Geofence).
     *
     * @return array{has_active_shift: bool, shift_name: ?string, lock_attendance: bool, lock_wfh: bool, lock_radius: bool, reason_attendance: ?string, reason_wfh: ?string, reason_radius: ?string}
     */
    public function getActiveShiftRequirements(?string $date = null): array
    {
        $targetDate = $date ?? now('Asia/Jakarta')->toDateString();

        $assignments = $this->relationLoaded('userShifts')
            ? $this->userShifts->filter(function ($us) use ($targetDate) {
                if (! $us->shift_id && ! $us->shift_pattern_id) return false;
                if ($us->end_date && Carbon::parse($us->end_date)->lt(Carbon::parse($targetDate))) return false;
                return true;
            })
            : $this->userShifts()
                ->with(['shift.schedules', 'shiftPattern.items'])
                ->where(function ($q) {
                    $q->whereNotNull('shift_id')->orWhereNotNull('shift_pattern_id');
                })
                ->where(function ($q) use ($targetDate) {
                    $q->whereNull('end_date')->orWhereDate('end_date', '>=', $targetDate);
                })
                ->get();

        $requiresWfh = false;
        $requiresRadius = false;
        $wfhShiftNames = [];
        $radiusShiftNames = [];

        foreach ($assignments as $assignment) {
            $name = $assignment->shift?->name ?? $assignment->shiftPattern?->name ?? 'Shift';
            if ($assignment->shift) {
                $schedules = $assignment->shift->relationLoaded('schedules')
                    ? $assignment->shift->schedules
                    : $assignment->shift->schedules()->get();

                if ($schedules->contains(fn ($s) => ! $s->is_off && (bool) $s->is_wfh)) {
                    $requiresWfh = true;
                    $wfhShiftNames[] = $name;
                }
                if ($schedules->contains(fn ($s) => ! $s->is_off && (bool) $s->is_field)) {
                    $requiresRadius = true;
                    $radiusShiftNames[] = $name;
                }
            } elseif ($assignment->shiftPattern) {
                $items = $assignment->shiftPattern->relationLoaded('items')
                    ? $assignment->shiftPattern->items
                    : $assignment->shiftPattern->items()->get();

                if ($items->contains(fn ($i) => ! $i->is_off && (bool) $i->is_wfh)) {
                    $requiresWfh = true;
                    $wfhShiftNames[] = $name;
                }
                if ($items->contains(fn ($i) => ! $i->is_off && (bool) $i->is_field)) {
                    $requiresRadius = true;
                    $radiusShiftNames[] = $name;
                }
            }
        }

        $wfhShiftNames = array_values(array_unique($wfhShiftNames));
        $radiusShiftNames = array_values(array_unique($radiusShiftNames));
        $mobileShiftNames = array_values(array_unique(array_merge($wfhShiftNames, $radiusShiftNames)));

        $requiresMobile = ! empty($mobileShiftNames);

        $shiftNameStr = ! empty($mobileShiftNames) ? implode(', ', $mobileShiftNames) : null;

        return [
            'has_active_shift'  => $assignments->isNotEmpty(),
            'shift_name'        => $shiftNameStr,
            'lock_attendance'   => $requiresMobile,
            'lock_wfh'          => $requiresWfh,
            'lock_radius'       => $requiresRadius,
            'reason_attendance' => $requiresMobile
                ? "Karyawan sedang terikat shift aktif '" . implode(', ', $mobileShiftNames) . "' yang memerlukan presensi mobile (WFH/Lapangan). Ubah atau akhiri penugasan shift di Manajemen Shift terlebih dahulu jika ingin menonaktifkan izin ini."
                : null,
            'reason_wfh'        => $requiresWfh
                ? "Karyawan sedang terikat shift aktif '" . implode(', ', $wfhShiftNames) . "' yang memiliki jadwal WFH. Ubah atau akhiri penugasan shift di Manajemen Shift terlebih dahulu jika ingin menonaktifkan izin ini."
                : null,
            'reason_radius'     => $requiresRadius
                ? "Karyawan sedang terikat shift aktif '" . implode(', ', $radiusShiftNames) . "' yang memiliki jadwal Lapangan. Ubah atau akhiri penugasan shift di Manajemen Shift terlebih dahulu jika ingin menonaktifkan izin ini."
                : null,
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Kantor tempat karyawan bekerja (attendance_settings). */
    public function office()
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    public function loginAttempts()
    {
        return $this->hasMany(LoginAttempt::class);
    }

    /** Penugasan shift karyawan (user_shifts). */
    public function userShifts()
    {
        return $this->hasMany(UserShift::class);
    }

    /** Berkas digital karyawan (user_documents). */
    public function documents()
    {
        return $this->hasMany(UserDocument::class);
    }
}

