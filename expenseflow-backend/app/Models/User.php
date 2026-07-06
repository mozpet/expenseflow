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
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['company_id', 'employee_code', 'name', 'email', 'password', 'role', 'department', 'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'attendance_enabled', 'wfh_enabled', 'radius_enabled', 'fcm_token', 'device_id', 'device_name', 'device_bound_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'attendance_enabled' => 'boolean',
            'wfh_enabled' => 'boolean',
            'radius_enabled' => 'boolean',
            'monthly_claim_limit' => 'decimal:2',
            'device_bound_at' => 'datetime',
        ];
    }

    public function canAccessReceipts(): bool
    {
        return true; // semua role bisa scan & submit struk via mobile
    }

    public function canAccessAttendance(): bool
    {
        return (bool) $this->attendance_enabled;
    }

    /** true → karyawan boleh presensi mobile (WFH atau lapangan), diatur HRD. */
    public function canWfh(): bool
    {
        return (bool) $this->wfh_enabled;
    }

    /** true → presensi mobile wajib dalam radius lokasi kerja (mode lapangan). */
    public function hasRadiusEnabled(): bool
    {
        return (bool) $this->radius_enabled;
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
}
