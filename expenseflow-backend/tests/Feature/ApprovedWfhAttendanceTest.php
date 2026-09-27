<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovedWfhAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $hrd;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-17 08:30:00', 'Asia/Jakarta'));

        $this->company = Company::create(['name' => 'PT Solusi Digital', 'is_active' => true]);
        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Pusat Jakarta',
            'office_latitude'             => -6.20000000,
            'office_longitude'            => 106.81666700,
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
            'wfh_enabled'           => true,
            'radius_enabled'        => false,
            'is_active'             => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        // Karyawan kantor biasa (onsite): WFH disabled, radius lapangan disabled, attendance_enabled disabled
        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Budi WFH Test',
            'attendance_enabled'    => false,
            'wfh_enabled'           => false,
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

    private function token(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('t')->plainTextToken];
    }

    public function test_approved_wfh_dynamically_activates_wfh_and_excludes_from_leave_card(): void
    {
        $todayStr = '2026-09-17';

        // 1. Buat pengajuan WFH yang disetujui HRD untuk hari ini
        $leave = LeaveRequest::create([
            'company_id'  => $this->company->id,
            'user_id'     => $this->employee->id,
            'leave_type'  => 'wfh',
            'start_date'  => $todayStr,
            'end_date'    => $todayStr,
            'total_days'  => 1,
            'reason'      => 'Isolasi mandiri / WFH kerja dari rumah',
            'status'      => 'approved',
            'approved_by' => $this->hrd->id,
            'approved_at' => now(),
        ]);

        // 2. Cek User model dynamic helpers
        $this->assertTrue($this->employee->hasApprovedWfhToday($todayStr));
        $this->assertTrue($this->employee->canAccessAttendance());
        $this->assertTrue($this->employee->canWfh());
        $this->assertFalse($this->employee->hasRadiusEnabled());

        // 3. Cek dashboard /today: karyawan TIDAK boleh masuk ke on_leave, harus masuk ke not_checked_in dengan is_wfh=true
        Sanctum::actingAs($this->hrd);
        $resToday = $this->getJson('/api/v1/dashboard/attendance/today');
        $resToday->assertStatus(200);
        $todayData = $resToday->json();

        $this->assertEquals(0, $todayData['summary']['on_leave']);
        $this->assertCount(0, $todayData['on_leave']);

        $empNotCheckedIn = collect($todayData['not_checked_in'])->firstWhere('user_id', $this->employee->id);
        $this->assertNotNull($empNotCheckedIn);
        $this->assertTrue($empNotCheckedIn['is_wfh']);
        $this->assertTrue($empNotCheckedIn['is_wfh_approved']);

        // 4. Cek endpoint listUsers: flag is_wfh_approved_today harus true
        Sanctum::actingAs($this->hrd);
        $resUsers = $this->getJson('/api/v1/dashboard/attendance/users');
        $resUsers->assertStatus(200);
        $usersList = $resUsers->json('data');
        $empUser = collect($usersList)->firstWhere('id', $this->employee->id);
        $this->assertNotNull($empUser);
        $this->assertTrue($empUser['is_wfh_approved_today']);

        // 5. Cek endpoint polling status mobile (/attendance/status): wfh_enabled=true, radius_enabled=false
        Sanctum::actingAs($this->employee);
        $resStatus = $this->getJson('/api/v1/attendance/status');
        $resStatus->assertStatus(200);
        $resStatus->assertJson([
            'checked_in'      => false,
            'wfh_enabled'     => true,
            'radius_enabled'  => false,
            'is_wfh_approved' => true,
        ]);

        // 6. Cek endpoint riwayat presensi mobile (/attendance/my): wfh_enabled=true, radius_enabled=false
        Sanctum::actingAs($this->employee);
        $resMy = $this->getJson('/api/v1/attendance/my');
        $resMy->assertStatus(200);
        $resMy->assertJson([
            'wfh_enabled'     => true,
            'radius_enabled'  => false,
            'is_wfh_approved' => true,
        ]);

        // 7. Coba toggleWfh secara manual oleh HRD: harus ditolak (422) karena WFH otomatis aktif hari ini
        Sanctum::actingAs($this->hrd);
        $resToggle = $this->postJson("/api/v1/dashboard/attendance/users/{$this->employee->id}/toggle-wfh");
        $resToggle->assertStatus(422);

        // 8. Lakukan check-in presensi dari mobile dari lokasi rumah (di luar radius kantor pusat)
        Sanctum::actingAs($this->employee);
        $resCheckIn = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'      => -7.00000000, // Lokasi rumah di Semarang (jauh dari Jakarta)
            'longitude'     => 110.40000000,
            'check_in_type' => 'wfh',
        ], ['X-Platform' => 'mobile']);

        $resCheckIn->assertStatus(201);
        $this->assertDatabaseHas('attendances', [
            'user_id'       => $this->employee->id,
            'date'          => $todayStr,
            'check_in_type' => 'wfh',
        ]);

        // 9. Cek dashboard /today setelah check-in: harus masuk ke checked_in dengan is_wfh=true
        Sanctum::actingAs($this->hrd);
        $resTodayAfter = $this->getJson('/api/v1/dashboard/attendance/today');
        $resTodayAfter->assertStatus(200);
        $afterData = $resTodayAfter->json();

        $this->assertEquals(1, $afterData['summary']['checked_in']);
        $empCheckedIn = collect($afterData['checked_in'])->firstWhere('user_id', $this->employee->id);
        $this->assertNotNull($empCheckedIn);
        $this->assertEquals('wfh', $empCheckedIn['check_in_type']);
        $this->assertTrue($empCheckedIn['is_wfh']);
    }

    public function test_next_day_automatically_reverts_to_onsite(): void
    {
        $todayStr = '2026-09-17';
        $tomorrowStr = '2026-09-18';

        // 1. Karyawan memiliki WFH hanya untuk tanggal 2026-09-17 (1 hari)
        LeaveRequest::create([
            'company_id'  => $this->company->id,
            'user_id'     => $this->employee->id,
            'leave_type'  => 'wfh',
            'start_date'  => $todayStr,
            'end_date'    => $todayStr,
            'total_days'  => 1,
            'reason'      => 'WFH 1 hari saja',
            'status'      => 'approved',
            'approved_by' => $this->hrd->id,
            'approved_at' => now(),
        ]);

        // 2. Majukan waktu sistem ke keesokan harinya (2026-09-18)
        Carbon::setTestNow(Carbon::parse('2026-09-18 08:30:00', 'Asia/Jakarta'));

        // 3. Cek User model helpers pada keesokan harinya
        $this->assertFalse($this->employee->hasApprovedWfhToday($tomorrowStr));
        $this->assertFalse($this->employee->canWfh());
        $this->assertFalse($this->employee->hasRadiusEnabled()); // Karyawan onsite: WFH OFF & Radius Lapangan OFF

        // 4. Cek endpoint polling status mobile pada keesokan harinya: wfh_enabled harus false, radius_enabled harus false
        Sanctum::actingAs($this->employee);
        $resStatus = $this->getJson('/api/v1/attendance/status');
        $resStatus->assertStatus(200);
        $resStatus->assertJson([
            'wfh_enabled'     => false,
            'radius_enabled'  => false,
            'is_wfh_approved' => false,
        ]);

        // 5. Cek endpoint listUsers dashboard HRD: is_wfh_approved_today harus false
        Sanctum::actingAs($this->hrd);
        $resUsers = $this->getJson('/api/v1/dashboard/attendance/users');
        $resUsers->assertStatus(200);
        $empUser = collect($resUsers->json('data'))->firstWhere('id', $this->employee->id);
        $this->assertFalse($empUser['is_wfh_approved_today']);

        // 6. Cek dashboard /today pada keesokan harinya: karyawan di not_checked_in dengan is_wfh = false (onsite)
        $resToday = $this->getJson('/api/v1/dashboard/attendance/today');
        $resToday->assertStatus(200);
        $empNotCheckedIn = collect($resToday->json('not_checked_in'))->firstWhere('user_id', $this->employee->id);
        $this->assertNotNull($empNotCheckedIn);
        $this->assertFalse($empNotCheckedIn['is_wfh']);
        $this->assertFalse($empNotCheckedIn['is_wfh_approved']);
    }

    public function test_radius_lapangan_cannot_be_on_when_wfh_is_off(): void
    {
        Sanctum::actingAs($this->hrd);

        $this->employee->update([
            'attendance_enabled' => true,
            'allow_attendance'   => true,
            'allow_wfh'          => true,
            'allow_radius'       => true,
        ]);

        // Karyawan saat ini WFH OFF: mencoba aktifkan radius lapangan langsung harus ditolak (422)
        $this->assertFalse($this->employee->wfh_enabled);
        $resToggleRadius = $this->postJson("/api/v1/dashboard/attendance/users/{$this->employee->id}/toggle-radius");
        $resToggleRadius->assertStatus(422);

        // Aktifkan WFH terlebih dahulu (Opsi A: default WFH murni bebas radius, radius_enabled tetap false)
        $this->postJson("/api/v1/dashboard/attendance/users/{$this->employee->id}/toggle-wfh")->assertStatus(200);
        $this->employee->refresh();
        $this->assertTrue($this->employee->wfh_enabled);
        $this->assertFalse($this->employee->radius_enabled);

        // Sekarang aktifkan radius lapangan (mode lapangan)
        $this->postJson("/api/v1/dashboard/attendance/users/{$this->employee->id}/toggle-radius")->assertStatus(200);
        $this->employee->refresh();
        $this->assertTrue($this->employee->radius_enabled);
        $this->assertTrue($this->employee->hasRadiusEnabled());

        // Matikan WFH: radius lapangan harus otomatis ikut OFF!
        $this->postJson("/api/v1/dashboard/attendance/users/{$this->employee->id}/toggle-wfh")->assertStatus(200);
        $this->employee->refresh();
        $this->assertFalse($this->employee->wfh_enabled);
        $this->assertFalse($this->employee->radius_enabled);
        $this->assertFalse($this->employee->hasRadiusEnabled());
    }

    public function test_karyawan_shift_lapangan_izin_wfh_bebas_radius_dan_kembali_lapangan_keesokan_hari(): void
    {
        // 1. Buat shift dengan hari Jumat & Sabtu adalah Lapangan (is_wfh = true, is_field = true)
        $shift = \App\Models\Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Lapangan Full',
            'is_active'             => true,
        ]);

        foreach ([0, 1, 2, 3, 4, 5, 6] as $dow) {
            \App\Models\ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'effective_date'  => '2026-09-01',
                'day_of_week'     => $dow,
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'is_off'          => false,
                'is_wfh'          => true,
                'is_field'        => true,
            ]);
        }

        // Assign shift ke employee mulai 2026-09-01
        \App\Models\UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shift->id,
            'start_date' => '2026-09-01',
        ]);

        $this->employee->update([
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        // 2. Karyawan mengajukan WFH khusus hari Jumat (2026-09-18) dan di-approve oleh HRD
        $fridayStr   = '2026-09-18';
        $saturdayStr = '2026-09-19';

        LeaveRequest::create([
            'company_id'  => $this->company->id,
            'user_id'     => $this->employee->id,
            'leave_type'  => 'wfh',
            'start_date'  => $fridayStr,
            'end_date'    => $fridayStr,
            'total_days'  => 1,
            'reason'      => 'Ada keperluan di rumah pada hari jadwal lapangan',
            'status'      => 'approved',
            'approved_by' => $this->hrd->id,
            'approved_at' => now(),
        ]);

        // 3. Pada hari Jumat (2026-09-18 07:55:00 WIB):
        Carbon::setTestNow(Carbon::parse('2026-09-18 07:55:00', 'Asia/Jakarta'));
        Sanctum::actingAs($this->employee);

        // Polling status mobile: WFH aktif, radius disabled karena ada WFH approved
        $resStatus = $this->getJson('/api/v1/attendance/status');
        $resStatus->assertStatus(200);
        $this->assertTrue($resStatus->json('wfh_enabled'));
        $this->assertFalse($resStatus->json('radius_enabled'));
        $this->assertTrue($resStatus->json('is_wfh_approved'));

        // Check-in dari rumah (jauh dari kantor, koordinat Bandung ~150km):
        $resCheckIn = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.9175,
            'longitude' => 107.6191,
        ], ['X-Platform' => 'mobile']);

        // HARUS BERHASIL (201 Created) dan check_in_type = 'wfh'
        $resCheckIn->assertCreated();
        $this->assertEquals('wfh', $resCheckIn->json('attendance.check_in_type'));

        // Checkout di sore hari
        Carbon::setTestNow(Carbon::parse('2026-09-18 17:05:00', 'Asia/Jakarta'));
        $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.9175,
            'longitude' => 107.6191,
        ], ['X-Platform' => 'mobile'])->assertOk();

        // 4. Keesokan harinya (Sabtu, 2026-09-19): Izin WFH berakhir, jadwal shift kembali Lapangan
        Carbon::setTestNow(Carbon::parse('2026-09-19 07:55:00', 'Asia/Jakarta'));

        // Polling status mobile: WFH aktif (karena shift lapangan), radius_enabled AKTIF kembali (true)!
        $resStatusTomorrow = $this->getJson('/api/v1/attendance/status');
        $resStatusTomorrow->assertStatus(200);
        $this->assertTrue($resStatusTomorrow->json('wfh_enabled'));
        $this->assertTrue($resStatusTomorrow->json('radius_enabled'));
        $this->assertFalse($resStatusTomorrow->json('is_wfh_approved'));

        // Coba check-in dari rumah (jauh dari kantor): HARUS DITOLAK (403) karena kembali ke mode lapangan!
        $checkInFar = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.9175,
            'longitude' => 107.6191,
        ], ['X-Platform' => 'mobile']);
        $checkInFar->assertStatus(403);
        $checkInFar->assertJsonStructure(['message', 'distance_meters', 'radius_meters', 'office_name']);

        // Check-in di area kantor (< 100m): HARUS BERHASIL dan check_in_type = 'field'
        $checkInNear = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20015000,
            'longitude' => 106.81666700,
        ], ['X-Platform' => 'mobile']);
        $checkInNear->assertCreated();
        $this->assertEquals('field', $checkInNear->json('attendance.check_in_type'));
    }
}

