<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    public const MODULE_RECEIPT        = 'receipt';
    public const MODULE_EXPENSE_REPORT = 'expense_report';
    public const MODULE_INVOICE        = 'invoice';
    public const MODULE_VENDOR         = 'vendor';
    public const MODULE_USER           = 'user';
    public const MODULE_ATTENDANCE     = 'attendance';
    public const MODULE_LEAVE          = 'leave';
    public const MODULE_OVERTIME       = 'overtime';
    public const MODULE_SHIFT           = 'shift';
    public const MODULE_PAYROLL         = 'payroll';
    public const MODULE_AUDIT_LOG       = 'audit_log';
    public const MODULE_SETTINGS        = 'settings';
    public const MODULE_ROLE_MANAGEMENT = 'role_management';

    public const AVAILABLE_MODULES = [
        self::MODULE_RECEIPT         => ['id' => self::MODULE_RECEIPT, 'name' => 'Struk Reimbursement', 'desc' => 'Inbox, persetujuan klaim struk bertingkat, serta pengaturan aturan & batas klaim limit'],
        self::MODULE_EXPENSE_REPORT  => ['id' => self::MODULE_EXPENSE_REPORT, 'name' => 'Laporan Dinas', 'desc' => 'Bundling pengeluaran laporan perjalanan dinas'],
        self::MODULE_INVOICE         => ['id' => self::MODULE_INVOICE, 'name' => 'Invoice Vendor', 'desc' => 'Tagihan vendor & alur persetujuan'],
        self::MODULE_VENDOR          => ['id' => self::MODULE_VENDOR, 'name' => 'Master Vendor', 'desc' => 'Kelola mitra vendor perusahaan'],
        self::MODULE_USER            => ['id' => self::MODULE_USER, 'name' => 'Data Karyawan', 'desc' => 'Data profil, arsip dokumen & akun SDM'],
        self::MODULE_ATTENDANCE      => ['id' => self::MODULE_ATTENDANCE, 'name' => 'Presensi & Kehadiran', 'desc' => 'Rekap harian, bulanan & ekspor kehadiran'],
        self::MODULE_LEAVE           => ['id' => self::MODULE_LEAVE, 'name' => 'Cuti & Izin', 'desc' => 'Pengajuan & kuota saldo cuti karyawan'],
        self::MODULE_OVERTIME        => ['id' => self::MODULE_OVERTIME, 'name' => 'Persetujuan Lembur', 'desc' => 'Verifikasi dan persetujuan klaim lembur'],
        self::MODULE_SHIFT           => ['id' => self::MODULE_SHIFT, 'name' => 'Shift & Penjadwalan', 'desc' => 'Roster shift, pola rotasi & kalender libur'],
        self::MODULE_PAYROLL         => ['id' => self::MODULE_PAYROLL, 'name' => 'Penggajian', 'desc' => 'Komponen gaji, proses payroll bertahap, slip gaji & PPh21'],
        self::MODULE_AUDIT_LOG       => ['id' => self::MODULE_AUDIT_LOG, 'name' => 'Audit Log', 'desc' => 'Log audit aktivitas sensitif & rekaman login'],
        self::MODULE_SETTINGS        => ['id' => self::MODULE_SETTINGS, 'name' => 'Pengaturan Aturan', 'desc' => 'Aturan presensi, cut-off, konfigurasi kantor cabang & finance'],
        self::MODULE_ROLE_MANAGEMENT => ['id' => self::MODULE_ROLE_MANAGEMENT, 'name' => 'Manajemen Role & Hak Akses', 'desc' => 'Kelola pembuatan custom role, izin modul & peran akun'],
    ];

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'description',
        'platform',
        'branch_scope',
        'is_builtin',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'is_active'  => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Role $role) {
            try {
                $employeeRole = static::whereNull('company_id')->where('slug', 'employee')->first();
                $employeeRoleId = $employeeRole?->id;

                // Reassign setiap user yang masih terasosiasi dengan role ini ke role 'employee'
                User::where('role_id', $role->id)
                    ->orWhere(function ($q) use ($role) {
                        $q->where('role', $role->slug)
                            ->where(function ($sub) use ($role) {
                                $sub->whereNull('role_id')
                                    ->orWhere('role_id', $role->id);
                            });
                    })
                    ->update([
                        'role_id' => $employeeRoleId,
                        'role'    => 'employee',
                    ]);
            } catch (\Throwable) {
                // Ignore jika tabel belum siap
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(AttendanceSetting::class, 'role_branches', 'role_id', 'attendance_setting_id')
            ->withTimestamps();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Evaluasi apakah role ini memiliki izin terhadap modul dan level tertentu.
     */
    public function hasPermission(string $module, string $requiredLevel = 'read'): bool
    {
        // super_admin bypass mutlak
        if ($this->slug === 'super_admin') {
            return true;
        }

        $perm = $this->relationLoaded('permissions')
            ? $this->permissions->firstWhere('module', $module)
            : $this->permissions()->where('module', $module)->first();

        if (! $perm || $perm->access_level === 'none') {
            return false;
        }

        if ($requiredLevel === 'read') {
            return in_array($perm->access_level, ['read', 'manage', 'spv', 'hrd', 'finance'], true);
        }

        if ($requiredLevel === 'manage') {
            return $perm->access_level === 'manage';
        }

        // Overtime, Leave, and Module SPV levels
        if ($requiredLevel === 'spv') {
            if ($module === self::MODULE_RECEIPT) {
                return $perm->access_level === 'spv';
            }
            return in_array($perm->access_level, ['spv', 'manage'], true);
        }

        // HRD level
        if ($requiredLevel === 'hrd') {
            return in_array($perm->access_level, ['hrd', 'manage'], true);
        }

        // Receipt levels
        if ($requiredLevel === 'finance') {
            // 'finance', 'spv' (SPV Finance), dan 'manage' semuanya punya hak finance
            return in_array($perm->access_level, ['finance', 'spv', 'manage'], true);
        }

        return false;
    }

    /**
     * Cek apakah role ini diizinkan mengakses data kantor cabang tertentu.
     */
    public function allowsBranch(int $branchId, ?User $user = null): bool
    {
        if ($this->slug === 'super_admin' || $this->branch_scope === 'all') {
            return true;
        }

        if ($this->branch_scope === 'self') {
            return $user && (int) $user->attendance_setting_id === (int) $branchId;
        }

        if ($this->branch_scope === 'specific') {
            $branchIds = $this->getAllowedBranchIds($user) ?? [];
            return in_array($branchId, $branchIds, true);
        }

        return false;
    }

    /**
     * Ambil array ID cabang yang diizinkan (null berarti seluruh cabang).
     *
     * @return int[]|null
     */
    public function getAllowedBranchIds(?User $user = null): ?array
    {
        if ($this->slug === 'super_admin' || $this->branch_scope === 'all') {
            return null;
        }

        if ($this->branch_scope === 'self') {
            return $user?->attendance_setting_id ? [(int) $user->attendance_setting_id] : [];
        }

        if ($this->branch_scope === 'specific') {
            if ($this->relationLoaded('branches')) {
                return $this->branches->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            return $this->branches()->pluck('attendance_settings.id')->map(fn ($id) => (int) $id)->all();
        }

        return [];
    }
}
