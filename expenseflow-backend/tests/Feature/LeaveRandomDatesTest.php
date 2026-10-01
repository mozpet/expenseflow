<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveRandomDatesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $setting;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 08:00:00');

        $this->company = Company::create([
            'name' => 'PT Random Dates Test',
            'code' => 'PTRDT',
        ]);

        $this->setting = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Pusat',
            'office_latitude'  => -6.200000,
            'office_longitude' => 106.816666,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
            'work_days'        => [1, 2, 3, 4, 5], // Senin s/d Jumat (2026-10-01 adalah Kamis, 10-02 Jumat, 10-03 Sabtu [libur], 10-04 Minggu [libur], 10-05 Senin [kerja])
            'is_active'        => true,
            'leave_multi_approval_enabled' => false,
        ]);

        $this->employee = User::create([
            'name'                  => 'Budi Cuti Acak',
            'email'                 => 'budi_acak@example.com',
            'password'              => bcrypt('secret'),
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->setting->id,
            'role'                  => 'employee',
            'gender'                => 'Laki-laki',
            'is_active'             => true,
            'attendance_enabled'    => true,
        ]);

        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'leave_type' => 'cuti',
            'year'       => 2026,
            'quota'      => 12,
            'used'       => 0,
        ]);
    }

    public function test_leave_preview_with_discrete_dates_array(): void
    {
        Sanctum::actingAs($this->employee);

        // Kamis 2026-10-01 (kerja), Sabtu 2026-10-03 (weekend), Senin 2026-10-05 (kerja), Kamis 2026-10-08 (kerja)
        $dates = ['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-08'];

        $response = $this->json('GET', '/api/v1/attendance/leave-preview', [
            'leave_type' => 'cuti',
            'dates'      => $dates,
        ]);

        $response->assertOk();
        $data = $response->json();

        // 2026-10-03 adalah Sabtu (off/weekend), jadi di-skip
        // Effective: 10-01, 10-05, 10-08 -> total 3 hari kerja
        $this->assertEquals(3, $data['total_days']);
        $this->assertEquals(['2026-10-01', '2026-10-05', '2026-10-08'], $data['effective_dates']);
        $this->assertCount(1, $data['skipped_dates']);
        $this->assertEquals('2026-10-03', $data['skipped_dates'][0]['date']);
    }

    public function test_leave_preview_with_discrete_dates_comma_separated(): void
    {
        Sanctum::actingAs($this->employee);

        $response = $this->json('GET', '/api/v1/attendance/leave-preview', [
            'leave_type' => 'izin',
            'dates'      => '2026-10-05, 2026-10-07, 2026-10-09',
        ]);

        $response->assertOk();
        $data = $response->json();

        // Semua hari kerja: 5 Okt (Senin), 7 Okt (Rabu), 9 Okt (Jumat)
        $this->assertEquals(3, $data['total_days']);
        $this->assertEquals(['2026-10-05', '2026-10-07', '2026-10-09'], $data['effective_dates']);
    }

    public function test_leave_preview_rejects_invalid_date_format(): void
    {
        Sanctum::actingAs($this->employee);

        $response = $this->json('GET', '/api/v1/attendance/leave-preview', [
            'leave_type' => 'cuti',
            'dates'      => ['2026-10-01', 'bukan-tanggal'],
        ]);

        $response->assertStatus(422);
    }

    public function test_request_leave_with_discrete_dates_creates_atomic_records_per_date(): void
    {
        Sanctum::actingAs($this->employee);

        // Pilih tanggal 2026-10-05, 2026-10-07, 2026-10-09 (Senin, Rabu, Jumat)
        $dates = ['2026-10-05', '2026-10-07', '2026-10-09'];

        $response = $this->json('POST', '/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'dates'      => $dates,
            'reason'     => 'Keperluan pribadi tanggal acak',
        ]);

        $response->assertStatus(201);
        $this->assertArrayHasKey('leaves', $response->json());
        $this->assertCount(3, $response->json('leaves'));

        // Cek bahwa 3 record terpisah dibuat di database
        $leaves = LeaveRequest::where('user_id', $this->employee->id)->orderBy('start_date')->get();
        $this->assertCount(3, $leaves);

        $this->assertEquals('2026-10-05', $leaves[0]->start_date->format('Y-m-d'));
        $this->assertEquals('2026-10-05', $leaves[0]->end_date->format('Y-m-d'));
        $this->assertEquals(1, $leaves[0]->total_days);

        $this->assertEquals('2026-10-07', $leaves[1]->start_date->format('Y-m-d'));
        $this->assertEquals('2026-10-07', $leaves[1]->end_date->format('Y-m-d'));
        $this->assertEquals(1, $leaves[1]->total_days);

        $this->assertEquals('2026-10-09', $leaves[2]->start_date->format('Y-m-d'));
        $this->assertEquals('2026-10-09', $leaves[2]->end_date->format('Y-m-d'));
        $this->assertEquals(1, $leaves[2]->total_days);

        // Pastikan tanggal 2026-10-06 dan 2026-10-08 TIDAK dianggap cuti
        $intermediateLeave = LeaveRequest::where('user_id', $this->employee->id)
            ->where('start_date', '<=', '2026-10-06')
            ->where('end_date', '>=', '2026-10-06')
            ->exists();
        $this->assertFalse($intermediateLeave, 'Hari di antara tanggal acak tidak boleh terpengaruh cuti');
    }

    public function test_request_leave_with_discrete_dates_skips_weekend(): void
    {
        Sanctum::actingAs($this->employee);

        // Pilih 2026-10-02 (Jumat), 2026-10-03 (Sabtu - off), 2026-10-05 (Senin)
        $dates = ['2026-10-02', '2026-10-03', '2026-10-05'];

        $response = $this->json('POST', '/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'dates'      => $dates,
            'reason'     => 'Cuti jumat dan senin',
        ]);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('leaves'));

        $leaves = LeaveRequest::where('user_id', $this->employee->id)->get();
        $this->assertCount(2, $leaves);
        $datesList = $leaves->map(fn ($l) => $l->start_date->format('Y-m-d'))->sort()->values()->toArray();
        $this->assertEquals(['2026-10-02', '2026-10-05'], $datesList);
    }

    public function test_request_leave_rejects_past_dates(): void
    {
        Sanctum::actingAs($this->employee);

        // 2026-09-30 adalah kemarin (past)
        $response = $this->json('POST', '/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'dates'      => ['2026-09-30', '2026-10-05'],
            'reason'     => 'Cuti masa lalu',
        ]);

        $response->assertStatus(422);
    }
}
