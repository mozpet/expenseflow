<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ShiftController;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\ShiftPatternItem;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftRotationWfhFieldNightTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 07:00:00');

        $this->company = Company::create(['name' => 'PT Uji Rotasi', 'is_active' => true]);

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
            'wfh_checkin_window_minutes'  => 60,
        ]);

        $this->admin = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'admin',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        // Karyawan default: attendance_enabled = true, wfh_enabled = false (hanya presensi kantor jika hari normal)
        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Testing Karyawan Rotasi',
            'attendance_enabled'    => true,
            'wfh_enabled'           => false,
            'radius_enabled'        => false,
            'attendance_setting_id' => $this->office->id,
        ]);
    }

    private function createPatternKombinasi(): int
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Kombinasi 4 Hari',
            'attendance_setting_id' => $this->office->id,
            'cycle_days'            => 4,
            'is_active'             => true,
            'items'                 => [
                [
                    'day_order'       => 1,
                    'is_off'          => false,
                    'name'            => 'Hari Kantor (WFO)',
                    'work_start_time' => '08:00',
                    'work_end_time'   => '17:00',
                    'break_minutes'   => 60,
                    'is_wfh'          => false,
                    'is_field'        => false,
                ],
                [
                    'day_order'       => 2,
                    'is_off'          => false,
                    'name'            => 'Hari WFH',
                    'work_start_time' => '08:00',
                    'work_end_time'   => '17:00',
                    'break_minutes'   => 60,
                    'is_wfh'          => true,
                    'is_field'        => false,
                ],
                [
                    'day_order'       => 3,
                    'is_off'          => false,
                    'name'            => 'Hari Lapangan',
                    'work_start_time' => '08:00',
                    'work_end_time'   => '17:00',
                    'break_minutes'   => 60,
                    'is_wfh'          => true,
                    'is_field'        => true,
                ],
                [
                    'day_order'       => 4,
                    'is_off'          => true,
                    'name'            => 'Hari Libur Siklus',
                ],
            ],
        ]);

        return $response->json('data.id');
    }

    /**
     * TEST: Check-in mobile pada Hari Normal (WFO) vs Hari WFH.
     * Pertanyaan user: "jika ada hari yang tidak ada wfh nya normal, lalu ada hari yang ber wfh
     * (apakah nanti di hari tersebut otomatis wfh sendiri lalu hari lain otomatis tidak wfh)"
     */
    public function test_perilaku_presensi_hari_normal_vs_hari_wfh(): void
    {
        $patternId = $this->createPatternKombinasi();

        // Assign ke employee mulai 2026-09-01
        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $patternId,
            'start_date'       => '2026-09-01',
            'anchor_day_order' => 1,
        ])->assertCreated();

        // ── KASUS A: Hari ke-1 (2026-09-01) - Normal WFO ──
        // Karyawan wfh_enabled=false di database.
        // Coba check-in mobile di hari normal WFO:
        Carbon::setTestNow('2026-09-01 07:55:00');
        Sanctum::actingAs($this->employee);

        $checkInWfo = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20000000,
            'longitude' => 106.81666700,
        ], ['X-Platform' => 'mobile']);

        // Harusnya DITOLAK karena hari ini WFO normal dan presensi mobile hanya untuk WFH/lapangan!
        $checkInWfo->assertStatus(403);
        $checkInWfo->assertJsonFragment([
            'message' => 'Presensi aplikasi hanya untuk karyawan WFH atau lapangan. Presensi di kantor dilakukan melalui perangkat presensi.',
        ]);

        // ── KASUS B: Hari ke-2 (2026-09-02) - WFH Otomatis ──
        // Tanggal beralih ke 2026-09-02 (Hari ke-2 siklus = WFH).
        // Tanpa HRD mengubah wfh_enabled karyawan secara manual,
        // karyawan mencoba check-in dari rumah (koordinat jauh dari kantor):
        Carbon::setTestNow('2026-09-02 07:55:00');

        $checkInWfh = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.9175, // Bandung (~150km dari kantor Jakarta)
            'longitude' => 107.6191,
        ], ['X-Platform' => 'mobile']);

        // Harusnya BERHASIL karena hari ini adalah hari WFH terjadwal dari pola rotasi!
        $checkInWfh->assertCreated();
        $this->assertEquals('wfh', $checkInWfh->json('attendance.check_in_type'));
        $this->assertEquals('present', $checkInWfh->json('attendance.status'));

        // Checkout di sore hari
        Carbon::setTestNow('2026-09-02 17:05:00');
        $checkOutWfh = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.9175,
            'longitude' => 107.6191,
        ], ['X-Platform' => 'mobile']);

        $checkOutWfh->assertOk();
        $this->assertEquals('wfh', $checkOutWfh->json('attendance.check_out_type'));
    }

    /**
     * TEST: Lapangan diaktifkan untuk 1 hari dalam siklus rotasi (Hari ke-3).
     * Pertanyaan user: "bagian jika lapangan di aktifkan untuk 1 hari saya apakah berjalan dengan baik"
     */
    public function test_perilaku_lapangan_satu_hari(): void
    {
        $patternId = $this->createPatternKombinasi();

        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $patternId,
            'start_date'       => '2026-09-01',
            'anchor_day_order' => 1,
        ])->assertCreated();

        // Hari ke-3 (2026-09-03) = Lapangan (is_wfh = true, is_field = true)
        Carbon::setTestNow('2026-09-03 07:55:00');
        Sanctum::actingAs($this->employee);

        // Kasus 1: Check-in di luar radius area kantor (> 100 meter)
        $checkInFar = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.21000000, // ~1.1 km dari kantor
            'longitude' => 106.81666700,
        ], ['X-Platform' => 'mobile']);

        // Wajib ditolak karena mode lapangan memerlukan verifikasi GPS radius kantor
        $checkInFar->assertStatus(403);
        $checkInFar->assertJsonStructure(['message', 'distance_meters', 'radius_meters', 'office_name']);

        // Kasus 2: Check-in di dalam radius area kantor (misal jarak 20 meter)
        $checkInNear = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20015000, // ~16 meter dari kantor
            'longitude' => 106.81666700,
        ], ['X-Platform' => 'mobile']);

        $checkInNear->assertCreated();
        // Mari kita periksa check_in_type yang tersimpan
        $checkInType = $checkInNear->json('attendance.check_in_type');
        dump("Check-in type pada hari lapangan terjadwal: " . $checkInType);
        $this->assertEquals('field', $checkInType, "Hari lapangan harus menghasilkan check_in_type = 'field', bukan 'onsite'");
    }

    /**
     * TEST: Pola Rotasi dengan Shift Malam (Lintas Hari / Cross-Day).
     * Pertanyaan user: "bagaimana jika di kombinasikan dengan shif malem apa yang akan terjadi coba kita lakukan test itu"
     *
     * Pola 3 Hari:
     * H1: Shift Malam (21:00 - 05:00 esok hari), is_wfh = true, is_field = false
     * H2: Libur Pasca Malam (is_off = true)
     * H3: Shift Normal Siang (13:00 - 21:00)
     */
    public function test_pola_rotasi_kombinasi_shift_malam(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat Pola dengan Shift Malam
        $resp = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola 3 Hari Shift Malam',
            'attendance_setting_id' => $this->office->id,
            'cycle_days'            => 3,
            'is_active'             => true,
            'items'                 => [
                [
                    'day_order'       => 1,
                    'is_off'          => false,
                    'name'            => 'Shift Malam',
                    'work_start_time' => '21:00',
                    'work_end_time'   => '05:00',
                    'break_minutes'   => 60,
                    'is_cross_day'    => true,
                    'is_wfh'          => true,
                    'is_field'        => false,
                ],
                [
                    'day_order'       => 2,
                    'is_off'          => true,
                    'name'            => 'Libur Recovery',
                ],
                [
                    'day_order'       => 3,
                    'is_off'          => false,
                    'name'            => 'Shift Siang',
                    'work_start_time' => '13:00',
                    'work_end_time'   => '21:00',
                    'break_minutes'   => 60,
                    'is_cross_day'    => false,
                    'is_wfh'          => true,
                    'is_field'        => false,
                ],
            ],
        ]);

        $resp->assertCreated();
        $patternId = $resp->json('data.id');

        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $patternId,
            'start_date'       => '2026-09-01',
            'anchor_day_order' => 1,
        ])->assertCreated();

        // 1. Check-in Shift Malam di H1 (2026-09-01 20:55 WIB)
        Carbon::setTestNow('2026-09-01 20:55:00');
        Sanctum::actingAs($this->employee);

        $checkInNight = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20000000,
            'longitude' => 106.81666700,
        ], ['X-Platform' => 'mobile']);

        $checkInNight->assertCreated();
        $this->assertEquals('2026-09-01', Carbon::parse($checkInNight->json('attendance.date'))->setTimezone('Asia/Jakarta')->toDateString());

        // 2. Check-out di pagi hari H2 (2026-09-02 05:05 WIB)
        Carbon::setTestNow('2026-09-02 05:05:00');

        $checkOutNight = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20000000,
            'longitude' => 106.81666700,
        ], ['X-Platform' => 'mobile']);

        $checkOutNight->assertOk();
        $this->assertEquals('2026-09-01', Carbon::parse($checkOutNight->json('attendance.date'))->setTimezone('Asia/Jakarta')->toDateString());
        // Jam kerja kotor: 21:00 -> 05:05 = 8 jam 5 menit (485 menit). Potong istirahat 60 mnt = 425 menit.
        $this->assertEquals(425, $checkOutNight->json('attendance.work_minutes'));
        // Overtime 0 menit karena selisih 5 menit belum memenuhi batas min_overtime_minutes (default 30 menit)
        $this->assertEquals(0, $checkOutNight->json('attendance.overtime_minutes'));

        // 3. Tes K3 Guard: Apa yang terjadi jika HRD mencoba membuat Shift Malam yang langsung disusul Shift Pagi keesokan harinya?
        // Misal: H1 Shift Malam (21:00 - 05:00), H2 Shift Pagi (07:00 - 15:00).
        // Jeda istirahat hanya 2 jam (05:00 s/d 07:00). Standar K3 mewajibkan minimal 8 jam!
        Sanctum::actingAs($this->admin);
        $unsafePatternResp = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Bahaya K3',
            'attendance_setting_id' => $this->office->id,
            'cycle_days'            => 3,
            'is_active'             => true,
            'items'                 => [
                [
                    'day_order'       => 1,
                    'is_off'          => false,
                    'work_start_time' => '21:00',
                    'work_end_time'   => '05:00',
                    'is_cross_day'    => true,
                ],
                [
                    'day_order'       => 2,
                    'is_off'          => false,
                    'work_start_time' => '07:00',
                    'work_end_time'   => '15:00',
                ],
                [
                    'day_order'       => 3,
                    'is_off'          => true,
                    'name'            => 'Hari Libur',
                ],
            ],
        ]);

        // Harus DITOLAK oleh validasi K3 dengan kode 422!
        $unsafePatternResp->assertStatus(422);
        dump("Pesan penolakan K3 shift malam tanpa jeda cukup: " . $unsafePatternResp->json('message'));
        $this->assertStringContainsString('minimum wajib 8 jam K3', $unsafePatternResp->json('message'));
    }

    /**
     * TEST: resolveSchedulesBulk (dipakai oleh Dashboard HRD "Presensi Hari Ini" / today).
     * Bug Check: Apakah resolveSchedulesBulk mengenali pola rotasi karyawan?
     */
    public function test_resolve_schedules_bulk_mengenali_pola_rotasi(): void
    {
        $patternId = $this->createPatternKombinasi();

        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $patternId,
            'start_date'       => '2026-09-01',
            'anchor_day_order' => 1,
        ])->assertCreated();

        // Hari ke-4 (2026-09-04) adalah hari LIBUR Pola Rotasi (is_off = true).
        // Tapi hari Jumat secara jadwal kantor adalah hari KERJA normal.
        $bulk = ShiftController::resolveSchedulesBulk(collect([$this->employee]), '2026-09-04');

        $empSched = $bulk[$this->employee->id] ?? null;
        $this->assertNotNull($empSched, 'Karyawan harus memiliki entri jadwal di resolveSchedulesBulk');
        dump("resolveSchedulesBulk result for employee on rotation off day: ", $empSched);
        $this->assertEquals('shift', $empSched['source'], "Sumber jadwal harus 'shift' (dari pola rotasi)");
        $this->assertTrue($empSched['is_off'], "Hari ke-4 pola rotasi adalah LIBUR, resolveSchedulesBulk harus menghasilkan is_off = true");
    }

    /**
     * TEST: Roster endpoint (/shifts/roster) menampilkan flag is_wfh dan is_field untuk Pola Rotasi.
     */
    public function test_roster_menampilkan_flag_wfh_dan_lapangan_pola_rotasi(): void
    {
        Sanctum::actingAs($this->admin);
        $patternId = $this->createPatternKombinasi();

        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $patternId,
            'start_date'       => '2026-09-01',
            'anchor_day_order' => 1,
        ])->assertCreated();

        // Cek Roster pada Hari ke-2 (2026-09-02) - WFH
        $rosterH2 = $this->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-02');
        $rosterH2->assertOk();
        $userRowH2 = collect($rosterH2->json('data'))->firstWhere('user_id', $this->employee->id);
        dump("Roster H2 (WFH): ", $userRowH2);
        $this->assertTrue($userRowH2['is_wfh'], "Roster harus menampilkan is_wfh = true pada hari WFH");
        $this->assertFalse($userRowH2['is_field'], "Roster harus menampilkan is_field = false pada hari WFH murni");

        // Cek Roster pada Hari ke-3 (2026-09-03) - Lapangan
        $rosterH3 = $this->getJson('/api/v1/dashboard/attendance/shifts/roster?date=2026-09-03');
        $rosterH3->assertOk();
        $userRowH3 = collect($rosterH3->json('data'))->firstWhere('user_id', $this->employee->id);
        dump("Roster H3 (Lapangan): ", $userRowH3);
        $this->assertTrue($userRowH3['is_wfh'], "Roster harus menampilkan is_wfh = true pada hari Lapangan");
        $this->assertTrue($userRowH3['is_field'], "Roster harus menampilkan is_field = true pada hari Lapangan");
    }

    /**
     * TEST: Endpoint /attendance/status dan /attendance/my-schedule otomatis menyesuaikan WFH & Lapangan
     * tanpa menunggu sinkronisasi cron jam 00:01.
     */
    public function test_status_dan_my_schedule_otomatis_wfh_dan_lapangan(): void
    {
        $patternId = $this->createPatternKombinasi();

        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employee->id,
            'shift_pattern_id' => $patternId,
            'start_date'       => '2026-09-01',
            'anchor_day_order' => 1,
        ])->assertCreated();

        Sanctum::actingAs($this->employee);

        // ── Hari ke-1: WFO Normal ──
        Carbon::setTestNow('2026-09-01 08:00:00');
        $statusH1 = $this->getJson('/api/v1/attendance/status');
        $statusH1->assertOk();
        $this->assertFalse($statusH1->json('wfh_enabled'), 'H1 Normal: wfh_enabled harus false');

        // ── Hari ke-2: WFH Terjadwal Pola ──
        Carbon::setTestNow('2026-09-02 08:00:00');
        $statusH2 = $this->getJson('/api/v1/attendance/status');
        $statusH2->assertOk();
        $this->assertTrue($statusH2->json('wfh_enabled'), 'H2 WFH: wfh_enabled otomatis true');
        $this->assertFalse($statusH2->json('radius_enabled'), 'H2 WFH murni: radius_enabled harus false');

        $mySchedH2 = $this->getJson('/api/v1/employee/my-schedule');
        $mySchedH2->assertOk();
        $this->assertTrue($mySchedH2->json('today.is_wfh'), 'H2 my-schedule today.is_wfh harus true');
        $this->assertFalse($mySchedH2->json('today.is_field'), 'H2 my-schedule today.is_field harus false');

        // ── Hari ke-3: Lapangan Terjadwal Pola ──
        Carbon::setTestNow('2026-09-03 08:00:00');
        $statusH3 = $this->getJson('/api/v1/attendance/status');
        $statusH3->assertOk();
        $this->assertTrue($statusH3->json('wfh_enabled'), 'H3 Lapangan: wfh_enabled otomatis true');
        $this->assertTrue($statusH3->json('radius_enabled'), 'H3 Lapangan: radius_enabled otomatis true');

        $mySchedH3 = $this->getJson('/api/v1/employee/my-schedule');
        $mySchedH3->assertOk();
        $this->assertTrue($mySchedH3->json('today.is_wfh'), 'H3 my-schedule today.is_wfh harus true');
        $this->assertTrue($mySchedH3->json('today.is_field'), 'H3 my-schedule today.is_field harus true');
    }
}
