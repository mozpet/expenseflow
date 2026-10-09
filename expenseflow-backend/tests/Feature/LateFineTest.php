<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\Attendance\LateFineCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DENDA KETERLAMBATAN (2026-10-09).
 *
 * Jam masuk 08:00, toleransi 15 menit, Rp 1.000/menit:
 *   after_work_start : 08:12 → tidak telat; 08:16 → telat 16 menit
 *   after_tolerance  : 08:16 → telat 1 menit
 */
class LateFineTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Jakarta';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Denda Telat', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function office(array $override = []): AttendanceSetting
    {
        return AttendanceSetting::create(array_merge([
            'company_id'                    => $this->company->id,
            'office_name'                   => 'Kantor Pusat',
            'office_latitude'               => -6.2000,
            'office_longitude'              => 106.8166,
            'radius_meters'                 => 200,
            'work_start_time'               => '08:00:00',
            'work_end_time'                 => '17:00:00',
            'break_minutes'                 => 60,
            'work_days'                     => [1, 2, 3, 4, 5],
            'late_tolerance_minutes'        => 15,
            'late_checkin_cutoff_minutes'   => 240,
            'wfh_checkin_window_minutes'    => null,
            'late_fine_per_minute'          => 1000,
            'late_count_mode'               => 'after_work_start',
            'min_overtime_minutes'          => 30,
            'overtime_enabled'              => true,
            'min_checkout_interval_minutes' => 0,
            'is_active'                     => true,
        ], $override));
    }

    private function employee(AttendanceSetting $office): User
    {
        return User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'radius_enabled'        => true,
            'department'            => 'Operasional',
        ]);
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

    /** Rabu 2026-10-07 pada jam lokal yang diberikan. */
    private function checkInAt(User $user, string $time)
    {
        Carbon::setTestNow(Carbon::parse("2026-10-07 {$time}:00", self::TZ));

        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2000, 'longitude' => 106.8166,
        ]);
    }

    private function row(User $user): Attendance
    {
        return Attendance::where('user_id', $user->id)->firstOrFail();
    }

    // ── Calculator murni ────────────────────────────────────────────────

    public function test_calculator_semua_mode(): void
    {
        $cfg = ['rate' => 1000.0, 'mode' => 'after_work_start', 'max' => null, 'tolerance' => 15];
        $at  = fn (string $t) => Carbon::parse("2026-10-07 {$t}", self::TZ);

        $this->assertSame(0, LateFineCalculator::calculate($at('08:12:00'), '08:00', self::TZ, $cfg)['late_minutes']);
        // tepat di batas toleransi tidak telat
        $this->assertSame(0, LateFineCalculator::calculate($at('08:15:00'), '08:00', self::TZ, $cfg)['late_minutes']);

        $r = LateFineCalculator::calculate($at('08:16:00'), '08:00', self::TZ, $cfg);
        $this->assertSame(16, $r['late_minutes']);
        $this->assertSame(16000.0, $r['late_fine_amount']);

        $r = LateFineCalculator::calculate($at('08:16:00'), '08:00', self::TZ, ['mode' => 'after_tolerance'] + $cfg);
        $this->assertSame(1, $r['late_minutes']);
        $this->assertSame(1000.0, $r['late_fine_amount']);

        // detik tambahan setelah batas tetap dihitung minimal 1 menit
        $r = LateFineCalculator::calculate($at('08:15:30'), '08:00', self::TZ, ['mode' => 'after_tolerance'] + $cfg);
        $this->assertSame(1, $r['late_minutes']);

        // batas maksimum per hari
        $r = LateFineCalculator::calculate($at('09:00:00'), '08:00', self::TZ, ['max' => 20000.0] + $cfg);
        $this->assertSame(60, $r['late_minutes']);
        $this->assertSame(20000.0, $r['late_fine_amount']);

        // tanpa tarif: menit tercatat, denda nol
        $r = LateFineCalculator::calculate($at('08:30:00'), '08:00', self::TZ, ['rate' => null] + $cfg);
        $this->assertSame(30, $r['late_minutes']);
        $this->assertSame(0.0, $r['late_fine_amount']);
    }

    // ── Check-in ────────────────────────────────────────────────────────

    public function test_dalam_toleransi_tidak_dicatat_telat_sama_sekali(): void
    {
        $emp = $this->employee($this->office());

        $this->checkInAt($emp, "08:12")->assertCreated()->assertJsonPath('attendance.status', 'present');

        $att = $this->row($emp);
        $this->assertSame(0, (int) $att->late_minutes);
        $this->assertEquals(0, (float) $att->late_fine_amount);
    }

    public function test_setelah_jam_masuk_menghitung_dari_jam_masuk(): void
    {
        $emp = $this->employee($this->office());

        $this->checkInAt($emp, '08:16')->assertCreated()->assertJsonPath('attendance.status', 'late');

        $att = $this->row($emp);
        $this->assertSame(16, (int) $att->late_minutes);
        $this->assertEquals(16000, (float) $att->late_fine_amount);
        $this->assertEquals(1000, (float) $att->snap_late_fine_per_minute);
        $this->assertSame('after_work_start', $att->snap_late_count_mode);
    }

    public function test_setelah_toleransi_menghitung_hanya_kelebihannya(): void
    {
        $emp = $this->employee($this->office(['late_count_mode' => 'after_tolerance']));

        $this->checkInAt($emp, '08:16')->assertCreated();

        $att = $this->row($emp);
        $this->assertSame(1, (int) $att->late_minutes);
        $this->assertEquals(1000, (float) $att->late_fine_amount);
    }

    public function test_denda_dibatasi_maksimum_per_hari(): void
    {
        $emp = $this->employee($this->office(['late_fine_max_per_day' => 20000]));

        $this->checkInAt($emp, '09:00')->assertCreated();

        $att = $this->row($emp);
        $this->assertSame(60, (int) $att->late_minutes);
        $this->assertEquals(20000, (float) $att->late_fine_amount);
    }

    public function test_tanpa_tarif_menit_tercatat_tetapi_tanpa_denda(): void
    {
        $emp = $this->employee($this->office(['late_fine_per_minute' => null]));

        $this->checkInAt($emp, '08:30')->assertCreated();

        $att = $this->row($emp);
        $this->assertSame(30, (int) $att->late_minutes);
        $this->assertEquals(0, (float) $att->late_fine_amount);
    }

    public function test_pulang_cepat_tidak_menghapus_denda_telat(): void
    {
        $emp = $this->employee($this->office());

        $this->checkInAt($emp, '08:20')->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', self::TZ));
        $this->actingAs($emp, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2000, 'longitude' => 106.8166,
        ])->assertSuccessful();

        $att = $this->row($emp);
        $this->assertSame('early_leave', $att->status);
        $this->assertSame(20, (int) $att->late_minutes);
        $this->assertEquals(20000, (float) $att->late_fine_amount);
    }

    public function test_snapshot_melindungi_dari_perubahan_tarif_setelah_check_in(): void
    {
        $office = $this->office();
        $emp    = $this->employee($office);

        $this->checkInAt($emp, '08:20')->assertCreated();
        $office->update(['late_fine_per_minute' => 9999, 'late_count_mode' => 'after_tolerance']);

        $att = $this->row($emp)->fresh();
        $this->assertEquals(20000, (float) $att->late_fine_amount);
        $this->assertEquals(1000, (float) $att->snap_late_fine_per_minute);
    }

    // ── Koreksi HRD ─────────────────────────────────────────────────────

    private function workDate(): string
    {
        $d = now(self::TZ)->subDays(3);
        while ($d->isWeekend()) {
            $d->subDay();
        }

        return $d->toDateString();
    }

    private function seedSnapshotRow(User $emp, AttendanceSetting $office, array $override = []): Attendance
    {
        $date = $this->workDate();

        return Attendance::create(array_merge([
            'company_id'                  => $this->company->id,
            'user_id'                     => $emp->id,
            'date'                        => $date,
            'check_in_time'               => $date . ' 08:00:00',
            'check_in_type'               => 'onsite',
            'status'                      => 'present',
            'overtime_minutes'            => 0,
            'is_holiday'                  => false,
            'snap_source'                 => 'office',
            'snap_work_start_time'        => '08:00',
            'snap_work_end_time'          => '17:00',
            'snap_is_off'                 => false,
            'snap_is_cross_day'           => false,
            'snap_office_id'              => $office->id,
            'snap_timezone'               => self::TZ,
            'snap_late_fine_per_minute'   => 1000,
            'snap_late_count_mode'        => 'after_work_start',
            'snap_late_tolerance_minutes' => 15,
            'late_minutes'                => 0,
            'late_fine_amount'            => 0,
        ], $override));
    }

    private function correct(User $hrd, User $emp, array $extra)
    {
        return $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/corrections', array_merge([
            'user_id'           => $emp->id,
            'date'              => $this->workDate(),
            'correction_reason' => 'Koreksi jam masuk berdasarkan log mesin absen.',
            'confirm_dangerous' => 'SIMPAN',
        ], $extra));
    }

    public function test_koreksi_jam_masuk_menghitung_ulang_denda_dari_snapshot(): void
    {
        $office = $this->office();
        $emp    = $this->employee($office);
        $hrd    = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office);

        // HRD mengubah tarif live — koreksi tetap memakai snapshot.
        $office->update(['late_fine_per_minute' => 5000]);

        $this->correct($hrd, $emp, ['check_in_time' => '08:30'])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertSame(30, (int) $att->late_minutes);
        $this->assertEquals(30000, (float) $att->late_fine_amount);

        // Dikoreksi kembali ke dalam toleransi → denda hilang.
        $this->correct($hrd, $emp, ['check_in_time' => '08:10'])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertSame(0, (int) $att->late_minutes);
        $this->assertEquals(0, (float) $att->late_fine_amount);
    }

    public function test_baris_lama_tanpa_snapshot_denda_tidak_disentuh(): void
    {
        $office = $this->office();
        $emp    = $this->employee($office);
        $hrd    = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office, [
            'snap_late_fine_per_minute'   => null,
            'snap_late_count_mode'        => null,
            'snap_late_tolerance_minutes' => null,
            'late_minutes'                => null,
            'late_fine_amount'            => null,
        ]);

        $this->correct($hrd, $emp, ['check_in_time' => '08:45'])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertNull($att->late_fine_amount);
    }

    // ── Pengaturan kantor ───────────────────────────────────────────────

    public function test_mengubah_field_denda_butuh_konfirmasi_simpan(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);
        $url    = "/api/v1/dashboard/attendance/settings/{$office->id}";

        $res = $this->actingAs($hrd, 'sanctum')->putJson($url, [
            'late_fine_per_minute' => 2500,
            'late_count_mode'      => 'after_tolerance',
        ]);

        $res->assertStatus(422)->assertJsonPath('requires_confirmation', true);
        $this->assertContains('late_fine_per_minute', $res->json('dangerous_changed_fields'));
        $this->assertContains('late_count_mode', $res->json('dangerous_changed_fields'));

        $this->actingAs($hrd, 'sanctum')->putJson($url, [
            'late_fine_per_minute'  => 2500,
            'late_count_mode'       => 'after_tolerance',
            'late_fine_max_per_day' => 50000,
            'confirm_dangerous'     => 'SIMPAN',
        ])->assertOk();

        $office->refresh();
        $this->assertEquals(2500, (float) $office->late_fine_per_minute);
        $this->assertSame('after_tolerance', $office->late_count_mode);
        $this->assertEquals(50000, (float) $office->late_fine_max_per_day);
    }

    public function test_nilai_sama_tidak_dianggap_perubahan_berbahaya(): void
    {
        $office = $this->office(['late_fine_per_minute' => 1000]);
        $hrd    = $this->hrd($office);

        $this->actingAs($hrd, 'sanctum')
            ->putJson("/api/v1/dashboard/attendance/settings/{$office->id}", [
                'late_fine_per_minute' => '1000.00',
                'late_count_mode'      => 'after_work_start',
            ])->assertOk();
    }

    public function test_validasi_field_denda(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);
        $url    = "/api/v1/dashboard/attendance/settings/{$office->id}";

        $this->actingAs($hrd, 'sanctum')->putJson($url, ['late_fine_per_minute' => -1, 'confirm_dangerous' => 'SIMPAN'])
            ->assertStatus(422)->assertJsonValidationErrors('late_fine_per_minute');
        $this->actingAs($hrd, 'sanctum')->putJson($url, ['late_count_mode' => 'ngawur', 'confirm_dangerous' => 'SIMPAN'])
            ->assertStatus(422)->assertJsonValidationErrors('late_count_mode');
        $this->actingAs($hrd, 'sanctum')->putJson($url, ['late_fine_max_per_day' => -5, 'confirm_dangerous' => 'SIMPAN'])
            ->assertStatus(422)->assertJsonValidationErrors('late_fine_max_per_day');
    }

    public function test_buat_kantor_menyimpan_field_denda(): void
    {
        $hrd = $this->hrd($this->office());

        $res = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/settings', [
            'office_name'           => 'Cabang Baru',
            'office_latitude'       => -6.9,
            'office_longitude'      => 107.6,
            'radius_meters'         => 150,
            'work_start_time'       => '08:00',
            'work_end_time'         => '17:00',
            'work_days'             => [1, 2, 3, 4, 5],
            'default_leave_quota'   => 12,
            'late_fine_per_minute'  => 1500,
            'late_count_mode'       => 'after_work_start',
            'late_fine_max_per_day' => 30000,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('attendance_settings', [
            'id'                    => $res->json('setting.id'),
            'late_fine_per_minute'  => 1500,
            'late_count_mode'       => 'after_work_start',
            'late_fine_max_per_day' => 30000,
        ]);
    }

    // ── Laporan & CSV ───────────────────────────────────────────────────

    public function test_laporan_dan_csv_memuat_menit_dan_denda(): void
    {
        $office = $this->office();
        $emp    = $this->employee($office);
        $hrd    = $this->hrd($office);
        $att    = $this->seedSnapshotRow($emp, $office, [
            'status' => 'late', 'late_minutes' => 20, 'late_fine_amount' => 20000,
        ]);
        $date = $att->date->toDateString();

        $res = $this->actingAs($hrd, 'sanctum')
            ->getJson("/api/v1/dashboard/attendance/report?start_date={$date}&end_date={$date}");
        $res->assertOk();

        $row = collect($res->json('report.data'))->firstWhere('user_id', $emp->id)
            ?? collect($res->json('report.data'))->first();
        $this->assertSame(20, (int) $row['late_minutes']);
        $this->assertEquals(20000, (float) $row['late_fine_amount']);

        $csv = $this->actingAs($hrd, 'sanctum')
            ->get("/api/v1/dashboard/attendance/report/export?start_date={$date}&end_date={$date}")
            ->streamedContent();
        $this->assertStringContainsString('Denda Telat (Rp)', $csv);
        $this->assertStringContainsString('20000', $csv);
    }
}
