<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftSnapshotMiddayChangeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employee;
    private Shift $shiftA;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-04 07:00:00');

        $this->company = Company::create(['name' => 'PT Operasional Fleksibel', 'is_active' => true]);

        // Default kantor: 08:00 - 17:00
        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Pusat',
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
            'name'                  => 'Karyawan Shift',
            'birth_date'            => '1995-05-12',
            'attendance_enabled'    => true,
            'wfh_enabled'           => true, // bypass GPS
            'radius_enabled'        => false,
            'attendance_setting_id' => $this->office->id,
        ]);

        // Shift A: 07:00 - 15:00
        $this->shiftA = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Pagi Pabrik',
            'is_active'             => true,
            'color'                 => '#3b82f6',
        ]);
        for ($day = 0; $day <= 6; $day++) {
            ShiftSchedule::create([
                'shift_id'        => $this->shiftA->id,
                'effective_date'  => '2026-09-01',
                'day_of_week'     => $day,
                'work_start_time' => '07:00:00',
                'work_end_time'   => '15:00:00',
                'break_minutes'   => 60,
                'is_off'          => false,
                'is_cross_day'    => false,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_midday_shift_deletion_allows_checkout_with_original_shift_schedule(): void
    {
        // 1. Karyawan ditugaskan Shift A mulai 2026-09-01
        $userShift = UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $this->shiftA->id,
            'start_date' => '2026-09-01',
            'end_date'   => null,
        ]);

        // 2. Karyawan check-in pada jam 07:00 (tepat waktu untuk Shift A)
        Carbon::setTestNow(Carbon::parse('2026-09-04 07:00:00', 'Asia/Jakarta'));
        $checkInRes = $this->actingAs($this->employee)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.816667,
        ]);
        $checkInRes->assertStatus(201);

        $attendance = Attendance::where('user_id', $this->employee->id)
            ->whereDate('date', '2026-09-04')
            ->first();

        $this->assertNotNull($attendance);
        $this->assertEquals('shift', $attendance->snap_source);
        $this->assertEquals('07:00:00', $attendance->snap_work_start_time);
        $this->assertEquals('15:00:00', $attendance->snap_work_end_time);
        $this->assertEquals($this->shiftA->id, $attendance->snap_shift_id);
        $this->assertEquals('Shift Pagi Pabrik', $attendance->snap_shift_name);
        $this->assertEquals('present', $attendance->status);

        // 3. Pada tengah hari (jam 11:30), HRD menghapus / mengakhiri assignment shift
        Carbon::setTestNow(Carbon::parse('2026-09-04 11:30:00', 'Asia/Jakarta'));
        $deleteRes = $this->actingAs($this->admin)->deleteJson("/api/v1/dashboard/attendance/assignments/{$userShift->id}");

        // Sebelumnya: DITOLAK 422 ("Tidak bisa menghapus assignment sekarang...").
        // Sekarang: BERHASIL 200 OK dengan proteksi sesi aktif!
        $deleteRes->assertStatus(200);
        $this->assertTrue($deleteRes->json('active_session_protected'));
        $this->assertStringContainsString('snapshot', $deleteRes->json('message'));

        // 4. Karyawan mengecek status presensi di HP (checkStatus API)
        $statusRes = $this->actingAs($this->employee)->getJson('/api/v1/attendance/status');
        $statusRes->assertStatus(200);

        // Aplikasi HP tetap menampilkan jadwal Shift A saat check-in (bukan langsung jam 17:00 kantor!)
        $this->assertTrue($statusRes->json('is_snapshot_session'));
        $this->assertEquals('Shift Pagi Pabrik', $statusRes->json('active_shift.shift_name'));
        $this->assertEquals('07:00', $statusRes->json('active_shift.work_start_time'));
        $this->assertEquals('15:00', $statusRes->json('active_shift.work_end_time'));

        // 5. Karyawan checkout pada jam 15:00 (tepat jam pulang Shift A)
        Carbon::setTestNow(Carbon::parse('2026-09-04 15:00:00', 'Asia/Jakarta'));
        $checkoutRes = $this->actingAs($this->employee)->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20,
            'longitude' => 106.816667,
        ]);
        $checkoutRes->assertStatus(200);

        $attendance->refresh();
        $this->assertNotNull($attendance->check_out_time);
        // Status hadir tepat waktu sesuai jadwal shift jam 15:00 (bukan dianggap pulang awal kantor jam 17:00)
        $this->assertEquals('present', $attendance->status);

        // 6. Setelah checkout selesai, sesi aktif berakhir. Status mobile kini siap untuk jadwal kantor default
        $statusAfterCheckout = $this->actingAs($this->employee)->getJson('/api/v1/attendance/status');
        $statusAfterCheckout->assertStatus(200);
        $this->assertTrue($statusAfterCheckout->json('checked_out'));
        // Tidak ada active_shift (kembali ke default kantor)
        $this->assertNull($statusAfterCheckout->json('active_shift'));
    }

    public function test_midday_shift_update_preserves_current_shift_until_checkout(): void
    {
        $userShift = UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $this->shiftA->id,
            'start_date' => '2026-09-01',
            'end_date'   => null,
        ]);

        // Check-in jam 07:00
        Carbon::setTestNow(Carbon::parse('2026-09-04 07:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->employee)->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.816667,
        ]);

        // Shift B: 10:00 - 18:00
        $shiftB = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Siang B',
            'is_active'             => true,
            'color'                 => '#10b981',
        ]);
        for ($day = 0; $day <= 6; $day++) {
            ShiftSchedule::create([
                'shift_id'        => $shiftB->id,
                'effective_date'  => '2026-09-01',
                'day_of_week'     => $day,
                'work_start_time' => '10:00:00',
                'work_end_time'   => '18:00:00',
                'break_minutes'   => 60,
                'is_off'          => false,
            ]);
        }

        // Jam 10:00, HRD mengubah shift karyawan ke Shift B
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Jakarta'));
        $updateRes = $this->actingAs($this->admin)->putJson("/api/v1/dashboard/attendance/assignments/{$userShift->id}", [
            'shift_id'   => $shiftB->id,
            'start_date' => '2026-09-04',
        ]);

        // Lolos tanpa error 422
        $updateRes->assertStatus(200);

        // Status di HP karyawan tetap menampilkan Shift A sampai checkout
        $statusRes = $this->actingAs($this->employee)->getJson('/api/v1/attendance/status');
        $this->assertEquals('Shift Pagi Pabrik', $statusRes->json('active_shift.shift_name'));
        $this->assertEquals('07:00', $statusRes->json('active_shift.work_start_time'));
        $this->assertEquals('15:00', $statusRes->json('active_shift.work_end_time'));
    }

    public function test_midday_shift_deletion_when_not_checked_in_reverts_immediately(): void
    {
        $userShift = UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $this->shiftA->id,
            'start_date' => '2026-09-01',
            'end_date'   => null,
        ]);

        // Karyawan BELUM check-in.
        // Jam 09:00, HRD menghapus shift.
        Carbon::setTestNow(Carbon::parse('2026-09-04 09:00:00', 'Asia/Jakarta'));
        $deleteRes = $this->actingAs($this->admin)->deleteJson("/api/v1/dashboard/attendance/assignments/{$userShift->id}");
        $deleteRes->assertStatus(200);
        $this->assertFalse($deleteRes->json('active_session_protected'));

        // Status di HP karyawan langsung kembali ke default kantor (active_shift null)
        $statusRes = $this->actingAs($this->employee)->getJson('/api/v1/attendance/status');
        $this->assertNull($statusRes->json('active_shift'));
    }
}
