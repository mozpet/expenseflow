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

#[Fillable(['company_id', 'role_id', 'division_id', 'position_id', 'manager_id', 'employee_code', 'identity_number', 'name', 'email', 'password', 'role', 'department', 'attendance_setting_id', 'payroll_group_id', 'monthly_claim_limit', 'allow_receipt_claim', 'is_active', 'can_login', 'attendance_enabled', 'overtime_enabled', 'wfh_enabled', 'radius_enabled', 'dinas_luar_enabled', 'flexitime_enabled', 'allow_attendance', 'allow_wfh', 'allow_radius', 'allow_leave', 'fcm_token', 'device_id', 'device_name', 'device_bound_at', 'phone', 'gender', 'birth_place', 'birth_date', 'is_pregnant', 'employment_type', 'bank_name', 'bank_account_no', 'bank_account_holder', 'joined_date', 'contract_start_date', 'contract_end_date', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone', 'emergency_contact_address', 'ktp_address', 'ktp_postal_code', 'ktp_city', 'ktp_province', 'domicile_address', 'is_domicile_same_as_ktp', 'religion', 'marital_status', 'number_of_dependents', 'blood_type', 'medical_conditions', 'education_level', 'institution_name', 'major', 'graduation_year', 'exit_date', 'exit_reason', 'exit_notes', 'severance_status', 'clearance_status'])]
#[Hidden(['password', 'remember_token', 'security_pin'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            try {
                // 1. Sinkronisasi nama divisi ke string department untuk backward compatibility
                if ($user->isDirty('division_id')) {
                    if ($user->division_id) {
                        $div = Division::find($user->division_id);
                        if ($div) {
                            $user->department = $div->name;
                        }
                    } else {
                        // Jika division_id eksplisit dikosongkan tapi department tidak diubah
                        if (! $user->isDirty('department')) {
                            $user->department = null;
                        }
                    }
                }

                // 2. Jika role_id secara eksplisit di-set NULL:
                if ($user->isDirty('role_id') && is_null($user->role_id)) {
                    if (! $user->isDirty('role') || ! in_array($user->role, ['super_admin', 'admin', 'hrd', 'finance', 'employee'])) {
                        $user->role = 'employee';
                        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();
                        if ($employeeRole) {
                            $user->role_id = $employeeRole->id;
                        }
                    }
                    return;
                }

                // 3. Jika role_id terisi dan dirty, dan role string TIDAK diubah secara eksplisit:
                if ($user->isDirty('role_id') && $user->role_id && ! $user->isDirty('role')) {
                    $roleModel = Role::find($user->role_id);
                    if ($roleModel) {
                        $user->role = $roleModel->slug;
                    }
                    return;
                }

                // 4. Jika users.role terisi tapi role_id kosong/belum terisi:
                if (empty($user->role_id) && ! empty($user->role)) {
                    $roleModel = Role::where(function ($q) use ($user) {
                        $q->whereNull('company_id');
                        if ($user->company_id) {
                            $q->orWhere('company_id', $user->company_id);
                        }
                    })->where('slug', $user->role)->first();

                    if ($roleModel) {
                        $user->role_id = $roleModel->id;
                    } elseif (! in_array($user->role, ['super_admin', 'admin', 'hrd', 'finance', 'employee'])) {
                        $user->role = 'employee';
                    }
                    return;
                }

                // 5. Jika users.role berubah:
                if ($user->isDirty('role') && ! $user->isDirty('role_id')) {
                    $roleModel = Role::where(function ($q) use ($user) {
                        $q->whereNull('company_id');
                        if ($user->company_id) {
                            $q->orWhere('company_id', $user->company_id);
                        }
                    })->where('slug', $user->role)->first();

                    if ($roleModel) {
                        $user->role_id = $roleModel->id;
                    }
                }
            } catch (\Throwable) {
                // Jangan menggagalkan operasi jika tabel roles belum tersedia
            }
        });
    }

    /**
     * The accessors to append to the model's array and JSON form.
     *
     * @var array<int, string>
     */
    protected $appends = ['age', 'shift_locks', 'effective_monthly_claim_limit'];

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
            'security_pin'         => 'hashed',
            'security_pin_set_at'  => 'datetime',
            'is_active'            => 'boolean',
            'can_login'            => 'boolean',
            'attendance_enabled'   => 'boolean',
            'overtime_enabled'     => 'boolean',
            'wfh_enabled'          => 'boolean',
            'radius_enabled'       => 'boolean',
            'dinas_luar_enabled'   => 'boolean',
            'flexitime_enabled'    => 'boolean',
            'allow_attendance'     => 'boolean',
            'allow_wfh'            => 'boolean',
            'allow_radius'         => 'boolean',
            'allow_leave'          => 'boolean',
            'monthly_claim_limit'  => 'decimal:2',
            'allow_receipt_claim'  => 'boolean',
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
        if (! $this->is_active) {
            return false;
        }

        return (bool) ($this->allow_receipt_claim ?? true);
    }

    public function canTakeLeave(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return (bool) ($this->allow_leave ?? true);
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

    /** Relasi ke Role akun (roles.id). */
    public function roleRelation()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Cek apakah user memiliki izin terhadap modul dan level tertentu (read / manage).
     */
    public function hasPermission(string $module, string $requiredLevel = 'read'): bool
    {
        if ($this->role === 'super_admin') {
            return true;
        }

        if ($this->role_id) {
            $role = $this->relationLoaded('roleRelation') ? $this->roleRelation : $this->roleRelation()->with('permissions')->first();
            if ($role) {
                return $role->hasPermission($module, $requiredLevel);
            }
        }

        // Fallback backward-compatibility untuk built-in role lama jika belum disinkronkan ke role_id
        return match ($this->role) {
            'admin' => true,
            'finance' => match ($module) {
                Role::MODULE_RECEIPT => in_array($requiredLevel, ['read', 'manage', 'finance'], true),
                Role::MODULE_EXPENSE_REPORT, Role::MODULE_INVOICE, Role::MODULE_VENDOR, Role::MODULE_SETTINGS => in_array($requiredLevel, ['read', 'manage'], true),
                Role::MODULE_AUDIT_LOG => $requiredLevel === 'read',
                Role::MODULE_PAYROLL => in_array($requiredLevel, ['read', 'manage'], true),
                default => false,
            },
            'hrd' => match ($module) {
                Role::MODULE_USER, Role::MODULE_ATTENDANCE, Role::MODULE_LEAVE, Role::MODULE_OVERTIME, Role::MODULE_SHIFT => true,
                Role::MODULE_AUDIT_LOG => $requiredLevel === 'read',
                Role::MODULE_PAYROLL => in_array($requiredLevel, ['read', 'manage'], true),
                default => false,
            },
            'employee' => match ($module) {
                Role::MODULE_RECEIPT, Role::MODULE_ATTENDANCE, Role::MODULE_LEAVE => $requiredLevel === 'read',
                default => false,
            },
            default => false,
        };
    }

    /**
     * Ambil array ID cabang yang boleh diakses user (null berarti semua cabang).
     *
     * @return int[]|null
     */
    public function allowedBranchIds(): ?array
    {
        if ($this->role === 'super_admin') {
            return null;
        }

        if ($this->role_id) {
            $role = $this->relationLoaded('roleRelation') ? $this->roleRelation : $this->roleRelation()->with('branches')->first();
            if ($role) {
                return $role->getAllowedBranchIds($this);
            }
        }

        // Fallback default: admin, finance & hrd bisa semua cabang, lainnya cabang penempatan
        if (in_array($this->role, ['admin', 'finance', 'hrd'])) {
            return null;
        }

        return $this->attendance_setting_id ? [(int) $this->attendance_setting_id] : [];
    }

    /**
     * Cek apakah user boleh mengakses data kantor cabang tertentu.
     * Jika $branchId === null (data umum / tanpa cabang), return true.
     */
    public function allowsBranch(?int $branchId): bool
    {
        $allowed = $this->allowedBranchIds();
        if ($allowed === null) {
            return true;
        }

        if ($branchId === null) {
            return true;
        }

        return in_array((int) $branchId, $allowed, true);
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

    /** Riwayat gaji pokok efektif karyawan (employee_salaries). */
    public function employeeSalaries()
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    /** Tunjangan / potongan tetap karyawan (employee_salary_components). */
    public function salaryComponents()
    {
        return $this->hasMany(EmployeeSalaryComponent::class);
    }

    /** Profil pajak karyawan (employee_tax_profiles). */
    public function taxProfile()
    {
        return $this->hasOne(EmployeeTaxProfile::class);
    }

    /** Profil kepesertaan BPJS karyawan (employee_bpjs_profiles). */
    public function bpjsProfile()
    {
        return $this->hasOne(EmployeeBpjsProfile::class);
    }

    /** Slip gaji karyawan (payslips). */
    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    /** Rekening bank karyawan (employee_bank_accounts). */
    public function bankAccounts()
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }

    /** Kasbon / pinjaman karyawan (employee_loans). */
    public function loans()
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    /** Gaji pokok efektif karyawan pada tanggal tertentu (default: hari ini). */
    public function activeSalaryOn(?string $date = null): ?EmployeeSalary
    {
        $targetDate = $date ?? now('Asia/Jakarta')->toDateString();

        return $this->employeeSalaries()
            ->where('is_active', true)
            ->whereDate('effective_date', '<=', $targetDate)
            ->where(function ($q) use ($targetDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $targetDate);
            })
            ->orderByDesc('effective_date')
            ->first();
    }

    /** Divisi karyawan. */
    public function division()
    {
        return $this->belongsTo(Division::class, 'division_id');
    }

    /** Jabatan / Posisi kerja karyawan. */
    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /** Grup payroll (keanggotaan MVP; nullable = ungrouped). */
    public function payrollGroup()
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }

    /** Atasan langsung karyawan. */
    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Daftar staf bawahan langsung. */
    public function subordinates()
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    /**
     * Cek apakah user ini adalah leluhur (atasan berjenjang) dari bawahan tertentu.
     * Mencegah circular manager assignment (A -> B -> A).
     */
    public function isAncestorOf(int $subordinateId): bool
    {
        $visited = [];
        $currentId = $subordinateId;

        while ($currentId) {
            if ($currentId === $this->id) {
                return true;
            }
            if (in_array($currentId, $visited, true)) {
                break;
            }
            $visited[] = $currentId;
            $currentId = static::where('id', $currentId)->value('manager_id');
        }

        return false;
    }

    /**
     * Cek apakah user memiliki peran struktural atau izin sebagai Supervisor / Atasan Langsung.
     * Batasan ketat Role vs Jabatan:
     * 1. Jabatan (Master Posisi): flag is_supervisor = true.
     * 2. Hierarki Organisasi: user memiliki bawahan langsung (subordinates()->exists()).
     * 3. Role/Permission granular: izin eksplisit 'spv' pada modul lembur atau cuti.
     * 4. Role administratif bawaan: Super Admin, Admin, HRD.
     * Tidak lagi menggunakan fuzzy string matching pada nama role (misal: 'spv', 'manager', 'kepala').
     */
    public function isSupervisor(): bool
    {
        if ($this->position && $this->position->is_supervisor) {
            return true;
        }

        if ($this->subordinates()->exists()) {
            return true;
        }

        if ($this->hasPermission(Role::MODULE_OVERTIME, 'spv') || $this->hasPermission(Role::MODULE_LEAVE, 'spv')) {
            return true;
        }

        $userRoleCode = strtolower($this->roleRelation?->slug ?? $this->role ?? '');

        return in_array($userRoleCode, ['super_admin', 'admin', 'hrd'], true);
    }

    /**
     * Hitung Effective Monthly Claim Limit:
     *   1. user.monthly_claim_limit (jika > 0)  → Custom Override individual
     *   2. company_settings.monthly_claim_limit → Default perusahaan
     *   3. 0 (unlimited)
     */
    public function getEffectiveMonthlyClaimLimitAttribute(): float
    {
        // 1. Custom override individu
        $personal = (float) ($this->monthly_claim_limit ?? 0);
        if ($personal > 0) {
            return $personal;
        }

        // 2. Fallback company_settings
        try {
            $companyLimit = (float) (\DB::table('company_settings')
                ->where('company_id', $this->company_id)
                ->where('key', 'monthly_claim_limit')
                ->value('value') ?? 0);

            return $companyLimit;
        } catch (\Throwable) {
            return 0.0;
        }
    }
}

