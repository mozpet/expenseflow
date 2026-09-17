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

class AttendanceTimezoneAndReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Maju Bersama',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'                   => $this->company->id,
            'office_name'                  => 'Kantor Pusat Jakarta',
            'office_latitude'              => -6.2000,
            'office_longitude'             => 106.8166,
            'radius_meters'                => 100,
            'work_start_time'              => '08:00:00',
            'work_end_time'                => '17:00:00',
            'break_minutes'                => 60,
            'late_tolerance_minutes'       => 15,
            'late_checkin_cutoff_minutes'  => 120, // 2 jam setelah jam masuk
            'wfh_checkin_window_minutes'   => null, // Bebas waktu awal WFH untuk pengujian dini hari
            'min_overtime_minutes'         => 30,
            'overtime_enabled'             => true,
            'min_checkout_interval_minutes'=> 10,
            'is_active'                    => true,
        ]);

        $this->admin = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'admin',
            'is_active'             => true,
            'name'                  => 'Admin HRD',
            'email'                 => 'admin@majubersama.co.id',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset frozen time
        parent::tearDown();
    }

    private function createEmployee(string $name = 'Budi Santoso', bool $wfh = true, bool $radius = false): User
    {
        return User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_enabled'    => true,
            'wfh_enabled'           => $wfh,
            'radius_enabled'        => $radius,
            'name'                  => $name,
            'department'            => 'Operasional',
        ]);
    }

    private function token(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('test-token')->plainTextToken];
    }

    // ─── 1. Uji Dini Hari (Anti-UTC bug pada 00:00-06:59 WIB) ─────────────────
    public function test_checkin_dini_hari_wib_tercatat_pada_tanggal_hari_ini(): void
    {
        // Pukul 01:30 WIB pada tanggal 16 September 2026
        // Dalam UTC ini adalah 15 September 2026 pukul 18:30 UTC
        $timeWib = Carbon::parse('2026-09-16 01:30:00', 'Asia/Jakarta');
        Carbon::setTestNow($timeWib);

        $employee = $this->createEmployee();

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $response->assertCreated();

        // Verifikasi database: tanggal wajib '2026-09-16', BUKAN '2026-09-15'
        $this->assertDatabaseHas('attendances', [
            'user_id' => $employee->id,
            'date'    => '2026-09-16',
        ]);

        $att = Attendance::where('user_id', $employee->id)->whereDate('date', '2026-09-16')->first();
        $this->assertNotNull($att);
        $this->assertEquals('2026-09-16 01:30:00', Carbon::parse($att->check_in_time)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
    }

    // ─── 2. Uji Datang Lebih Awal (07:30 WIB vs Jadwal 08:00 WIB) ───────────
    public function test_checkin_datang_lebih_awal_jam_kerja_dihitung_dari_jadwal(): void
    {
        // Check-in jam 07:30 WIB (30 menit sebelum jadwal masuk 08:00)
        Carbon::setTestNow(Carbon::parse('2026-09-16 07:30:00', 'Asia/Jakarta'));
        $employee = $this->createEmployee();

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $response->assertCreated()
            ->assertJsonPath('attendance.status', 'present');

        // Check-out tepat jam 17:00 WIB (jadwal pulang)
        // Dari 08:00 ke 17:00 = 540 menit, dikurangi 60 menit istirahat UU = 480 menit jam kerja efektif.
        Carbon::setTestNow(Carbon::parse('2026-09-16 17:00:00', 'Asia/Jakarta'));

        $resCheckout = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $resCheckout->assertOk();

        $att = Attendance::where('user_id', $employee->id)->whereDate('date', '2026-09-16')->first();
        $this->assertEquals(480, $att->work_minutes, 'Jam kerja harus dihitung dari jadwal 08:00 (540 - 60 istirahat = 480 menit), bukan check-in awal');
        $this->assertEquals(0, $att->overtime_minutes);
    }

    // ─── 3. Uji Dalam Batas Toleransi (08:14:59 WIB) → Status Present ────────
    public function test_checkin_dalam_batas_toleransi_status_present(): void
    {
        // Masuk 08:00, toleransi 15 menit → batas sampai 08:15:00
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:14:50', 'Asia/Jakarta'));
        $employee = $this->createEmployee();

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $response->assertCreated()
            ->assertJsonPath('attendance.status', 'present');
    }

    // ─── 4. Uji Terlambat (08:16:00 WIB) → Status Late ─────────────────────
    public function test_checkin_lewat_batas_toleransi_status_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:16:00', 'Asia/Jakarta'));
        $employee = $this->createEmployee();

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $response->assertCreated()
            ->assertJsonPath('attendance.status', 'late');
    }

    // ─── 5. Uji Lewat Batas Cutoff (10:05:00 WIB) → Ditolak 403 ────────────
    public function test_checkin_lewat_batas_cutoff_ditolak(): void
    {
        // Jam masuk 08:00 + late_checkin_cutoff_minutes 120 = cutoff 10:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:05:00', 'Asia/Jakarta'));
        $employee = $this->createEmployee();

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $response->assertStatus(403)
            ->assertJsonPath('cutoff_at', '10:00');
    }

    // ─── 6. Uji Checkout Lembur (17:45:00 WIB) ──────────────────────────────
    public function test_checkout_lembur_terhitung_dan_buat_approval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'Asia/Jakarta'));
        $employee = $this->createEmployee();

        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee))->assertCreated();

        // Checkout 17:45 WIB (lewat 45 menit dari jam pulang 17:00, >= min_overtime 30 menit)
        Carbon::setTestNow(Carbon::parse('2026-09-16 17:45:00', 'Asia/Jakarta'));

        $response = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $response->assertOk()
            ->assertJsonPath('attendance.overtime_minutes', 45);

        $this->assertDatabaseHas('overtime_approvals', [
            'user_id'          => $employee->id,
            'overtime_minutes' => 45,
            'status'           => 'pending',
        ]);
    }

    // ─── 7. Uji Shift Malam Lintas Hari (Cross-day: 22:00 -> 06:00 Besok) ───
    public function test_shift_malam_lintas_hari_checkout_keesokan_harinya_tercatat_satu_baris(): void
    {
        $shiftMalam = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam CrossDay',
            'color'                 => '#8b5cf6',
            'is_active'             => true,
        ]);

        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shiftMalam->id,
                'day_of_week'     => $d,
                'work_start_time' => '22:00',
                'work_end_time'   => '06:00',
                'is_off'          => false,
                'is_cross_day'    => true,
                'effective_date'  => '2026-01-01',
            ]);
        }

        $employee = $this->createEmployee('Agus Shift Malam');

        UserShift::create([
            'user_id'    => $employee->id,
            'shift_id'   => $shiftMalam->id,
            'start_date' => '2026-09-01',
            'end_date'   => null,
        ]);

        // Check-in tanggal 16 Sept jam 22:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-09-16 22:00:00', 'Asia/Jakarta'));
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee))->assertCreated();

        // Check-out keesokan harinya: 17 Sept jam 06:05 WIB
        Carbon::setTestNow(Carbon::parse('2026-09-17 06:05:00', 'Asia/Jakarta'));
        $resCheckout = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee));

        $resCheckout->assertOk();

        // Verifikasi bahwa record tetap HANYA 1 pada tanggal 16 Sept
        $records = Attendance::where('user_id', $employee->id)->get();
        $this->assertCount(1, $records);

        $att = $records->first();
        $this->assertEquals('2026-09-16', Carbon::parse($att->date)->toDateString());
        $this->assertNotNull($att->check_out_time);
        $this->assertEquals('2026-09-17 06:05:00', Carbon::parse($att->check_out_time)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
    }

    // ─── 8. Uji Laporan Presensi Saat Dini Hari (Report Attendance Timezone) ──
    public function test_laporan_presensi_saat_dini_hari_menggunakan_tanggal_wib(): void
    {
        // Jam 02:00 WIB tanggal 16 Sept (di mana UTC masih 15 Sept 19:00)
        Carbon::setTestNow(Carbon::parse('2026-09-16 02:00:00', 'Asia/Jakarta'));

        $employee = $this->createEmployee('Rina Operasional');

        // Employee check-in pada jam 02:00 WIB
        $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20,
            'longitude' => 106.81,
        ], $this->token($employee))->assertCreated();

        // Panggil endpoint laporan tanpa parameter start_date & end_date
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/report');

        $response->assertOk();

        // Pastikan laporan mengembalikan data dengan tanggal 2026-09-16
        $data = $response->json('report.data');
        $found = collect($data)->firstWhere('user_id', $employee->id);

        $this->assertNotNull($found, 'Data karyawan harus muncul dalam laporan presensi hari ini');
        $this->assertEquals('2026-09-16', $found['date']);
        $this->assertEquals('present', $found['status']);
    }

    // ─── 9. Uji Rekap Bulanan (Monthly Summary Timezone) ──────────────────────
    public function test_rekap_bulanan_dini_hari_tidak_meleset_bulan(): void
    {
        // 1 Oktober 2026 jam 01:00 WIB (di mana UTC masih 30 September 18:00)
        Carbon::setTestNow(Carbon::parse('2026-10-01 01:00:00', 'Asia/Jakarta'));

        $employee = $this->createEmployee('Siti Bulanan');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/summary?user_id={$employee->id}");

        $response->assertOk()
            ->assertJsonPath('period.month', 10)
            ->assertJsonPath('period.year', 2026);
    }
}
