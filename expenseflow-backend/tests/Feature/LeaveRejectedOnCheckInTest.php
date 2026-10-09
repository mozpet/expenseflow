<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveRejectedOnCheckInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $hrd;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-22 08:30:00', 'Asia/Jakarta'));

        $this->company = Company::create(['name' => 'PT Uji Checkin Cuti', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Pusat',
            'office_latitude'             => -6.200000,
            'office_longitude'            => 106.816666,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);

        $this->hrd = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'hrd',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => false,
            'dinas_luar_enabled'    => false,
            'is_active'             => true,
            'attendance_setting_id' => $this->office->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkIn(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->employee, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -7.000000,
            'longitude' => 110.400000,
        ], ['X-Platform' => 'mobile']);
    }

    public function test_pending_izin_otomatis_ditolak_saat_karyawan_checkin_di_hari_h(): void
    {
        $today = '2026-09-22';

        $leave = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'izin',
            'start_date' => $today,
            'end_date'   => $today,
            'total_days' => 1,
            'reason'     => 'Urusan keluarga',
            'status'     => 'pending',
        ]);

        // Notifikasi pending untuk HRD seperti yang dibuat saat pengajuan diajukan
        DB::table('notifications')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'type'            => 'leave_requested',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $this->hrd->id,
            'user_id'         => $this->hrd->id,
            'data'            => json_encode(['title' => 'Pengajuan Izin']),
            'entity_type'     => 'leave_request',
            'entity_id'       => $leave->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $response = $this->checkIn();
        $response->assertCreated();

        $leave->refresh();
        $this->assertSame('rejected', $leave->status);
        $this->assertStringContainsString('check-in', $leave->rejection_reason);

        // Notifikasi pending approver harus dihapus
        $pendingNotif = DB::table('notifications')
            ->where('entity_type', 'leave_request')
            ->where('entity_id', $leave->id)
            ->where('type', 'leave_requested')
            ->first();
        $this->assertNull($pendingNotif);

        // Notifikasi pembatalan harus masuk ke karyawan
        $userNotif = DB::table('notifications')
            ->where('user_id', $this->employee->id)
            ->where('entity_type', 'leave_request')
            ->where('entity_id', $leave->id)
            ->where('type', 'personal_leave_cancelled')
            ->first();
        $this->assertNotNull($userNotif);
    }

    public function test_pending_cuti_multi_hari_ikut_ditolak_jika_checkin_di_salah_satu_hari(): void
    {
        // Cuti 3 hari (22-24 Sep), karyawan tetap check-in di hari ke-2 (23 Sep)
        $leave = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'cuti',
            'start_date' => '2026-09-22',
            'end_date'   => '2026-09-24',
            'total_days' => 3,
            'reason'     => 'Liburan keluarga',
            'status'     => 'pending',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-23 08:30:00', 'Asia/Jakarta'));

        $response = $this->checkIn();
        $response->assertCreated();

        $leave->refresh();
        $this->assertSame('rejected', $leave->status, 'Seluruh pengajuan cuti multi-hari harus ditolak walau check-in hanya di salah satu hari.');
    }

    public function test_pending_wfh_tidak_ikut_ditolak_saat_checkin(): void
    {
        // leave_type 'wfh' adalah mode kerja, bukan klaim tidak hadir -> tidak boleh
        // ikut auto-reject oleh guard ini.
        $today = '2026-09-22';

        $leave = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'wfh',
            'start_date' => $today,
            'end_date'   => $today,
            'total_days' => 1,
            'reason'     => 'WFH hari ini',
            'status'     => 'pending',
        ]);

        $response = $this->checkIn();
        $response->assertCreated();

        $leave->refresh();
        $this->assertSame('pending', $leave->status);
    }

    public function test_pending_cuti_di_tanggal_lain_tidak_terpengaruh(): void
    {
        // Cuti untuk tanggal lain (besok), check-in hari ini tidak boleh mempengaruhinya.
        $leave = LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'sakit',
            'start_date' => '2026-09-23',
            'end_date'   => '2026-09-23',
            'total_days' => 1,
            'reason'     => 'Kontrol dokter',
            'status'     => 'pending',
        ]);

        $response = $this->checkIn();
        $response->assertCreated();

        $leave->refresh();
        $this->assertSame('pending', $leave->status);
    }

    public function test_leave_sudah_rejected_tidak_terpengaruh_lagi(): void
    {
        $today = '2026-09-22';

        $leave = LeaveRequest::create([
            'user_id'          => $this->employee->id,
            'company_id'       => $this->company->id,
            'leave_type'       => 'izin',
            'start_date'       => $today,
            'end_date'         => $today,
            'total_days'       => 1,
            'reason'           => 'Sudah ditolak sebelumnya',
            'status'           => 'rejected',
            'rejection_reason' => 'Ditolak manual oleh HRD.',
            'approved_at'      => now(),
        ]);

        $response = $this->checkIn();
        $response->assertCreated();

        $leave->refresh();
        $this->assertSame('rejected', $leave->status);
        $this->assertSame('Ditolak manual oleh HRD.', $leave->rejection_reason, 'Alasan reject manual tidak boleh ditimpa.');
    }
}
