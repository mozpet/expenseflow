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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftRosterPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private AttendanceSetting $office;
    private Shift $shiftPagi;
    private ShiftPattern $pattern;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Manufaktur Aman',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'         => $this->company->id,
            'office_name'        => 'Kantor Pusat',
            'office_latitude'    => -6.2088,
            'office_longitude'   => 106.8456,
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
            'name'                  => 'Admin HR',
            'email'                 => 'admin@example.com',
        ]);

        $this->shiftPagi = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Pagi Operasional',
            'color'                 => '#3b82f6',
            'is_active'             => true,
        ]);

        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $this->shiftPagi->id,
                'day_of_week'     => $d,
                'effective_date'  => '2026-01-01',
                'work_start_time' => '07:00',
                'work_end_time'   => '15:00',
                'break_minutes'   => 60,
                'is_off'          => false,
            ]);
        }

        $this->pattern = ShiftPattern::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Rotasi 4-2',
            'cycle_days'            => 6,
            'is_active'             => true,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            ShiftPatternItem::create([
                'shift_pattern_id' => $this->pattern->id,
                'day_order'        => $i,
                'is_off'           => $i >= 5,
                'shift_id'         => $i < 5 ? $this->shiftPagi->id : null,
                'work_start_time'  => $i < 5 ? '07:00' : null,
                'work_end_time'    => $i < 5 ? '15:00' : null,
                'break_minutes'    => $i < 5 ? 60 : 0,
            ]);
        }
    }

    /**
     * Helper membuat N karyawan untuk testing paginasi
     */
    private function createEmployees(int $count): array
    {
        $employees = [];
        for ($i = 1; $i <= $count; $i++) {
            $num = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $employees[] = User::factory()->create([
                'company_id'            => $this->company->id,
                'attendance_setting_id' => $this->office->id,
                'role'                  => 'employee',
                'name'                  => "Karyawan {$num}",
                'email'                 => "karyawan{$num}@example.com",
                'department'            => $i % 2 === 0 ? 'Operasional' : 'IT',
                'is_active'             => true,
            ]);
        }
        return $employees;
    }

    /**
     * Uji 1: Default server-side pagination membatasi ke 25 data per halaman
     * dan menyertakan metadata paginasi lengkap.
     */
    public function test_roster_defaults_to_server_side_pagination(): void
    {
        $this->createEmployees(30);

        // Total user aktif = 30 karyawan + 1 admin = 31
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08');

        $response->assertOk();
        $response->assertJsonStructure([
            'date',
            'day_name',
            'total',
            'current_page',
            'last_page',
            'per_page',
            'from',
            'to',
            'counts' => ['all', 'assigned', 'unassigned'],
            'departments',
            'data',
        ]);

        $this->assertEquals(31, $response->json('total'));
        $this->assertEquals(25, $response->json('per_page'));
        $this->assertEquals(1, $response->json('current_page'));
        $this->assertEquals(2, $response->json('last_page'));
        $this->assertEquals(1, $response->json('from'));
        $this->assertEquals(25, $response->json('to'));
        $this->assertCount(25, $response->json('data'));
    }

    /**
     * Uji 2: Custom per_page dan navigasi page berikutnya bekerja konsisten tanpa duplikasi.
     */
    public function test_roster_custom_per_page_and_page_navigation(): void
    {
        $this->createEmployees(24); // 24 + 1 admin = 25 total

        // Halaman 1 dengan per_page=10
        $res1 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&per_page=10&page=1');
        $res1->assertOk();
        $this->assertEquals(25, $res1->json('total'));
        $this->assertEquals(1, $res1->json('current_page'));
        $this->assertEquals(3, $res1->json('last_page'));
        $this->assertEquals(10, $res1->json('per_page'));
        $this->assertCount(10, $res1->json('data'));
        $page1Ids = collect($res1->json('data'))->pluck('user_id')->all();

        // Halaman 2 dengan per_page=10
        $res2 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&per_page=10&page=2');
        $res2->assertOk();
        $this->assertEquals(2, $res2->json('current_page'));
        $this->assertEquals(11, $res2->json('from'));
        $this->assertEquals(20, $res2->json('to'));
        $this->assertCount(10, $res2->json('data'));
        $page2Ids = collect($res2->json('data'))->pluck('user_id')->all();

        // Pastikan tidak ada data yang tumpang tindih antar halaman
        $overlap = array_intersect($page1Ids, $page2Ids);
        $this->assertEmpty($overlap, 'Halaman 1 dan 2 tidak boleh memiliki user_id yang sama');

        // Halaman 3 (sisa 5 data)
        $res3 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&per_page=10&page=3');
        $res3->assertOk();
        $this->assertEquals(3, $res3->json('current_page'));
        $this->assertCount(5, $res3->json('data'));
    }

    /**
     * Uji 3: Parameter per_page=all mengembalikan seluruh karyawan tanpa paginasi (backward compatibility).
     */
    public function test_roster_per_page_all_returns_unpaginated(): void
    {
        $this->createEmployees(30);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&per_page=all');

        $response->assertOk();
        $this->assertEquals(31, $response->json('total'));
        $this->assertEquals(1, $response->json('current_page'));
        $this->assertEquals(1, $response->json('last_page'));
        $this->assertCount(31, $response->json('data'));
    }

    /**
     * Uji 4: Filter status (ASSIGNED vs UNASSIGNED) dan keakuratan counter agregat global.
     */
    public function test_roster_status_filter_and_aggregated_counts(): void
    {
        $employees = $this->createEmployees(10); // 10 + 1 admin = 11

        // Tugaskan 3 karyawan ke Shift Pagi
        for ($i = 0; $i < 3; $i++) {
            UserShift::create([
                'user_id'    => $employees[$i]->id,
                'shift_id'   => $this->shiftPagi->id,
                'start_date' => '2026-09-01',
            ]);
        }

        // Tugaskan 1 karyawan ke Pola Rotasi
        UserShift::create([
            'user_id'          => $employees[3]->id,
            'shift_pattern_id' => $this->pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);

        // Total Assigned = 4 karyawan. Total Unassigned = 7 (6 karyawan + 1 admin).
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08');

        $response->assertOk();
        $counts = $response->json('counts');
        $this->assertEquals(11, $counts['all']);
        $this->assertEquals(4, $counts['assigned']);
        $this->assertEquals(7, $counts['unassigned']);

        // Filter: Hanya yang ASSIGNED
        $assignedRes = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&status=ASSIGNED');
        $assignedRes->assertOk();
        $this->assertEquals(4, $assignedRes->json('total'));
        $assignedData = $assignedRes->json('data');
        $this->assertCount(4, $assignedData);
        foreach ($assignedData as $row) {
            $this->assertEquals('shift', $row['source']);
        }

        // Filter: Hanya yang UNASSIGNED (Default Kantor)
        $unassignedRes = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&status=UNASSIGNED');
        $unassignedRes->assertOk();
        $this->assertEquals(7, $unassignedRes->json('total'));
        $unassignedData = $unassignedRes->json('data');
        $this->assertCount(7, $unassignedData);
        foreach ($unassignedData as $row) {
            $this->assertEquals('office', $row['source']);
        }
    }

    /**
     * Uji 5: Filter shift_name spesifik dan DEFAULT
     */
    public function test_roster_shift_name_filter(): void
    {
        $employees = $this->createEmployees(6);

        UserShift::create([
            'user_id'    => $employees[0]->id,
            'shift_id'   => $this->shiftPagi->id,
            'start_date' => '2026-09-01',
        ]);

        // Filter nama template shift
        $resShift = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&shift_name=Shift%20Pagi%20Operasional');
        $resShift->assertOk();
        $this->assertEquals(1, $resShift->json('total'));
        $this->assertEquals($employees[0]->id, $resShift->json('data.0.user_id'));

        // Filter DEFAULT (hanya yang tidak punya shift custom)
        $resDefault = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08&shift_name=DEFAULT');
        $resDefault->assertOk();
        $this->assertEquals(6, $resDefault->json('total')); // 5 employee + 1 admin
    }

    /**
     * Uji 6: Verifikasi pattern_name dan cycle_day disertakan pada output roster
     */
    public function test_roster_pattern_name_and_cycle_day_returned(): void
    {
        $employees = $this->createEmployees(2);

        // Karyawan 0 ditugaskan pola rotasi mulai 1 Sep 2026 (fase H1)
        // 8 Sep 2026 = 7 hari setelahnya -> (1 - 1 + 7) % 6 + 1 = 2 (fase H2)
        UserShift::create([
            'user_id'          => $employees[0]->id,
            'shift_pattern_id' => $this->pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-08');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('user_id', $employees[0]->id);
        $this->assertNotNull($row);
        $this->assertEquals('Pola Rotasi 4-2', $row['pattern_name']);
        $this->assertEquals(2, $row['cycle_day']);
        $this->assertEquals('shift', $row['source']);
    }
}
