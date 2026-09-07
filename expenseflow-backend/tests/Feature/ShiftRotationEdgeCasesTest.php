<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ShiftController;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\ShiftPatternItem;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftRotationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employee;
    private Shift $templateShift;
    private ShiftPattern $pattern42;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 07:00:00');

        $this->company = Company::create(['name' => 'PT Manufaktur Aman', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Pabrik Sentral',
            'office_latitude'             => -6.20000000,
            'office_longitude'            => 106.81666700,
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
            'role'                  => 'employee',
            'name'                  => 'Joko Santoso',
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        // Template Shift Biasa (Senin-Jumat 08:00-17:00, Sabtu-Minggu Off)
        $this->templateShift = Shift::create([
            'company_id' => $this->company->id,
            'name'       => 'Shift Kantor Normal',
            'color'      => '#3b82f6',
            'is_active'  => true,
        ]);

        for ($dow = 0; $dow <= 6; $dow++) {
            $isWeekend = ($dow === 0 || $dow === 6);
            ShiftSchedule::create([
                'shift_id'        => $this->templateShift->id,
                'day_of_week'     => $dow,
                'effective_date'  => '2026-01-01',
                'work_start_time' => $isWeekend ? null : '08:00:00',
                'work_end_time'   => $isWeekend ? null : '17:00:00',
                'break_minutes'   => 60,
                'is_off'          => $isWeekend,
            ]);
        }

        // Master Pola Rotasi 4-2 (4 Kerja, 2 Libur)
        $this->pattern42 = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola 4-2 Pabrik',
            'cycle_days' => 6,
            'is_active'  => true,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $isOff = $i >= 5;
            ShiftPatternItem::create([
                'shift_pattern_id' => $this->pattern42->id,
                'day_order'        => $i,
                'shift_id'         => $isOff ? null : $this->templateShift->id,
                'is_off'           => $isOff,
                'work_start_time'  => $isOff ? null : '07:00:00',
                'work_end_time'    => $isOff ? null : '15:00:00',
                'break_minutes'    => 60,
                'is_cross_day'     => false,
            ]);
        }
    }

    /**
     * KASUS 1: Karyawan sudah punya Template Shift, lalu HRD memasang Pola Rotasi baru.
     * Sebelum start_date rotasi: jadwal lama tetap berlaku.
     * Pada & setelah start_date rotasi: jadwal otomatis beralih ke pola rotasi.
     */
    public function test_seamless_transition_from_template_shift_to_rotation_pattern(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. Karyawan sudah punya Template Shift sejak Agustus
        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $this->templateShift->id,
            'start_date' => '2026-08-01',
            'end_date'   => null,
        ]);

        // 2. HRD memasang Pola Rotasi 4-2 mulai 2026-09-05
        $res = $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $this->pattern42->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-05',
        ]);
        $res->assertStatus(201);

        // 3. Cek jadwal pada tanggal 2026-09-04 (sebelum rotasi mulai):
        // Hari Jumat: Harus tetap mengikuti Template Shift (masuk 08:00 - 17:00)
        $schedBefore = ShiftController::resolveSchedule($this->employee, '2026-09-04');
        $this->assertEquals($this->templateShift->id, $schedBefore['shift_id']);
        $this->assertEquals('08:00:00', $schedBefore['work_start_time']);
        $this->assertFalse($schedBefore['is_off']);

        // 4. Cek jadwal pada tanggal 2026-09-05 (hari pertama rotasi):
        // Hari Sabtu: Jika template biasa harusnya libur weekend, TAPI karena rotasi 4-2 H1 mulai hari ini,
        // maka karyawan MASUK KERJA jam 07:00 - 15:00!
        $schedStart = ShiftController::resolveSchedule($this->employee, '2026-09-05');
        $this->assertEquals($this->pattern42->id, $schedStart['pattern_id']);
        $this->assertEquals(1, $schedStart['cycle_day']);
        $this->assertEquals('07:00', $schedStart['work_start_time']);
        $this->assertFalse($schedStart['is_off']);
    }

    /**
     * KASUS 2: HRD memutus Pola Rotasi di tengah jalan (Soft-End).
     * Histori hari kemarin tetap utuh, hari ini & ke depan kembali ke default kantor.
     */
    public function test_ending_rotation_assignment_preserves_history_and_falls_back_to_office_default(): void
    {
        Sanctum::actingAs($this->admin);

        // Rotasi sudah berjalan sejak 2026-08-20
        $userShift = UserShift::create([
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $this->pattern42->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-08-20',
            'end_date'         => null,
        ]);

        // Hari ini adalah 2026-09-01. HRD memutus assignment hari ini via DELETE /assignments/{id}
        $delRes = $this->deleteJson("/api/v1/dashboard/attendance/assignments/{$userShift->id}");
        $delRes->assertStatus(200);
        $delRes->assertJsonFragment(['soft_end' => true]);

        // Database: baris tidak dihapus, melainkan end_date di-set kemarin (2026-08-31)
        $userShift->refresh();
        $this->assertEquals('2026-08-31', $userShift->end_date->toDateString());

        // Histori kemarin (2026-08-31): masih terbaca sebagai rotasi
        $schedYesterday = ShiftController::resolveSchedule($this->employee, '2026-08-31');
        $this->assertEquals($this->pattern42->id, $schedYesterday['pattern_id']);

        // Jadwal hari ini (2026-09-01): otomatis kembali ke jam kantor default (08:00 - 17:00)
        $schedToday = ShiftController::resolveSchedule($this->employee, '2026-09-01');
        $this->assertEquals('office', $schedToday['source']);
        $this->assertEquals('08:00:00', $schedToday['work_start_time']);
        $this->assertEquals('17:00:00', $schedToday['work_end_time']);
    }

    /**
     * KASUS 3: Proteksi Presensi Aktif Hari Ini (Snapshot Protection).
     * Jika karyawan sudah check-in hari ini, lalu HRD mengubah atau menghapus shift,
     * sesi presensi hari ini tetap diselesaikan sesuai jadwal saat check-in.
     */
    public function test_active_checkin_session_protected_when_shift_assigned_or_removed_midday(): void
    {
        Sanctum::actingAs($this->admin);

        // Karyawan check-in hari ini jam 07:05
        $attendance = Attendance::create([
            'company_id'             => $this->company->id,
            'user_id'                => $this->employee->id,
            'date'                   => '2026-09-01',
            'check_in_time'          => '07:05:00',
            'check_in_lat'           => -6.20000000,
            'check_in_lng'           => 106.81666700,
            'check_in_type'          => 'onsite',
            'status'                 => 'present',
        ]);

        // HRD menghapus assignment yang ada hari ini
        $userShift = UserShift::create([
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $this->pattern42->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-08-10',
            'end_date'         => null,
        ]);

        $res = $this->deleteJson("/api/v1/dashboard/attendance/assignments/{$userShift->id}");
        $res->assertStatus(200);
        $res->assertJsonFragment(['active_session_protected' => true]);

        // Catatan presensi hari ini tetap tidak rusak
        $attendance->refresh();
        $this->assertNotNull($attendance->check_in_time);
        $this->assertNull($attendance->check_out_time);
    }

    /**
     * KASUS 4: Karyawan dicegah dari dobel penugasan pola yang sama.
     */
    public function test_cannot_assign_duplicate_active_pattern_to_same_employee(): void
    {
        Sanctum::actingAs($this->admin);

        UserShift::create([
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $this->pattern42->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        // Coba assign pola yang sama lagi mulai 2026-09-10
        $res = $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $this->pattern42->id,
            'anchor_day_order' => 2,
            'start_date'       => '2026-09-10',
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment([
            'message' => "Karyawan ini sudah memiliki assignment aktif untuk pola rotasi '{$this->pattern42->name}'. Akhiri assignment yang sedang berjalan terlebih dahulu.",
        ]);
    }

    /**
     * KASUS 5: Mengubah assignment dari pola rotasi ke shift mingguan biasa (atau sebaliknya) via updateAssignment.
     */
    public function test_updating_assignment_from_pattern_to_shift_clears_pattern_id(): void
    {
        Sanctum::actingAs($this->admin);

        $userShift = UserShift::create([
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $this->pattern42->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        // HRD mengubah assignment ini menjadi shift mingguan
        $res = $this->putJson("/api/v1/dashboard/attendance/assignments/{$userShift->id}", [
            'shift_id'   => $this->templateShift->id,
            'start_date' => '2026-09-01',
        ]);
        $res->assertStatus(200);

        $userShift->refresh();
        $this->assertEquals($this->templateShift->id, $userShift->shift_id);
        $this->assertNull($userShift->shift_pattern_id);

        $sched = ShiftController::resolveSchedule($this->employee, '2026-09-01');
        $this->assertEquals($this->templateShift->id, $sched['shift_id']);
        $this->assertNull($sched['pattern_id'] ?? null);
    }

    /**
     * KASUS 6: Pola rotasi dengan jam kerja custom (shift_id = null) harus tetap dianggap hari kerja (is_off = false).
     */
    public function test_rotation_pattern_with_custom_hours_without_template_shift_is_working_day(): void
    {
        $customPattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola Jam Custom',
            'cycle_days' => 3,
            'is_active'  => true,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $customPattern->id,
            'day_order'        => 1,
            'shift_id'         => null,
            'is_off'           => false,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
            'break_minutes'    => 60,
            'is_cross_day'     => false,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $customPattern->id,
            'day_order'        => 2,
            'shift_id'         => null,
            'is_off'           => false,
            'work_start_time'  => '09:00:00',
            'work_end_time'    => '18:00:00',
            'break_minutes'    => 60,
            'is_cross_day'     => false,
        ]);

        ShiftPatternItem::create([
            'shift_pattern_id' => $customPattern->id,
            'day_order'        => 3,
            'shift_id'         => null,
            'is_off'           => true,
            'work_start_time'  => null,
            'work_end_time'    => null,
            'break_minutes'    => 0,
            'is_cross_day'     => false,
        ]);

        UserShift::create([
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $customPattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        $schedH1 = ShiftController::resolveSchedule($this->employee, '2026-09-01');
        $this->assertFalse($schedH1['is_off'], 'H1 seharusnya hari kerja (bukan libur)!');
        $this->assertEquals('08:00', $schedH1['work_start_time']);
        $this->assertEquals('17:00', $schedH1['work_end_time']);

        $schedH3 = ShiftController::resolveSchedule($this->employee, '2026-09-03');
        $this->assertTrue($schedH3['is_off'], 'H3 seharusnya hari libur!');

        Sanctum::actingAs($this->admin);
        $calRes = $this->getJson('/api/v1/dashboard/attendance/shifts/calendar?month=9&year=2026');
        $calRes->assertStatus(200);
        $h1Entries = $calRes->json('days.2026-09-01');
        $this->assertNotEmpty($h1Entries);
        $workEntry = collect($h1Entries)->firstWhere('is_off', false);
        $this->assertNotNull($workEntry, 'Harus ada entri kerja pada hari H1 di kalender');
        $this->assertTrue(collect($workEntry['users'])->contains('user_id', $this->employee->id));
    }
}


