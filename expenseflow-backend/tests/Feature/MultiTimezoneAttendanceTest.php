<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRESENSI MULTI-ZONA (WIB / WITA / WIT) — 2026-10-08.
 *
 * Dua aturan yang diuji di sini adalah fondasi seluruh fitur:
 *
 *   1. ZONA BISNIS = zona kantor cabang PENEMPATAN karyawan. Jadwal kerja,
 *      status telat, dan attendances.date SELALU dihitung di zona itu. Zona
 *      lokasi fisik karyawan (diturunkan dari GPS) hanya untuk tampilan.
 *
 *   2. ZONA PENYIMPANAN tetap WIB selamanya. Kolom datetime menyimpan jam
 *      dinding WIB berapa pun zona cabangnya, sehingga data historis tidak
 *      pernah diinterpretasi ulang. Assertion string MENTAH dari DB
 *      (DB::table()->value()) yang menjaga aturan ini — bukan accessor model,
 *      karena accessor justru menyembunyikan pergeseran yang ingin dideteksi.
 *
 * Non-regresi: cabang dengan timezone NULL dan presensi dengan snap_timezone
 * NULL harus berperilaku identik dengan sebelum fitur ini ada.
 */
class MultiTimezoneAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const WIB  = 'Asia/Jakarta';
    private const WITA = 'Asia/Makassar';
    private const WIT  = 'Asia/Jayapura';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Nusantara Lintas Zona',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────── Helper ───────────────────────────────

    /**
     * Kantor cabang dengan jam kerja 08:00–17:00 LOKAL cabang.
     *
     * wfh_checkin_window_minutes = null agar uji dini hari tidak tertolak oleh
     * jendela WFH; late_checkin_cutoff_minutes dibuat lebar (240) supaya yang
     * diuji benar-benar status telat, bukan gerbang cutoff.
     */
    private function office(string $name, float $lat, float $lng, ?string $tz): AttendanceSetting
    {
        return AttendanceSetting::create([
            'company_id'                    => $this->company->id,
            'office_name'                   => $name,
            'office_latitude'               => $lat,
            'office_longitude'              => $lng,
            'timezone'                      => $tz,
            'radius_meters'                 => 200,
            'work_start_time'               => '08:00:00',
            'work_end_time'                 => '17:00:00',
            'break_minutes'                 => 60,
            'late_tolerance_minutes'        => 15,
            'late_checkin_cutoff_minutes'   => 240,
            'wfh_checkin_window_minutes'    => null,
            'min_overtime_minutes'          => 30,
            'overtime_enabled'              => true,
            'min_checkout_interval_minutes' => 10,
            'is_active'                     => true,
        ]);
    }

    private function jakartaOffice(?string $tz = self::WIB): AttendanceSetting
    {
        return $this->office('Kantor Pusat Jakarta', -6.2000, 106.8166, $tz);
    }

    private function makassarOffice(): AttendanceSetting
    {
        return $this->office('Cabang Makassar', -5.1477, 119.4327, self::WITA);
    }

    private function jayapuraOffice(): AttendanceSetting
    {
        return $this->office('Cabang Jayapura', -2.5330, 140.7000, self::WIT);
    }

    private function employee(AttendanceSetting $office, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => true,
            'department'            => 'Operasional',
        ], $overrides));
    }

    private function hrd(AttendanceSetting $office): User
    {
        return User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $office->id,
            'role'                  => 'hrd',
            'is_active'             => true,
        ]);
    }

    private function checkIn(User $user, float $lat, float $lng, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', array_merge([
            'latitude'  => $lat,
            'longitude' => $lng,
        ], $extra));
    }

    private function checkOut(User $user, float $lat, float $lng)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude'  => $lat,
            'longitude' => $lng,
        ]);
    }

    /** String MENTAH dari DB — melewati cast model, agar pergeseran zona terlihat. */
    private function rawColumn(int $attendanceId, string $column): ?string
    {
        return DB::table('attendances')->where('id', $attendanceId)->value($column);
    }

    // ─── 1. Saran zona dari koordinat (termasuk kotak pengecualian) ───────────

    public function test_zona_cabang_disarankan_dari_koordinat(): void
    {
        $hrd = $this->hrd($this->jakartaOffice());

        // [koordinat, zona yang diharapkan, sumber, keterangan]
        $kasus = [
            [-5.1477, 119.4327, self::WITA, 'Makassar (Sulawesi)'],
            [-8.6500, 115.2200, self::WITA, 'Denpasar — 115°E tapi WITA, uji kotak Bali'],
            [-3.7000, 128.1800, self::WIT,  'Ambon — 128°E tapi WIT, uji kotak Maluku'],
            [-0.0200, 109.3300, self::WIB,  'Pontianak (Kalimantan Barat)'],
            [-2.5330, 140.7000, self::WIT,  'Jayapura (Papua)'],
        ];

        foreach ($kasus as [$lat, $lng, $expected, $label]) {
            $res = $this->actingAs($hrd, 'sanctum')->getJson(
                "/api/v1/dashboard/attendance/timezones?latitude={$lat}&longitude={$lng}"
            );

            $res->assertOk()->assertJsonPath('suggested.timezone', $expected);

            // Tepat SATU entri katalog yang ditandai, dan entri itu yang disarankan.
            $ditandai = array_values(array_filter(
                $res->json('timezones'),
                fn ($t) => $t['is_suggested'] === true
            ));
            $this->assertCount(1, $ditandai, "Harus tepat 1 saran untuk {$label}.");
            $this->assertSame($expected, $ditandai[0]['id'], "Saran salah untuk {$label}.");
        }

        // Tanpa koordinat: katalog tetap lengkap, tetapi TIDAK ada saran ngawur.
        $tanpaKoordinat = $this->actingAs($hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/timezones');

        $tanpaKoordinat->assertOk()
            ->assertJsonPath('suggested', null)
            ->assertJsonPath('storage', self::WIB)
            ->assertJsonCount(3, 'timezones');
    }

    // ─── 2. Auto-detect saat membuat kantor, override manual tetap menang ────

    public function test_store_kantor_tanpa_timezone_terisi_otomatis_dan_override_manual_menang(): void
    {
        $hrd = $this->hrd($this->jakartaOffice());

        // settingRules() menandai work_days & default_leave_quota sebagai required
        // pada store — keduanya wajib ada agar yang diuji benar-benar perilaku
        // timezone, bukan kelengkapan payload.
        $payloadDasar = [
            'office_name'         => 'Cabang Baru',
            'office_latitude'     => -5.1477,
            'office_longitude'    => 119.4327,
            'radius_meters'       => 150,
            'work_start_time'     => '08:00',
            'work_end_time'       => '17:00',
            'work_days'           => [1, 2, 3, 4, 5],
            'default_leave_quota' => 12,
        ];

        // (a) Tanpa field timezone → terisi dari koordinat (Makassar → WITA).
        $auto = $this->actingAs($hrd, 'sanctum')
            ->postJson('/api/v1/dashboard/attendance/settings', $payloadDasar);

        $auto->assertCreated()
            ->assertJsonPath('setting.timezone', self::WITA)
            // Accessor turunan ikut terkirim agar frontend tak perlu memetakan sendiri.
            ->assertJsonPath('setting.timezone_label', 'WITA')
            ->assertJsonPath('setting.timezone_offset_minutes', 480);

        $this->assertDatabaseHas('attendance_settings', [
            'id'       => $auto->json('setting.id'),
            'timezone' => self::WITA,
        ]);

        // (b) Override manual menang atas hasil deteksi — inilah alasan override
        //     ada: koordinat tidak selalu menentukan zona administratif.
        $manual = $this->actingAs($hrd, 'sanctum')->postJson(
            '/api/v1/dashboard/attendance/settings',
            array_merge($payloadDasar, [
                'office_name' => 'Cabang Override',
                'timezone'    => self::WIT,
            ])
        );

        $manual->assertCreated()->assertJsonPath('setting.timezone', self::WIT);
        $this->assertDatabaseHas('attendance_settings', [
            'id'       => $manual->json('setting.id'),
            'timezone' => self::WIT,
        ]);

        // (c) Zona di luar katalog ditolak — bukan diam-diam di-fallback ke WIB.
        $this->actingAs($hrd, 'sanctum')->postJson(
            '/api/v1/dashboard/attendance/settings',
            array_merge($payloadDasar, ['office_name' => 'Cabang Ngawur', 'timezone' => 'Europe/London'])
        )->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    // ─── 3. Status telat dinilai di jam dinding cabang ───────────────────────

    public function test_checkin_cabang_wita_dinilai_dengan_jam_wita(): void
    {
        $office   = $this->makassarOffice();
        $employee = $this->employee($office);

        // 08:20 WITA = 07:20 WIB. Jam masuk 08:00 + toleransi 15 menit.
        // Bila dinilai dengan jam dinding WIB (07:20) ia justru "datang awal";
        // dinilai benar di zona cabang ia TELAT 20 menit.
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:20:00', self::WITA));

        $res = $this->checkIn($employee, -5.1477, 119.4327);
        $res->assertCreated()->assertJsonPath('attendance.status', 'late');

        $attId = $res->json('attendance.id');

        // Tanggal presensi = tanggal lokal cabang.
        $this->assertDatabaseHas('attendances', ['id' => $attId, 'date' => '2026-10-07']);

        // ATURAN PENYIMPANAN: kolom berisi jam dinding WIB, bukan 08:20 WITA.
        $this->assertSame(
            '2026-10-07 07:20:00',
            $this->rawColumn($attId, 'check_in_time'),
            'check_in_time wajib tersimpan sebagai jam dinding WIB (07:20), bukan jam WITA (08:20).'
        );

        // Snapshot membekukan zona cabang pada saat check-in.
        $this->assertSame(self::WITA, $this->rawColumn($attId, 'snap_timezone'));

        // Objek timezone: satu zona saja → tidak lintas zona, display tanpa kurung.
        $res->assertJsonPath('timezone.branch.label', 'WITA')
            ->assertJsonPath('timezone.branch_time', '08:20')
            ->assertJsonPath('timezone.is_cross_timezone', false)
            ->assertJsonPath('timezone.display', '08:20 WITA');
    }

    // ─── 4. Dini hari di cabang timur: tanggal lokal cabang, bukan tanggal WIB ─

    public function test_checkin_dini_hari_wit_tercatat_pada_tanggal_lokal_cabang(): void
    {
        $office   = $this->jayapuraOffice();
        $employee = $this->employee($office);

        // 00:30 WIT tanggal 8 = 22:30 WIB tanggal 7. Tanggal presensi HARUS
        // mengikuti kalender cabang (8), bukan kalender zona penyimpanan (7).
        Carbon::setTestNow(Carbon::parse('2026-10-08 00:30:00', self::WIT));

        $res = $this->checkIn($employee, -2.5330, 140.7000);
        $res->assertCreated();

        $attId = $res->json('attendance.id');

        $this->assertDatabaseHas('attendances', ['id' => $attId, 'date' => '2026-10-08']);
        $this->assertDatabaseMissing('attendances', ['id' => $attId, 'date' => '2026-10-07']);

        // Jam dinding WIB-nya memang masih tanggal 7 — justru itu buktinya bahwa
        // `date` diturunkan terpisah dari kolom datetime.
        $this->assertSame('2026-10-07 22:30:00', $this->rawColumn($attId, 'check_in_time'));
    }

    // ─── 5. Dinas lintas zona: zona cabang tetap otoritatif ─────────────────

    public function test_karyawan_dinas_di_papua_dinilai_dengan_jam_cabang_jakarta(): void
    {
        $office   = $this->jakartaOffice();
        $employee = $this->employee($office, [
            'dinas_luar_enabled' => true,
            'radius_enabled'     => false,
        ]);

        // Karyawan cabang Jakarta sedang dinas di Jayapura.
        // 10:20 WIT = 08:20 WIB → terhadap jam masuk cabang 08:00 + toleransi 15
        // ia TELAT. Jam lokasinya (10:20) tidak boleh dipakai menilai.
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:20:00', self::WIT));

        $res = $this->checkIn($employee, -2.5330, 140.7000);
        $res->assertCreated()->assertJsonPath('attendance.status', 'late');

        $attId = $res->json('attendance.id');

        // Tanggal & jam simpan mengikuti zona cabang / zona penyimpanan.
        $this->assertDatabaseHas('attendances', ['id' => $attId, 'date' => '2026-10-07']);
        $this->assertSame('2026-10-07 08:20:00', $this->rawColumn($attId, 'check_in_time'));

        // Zona cabang yang dibekukan = cabang PENEMPATAN (Jakarta), bukan zona
        // lokasi fisik — walaupun GPS-nya di Papua.
        $this->assertSame(self::WIB, $this->rawColumn($attId, 'snap_timezone'));

        // Zona lokasi fisik diturunkan server dari GPS, untuk tampilan & audit.
        $this->assertSame(self::WIT, $this->rawColumn($attId, 'check_in_local_timezone'));

        $res->assertJsonPath('timezone.branch.label', 'WIB')
            ->assertJsonPath('timezone.local.label', 'WIT')
            ->assertJsonPath('timezone.is_cross_timezone', true)
            ->assertJsonPath('timezone.branch_time', '08:20')
            ->assertJsonPath('timezone.local_time', '10:20')
            ->assertJsonPath('timezone.display', '08:20 WIB (10:20 WIT lokasi Anda)');
    }

    // ─── 5b. Sebaliknya: Karyawan fisik di Jakarta (WIB) dinilai jam cabang Jayapura (WIT) ─

    public function test_karyawan_di_jakarta_dinilai_dengan_jam_cabang_jayapura(): void
    {
        $office   = $this->jayapuraOffice(); // Jam kantor 08:00 - 17:00 WIT, toleransi 15 menit
        $employee = $this->employee($office, [
            'wfh_enabled'    => true,
            'radius_enabled' => false,
        ]);

        // Kasus B1: Check-in jam 06:10 WIB (= 08:10 WIT).
        // Di Jayapura sudah jam 08:10 WIT. Karena <= 08:15 WIT, status HADIR tepat waktu (present).
        Carbon::setTestNow(Carbon::parse('2026-10-07 06:10:00', self::WIB));

        $resPresent = $this->checkIn($employee, -6.2000, 106.8166); // Koordinat Jakarta (WIB)
        $resPresent->assertCreated()->assertJsonPath('attendance.status', 'present');

        $attId = $resPresent->json('attendance.id');

        // Tanggal mengikuti kalender cabang Jayapura (WIT)
        $this->assertDatabaseHas('attendances', ['id' => $attId, 'date' => '2026-10-07']);

        // Penyimpanan DB selalu jam dinding WIB (06:10)
        $this->assertSame('2026-10-07 06:10:00', $this->rawColumn($attId, 'check_in_time'));

        // snap_timezone dibekukan ke zona cabang (WIT), local_tz diturunkan dari GPS (WIB)
        $this->assertSame(self::WIT, $this->rawColumn($attId, 'snap_timezone'));
        $this->assertSame(self::WIB, $this->rawColumn($attId, 'check_in_local_timezone'));

        $resPresent->assertJsonPath('timezone.branch.label', 'WIT')
            ->assertJsonPath('timezone.local.label', 'WIB')
            ->assertJsonPath('timezone.is_cross_timezone', true)
            ->assertJsonPath('timezone.branch_time', '08:10')
            ->assertJsonPath('timezone.local_time', '06:10')
            ->assertJsonPath('timezone.display', '08:10 WIT (06:10 WIB lokasi Anda)');

        // Kasus B2: Check-out jam 15:45 WIB (= 17:45 WIT) dari Jakarta.
        // Jam pulang cabang Jayapura adalah 17:00 WIT.
        // Bila salah dihitung di WIB (15:45 WIB < 17:00 WIB), lembur akan 0 atau ditolak.
        // Tetapi dengan snap_timezone WIT, lembur terhitung 45 menit!
        Carbon::setTestNow(Carbon::parse('2026-10-07 15:45:00', self::WIB));

        $resCheckout = $this->checkOut($employee, -6.2000, 106.8166);
        $resCheckout->assertOk()
            ->assertJsonPath('attendance.overtime_minutes', 45)
            // 08:10–17:45 = 575 menit, potong istirahat 60 → 515 menit kerja
            ->assertJsonPath('attendance.work_minutes', 515)
            ->assertJsonPath('timezone.branch_time', '17:45')
            ->assertJsonPath('timezone.local_time', '15:45')
            ->assertJsonPath('timezone.display', '17:45 WIT (15:45 WIB lokasi Anda)');

        $this->assertSame('2026-10-07 15:45:00', $this->rawColumn($attId, 'check_out_time'));
        $this->assertSame(self::WIB, $this->rawColumn($attId, 'check_out_local_timezone'));

        // Kasus B3: Karyawan kedua terlambat — check-in jam 06:20 WIB (= 08:20 WIT).
        // Meskipun di jam tangan HP Jakarta baru jam 06:20 pagi, di Jayapura sudah 08:20 WIT (> 08:15 WIT).
        // Maka dinilai TELAT (late).
        $employeeLate = $this->employee($office, [
            'wfh_enabled'    => true,
            'radius_enabled' => false,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 06:20:00', self::WIB));

        $resLate = $this->checkIn($employeeLate, -6.2000, 106.8166);
        $resLate->assertCreated()->assertJsonPath('attendance.status', 'late');

        $lateId = $resLate->json('attendance.id');
        $this->assertSame('2026-10-07 06:20:00', $this->rawColumn($lateId, 'check_in_time'));
        $this->assertSame(self::WIT, $this->rawColumn($lateId, 'snap_timezone'));
        $this->assertSame(self::WIB, $this->rawColumn($lateId, 'check_in_local_timezone'));
        $resLate->assertJsonPath('timezone.display', '08:20 WIT (06:20 WIB lokasi Anda)');
    }

    // ─── 6. Checkout & lembur memakai snap_timezone ─────────────────────────

    public function test_checkout_dan_lembur_cabang_wit_memakai_snap_timezone(): void
    {
        $office   = $this->jayapuraOffice();
        $employee = $this->employee($office);

        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', self::WIT));
        $checkIn = $this->checkIn($employee, -2.5330, 140.7000);
        $checkIn->assertCreated()->assertJsonPath('attendance.status', 'present');

        // Pulang 17:45 WIT — lewat 45 menit dari jam pulang 17:00 LOKAL CABANG.
        // Dinilai di WIB, 17:45 WIT = 15:45 WIB → belum lewat jam pulang sama
        // sekali, dan lembur akan menjadi 0. Itulah yang diuji di sini.
        Carbon::setTestNow(Carbon::parse('2026-10-07 17:45:00', self::WIT));

        $res = $this->checkOut($employee, -2.5330, 140.7000);
        $res->assertOk()
            ->assertJsonPath('attendance.overtime_minutes', 45)
            // 08:00–17:45 = 585 menit, potong istirahat 60 → 525.
            ->assertJsonPath('attendance.work_minutes', 525);

        $attId = $res->json('attendance.id');

        // Penyimpanan tetap jam dinding WIB: 17:45 WIT = 15:45 WIB.
        $this->assertSame('2026-10-07 15:45:00', $this->rawColumn($attId, 'check_out_time'));
        $this->assertSame(self::WIT, $this->rawColumn($attId, 'check_out_local_timezone'));
    }

    // ─── 7. Mengubah zona cabang adalah perubahan BERBAHAYA ─────────────────

    public function test_mengubah_timezone_cabang_butuh_konfirmasi_simpan(): void
    {
        $office = $this->makassarOffice();
        $hrd    = $this->hrd($office);

        $payload = ['timezone' => self::WIT];

        // Tanpa confirm_dangerous → ditolak, dan 'timezone' tercantum sebagai
        // field berbahaya yang berubah.
        $tanpaKonfirmasi = $this->actingAs($hrd, 'sanctum')
            ->putJson("/api/v1/dashboard/attendance/settings/{$office->id}", $payload);

        $tanpaKonfirmasi->assertStatus(422)
            ->assertJsonPath('requires_confirmation', true);
        $this->assertContains('timezone', $tanpaKonfirmasi->json('dangerous_changed_fields'));

        // Zona belum berubah di DB.
        $this->assertDatabaseHas('attendance_settings', [
            'id' => $office->id, 'timezone' => self::WITA,
        ]);

        // Dengan konfirmasi → tersimpan.
        $this->actingAs($hrd, 'sanctum')->putJson(
            "/api/v1/dashboard/attendance/settings/{$office->id}",
            $payload + ['confirm_dangerous' => 'SIMPAN']
        )->assertOk();

        $this->assertDatabaseHas('attendance_settings', [
            'id' => $office->id, 'timezone' => self::WIT,
        ]);
    }

    // ─── 8. Snapshot melindungi presensi yang sedang berjalan ───────────────

    public function test_mengubah_timezone_cabang_tidak_mengubah_presensi_yang_sudah_berjalan(): void
    {
        $office   = $this->jakartaOffice();
        $employee = $this->employee($office);
        $hrd      = $this->hrd($office);

        // Check-in 08:00 WIB di cabang WIB.
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', self::WIB));
        $attId = $this->checkIn($employee, -6.2000, 106.8166)->assertCreated()->json('attendance.id');

        $this->assertSame(self::WIB, $this->rawColumn($attId, 'snap_timezone'));

        // HRD mengubah zona cabang ke WIT di tengah hari.
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', self::WIB));
        $this->actingAs($hrd, 'sanctum')->putJson(
            "/api/v1/dashboard/attendance/settings/{$office->id}",
            ['timezone' => self::WIT, 'confirm_dangerous' => 'SIMPAN']
        )->assertOk();

        // Checkout 17:45 WIB. Snapshot (WIB) yang dipakai, BUKAN zona kantor
        // yang baru (WIT) — kalau WIT yang dipakai, jam pulang bergeser 2 jam
        // dan lembur akan salah.
        Carbon::setTestNow(Carbon::parse('2026-10-07 17:45:00', self::WIB));

        $this->checkOut($employee, -6.2000, 106.8166)
            ->assertOk()
            ->assertJsonPath('attendance.overtime_minutes', 45)
            ->assertJsonPath('attendance.work_minutes', 525);

        // Snapshot tidak berubah oleh update kantor.
        $this->assertSame(self::WIB, $this->rawColumn($attId, 'snap_timezone'));
    }

    // ─── 9. Non-regresi: baris lama tanpa snap_timezone tetap WIB ───────────

    public function test_baris_presensi_lama_tanpa_snap_timezone_tetap_dihitung_wib(): void
    {
        // Kantor TANPA kolom timezone (NULL) — mewakili cabang yang ada sebelum
        // fitur ini, yang tidak boleh berubah perilakunya sedikit pun.
        $office   = $this->jakartaOffice(null);
        $employee = $this->employee($office);

        $this->assertSame(self::WIB, $office->effectiveTimezone());

        Carbon::setTestNow(Carbon::parse('2026-10-07 08:20:00', self::WIB));
        $res = $this->checkIn($employee, -6.2000, 106.8166);

        // Telat 20 menit terhadap 08:00 WIB — persis perilaku lama.
        $res->assertCreated()->assertJsonPath('attendance.status', 'late');

        $attId = $res->json('attendance.id');
        $this->assertDatabaseHas('attendances', ['id' => $attId, 'date' => '2026-10-07']);
        $this->assertSame('2026-10-07 08:20:00', $this->rawColumn($attId, 'check_in_time'));

        // Presensi lama yang snap_timezone-nya NULL: recalculate tetap WIB.
        DB::table('attendances')->where('id', $attId)->update(['snap_timezone' => null]);

        $att = Attendance::find($attId);
        $this->assertSame(self::WIB, $att->effectiveTimezone());
        $this->assertSame(self::WIB, $att->snapshotSchedule()['timezone'] ?? self::WIB);

        Carbon::setTestNow(Carbon::parse('2026-10-07 17:45:00', self::WIB));
        $this->checkOut($employee, -6.2000, 106.8166)
            ->assertOk()
            ->assertJsonPath('attendance.overtime_minutes', 45);
    }

    // ─── 10. Pesan cutoff memakai label zona cabang ─────────────────────────

    public function test_pesan_cutoff_memakai_label_zona_cabang(): void
    {
        $office = $this->makassarOffice();
        // Cutoff 60 menit setelah jam masuk → batas 09:00 WITA.
        $office->update(['late_checkin_cutoff_minutes' => 60]);

        $employee = $this->employee($office);

        // 09:30 WITA = 08:30 WIB. Dinilai di WIB ia masih di bawah cutoff
        // (08:30 < 09:00) dan akan lolos — keliru. Dinilai di WITA ia melewati
        // batas dan harus ditolak.
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:30:00', self::WITA));

        $res = $this->checkIn($employee, -5.1477, 119.4327);
        $res->assertStatus(403);

        // Pesan untuk pengguna menyebut WITA, bukan WIB — label berasal dari
        // zona cabang, bukan konstanta.
        $pesan = (string) $res->json('message');
        $this->assertStringContainsString('WITA', $pesan);
        $this->assertStringNotContainsString('WIB', $pesan);

        // Tidak ada baris presensi yang terbuat.
        $this->assertDatabaseMissing('attendances', ['user_id' => $employee->id]);
    }

    // ─── 11. Auto-checkout melayani dua zona dalam satu eksekusi ────────────

    public function test_auto_checkout_memakai_zona_snapshot_untuk_dua_cabang_sekaligus(): void
    {
        $makassar = $this->makassarOffice();
        $jayapura = $this->jayapuraOffice();

        // Auto-checkout butuh grace period yang sudah terlampaui; pakai nilai
        // kecil agar kedua cabang jatuh tempo pada instan uji yang sama.
        $makassar->update(['auto_checkout_grace_minutes' => 60]);
        $jayapura->update(['auto_checkout_grace_minutes' => 60]);

        $empWita = $this->employee($makassar);
        $empWit  = $this->employee($jayapura);

        // Keduanya check-in 08:00 jam LOKAL masing-masing. Dua instan berbeda.
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', self::WITA));
        $attWita = $this->checkIn($empWita, -5.1477, 119.4327)->assertCreated()->json('attendance.id');

        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', self::WIT));
        $attWit = $this->checkIn($empWit, -2.5330, 140.7000)->assertCreated()->json('attendance.id');

        // Satu instan: 19:30 WIT = 18:30 WITA. Keduanya sudah melewati
        // 17:00 lokal + grace 60 menit, jadi satu eksekusi harus menutup
        // KEDUANYA — masing-masing dengan jam pulang zonanya sendiri.
        Carbon::setTestNow(Carbon::parse('2026-10-07 19:30:00', self::WIT));
        $this->artisan('attendance:auto-checkout')->assertExitCode(0);

        foreach ([$attWita, $attWit] as $id) {
            $att = Attendance::find($id);
            $this->assertNotNull($att->check_out_time, "Presensi #{$id} harus ditutup otomatis.");
            $this->assertTrue((bool) $att->is_auto_checkout, "Presensi #{$id} harus bertanda auto-checkout.");
        }

        // AutoCheckoutCommand meng-clamp jam checkout ke JADWAL TARGET, yaitu
        // jam pulang + grace (lihat docblock command-nya), bukan ke jam pulang
        // dan bukan ke jam eksekusi. Jadi target lokalnya 17:00 + 60 = 18:00
        // LOKAL CABANG, yang tersimpan sebagai jam dinding WIB:
        //   Makassar 18:00 WITA → 17:00 WIB
        //   Jayapura 18:00 WIT  → 16:00 WIB
        // Dua nilai yang BERBEDA inilah buktinya: bila zona snapshot diabaikan,
        // satu eksekusi akan menutup kedua cabang pada jam dinding yang sama.
        $this->assertSame('2026-10-07 17:00:00', $this->rawColumn($attWita, 'check_out_time'));
        $this->assertSame('2026-10-07 16:00:00', $this->rawColumn($attWit, 'check_out_time'));
    }

    // ─── 12. Override fromDateTime(): penyimpanan selalu jam dinding WIB ────

    public function test_datetime_selalu_tersimpan_sebagai_jam_dinding_wib(): void
    {
        $office   = $this->jayapuraOffice();
        $employee = $this->employee($office);

        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', self::WIT));
        $attId = $this->checkIn($employee, -2.5330, 140.7000)->assertCreated()->json('attendance.id');

        // Assign Carbon bernuansa WIT langsung ke kolom datetime. Tanpa override
        // fromDateTime(), Laravel menuliskan jam dinding WIT (17:45) yang nanti
        // dibaca ulang sebagai WIB — instan bergeser 2 jam TANPA error apa pun.
        $att = Attendance::find($attId);
        $att->check_out_time = Carbon::parse('2026-10-07 17:45:00', self::WIT);
        $att->save();

        $this->assertSame(
            '2026-10-07 15:45:00',
            $this->rawColumn($attId, 'check_out_time'),
            'Carbon bernuansa WIT wajib dikonversi ke jam dinding WIB sebelum disimpan.'
        );

        // Dibaca ulang, instannya tetap sama dengan yang di-assign.
        //
        // setTestNow() WAJIB di-reset lebih dulu. Carbon mewarisi zona dari waktu
        // uji yang aktif, sehingga selama testNow bernuansa WIT, string DB
        // '15:45' ikut di-parse SEBAGAI WIT — bukan WIB — dan instannya bergeser
        // 2 jam. Itu artefak harness, bukan perilaku produksi: di produksi tidak
        // ada testNow, jadi pembacaan selalu memakai zona default aplikasi (WIB).
        // Membaca setelah reset-lah yang mencerminkan produksi.
        Carbon::setTestNow();

        $this->assertSame(
            '2026-10-07 17:45:00',
            Attendance::find($attId)->check_out_time->copy()->setTimezone(self::WIT)->format('Y-m-d H:i:s')
        );
    }
}
