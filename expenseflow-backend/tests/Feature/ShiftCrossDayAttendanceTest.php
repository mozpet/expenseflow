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

class ShiftCrossDayAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Maju Shift', 'is_active' => true]);
        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Operasional',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.81666700,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role = 'employee', bool $wfh = true): User
    {
        return User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => $role,
            'attendance_enabled'    => true,
            'wfh_enabled'           => $wfh,
            'radius_enabled'        => false,
            'is_active'             => true,
            'attendance_setting_id' => $this->office->id,
        ]);
    }

    private function token(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('t')->plainTextToken];
    }

    private function createNightShift(): Shift
    {
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'name'                  => 'Shift Malam',
            'attendance_setting_id' => $this->office->id,
        ]);

        // Jadwal Senin - Minggu: 22:00 s/d 06:00 (lintas hari)
        for ($dow = 0; $dow <= 6; $dow++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $dow,
                'work_start_time' => '22:00:00',
                'work_end_time'   => '06:00:00',
                'is_off'          => false,
                'is_cross_day'    => true,
            ]);
        }

        return $shift;
    }

    // ── 1. Check-in Shift Malam (Lintas Hari) ────────────────────────
    public function test_checkin_shift_malam_lintas_hari_berhasil(): void
    {
        $shift = $this->createNightShift();
        $emp = $this->user();
        UserShift::create([
            'user_id'    => $emp->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-08-01',
        ]);

        // Freeze waktu: 2026-08-28 jam 22:05 WIB (tepat saat shift malam dimulai)
        Carbon::setTestNow(Carbon::parse('2026-08-28 22:05:00', 'Asia/Jakarta'));

        $res = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81666700,
        ], $this->token($emp));

        $res->assertCreated();
        $res->assertJsonPath('attendance.status', 'present');

        // Pastikan record tersimpan dengan snapshot lintas hari
        $att = Attendance::where('user_id', $emp->id)->whereDate('date', '2026-08-28')->first();
        $this->assertNotNull($att);
        $this->assertTrue($att->snap_is_cross_day);
        $this->assertEquals('22:00:00', $att->snap_work_start_time);
        $this->assertEquals('06:00:00', $att->snap_work_end_time);
    }

    // ── 2. Check-out Shift Malam pada Keesokan Harinya ───────────────
    public function test_checkout_shift_malam_keesokan_harinya_berhasil_hitung_jam(): void
    {
        $shift = $this->createNightShift();
        $emp = $this->user();
        UserShift::create([
            'user_id'    => $emp->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-08-01',
        ]);

        // Check-in jam 22:00 WIB (2026-08-28)
        Carbon::setTestNow(Carbon::parse('2026-08-28 22:00:00', 'Asia/Jakarta'));
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81666700,
        ], $this->token($emp))->assertCreated();

        // Check-out jam 06:00 WIB keesokan harinya (2026-08-29)
        Carbon::setTestNow(Carbon::parse('2026-08-29 06:00:00', 'Asia/Jakarta'));
        $res = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20,
            'longitude' => 106.81666700,
        ], $this->token($emp));

        $res->assertOk();
        $att = Attendance::where('user_id', $emp->id)->whereDate('date', '2026-08-28')->first();
        $this->assertNotNull($att->check_out_time);
        // Durasi 22:00 s/d 06:00 = 8 jam = 480 menit (dipotong istirahat 60 menit = 420 menit)
        $this->assertEquals(420, $att->work_minutes);
    }

    // ── 3. Cegah check-in baru jika shift malam kemarin belum checkout ──
    public function test_cegah_checkin_baru_jika_shift_malam_kemarin_belum_checkout(): void
    {
        $shift = $this->createNightShift();
        $emp = $this->user();
        UserShift::create([
            'user_id'    => $emp->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-08-01',
        ]);

        // Check-in shift malam kemarin (2026-08-28 22:00 WIB) dan BELUM checkout
        Carbon::setTestNow(Carbon::parse('2026-08-28 22:00:00', 'Asia/Jakarta'));
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81666700,
        ], $this->token($emp))->assertCreated();

        // Pagi harinya (2026-08-29 07:00 WIB), karyawan coba check-in baru tanpa checkout shift malam
        Carbon::setTestNow(Carbon::parse('2026-08-29 07:00:00', 'Asia/Jakarta'));
        $res = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81666700,
        ], $this->token($emp));

        // Ditolak HTTP 409 Conflict agar checkout shift malam diselesaikan terlebih dahulu
        $res->assertStatus(409);
        $res->assertJsonStructure(['message', 'pending_attendance_id', 'pending_shift_date']);
    }

    // ── 4. Auto-checkout Shift Malam oleh Scheduler ─────────────────
    public function test_autocheckout_shift_malam_berhasil_ditutup_otomatis(): void
    {
        $shift = $this->createNightShift();
        $emp = $this->user();
        UserShift::create([
            'user_id'    => $emp->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-08-01',
        ]);

        // Check-in shift malam 2026-08-28 jam 22:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-08-28 22:00:00', 'Asia/Jakarta'));
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81666700,
        ], $this->token($emp))->assertCreated();

        // Maju ke jam 07:05 WIB keesokan harinya (jam pulang 06:00 + grace 60 menit = batas 07:00)
        Carbon::setTestNow(Carbon::parse('2026-08-29 07:05:00', 'Asia/Jakarta'));

        // Jalankan perintah auto-checkout
        $this->artisan('attendance:auto-checkout')
            ->assertSuccessful();

        $att = Attendance::where('user_id', $emp->id)->whereDate('date', '2026-08-28')->first();
        $this->assertNotNull($att->check_out_time);
        $this->assertTrue($att->is_auto_checkout);
        // Durasi tercatat mencakup jam kerja shift malam (minimal 8 jam / 480 menit)
        $this->assertGreaterThanOrEqual(480, $att->work_minutes);
    }
}
