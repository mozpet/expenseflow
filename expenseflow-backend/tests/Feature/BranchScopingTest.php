<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\RoleBranch;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BranchScopingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $branchA;
    private AttendanceSetting $branchB;
    private Role $roleBranchA;
    private Role $roleBranchSelf;
    private User $userBranchA;
    private User $employeeA;
    private User $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PT Test Corp', 'is_active' => true]);

        $this->branchA = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Jakarta (A)',
            'office_latitude'  => -6.2088,
            'office_longitude' => 106.8456,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
        ]);

        $this->branchB = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Surabaya (B)',
            'office_latitude'  => -7.2575,
            'office_longitude' => 112.7521,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
        ]);

        // Custom role with specific branch A
        $this->roleBranchA = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'HRD Cabang A',
            'slug'         => 'hrd_cabang_a_' . uniqid(),
            'platform'     => 'both',
            'branch_scope' => 'specific',
            'is_system'    => false,
        ]);
        RoleBranch::create([
            'role_id'               => $this->roleBranchA->id,
            'attendance_setting_id' => $this->branchA->id,
        ]);

        // Permissions for roleBranchA
        foreach ([Role::MODULE_USER, Role::MODULE_ATTENDANCE, Role::MODULE_LEAVE, Role::MODULE_SETTINGS] as $mod) {
            RolePermission::create([
                'role_id'      => $this->roleBranchA->id,
                'module'       => $mod,
                'access_level' => 'manage',
            ]);
        }

        // Custom role with self branch
        $this->roleBranchSelf = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'SPV Cabang Self',
            'slug'         => 'spv_cabang_self_' . uniqid(),
            'platform'     => 'both',
            'branch_scope' => 'self',
            'is_system'    => false,
        ]);
        foreach ([Role::MODULE_USER, Role::MODULE_ATTENDANCE, Role::MODULE_LEAVE, Role::MODULE_SETTINGS] as $mod) {
            RolePermission::create([
                'role_id'      => $this->roleBranchSelf->id,
                'module'       => $mod,
                'access_level' => 'manage',
            ]);
        }

        // User assigned to roleBranchA
        $this->userBranchA = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => $this->roleBranchA->slug,
            'role_id'               => $this->roleBranchA->id,
            'attendance_setting_id' => $this->branchA->id,
            'is_active'             => true,
        ]);

        // Employees in branch A and B
        $this->employeeA = User::factory()->create([
            'company_id'            => $this->company->id,
            'name'                  => 'Karyawan Jakarta A',
            'role'                  => 'employee',
            'attendance_setting_id' => $this->branchA->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
        ]);

        $this->employeeB = User::factory()->create([
            'company_id'            => $this->company->id,
            'name'                  => 'Karyawan Surabaya B',
            'role'                  => 'employee',
            'attendance_setting_id' => $this->branchB->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
        ]);
    }

    private function getWithUser(User $u, string $uri): TestResponse
    {
        return $this->actingAs($u, 'sanctum')
            ->withHeaders([
                'X-Platform' => 'web',
                'Accept'     => 'application/json',
            ])
            ->getJson($uri);
    }

    public function test_auth_me_returns_allowed_branches_and_branch_scope(): void
    {
        $response = $this->getWithUser($this->userBranchA, '/api/v1/me');

        $response->assertStatus(200);
        $this->assertEquals([$this->branchA->id], $response->json('user.allowed_branch_ids'));
        $this->assertEquals('specific', $response->json('user.branch_scope'));
    }

    public function test_list_settings_filters_branches_by_role_branch_scope(): void
    {
        // User with role limited to branch A
        $responseA = $this->getWithUser($this->userBranchA, '/api/v1/dashboard/attendance/settings');
        $responseA->assertStatus(200);
        $settingsA = $responseA->json('settings');
        $this->assertCount(1, $settingsA);
        $this->assertEquals($this->branchA->id, $settingsA[0]['id']);

        // Admin with branch_scope all sees all branches
        $admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $responseAdmin = $this->getWithUser($admin, '/api/v1/dashboard/attendance/settings');
        $responseAdmin->assertStatus(200);
        $settingsAdmin = $responseAdmin->json('settings');
        $this->assertCount(2, $settingsAdmin);
    }

    public function test_list_settings_with_self_branch_scope(): void
    {
        $userSelf = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => $this->roleBranchSelf->slug,
            'role_id'               => $this->roleBranchSelf->id,
            'attendance_setting_id' => $this->branchB->id,
            'is_active'             => true,
        ]);

        $response = $this->getWithUser($userSelf, '/api/v1/dashboard/attendance/settings');
        $response->assertStatus(200);
        $settings = $response->json('settings');
        $this->assertCount(1, $settings);
        $this->assertEquals($this->branchB->id, $settings[0]['id']);
    }

    public function test_user_index_and_attendance_list_users_filters_by_branch(): void
    {
        // 1. UserController index (/api/v1/admin/users)
        $resUsers = $this->getWithUser($this->userBranchA, '/api/v1/admin/users');
        $resUsers->assertStatus(200);
        $userList = $resUsers->json('data');
        $userIds = collect($userList)->pluck('id')->all();
        $this->assertContains($this->employeeA->id, $userIds);
        $this->assertNotContains($this->employeeB->id, $userIds);

        // 2. AttendanceController listUsers
        $resAttUsers = $this->getWithUser($this->userBranchA, '/api/v1/dashboard/attendance/users?all=true');
        $resAttUsers->assertStatus(200);
        $attUserList = $resAttUsers->json('data');
        $attUserIds = collect($attUserList)->pluck('id')->all();
        $this->assertContains($this->employeeA->id, $attUserIds);
        $this->assertNotContains($this->employeeB->id, $attUserIds);
    }

    public function test_attendance_today_filters_by_branch(): void
    {
        $today = Carbon::today()->toDateString();

        Attendance::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employeeA->id,
            'attendance_setting_id' => $this->branchA->id,
            'date'                  => $today,
            'status'                => 'present',
            'check_in_time'         => '08:05:00',
        ]);

        Attendance::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employeeB->id,
            'attendance_setting_id' => $this->branchB->id,
            'date'                  => $today,
            'status'                => 'present',
            'check_in_time'         => '08:10:00',
        ]);

        $response = $this->getWithUser($this->userBranchA, '/api/v1/dashboard/attendance/today');
        $response->assertStatus(200);

        $checkedInList = $response->json('checked_in');
        $checkedInUserIds = collect($checkedInList)->pluck('user_id')->all();
        $this->assertContains($this->employeeA->id, $checkedInUserIds);
        $this->assertNotContains($this->employeeB->id, $checkedInUserIds);
    }

    public function test_leave_requests_and_balances_filter_by_branch(): void
    {
        LeaveRequest::create([
            'company_id'  => $this->company->id,
            'user_id'     => $this->employeeA->id,
            'leave_type'  => 'cuti',
            'start_date'  => Carbon::today()->addDays(5)->toDateString(),
            'end_date'    => Carbon::today()->addDays(6)->toDateString(),
            'total_days'  => 2,
            'reason'      => 'Liburan A',
            'status'      => 'pending',
        ]);

        LeaveRequest::create([
            'company_id'  => $this->company->id,
            'user_id'     => $this->employeeB->id,
            'leave_type'  => 'cuti',
            'start_date'  => Carbon::today()->addDays(5)->toDateString(),
            'end_date'    => Carbon::today()->addDays(6)->toDateString(),
            'total_days'  => 2,
            'reason'      => 'Liburan B',
            'status'      => 'pending',
        ]);

        // listLeaves
        $resLeaves = $this->getWithUser($this->userBranchA, '/api/v1/dashboard/attendance/leaves');
        $resLeaves->assertStatus(200);
        $leaveData = $resLeaves->json('data');
        $leaveUserIds = collect($leaveData)->pluck('user_id')->all();
        $this->assertContains($this->employeeA->id, $leaveUserIds);
        $this->assertNotContains($this->employeeB->id, $leaveUserIds);

        // listLeaveBalances
        $resBalances = $this->getWithUser($this->userBranchA, '/api/v1/dashboard/attendance/leave-balances');
        $resBalances->assertStatus(200);
        $balanceData = $resBalances->json('balances');
        $balanceUserIds = collect($balanceData)->pluck('user_id')->all();
        $this->assertContains($this->employeeA->id, $balanceUserIds);
        $this->assertNotContains($this->employeeB->id, $balanceUserIds);
    }

    public function test_monthly_summary_branch_access_control(): void
    {
        // Target in allowed branch A: allowed
        $resA = $this->getWithUser($this->userBranchA, "/api/v1/dashboard/attendance/summary?user_id={$this->employeeA->id}");
        $resA->assertStatus(200);

        // Target in branch B: forbidden (403)
        $resB = $this->getWithUser($this->userBranchA, "/api/v1/dashboard/attendance/summary?user_id={$this->employeeB->id}");
        $resB->assertStatus(403);
    }

    public function test_list_holidays_filters_by_branch(): void
    {
        $year = now()->year;

        // National holiday (all see)
        Holiday::create([
            'company_id'            => null,
            'attendance_setting_id' => null,
            'date'                  => "{$year}-01-01",
            'name'                  => 'Tahun Baru Masehi',
            'is_national'           => true,
        ]);

        // Branch A holiday
        Holiday::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->branchA->id,
            'date'                  => "{$year}-06-22",
            'name'                  => 'HUT DKI Jakarta',
            'is_national'           => false,
        ]);

        // Branch B holiday
        Holiday::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->branchB->id,
            'date'                  => "{$year}-05-31",
            'name'                  => 'HUT Kota Surabaya',
            'is_national'           => false,
        ]);

        $response = $this->getWithUser($this->userBranchA, "/api/v1/dashboard/attendance/holidays?year={$year}");
        $response->assertStatus(200);

        $holidays = $response->json('holidays');
        $holidayNames = collect($holidays)->pluck('name')->all();

        $this->assertContains('Tahun Baru Masehi', $holidayNames);
        $this->assertContains('HUT DKI Jakarta', $holidayNames);
        $this->assertNotContains('HUT Kota Surabaya', $holidayNames);
    }
}
