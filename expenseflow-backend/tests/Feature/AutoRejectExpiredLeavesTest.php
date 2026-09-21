<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoRejectExpiredLeavesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Tetapkan waktu "Hari H" sekarang ke 22 September 2026
        Carbon::setTestNow('2026-09-22 09:00:00');

        $this->company = Company::create([
            'name'      => 'PT Auto Reject Leave',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Utama',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.816667,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'break_minutes'               => 60,
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);

        $this->admin = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'admin',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'karyawan',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->office->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_leave_requests_on_or_before_hari_h_are_auto_rejected(): void
    {
        // Hari H: 2026-09-22
        $leaveHariH = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'cuti',
            'start_date' => '2026-09-22',
            'end_date'   => '2026-09-23',
            'total_days' => 2,
            'reason'     => 'Liburan keluarga',
            'status'     => 'pending',
        ]);

        // Lewat Hari H (masa lalu)
        $leavePast = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'izin',
            'start_date' => '2026-09-20',
            'end_date'   => '2026-09-20',
            'total_days' => 1,
            'reason'     => 'Urusan bank',
            'status'     => 'pending',
        ]);

        // Belum Hari H (masa depan: 25 Sep 2026)
        $leaveFuture = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'sakit',
            'start_date' => '2026-09-25',
            'end_date'   => '2026-09-26',
            'total_days' => 2,
            'reason'     => 'Operasi gigi',
            'status'     => 'pending',
        ]);

        // Buat dummy notifikasi pending untuk approver
        DB::table('notifications')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'type'            => 'leave_requested',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $this->admin->id,
            'user_id'         => $this->admin->id,
            'data'            => json_encode(['title' => 'Pengajuan Cuti']),
            'entity_type'     => 'leave_request',
            'entity_id'       => $leaveHariH->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $rejectedCount = LeaveRequest::autoRejectExpiredLeaves($this->company->id);

        $this->assertEquals(2, $rejectedCount);

        $leaveHariH->refresh();
        $this->assertEquals('rejected', $leaveHariH->status);
        $this->assertStringContainsString('hingga hari H', $leaveHariH->rejection_reason);

        $leavePast->refresh();
        $this->assertEquals('rejected', $leavePast->status);

        $leaveFuture->refresh();
        $this->assertEquals('pending', $leaveFuture->status);

        // Notifikasi pending approver harus dihapus
        $pendingNotif = DB::table('notifications')
            ->where('entity_type', 'leave_request')
            ->where('entity_id', $leaveHariH->id)
            ->where('type', 'leave_requested')
            ->first();
        $this->assertNull($pendingNotif);

        // Notifikasi pembatalan/penolakan harus masuk ke karyawan
        $userNotif = DB::table('notifications')
            ->where('user_id', $this->employee->id)
            ->where('entity_type', 'leave_request')
            ->where('entity_id', $leaveHariH->id)
            ->where('type', 'personal_leave_cancelled')
            ->first();
        $this->assertNotNull($userNotif);
    }

    public function test_all_leave_types_are_auto_rejected_on_hari_h(): void
    {
        $types = ['cuti', 'izin', 'sakit', 'wfh'];

        foreach ($types as $t) {
            LeaveRequest::create([
                'user_id'    => $this->employee->id,
                'company_id' => $this->company->id,
                'leave_type' => $t,
                'start_date' => '2026-09-22',
                'end_date'   => '2026-09-22',
                'total_days' => 1,
                'reason'     => "Test $t",
                'status'     => 'pending',
            ]);
        }

        $count = LeaveRequest::autoRejectExpiredLeaves($this->company->id);
        $this->assertEquals(4, $count);

        $pendingLeft = LeaveRequest::where('status', 'pending')->count();
        $this->assertEquals(0, $pendingLeft);

        $rejectedCount = LeaveRequest::where('status', 'rejected')->count();
        $this->assertEquals(4, $rejectedCount);
    }

    public function test_list_leaves_endpoint_auto_rejects_expired_leaves_on_access(): void
    {
        // Buat pengajuan cuti hari ini (22 Sep 2026) dengan status pending
        $leave = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'cuti',
            'start_date' => '2026-09-22',
            'end_date'   => '2026-09-23',
            'total_days' => 2,
            'reason'     => 'Cuti Hari H',
            'status'     => 'pending',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/attendance/leaves');

        $response->assertStatus(200);

        // Di database harus sudah otomatis berubah jadi rejected
        $leave->refresh();
        $this->assertEquals('rejected', $leave->status);
        $this->assertStringContainsString('hingga hari H', $leave->rejection_reason);

        // Jika filter status=pending, data ini tidak boleh muncul
        $pendingResponse = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/attendance/leaves?status=pending');
        $pendingResponse->assertStatus(200);
        $pendingLeaves = $pendingResponse->json('data.leaves') ?? $pendingResponse->json('data') ?? [];
        $ids = array_column($pendingLeaves, 'id');
        $this->assertNotContains($leave->id, $ids);
    }

    public function test_approve_leave_on_hari_h_fails_and_auto_rejects(): void
    {
        $leave = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'izin',
            'start_date' => '2026-09-22',
            'end_date'   => '2026-09-22',
            'total_days' => 1,
            'reason'     => 'Izin Hari H',
            'status'     => 'pending',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve");

        $response->assertStatus(422);

        $leave->refresh();
        $this->assertEquals('rejected', $leave->status);
    }

    public function test_artisan_command_auto_rejects_expired_leaves(): void
    {
        LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'wfh',
            'start_date' => '2026-09-22',
            'end_date'   => '2026-09-22',
            'total_days' => 1,
            'reason'     => 'WFH Hari H',
            'status'     => 'pending',
        ]);

        $exitCode = Artisan::call('attendance:auto-reject-expired-leaves');
        $this->assertEquals(0, $exitCode);

        $pendingCount = LeaveRequest::where('status', 'pending')->count();
        $this->assertEquals(0, $pendingCount);

        $rejected = LeaveRequest::where('status', 'rejected')->first();
        $this->assertNotNull($rejected);
        $this->assertEquals('wfh', $rejected->leave_type);
    }
}
