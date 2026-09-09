<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportShiftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private AttendanceSetting $office;
    private Shift $shiftMalam;
    private Shift $shiftPagi;
    private User $userMalam;
    private User $userPagi;
    private User $userOffice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Industri Sejahtera',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'         => $this->company->id,
            'office_name'        => 'Pabrik Utama',
            'office_latitude'    => -6.2000,
            'office_longitude'   => 106.8166,
            'radius_meters'      => 100,
            'work_start_time'    => '08:00',
            'work_end_time'      => '17:00',
            'break_minutes'      => 60,
            'work_days'          => [1, 2, 3, 4, 5],
            'is_active'          => true,
        ]);

        $this->admin = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'admin',
            'is_active'             => true,
            'name'                  => 'HRD Manager',
            'email'                 => 'hrd@industri.com',
        ]);

        $this->shiftMalam = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam',
            'color'                 => '#8b5cf6',
            'is_active'             => true,
        ]);

        $this->shiftPagi = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Pagi',
            'color'                 => '#3b82f6',
            'is_active'             => true,
        ]);

        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $this->shiftMalam->id,
                'day_of_week'     => $d,
                'work_start_time' => '22:00',
                'work_end_time'   => '06:00',
                'is_off'          => false,
                'is_cross_day'    => true,
                'effective_date'  => '2026-01-01',
            ]);

            ShiftSchedule::create([
                'shift_id'        => $this->shiftPagi->id,
                'day_of_week'     => $d,
                'work_start_time' => '07:00',
                'work_end_time'   => '15:00',
                'is_off'          => false,
                'is_cross_day'    => false,
                'effective_date'  => '2026-01-01',
            ]);
        }

        $this->userMalam = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'name'                  => 'Agus Malam',
            'employee_code'         => 'EMP-MLM',
            'department'            => 'Produksi',
        ]);

        $this->userPagi = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'name'                  => 'Budi Pagi',
            'employee_code'         => 'EMP-PAGI',
            'department'            => 'Produksi',
        ]);

        $this->userOffice = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'name'                  => 'Citra Kantor',
            'employee_code'         => 'EMP-OFF',
            'department'            => 'Administrasi',
        ]);

        UserShift::create([
            'user_id'    => $this->userMalam->id,
            'shift_id'   => $this->shiftMalam->id,
            'start_date' => '2026-09-01',
            'end_date'   => null,
        ]);

        UserShift::create([
            'user_id'    => $this->userPagi->id,
            'shift_id'   => $this->shiftPagi->id,
            'start_date' => '2026-09-01',
            'end_date'   => null,
        ]);

        // Beri admin penugasan Shift Pagi agar tidak masuk sebagai floating office employee
        UserShift::create([
            'user_id'    => $this->admin->id,
            'shift_id'   => $this->shiftPagi->id,
            'start_date' => '2026-09-01',
            'end_date'   => null,
        ]);
    }

    public function test_report_attendance_returns_by_shift_breakdown_and_answers_late_in_night_shift(): void
    {
        $date = '2026-09-07';

        // 1. Agus Malam check-in TELAT pada Shift Malam
        Attendance::create([
            'company_id'          => $this->company->id,
            'user_id'             => $this->userMalam->id,
            'date'                => $date,
            'check_in_time'       => '2026-09-07 22:35:00',
            'check_out_time'      => '2026-09-08 06:05:00',
            'check_in_type'       => 'onsite',
            'status'              => 'late',
            'snap_shift_id'       => $this->shiftMalam->id,
            'snap_shift_name'     => 'Shift Malam',
            'snap_source'         => 'shift',
            'snap_work_start_time'=> '22:00',
            'work_minutes'        => 450,
            'overtime_minutes'    => 5,
            'is_holiday'          => false,
        ]);

        // 2. Budi Pagi check-in TEPAT WAKTU pada Shift Pagi
        Attendance::create([
            'company_id'          => $this->company->id,
            'user_id'             => $this->userPagi->id,
            'date'                => $date,
            'check_in_time'       => '2026-09-07 06:55:00',
            'check_out_time'      => '2026-09-07 15:00:00',
            'check_in_type'       => 'onsite',
            'status'              => 'present',
            'snap_shift_id'       => $this->shiftPagi->id,
            'snap_shift_name'     => 'Shift Pagi',
            'snap_source'         => 'shift',
            'snap_work_start_time'=> '07:00',
            'work_minutes'        => 480,
            'overtime_minutes'    => 0,
            'is_holiday'          => false,
        ]);

        // 3. Citra Kantor check-in TEPAT WAKTU pada Jadwal Kantor Default
        Attendance::create([
            'company_id'          => $this->company->id,
            'user_id'             => $this->userOffice->id,
            'date'                => $date,
            'check_in_time'       => '2026-09-07 07:58:00',
            'check_out_time'      => '2026-09-07 17:01:00',
            'check_in_type'       => 'onsite',
            'status'              => 'present',
            'snap_source'         => 'office',
            'snap_work_start_time'=> '08:00',
            'work_minutes'        => 540,
            'overtime_minutes'    => 0,
            'is_holiday'          => false,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/report?start_date={$date}&end_date={$date}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary',
                'by_type',
                'by_shift',
                'available_shifts',
                'report' => [
                    'data',
                    'current_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                    'last_page',
                ],
            ]);

        $byShift = collect($response->json('by_shift'));

        // HRD menjawab: "berapa telat di shift malam?"
        $malamStats = $byShift->firstWhere('shift_name', 'Shift Malam');
        $this->assertNotNull($malamStats, 'Shift Malam harus ada di breakdown by_shift');
        $this->assertEquals(1, $malamStats['late'], 'Telat di Shift Malam harus tepat 1');
        $this->assertEquals(0, $malamStats['present'], 'Present di Shift Malam harus 0');
        $this->assertEquals(1, $malamStats['total_records']);

        // Shift Pagi tidak ada telat
        $pagiStats = $byShift->firstWhere('shift_name', 'Shift Pagi');
        $this->assertNotNull($pagiStats);
        $this->assertEquals(0, $pagiStats['late'], 'Telat di Shift Pagi harus 0');
        $this->assertEquals(1, $pagiStats['present'], 'Present di Shift Pagi harus 1');

        // Kantor (Default)
        $officeStats = $byShift->firstWhere('shift_name', 'Kantor (Default)');
        $this->assertNotNull($officeStats);
        $this->assertEquals(0, $officeStats['late']);
        $this->assertEquals(1, $officeStats['present']);

        // Verifikasi metadata baris data memuat nama shift
        $reportData = collect($response->json('report.data'));
        $rowAgus = $reportData->firstWhere('user_name', 'Agus Malam');
        $this->assertNotNull($rowAgus);
        $this->assertEquals('Shift Malam', $rowAgus['shift_name']);
        $this->assertEquals($this->shiftMalam->id, $rowAgus['shift_id']);
        $this->assertEquals('#8b5cf6', $rowAgus['shift_color']);
    }

    public function test_report_attendance_filters_by_specific_shift_id(): void
    {
        $date = '2026-09-07';

        Attendance::create([
            'company_id'      => $this->company->id,
            'user_id'         => $this->userMalam->id,
            'date'            => $date,
            'status'          => 'late',
            'snap_shift_id'   => $this->shiftMalam->id,
            'snap_shift_name' => 'Shift Malam',
            'snap_source'     => 'shift',
        ]);

        Attendance::create([
            'company_id'      => $this->company->id,
            'user_id'         => $this->userPagi->id,
            'date'            => $date,
            'status'          => 'present',
            'snap_shift_id'   => $this->shiftPagi->id,
            'snap_shift_name' => 'Shift Pagi',
            'snap_source'     => 'shift',
        ]);

        // Filter khusus Shift Malam
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/report?start_date={$date}&end_date={$date}&shift_id={$this->shiftMalam->id}");

        $response->assertStatus(200);

        $reportData = $response->json('report.data');
        $this->assertCount(1, $reportData);
        $this->assertEquals('Agus Malam', $reportData[0]['user_name']);
        $this->assertEquals('Shift Malam', $reportData[0]['shift_name']);
        $this->assertEquals(1, $response->json('report.total'));
    }

    public function test_report_attendance_filters_by_office_default_shift(): void
    {
        $date = '2026-09-07';

        Attendance::create([
            'company_id'      => $this->company->id,
            'user_id'         => $this->userMalam->id,
            'date'            => $date,
            'status'          => 'present',
            'snap_shift_id'   => $this->shiftMalam->id,
            'snap_shift_name' => 'Shift Malam',
            'snap_source'     => 'shift',
        ]);

        Attendance::create([
            'company_id'      => $this->company->id,
            'user_id'         => $this->userOffice->id,
            'date'            => $date,
            'status'          => 'present',
            'snap_source'     => 'office',
        ]);

        // Filter khusus Kantor (Default) via shift_id=office
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/report?start_date={$date}&end_date={$date}&shift_id=office");

        $response->assertStatus(200);

        $reportData = $response->json('report.data');
        $this->assertCount(1, $reportData);
        $this->assertEquals('Citra Kantor', $reportData[0]['user_name']);
        $this->assertEquals('Kantor (Default)', $reportData[0]['shift_name']);
        $this->assertNull($reportData[0]['shift_id']);
    }

    public function test_export_report_csv_includes_shift_column_and_filters_correctly(): void
    {
        $date = '2026-09-07';

        Attendance::create([
            'company_id'      => $this->company->id,
            'user_id'         => $this->userMalam->id,
            'date'            => $date,
            'status'          => 'late',
            'snap_shift_id'   => $this->shiftMalam->id,
            'snap_shift_name' => 'Shift Malam',
            'snap_source'     => 'shift',
        ]);

        Attendance::create([
            'company_id'      => $this->company->id,
            'user_id'         => $this->userPagi->id,
            'date'            => $date,
            'status'          => 'present',
            'snap_shift_id'   => $this->shiftPagi->id,
            'snap_shift_name' => 'Shift Pagi',
            'snap_source'     => 'shift',
        ]);

        // Export CSV difilter Shift Malam
        $response = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/v1/dashboard/attendance/report/export?start_date={$date}&end_date={$date}&shift_id={$this->shiftMalam->id}");

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/csv', $response->headers->get('content-type'));

        $content = $response->streamedContent();

        // Header harus memuat kolom Shift
        $this->assertStringContainsString('NIK,Nama,Departemen,Shift,Tanggal', $content);

        // Baris data harus memuat Agus Malam dan Shift Malam
        $this->assertStringContainsString('Agus Malam', $content);
        $this->assertStringContainsString('Shift Malam', $content);

        // Budi Pagi tidak boleh ada karena terfilter
        $this->assertStringNotContainsString('Budi Pagi', $content);
        $this->assertStringNotContainsString('Shift Pagi', $content);
    }
}
