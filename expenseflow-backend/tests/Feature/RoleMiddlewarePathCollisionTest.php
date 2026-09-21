<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewarePathCollisionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $branch;
    private User $targetEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::create([
            'name'      => 'PT Sinergi Sukses',
            'is_active' => true,
        ]);

        $this->branch = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Pusat',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $this->targetEmployee = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $employeeRole->id,
            'name'                  => 'Budi Karyawan',
            'email'                 => 'budi@sinergisukses.com',
            'password'              => bcrypt('password'),
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->branch->id,
        ]);
    }

    private function createCustomUser(array $permissions, string $roleName = 'Custom Officer'): User
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => $roleName,
            'slug'         => strtolower(str_replace(' ', '_', $roleName)) . '_' . uniqid(),
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        foreach ($permissions as $module => $level) {
            RolePermission::create([
                'role_id'      => $role->id,
                'module'       => $module,
                'access_level' => $level,
            ]);
        }

        return User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => $roleName . ' User',
            'email'                 => uniqid('user_') . '@sinergisukses.com',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
        ]);
    }

    /**
     * Fix #6: User dengan izin MODULE_ATTENDANCE harus dapat mengakses
     * /api/v1/dashboard/attendance/users tanpa tertabrak tuntutan MODULE_USER.
     */
    public function test_attendance_officer_can_access_attendance_users_endpoint(): void
    {
        // User HANYA punya izin attendance (read), TIDAK punya user management
        $attendanceOfficer = $this->createCustomUser([
            Role::MODULE_ATTENDANCE => 'read',
            Role::MODULE_USER       => 'none',
        ], 'Staf Presensi');

        // Harus berhasil 200 OK di endpoint attendance/users
        $response = $this->actingAs($attendanceOfficer, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/users');

        $response->assertOk();

        // Harus berhasil 200 OK di endpoint attendance/users/all
        $resAll = $this->actingAs($attendanceOfficer, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/users/all');

        $resAll->assertOk();

        // Namun DITOLAK (403) jika mengakses admin/users (manajemen data karyawan)
        $resAdminUsers = $this->actingAs($attendanceOfficer, 'sanctum')
            ->getJson('/api/v1/admin/users');

        $resAdminUsers->assertForbidden();
    }

    /**
     * Fix #6: User dengan izin MODULE_ATTENDANCE (manage) dapat melakukan aksi
     * toggle attendance pada karyawan di bawah dashboard/attendance/users.
     */
    public function test_attendance_manager_can_toggle_attendance_settings_of_user(): void
    {
        $attendanceManager = $this->createCustomUser([
            Role::MODULE_ATTENDANCE => 'manage',
            Role::MODULE_USER       => 'none',
        ], 'Manajer Presensi');

        $response = $this->actingAs($attendanceManager, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/users/{$this->targetEmployee->id}/toggle-wfh");

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id'          => $this->targetEmployee->id,
            'wfh_enabled' => true,
        ]);
    }

    /**
     * Fix #6: User dengan izin MODULE_USER (Data Karyawan) HANYA bisa mengakses
     * admin/users dan TIDAK bisa mengakses dashboard/attendance/users.
     */
    public function test_user_management_officer_cannot_access_attendance_users(): void
    {
        // User HANYA punya izin data karyawan (user), TIDAK punya attendance
        $hrOfficer = $this->createCustomUser([
            Role::MODULE_USER       => 'read',
            Role::MODULE_ATTENDANCE => 'none',
        ], 'Staf Personalia');

        // Boleh akses admin/users
        $resAdmin = $this->actingAs($hrOfficer, 'sanctum')
            ->getJson('/api/v1/admin/users');

        $resAdmin->assertOk();

        // DITOLAK saat mencoba akses dashboard/attendance/users
        $resAttUsers = $this->actingAs($hrOfficer, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/users');

        $resAttUsers->assertForbidden();
    }

    /**
     * Fix #6: User dengan izin MODULE_SHIFT harus dapat mengakses
     * endpoint shift yang mengandung segmen 'users' tanpa tertabrak MODULE_USER.
     */
    public function test_shift_officer_can_access_shift_users_endpoints(): void
    {
        $shiftOfficer = $this->createCustomUser([
            Role::MODULE_SHIFT => 'read',
            Role::MODULE_USER  => 'none',
        ], 'Staf Penjadwalan');

        $shift = Shift::create([
            'company_id'             => $this->company->id,
            'attendance_setting_id'  => $this->branch->id,
            'name'                   => 'Shift Pagi',
            'work_start_time'        => '07:00:00',
            'work_end_time'          => '15:00:00',
            'late_tolerance_minutes' => 10,
            'is_active'              => true,
        ]);

        $pattern = ShiftPattern::create([
            'company_id'  => $this->company->id,
            'name'        => 'Pola 5-2',
            'cycle_days'  => 7,
            'is_active'   => true,
        ]);

        // 1. GET /dashboard/attendance/shifts/{id}/users
        $resShiftUsers = $this->actingAs($shiftOfficer, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/shifts/{$shift->id}/users");

        $resShiftUsers->assertOk();

        // 2. GET /dashboard/attendance/shift-patterns/{id}/users
        $resPatternUsers = $this->actingAs($shiftOfficer, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}/users");

        $resPatternUsers->assertOk();

        // 3. GET /dashboard/attendance/users/{id}/shift-history
        $resHistory = $this->actingAs($shiftOfficer, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/users/{$this->targetEmployee->id}/shift-history");

        $resHistory->assertOk();

        // DITOLAK saat mencoba akses admin/users
        $resAdmin = $this->actingAs($shiftOfficer, 'sanctum')
            ->getJson('/api/v1/admin/users');

        $resAdmin->assertForbidden();
    }

    /**
     * Fix #6: User dengan izin MODULE_LEAVE (Cuti) HANYA bisa mengakses
     * leaves / leave-balances dan ditolak di attendance/users.
     */
    public function test_leave_officer_can_access_leaves_and_rejected_at_attendance_users(): void
    {
        $leaveOfficer = $this->createCustomUser([
            Role::MODULE_LEAVE      => 'read',
            Role::MODULE_ATTENDANCE => 'none',
        ], 'Staf Cuti');

        $resLeaves = $this->actingAs($leaveOfficer, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/leaves');

        $resLeaves->assertOk();

        $resAttUsers = $this->actingAs($leaveOfficer, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/users');

        $resAttUsers->assertForbidden();
    }

    /**
     * Fix #6: Role Finance dengan izin MODULE_RECEIPT dapat membaca daftar cabang
     * (GET /api/v1/dashboard/attendance/settings) tetapi DITOLAK saat POST (buat cabang baru).
     */
    public function test_finance_can_read_branch_settings_but_cannot_create_branch(): void
    {
        $financeUser = $this->createCustomUser([
            Role::MODULE_RECEIPT => 'manage',
            Role::MODULE_SETTINGS => 'none',
            Role::MODULE_ATTENDANCE => 'none',
        ], 'Finance Officer');

        // GET cabang diperbolehkan untuk filter struk
        $resGet = $this->actingAs($financeUser, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/settings');

        $resGet->assertOk();

        // POST cabang baru DITOLAK karena mutasi kantor butuh izin manage settings/attendance
        $resPost = $this->actingAs($financeUser, 'sanctum')
            ->postJson('/api/v1/dashboard/attendance/settings', [
                'office_name'            => 'Cabang Ilegal',
                'office_latitude'        => -6.123456,
                'office_longitude'       => 106.123456,
                'radius_meters'          => 100,
                'work_start_time'        => '08:00:00',
                'work_end_time'          => '17:00:00',
                'late_tolerance_minutes' => 15,
            ]);

        $resPost->assertForbidden();
    }
}
