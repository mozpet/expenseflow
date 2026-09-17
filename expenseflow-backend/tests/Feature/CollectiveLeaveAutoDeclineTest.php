<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CollectiveLeaveAutoDeclineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $hrd;
    private User $employee1;
    private User $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Sinar Abadi',
            'is_active' => true,
        ]);

        AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Utama',
            'office_latitude'  => -6.20000000,
            'office_longitude' => 106.81666700,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
            'work_days'        => [1, 2, 3, 4, 5],
        ]);

        $this->hrd = User::factory()->create([
            'company_id'         => $this->company->id,
            'role'               => 'hrd',
            'attendance_enabled' => true,
            'is_active'          => true,
        ]);

        $this->employee1 = User::factory()->create([
            'company_id'         => $this->company->id,
            'role'               => 'employee',
            'attendance_enabled' => true,
            'is_active'          => true,
        ]);

        $this->employee2 = User::factory()->create([
            'company_id'         => $this->company->id,
            'role'               => 'employee',
            'attendance_enabled' => true,
            'is_active'          => true,
        ]);

        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee1->id,
            'year'       => 2026,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 0,
        ]);

        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee2->id,
            'year'       => 2026,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_collective_leave_detail_automatically_declines_pending_on_hari_h(): void
    {
        // Set waktu sekarang ke 14 September 2026
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Jakarta'));

        // Buat cuti bersama untuk tanggal hari ini (14 September 2026)
        $holiday = Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => '2026-09-14',
            'name'          => 'Cuti Bersama Uji Coba',
            'is_national'   => false,
            'is_collective' => true,
        ]);

        // Employee 1 menerima / ikut (accepted)
        LeaveRequest::create([
            'company_id'        => $this->company->id,
            'user_id'           => $this->employee1->id,
            'holiday_id'        => $holiday->id,
            'leave_type'        => 'cuti',
            'start_date'        => '2026-09-14',
            'end_date'          => '2026-09-14',
            'total_days'        => 1,
            'reason'            => 'Cuti bersama',
            'status'            => 'approved',
            'collective_status' => 'accepted',
        ]);

        // Employee 2 belum memilih (pending)
        $leave2 = LeaveRequest::create([
            'company_id'        => $this->company->id,
            'user_id'           => $this->employee2->id,
            'holiday_id'        => $holiday->id,
            'leave_type'        => 'cuti',
            'start_date'        => '2026-09-14',
            'end_date'          => '2026-09-14',
            'total_days'        => 1,
            'reason'            => 'Cuti bersama',
            'status'            => 'pending',
            'collective_status' => 'pending',
        ]);

        // Akses endpoint detail rekap opt-in cuti bersama
        $response = $this->actingAs($this->hrd)
            ->getJson("/api/v1/dashboard/attendance/collective-leaves/{$holiday->id}/detail");

        $response->assertOk();
        $data = $response->json();

        // Verifikasi summary: accepted=1, declined=1, pending=0 (otomatis declined on-the-fly)
        $this->assertEquals(1, $data['summary']['accepted']);
        $this->assertEquals(1, $data['summary']['declined']);
        $this->assertEquals(0, $data['summary']['pending']);

        // Verifikasi database: status employee 2 sudah berubah menjadi declined & rejected
        $leave2->refresh();
        $this->assertEquals('declined', $leave2->collective_status);
        $this->assertEquals('rejected', $leave2->status);
        $this->assertStringContainsString('hari H', $leave2->rejection_reason);
    }

    public function test_future_collective_leave_remains_pending_before_hari_h(): void
    {
        // Set waktu sekarang ke 10 September 2026 (H-4)
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Asia/Jakarta'));

        $holiday = Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => '2026-09-14',
            'name'          => 'Cuti Bersama Masa Depan',
            'is_national'   => false,
            'is_collective' => true,
        ]);

        $leave = LeaveRequest::create([
            'company_id'        => $this->company->id,
            'user_id'           => $this->employee1->id,
            'holiday_id'        => $holiday->id,
            'leave_type'        => 'cuti',
            'start_date'        => '2026-09-14',
            'end_date'          => '2026-09-14',
            'total_days'        => 1,
            'reason'            => 'Cuti bersama',
            'status'            => 'pending',
            'collective_status' => 'pending',
        ]);

        $response = $this->actingAs($this->hrd)
            ->getJson("/api/v1/dashboard/attendance/collective-leaves/{$holiday->id}/detail");

        $response->assertOk();
        $data = $response->json();

        // Sebelum hari H, status pending tetap pending
        $this->assertEquals(0, $data['summary']['accepted']);
        $this->assertEquals(0, $data['summary']['declined']);
        $this->assertEquals(1, $data['summary']['pending']);

        $leave->refresh();
        $this->assertEquals('pending', $leave->collective_status);
    }

    public function test_employee_cannot_respond_on_or_after_hari_h(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00', 'Asia/Jakarta'));

        $holiday = Holiday::create([
            'company_id'    => $this->company->id,
            'date'          => '2026-09-14',
            'name'          => 'Cuti Bersama Hari H',
            'is_national'   => false,
            'is_collective' => true,
        ]);

        $response = $this->actingAs($this->employee1)
            ->postJson("/api/v1/attendance/collective-leave/{$holiday->id}/respond", [
                'response' => 'accepted',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Batas waktu memilih telah berakhir', $response->json('message'));
    }
}
