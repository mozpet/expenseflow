<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\ShiftPatternItem;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftManagementCategoryCTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $admin;
    protected User $employee1; // Dept: Operasional
    protected User $employee2; // Dept: IT
    protected AttendanceSetting $branch;
    protected Shift $shiftPagi;
    protected ShiftPattern $pattern42;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 07:00:00');

        $this->company = Company::create(['name' => 'PT Manufaktur Aman', 'is_active' => true]);

        $this->branch = AttendanceSetting::create([
            'company_id'          => $this->company->id,
            'office_name'         => 'Kantor Pusat Jakarta',
            'office_latitude'     => -6.2088,
            'office_longitude'    => 106.8456,
            'radius_meters'       => 100,
            'work_start_time'     => '08:00',
            'work_end_time'       => '17:00',
            'work_days'           => [1, 2, 3, 4, 5], // Senin-Jumat
            'break_minutes'       => 60,
        ]);

        $this->admin = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->branch->id,
            'role'                  => 'admin',
            'department'            => 'HRD',
        ]);

        $this->employee1 = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->branch->id,
            'role'                  => 'employee',
            'name'                  => 'Karyawan Operasional',
            'department'            => 'Operasional',
        ]);

        $this->employee2 = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->branch->id,
            'role'                  => 'employee',
            'name'                  => 'Karyawan IT',
            'department'            => 'IT',
        ]);

        $this->shiftPagi = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->branch->id,
            'name'                  => 'Shift Pagi Operasional',
            'color'                 => '#10b981',
            'is_active'             => true,
        ]);

        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $this->shiftPagi->id,
                'day_of_week'     => $d,
                'effective_date'  => '2026-01-01',
                'work_start_time' => ($d === 0 || $d === 6) ? null : '07:00',
                'work_end_time'   => ($d === 0 || $d === 6) ? null : '15:00',
                'break_minutes'   => ($d === 0 || $d === 6) ? 0 : 60,
                'is_off'          => ($d === 0 || $d === 6),
            ]);
        }

        // Buat pola rotasi 4-2 (4 kerja, 2 libur)
        $this->pattern42 = ShiftPattern::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->branch->id,
            'name'                  => 'Pola Rotasi 4-2',
            'cycle_days'            => 6,
            'is_active'             => true,
        ]);

        for ($day = 1; $day <= 6; $day++) {
            $isOff = ($day > 4);
            ShiftPatternItem::create([
                'shift_pattern_id' => $this->pattern42->id,
                'day_order'        => $day,
                'shift_id'         => $isOff ? null : $this->shiftPagi->id,
                'is_off'           => $isOff,
                'work_start_time'  => $isOff ? null : '07:00',
                'work_end_time'    => $isOff ? null : '15:00',
                'break_minutes'    => $isOff ? 0 : 60,
            ]);
        }
    }

    /**
     * Uji Poin 10: Endpoint Roster mendukung filter Departemen
     */
    public function test_roster_endpoint_supports_department_filter(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?department=Operasional');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        // Hanya karyawan dengan departemen Operasional yang muncul
        foreach ($data as $row) {
            $this->assertEquals('Operasional', $row['department']);
        }

        // Verifikasi departments list disertakan di JSON response
        $departments = $response->json('departments');
        $this->assertContains('Operasional', $departments);
        $this->assertContains('IT', $departments);
        $this->assertContains('HRD', $departments);
    }

    /**
     * Uji Poin 10: Endpoint Calendar mendukung filter Departemen
     */
    public function test_calendar_endpoint_supports_department_filter(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/calendar?month=9&year=2026&department=IT');

        $response->assertOk();
        $departments = $response->json('departments');
        $this->assertContains('IT', $departments);
    }

    /**
     * Uji Poin 11: Endpoint Calendar menampilkan karyawan Libur (OFF)
     */
    public function test_calendar_endpoint_reports_off_employees(): void
    {
        // Pasangkan karyawan 1 ke pola rotasi 4-2 mulai 1 September 2026 (fase H1)
        // Hari 1..4: Kerja, Hari 5..6 (5 & 6 Sep): Libur Siklus (OFF)
        UserShift::create([
            'user_id'          => $this->employee1->id,
            'shift_pattern_id' => $this->pattern42->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/calendar?month=9&year=2026');

        $response->assertOk();
        $days = $response->json('days');

        // Tanggal 5 September 2026 (Hari ke-5 siklus -> OFF)
        $this->assertArrayHasKey('2026-09-05', $days);
        $day5Entries = collect($days['2026-09-05']);

        // Harus ada entri Libur (OFF)
        $offEntry = $day5Entries->firstWhere('is_off', true);
        $this->assertNotNull($offEntry);
        $this->assertEquals('Libur (OFF)', $offEntry['shift_name']);

        // Karyawan 1 harus ada dalam daftar users yang libur dengan alasan pola rotasi
        $offUserIds = collect($offEntry['users'])->pluck('user_id')->all();
        $this->assertContains($this->employee1->id, $offUserIds);

        $emp1Detail = collect($offEntry['users'])->firstWhere('user_id', $this->employee1->id);
        $this->assertStringContainsString('Libur Siklus', $emp1Detail['reason']);
    }

    /**
     * Uji Poin 12: Integritas Status Penugasan di Roster (source: shift vs office)
     */
    public function test_roster_assignment_source_integrity(): void
    {
        // Karyawan 1 di-assign shift pagi
        UserShift::create([
            'user_id'    => $this->employee1->id,
            'shift_id'   => $this->shiftPagi->id,
            'start_date' => '2026-09-01',
        ]);

        // Karyawan 2 tidak memiliki custom shift (floating staff / default kantor)

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-04');

        $response->assertOk();
        $data = collect($response->json('data'));

        $row1 = $data->firstWhere('user_id', $this->employee1->id);
        $row2 = $data->firstWhere('user_id', $this->employee2->id);

        $this->assertEquals('shift', $row1['source']);
        $this->assertEquals('office', $row2['source']);
    }
}
