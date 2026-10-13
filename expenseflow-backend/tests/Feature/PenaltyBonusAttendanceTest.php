<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PERATURAN DENDA & BONUS KANTOR — JALUR TULIS PRESENSI (2026-10-11).
 *
 * Kalkulatornya sendiri sudah diuji terpisah di tests/Unit/PenaltyBonusCalculatorTest.
 * Di sini yang diuji adalah SIAPA menulis kolom apa, dan kapan kolom itu DINOLKAN:
 *
 *   check-in            → membekukan snap_* + premi shift malam
 *   check-out           → early_leave_minutes / early_leave_fine_amount
 *   attendance:auto-checkout → missed_checkout_fine_amount (+ opsi status pulang cepat)
 *   koreksi HRD         → hitung ulang dari SNAPSHOT, bukan setting live
 *   pengaturan kantor   → gerbang nominal & gerbang konfirmasi "SIMPAN"
 *
 * Kantor acuan: masuk 08:00, pulang 17:00, toleransi pulang cepat 30 menit
 * (batas 16:30), grace auto-checkout 60 menit (batas 18:00).
 */
class PenaltyBonusAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Jakarta';

    /** Rabu — hari kerja menurut work_days default [1..5]. */
    private const HARI = '2026-10-07';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Denda Bonus', 'is_active' => true]);
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
            'early_leave_tolerance_minutes' => 30,
            'late_checkin_cutoff_minutes'   => 240,
            'checkout_reminder_minutes'     => 30,
            'auto_checkout_grace_minutes'   => 60,
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

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse(self::HARI . " {$time}:00", self::TZ));
    }

    private function checkInAt(User $user, string $time)
    {
        $this->at($time);

        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2000, 'longitude' => 106.8166,
        ]);
    }

    private function checkOutAt(User $user, string $time)
    {
        $this->at($time);

        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2000, 'longitude' => 106.8166,
        ]);
    }

    private function row(User $user): Attendance
    {
        return Attendance::where('user_id', $user->id)->firstOrFail();
    }

    // ═════════════ Check-in: pembekuan snapshot & premi shift malam ═════════════

    public function test_checkin_membekukan_seluruh_konfigurasi_denda_dan_bonus(): void
    {
        $emp = $this->employee($this->office([
            'early_leave_fine_mode'        => 'per_minute',
            'early_leave_fine_amount'      => 1000,
            'early_leave_fine_max'         => 80000,
            'missed_checkout_penalty_mode' => 'flat_fine',
            'missed_checkout_fine_amount'  => 25000,
            'night_shift_bonus_enabled'    => true,
            'night_shift_bonus_amount'     => 75000,
        ]));

        $this->checkInAt($emp, '08:05')->assertCreated();

        $att = $this->row($emp);
        $this->assertSame('per_minute', $att->snap_early_leave_fine_mode);
        $this->assertEquals(1000, (float) $att->snap_early_leave_fine_amount);
        $this->assertEquals(80000, (float) $att->snap_early_leave_fine_max);
        $this->assertSame(30, (int) $att->snap_early_leave_tolerance_minutes);
        $this->assertSame('flat_fine', $att->snap_missed_checkout_penalty_mode);
        $this->assertEquals(25000, (float) $att->snap_missed_checkout_fine_amount);
        $this->assertTrue((bool) $att->snap_night_shift_bonus_enabled);
        $this->assertEquals(75000, (float) $att->snap_night_shift_bonus_amount);
    }

    public function test_premi_shift_malam_dibekukan_saat_checkin(): void
    {
        // Jalur kantor biasa SELALU mengembalikan is_cross_day = false, jadi
        // satu-satunya cara jadwal kantor tergolong shift malam adalah jam
        // mulainya >= 22:00.
        $emp = $this->employee($this->office([
            'work_start_time'           => '22:00:00',
            'work_end_time'             => '06:00:00',
            'night_shift_bonus_enabled' => true,
            'night_shift_bonus_amount'  => 75000,
        ]));

        $this->checkInAt($emp, '22:05')->assertCreated();

        $this->assertEquals(75000, (float) $this->row($emp)->night_shift_bonus_amount);
    }

    public function test_shift_siang_tidak_mendapat_premi_malam(): void
    {
        $emp = $this->employee($this->office([
            'night_shift_bonus_enabled' => true,
            'night_shift_bonus_amount'  => 75000,
        ]));

        $this->checkInAt($emp, '08:05')->assertCreated();

        $this->assertEquals(0, (float) $this->row($emp)->night_shift_bonus_amount);
    }

    public function test_premi_malam_beku_terhadap_perubahan_nominal_setelah_checkin(): void
    {
        $office = $this->office([
            'work_start_time'           => '22:00:00',
            'work_end_time'             => '06:00:00',
            'night_shift_bonus_enabled' => true,
            'night_shift_bonus_amount'  => 75000,
        ]);
        $emp = $this->employee($office);

        $this->checkInAt($emp, '22:05')->assertCreated();
        $office->update(['night_shift_bonus_amount' => 1]);

        $this->assertEquals(75000, (float) $this->row($emp)->fresh()->night_shift_bonus_amount);
    }

    // ═════════════ Check-out: menit & denda pulang cepat ═════════════

    public function test_checkout_lebih_awal_menulis_menit_dan_denda(): void
    {
        $emp = $this->employee($this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
        ]));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->checkOutAt($emp, '16:00')->assertOk()->assertJsonPath('attendance.status', 'early_leave');

        $att = $this->row($emp);
        // Batas toleransi 16:30, bukan jam pulang 17:00 → 30 menit, bukan 60.
        $this->assertSame(30, (int) $att->early_leave_minutes);
        $this->assertEquals(30000, (float) $att->early_leave_fine_amount);
    }

    public function test_mode_prorata_mencatat_menit_tanpa_denda_presensi(): void
    {
        // Rupiahnya diambil payroll dari gaji pokok, bukan dari baris presensi.
        $emp = $this->employee($this->office(['early_leave_fine_mode' => 'prorata']));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->checkOutAt($emp, '15:00')->assertOk();

        $att = $this->row($emp);
        $this->assertSame(90, (int) $att->early_leave_minutes);
        $this->assertEquals(0, (float) $att->early_leave_fine_amount);
    }

    public function test_denda_pulang_cepat_dibatasi_nilai_maksimum(): void
    {
        $emp = $this->employee($this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
            'early_leave_fine_max'    => 40000,
        ]));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->checkOutAt($emp, '14:00')->assertOk();

        $att = $this->row($emp);
        $this->assertSame(150, (int) $att->early_leave_minutes);
        $this->assertEquals(40000, (float) $att->early_leave_fine_amount);
    }

    public function test_pulang_di_dalam_toleransi_tidak_menulis_kolom_pulang_cepat(): void
    {
        $emp = $this->employee($this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
        ]));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->checkOutAt($emp, '16:45')->assertOk()->assertJsonPath('attendance.status', 'present');

        $att = $this->row($emp);
        $this->assertNull($att->early_leave_minutes);
        $this->assertNull($att->early_leave_fine_amount);
    }

    public function test_toleransi_null_mematikan_seluruh_fitur_pulang_cepat(): void
    {
        $emp = $this->employee($this->office([
            'early_leave_tolerance_minutes' => null,
            'early_leave_fine_mode'         => 'per_minute',
            'early_leave_fine_amount'       => 1000,
        ]));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->checkOutAt($emp, '12:00')->assertOk()->assertJsonPath('attendance.status', 'present');

        $att = $this->row($emp);
        $this->assertNull($att->early_leave_minutes);
        $this->assertNull($att->early_leave_fine_amount);
    }

    public function test_snapshot_melindungi_denda_pulang_cepat_dari_perubahan_tarif(): void
    {
        $office = $this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
        ]);
        $emp = $this->employee($office);

        $this->checkInAt($emp, '08:00')->assertCreated();

        // HRD menaikkan tarif di tengah hari — presensi yang sudah berjalan tidak ikut.
        $office->update(['early_leave_fine_amount' => 9000, 'early_leave_fine_mode' => 'flat']);

        $this->checkOutAt($emp, '16:00')->assertOk();

        $this->assertEquals(30000, (float) $this->row($emp)->early_leave_fine_amount);
    }

    // ═════════════ Auto-checkout: denda lupa check-out ═════════════

    private function jalankanAutoCheckout(string $time = '18:01'): void
    {
        $this->at($time);
        $this->artisan('attendance:auto-checkout')->assertExitCode(0);
    }

    public function test_auto_checkout_mode_flat_fine_mendenda_tanpa_mengubah_status(): void
    {
        $emp = $this->employee($this->office([
            'missed_checkout_penalty_mode' => 'flat_fine',
            'missed_checkout_fine_amount'  => 25000,
        ]));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->jalankanAutoCheckout();

        $att = $this->row($emp)->fresh();
        $this->assertTrue((bool) $att->is_auto_checkout);
        $this->assertEquals(25000, (float) $att->missed_checkout_fine_amount);
        // Lupa check-out BUKAN pulang cepat: statusnya tidak boleh diperberat.
        $this->assertSame('present', $att->status);
        $this->assertNull($att->early_leave_minutes);
    }

    public function test_auto_checkout_mode_mark_early_leave_mengubah_status_tanpa_rupiah(): void
    {
        $emp = $this->employee($this->office([
            'missed_checkout_penalty_mode' => 'mark_early_leave',
            'missed_checkout_fine_amount'  => 25000,
        ]));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->jalankanAutoCheckout();

        $att = $this->row($emp)->fresh();
        $this->assertSame('early_leave', $att->status);
        $this->assertNull($att->missed_checkout_fine_amount);
        // Tidak ada menit pulang cepat yang dikarang — inilah yang mencegah
        // dobel potong dengan langkah pulang cepat di payroll.
        $this->assertNull($att->early_leave_minutes);
    }

    public function test_auto_checkout_mode_none_tidak_mendenda(): void
    {
        $emp = $this->employee($this->office([
            'missed_checkout_penalty_mode' => 'none',
            'missed_checkout_fine_amount'  => 25000,
        ]));

        $this->checkInAt($emp, '08:00')->assertCreated();
        $this->jalankanAutoCheckout();

        $att = $this->row($emp)->fresh();
        $this->assertTrue((bool) $att->is_auto_checkout);
        $this->assertNull($att->missed_checkout_fine_amount);
        $this->assertSame('present', $att->status);
    }

    public function test_mark_early_leave_tidak_berlaku_di_hari_yang_bukan_hari_kerja(): void
    {
        // Karyawan check-in saat Rabu masih hari kerja; HRD lalu mengeluarkan
        // Rabu dari work_days. Hari itu kini bukan hari kerja, dan hari non-kerja
        // tidak mengenal konsep pulang cepat.
        $office = $this->office([
            'missed_checkout_penalty_mode' => 'mark_early_leave',
            'missed_checkout_fine_amount'  => 25000,
        ]);
        $emp = $this->employee($office);

        $this->checkInAt($emp, '08:00')->assertCreated();
        $office->update(['work_days' => [1, 2, 4, 5]]);

        $this->jalankanAutoCheckout();

        $att = $this->row($emp)->fresh();
        $this->assertTrue((bool) $att->is_auto_checkout);
        $this->assertSame('present', $att->status);
    }

    public function test_status_absent_tidak_diringankan_menjadi_pulang_cepat(): void
    {
        $office = $this->office([
            'missed_checkout_penalty_mode' => 'mark_early_leave',
            'missed_checkout_fine_amount'  => 25000,
        ]);
        $emp = $this->employee($office);

        $this->checkInAt($emp, '08:00')->assertCreated();
        // HRD menetapkan alpha sebelum presensi tertutup sistem.
        $this->row($emp)->update(['status' => 'absent']);

        $this->jalankanAutoCheckout();

        $this->assertSame('absent', $this->row($emp)->fresh()->status);
    }

    // ═════════════ Koreksi HRD ═════════════

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
            'company_id'                         => $this->company->id,
            'user_id'                            => $emp->id,
            'date'                               => $date,
            'check_in_time'                      => $date . ' 08:00:00',
            'check_out_time'                     => $date . ' 17:00:00',
            'check_in_type'                      => 'onsite',
            'status'                             => 'present',
            'overtime_minutes'                   => 0,
            'is_holiday'                         => false,
            'snap_source'                        => 'office',
            'snap_work_start_time'               => '08:00',
            'snap_work_end_time'                 => '17:00',
            'snap_is_off'                        => false,
            'snap_is_cross_day'                  => false,
            'snap_office_id'                     => $office->id,
            'snap_timezone'                      => self::TZ,
            'snap_late_fine_per_minute'          => null,
            'snap_late_count_mode'               => 'after_tolerance',
            'snap_late_tolerance_minutes'        => 15,
            'snap_early_leave_tolerance_minutes' => 30,
            'snap_early_leave_fine_mode'         => 'per_minute',
            'snap_early_leave_fine_amount'       => 1000,
            'snap_early_leave_fine_max'          => null,
            'late_minutes'                       => 0,
            'late_fine_amount'                   => 0,
        ], $override));
    }

    /**
     * Guard 7 controller: jam pulang tidak bisa dikoreksi tanpa menyertakan jam
     * masuk, jadi helper ini selalu mengirim keduanya kecuali dioverride.
     */
    private function correct(User $hrd, User $emp, array $extra)
    {
        return $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/corrections', array_merge([
            'user_id'           => $emp->id,
            'date'              => $this->workDate(),
            'correction_reason' => 'Koreksi jam presensi berdasarkan log mesin absen.',
            'confirm_dangerous' => 'SIMPAN',
        ], $extra));
    }

    public function test_koreksi_jam_pulang_menghitung_ulang_denda_dari_snapshot(): void
    {
        $office = $this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
        ]);
        $emp = $this->employee($office);
        $hrd = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office);

        // Tarif live dinaikkan — koreksi tetap memakai snapshot 1.000/menit.
        $office->update(['early_leave_fine_amount' => 9000]);

        $this->correct($hrd, $emp, [
            'check_in_time'  => '08:00',
            'check_out_time' => '15:30',
        ])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertSame('early_leave', $att->status);
        $this->assertSame(60, (int) $att->early_leave_minutes);
        $this->assertEquals(60000, (float) $att->early_leave_fine_amount);

        // Dikoreksi kembali ke jam normal → kolom dinolkan, bukan dibiarkan.
        $this->correct($hrd, $emp, [
            'check_in_time'  => '08:00',
            'check_out_time' => '17:00',
        ])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertNull($att->early_leave_minutes);
        $this->assertEquals(0, (float) $att->early_leave_fine_amount);
    }

    public function test_hrd_membebaskan_pulang_cepat_dengan_status_eksplisit(): void
    {
        $office = $this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
        ]);
        $emp = $this->employee($office);
        $hrd = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office, [
            'status'                   => 'early_leave',
            'early_leave_minutes'      => 60,
            'early_leave_fine_amount'  => 60000,
        ]);

        // Pulang awal karena izin atasan: HRD menetapkan status hadir biasa.
        $this->correct($hrd, $emp, [
            'check_in_time'  => '08:00',
            'check_out_time' => '15:30',
            'status'         => 'present',
        ])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertSame('present', $att->status);
        $this->assertNull($att->early_leave_minutes);
        $this->assertEquals(0, (float) $att->early_leave_fine_amount);
    }

    public function test_baris_lama_tanpa_snapshot_pulang_cepat_tidak_disentuh(): void
    {
        $office = $this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
        ]);
        $emp = $this->employee($office);
        $hrd = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office, [
            'snap_early_leave_tolerance_minutes' => null,
            'snap_early_leave_fine_mode'         => null,
            'snap_early_leave_fine_amount'       => null,
        ]);

        $this->correct($hrd, $emp, [
            'check_in_time'  => '08:00',
            'check_out_time' => '15:30',
        ])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertNull($att->early_leave_minutes);
        $this->assertNull($att->early_leave_fine_amount);
    }

    public function test_backfill_manual_hrd_mendapat_premi_shift_malam(): void
    {
        $office = $this->office([
            'work_start_time'           => '22:00:00',
            'work_end_time'             => '06:00:00',
            'night_shift_bonus_enabled' => true,
            'night_shift_bonus_amount'  => 75000,
        ]);
        $emp = $this->employee($office);
        $hrd = $this->hrd($office);

        // Baris belum ada: HRD menginput presensi shift malam secara manual.
        $this->correct($hrd, $emp, [
            'check_in_time'  => '22:00',
            'check_out_time' => '23:30',
        ])->assertOk();

        $this->assertEquals(75000, (float) $this->row($emp)->night_shift_bonus_amount);
    }

    public function test_premi_shift_malam_baris_lama_tidak_dihitung_ulang(): void
    {
        $office = $this->office([
            'night_shift_bonus_enabled' => true,
            'night_shift_bonus_amount'  => 75000,
        ]);
        $emp = $this->employee($office);
        $hrd = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office, [
            'night_shift_bonus_amount'       => 75000,
            'snap_night_shift_bonus_enabled' => true,
            'snap_night_shift_bonus_amount'  => 75000,
        ]);

        // Jadwalnya kini siang (08:00) dan nominalnya berubah, tetapi premi yang
        // sudah beku sejak check-in tidak boleh hilang karena koreksi jam.
        $office->update(['night_shift_bonus_amount' => 1]);

        $this->correct($hrd, $emp, ['check_in_time' => '08:30'])->assertOk();

        $this->assertEquals(75000, (float) $this->row($emp)->fresh()->night_shift_bonus_amount);
    }

    public function test_denda_lupa_checkout_dihapus_bila_baris_bukan_auto_checkout(): void
    {
        $office = $this->office();
        $emp    = $this->employee($office);
        $hrd    = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office, [
            'missed_checkout_fine_amount' => 25000,
            'is_auto_checkout'            => false,
        ]);

        $this->correct($hrd, $emp, ['check_in_time' => '08:30'])->assertOk();

        $this->assertNull($this->row($emp)->fresh()->missed_checkout_fine_amount);
    }

    public function test_denda_lupa_checkout_dibiarkan_bila_jam_pulang_tidak_diubah(): void
    {
        $office = $this->office();
        $emp    = $this->employee($office);
        $hrd    = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office, [
            'missed_checkout_fine_amount' => 25000,
            'is_auto_checkout'            => true,
        ]);

        $this->correct($hrd, $emp, ['check_in_time' => '08:30'])->assertOk();

        $this->assertEquals(25000, (float) $this->row($emp)->fresh()->missed_checkout_fine_amount);
    }

    public function test_status_alpha_membersihkan_semua_denda_dan_premi(): void
    {
        $office = $this->office();
        $emp    = $this->employee($office);
        $hrd    = $this->hrd($office);
        $this->seedSnapshotRow($emp, $office, [
            'status'                      => 'early_leave',
            'early_leave_minutes'         => 60,
            'early_leave_fine_amount'     => 60000,
            'missed_checkout_fine_amount' => 25000,
            'night_shift_bonus_amount'    => 75000,
            'is_auto_checkout'            => true,
        ]);

        $this->correct($hrd, $emp, ['status' => 'absent'])->assertOk();

        $att = $this->row($emp)->fresh();
        $this->assertSame('absent', $att->status);
        // Karyawan yang tidak hadir tidak mungkin pulang cepat / lupa check-out,
        // dan tidak menjalani shift malam. Sanksinya diambil kebijakan alpha di payroll.
        $this->assertNull($att->early_leave_minutes);
        $this->assertEquals(0, (float) $att->early_leave_fine_amount);
        $this->assertNull($att->missed_checkout_fine_amount);
        $this->assertNull($att->night_shift_bonus_amount);
    }

    // ═════════════ Pengaturan kantor: gerbang nominal ═════════════

    private function ubah(User $hrd, AttendanceSetting $office, array $payload)
    {
        return $this->actingAs($hrd, 'sanctum')->putJson(
            "/api/v1/dashboard/attendance/settings/{$office->id}",
            $payload + ['confirm_dangerous' => 'SIMPAN']
        );
    }

    public function test_mode_berbasis_denda_wajib_mengisi_nominal(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        // Ditulis satu per satu agar pesan kegagalan menunjuk mode yang salah.
        $this->ubah($hrd, $office, ['early_leave_fine_mode' => 'per_minute'])
            ->assertStatus(422)->assertJsonValidationErrors('early_leave_fine_amount');
        $this->ubah($hrd, $office, ['early_leave_fine_mode' => 'flat'])
            ->assertStatus(422)->assertJsonValidationErrors('early_leave_fine_amount');
        $this->ubah($hrd, $office, ['alpha_penalty_mode' => 'prorata_plus_fine'])
            ->assertStatus(422)->assertJsonValidationErrors('alpha_fine_flat');
        $this->ubah($hrd, $office, ['unpaid_leave_penalty_mode' => 'flat'])
            ->assertStatus(422)->assertJsonValidationErrors('unpaid_leave_fine_flat');
        $this->ubah($hrd, $office, ['missed_checkout_penalty_mode' => 'flat_fine'])
            ->assertStatus(422)->assertJsonValidationErrors('missed_checkout_fine_amount');
    }

    public function test_mode_prorata_tidak_wajib_mengisi_nominal(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        // Besaran prorata diturunkan payroll dari gaji pokok karyawan.
        $this->ubah($hrd, $office, ['early_leave_fine_mode' => 'prorata'])->assertOk();
        $this->ubah($hrd, $office, ['unpaid_leave_penalty_mode' => 'prorata'])->assertOk();
        $this->ubah($hrd, $office, ['alpha_penalty_mode' => 'none'])->assertOk();

        $office->refresh();
        $this->assertSame('prorata', $office->early_leave_fine_mode);
        $this->assertSame('prorata', $office->unpaid_leave_penalty_mode);
        $this->assertSame('none', $office->alpha_penalty_mode);
    }

    public function test_bonus_yang_diaktifkan_wajib_mengisi_nominal(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $this->ubah($hrd, $office, ['perfect_attendance_bonus_enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('perfect_attendance_bonus_amount');
        $this->ubah($hrd, $office, ['night_shift_bonus_enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('night_shift_bonus_amount');

        $this->ubah($hrd, $office, [
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500000,
            'night_shift_bonus_enabled'        => true,
            'night_shift_bonus_amount'         => 75000,
        ])->assertOk();
    }

    public function test_batas_maksimum_lebih_kecil_dari_denda_flat_ditolak(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $this->ubah($hrd, $office, [
            'early_leave_fine_mode'   => 'flat',
            'early_leave_fine_amount' => 50000,
            'early_leave_fine_max'    => 20000,
        ])->assertStatus(422)->assertJsonValidationErrors('early_leave_fine_max');

        $this->ubah($hrd, $office, [
            'early_leave_fine_mode'   => 'flat',
            'early_leave_fine_amount' => 50000,
            'early_leave_fine_max'    => 50000,
        ])->assertOk();
    }

    public function test_mode_tanpa_nominal_ditolak_memakai_nilai_tersimpan(): void
    {
        // HRD hanya mengirim mode baru; nominal tersimpan masih kosong, jadi
        // gerbang harus menahannya walau payloadnya sendiri "bersih".
        $office = $this->office(['early_leave_fine_amount' => null]);
        $hrd    = $this->hrd($office);

        $this->ubah($hrd, $office, ['early_leave_fine_mode' => 'per_minute'])
            ->assertStatus(422)->assertJsonValidationErrors('early_leave_fine_amount');
    }

    public function test_mode_tanpa_nominal_diterima_bila_nominal_tersimpan_sudah_ada(): void
    {
        $office = $this->office(['early_leave_fine_amount' => 2000]);
        $hrd    = $this->hrd($office);

        $this->ubah($hrd, $office, ['early_leave_fine_mode' => 'per_minute'])->assertOk();

        $office->refresh();
        $this->assertSame('per_minute', $office->early_leave_fine_mode);
        $this->assertEquals(2000, (float) $office->early_leave_fine_amount);
    }

    // ═════════════ Pengaturan kantor: gerbang konfirmasi SIMPAN ═════════════

    public function test_perubahan_kebijakan_denda_bonus_butuh_konfirmasi_simpan(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $payload = [
            'early_leave_fine_mode'            => 'per_minute',
            'early_leave_fine_amount'          => 1000,
            'early_leave_fine_max'             => 80000,
            'alpha_penalty_mode'               => 'prorata_plus_fine',
            'alpha_fine_flat'                  => 100000,
            'unpaid_leave_penalty_mode'        => 'flat',
            'unpaid_leave_fine_flat'           => 150000,
            'missed_checkout_penalty_mode'     => 'flat_fine',
            'missed_checkout_fine_amount'      => 25000,
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500000,
            'perfect_attendance_allow_leave'   => false,
            'night_shift_bonus_enabled'        => true,
            'night_shift_bonus_amount'         => 75000,
        ];

        $res = $this->actingAs($hrd, 'sanctum')
            ->putJson("/api/v1/dashboard/attendance/settings/{$office->id}", $payload);

        $res->assertStatus(422)->assertJsonPath('requires_confirmation', true);
        foreach (array_keys($payload) as $field) {
            $this->assertContains($field, $res->json('dangerous_changed_fields'));
        }

        $this->actingAs($hrd, 'sanctum')->putJson(
            "/api/v1/dashboard/attendance/settings/{$office->id}",
            $payload + ['confirm_dangerous' => 'SIMPAN']
        )->assertOk();

        $office->refresh();
        $this->assertSame('per_minute', $office->early_leave_fine_mode);
        $this->assertSame('prorata_plus_fine', $office->alpha_penalty_mode);
        $this->assertEquals(100000, (float) $office->alpha_fine_flat);
        $this->assertSame('flat', $office->unpaid_leave_penalty_mode);
        $this->assertSame('flat_fine', $office->missed_checkout_penalty_mode);
        $this->assertTrue((bool) $office->perfect_attendance_bonus_enabled);
        $this->assertFalse((bool) $office->perfect_attendance_allow_leave);
        $this->assertTrue((bool) $office->night_shift_bonus_enabled);
        $this->assertEquals(75000, (float) $office->night_shift_bonus_amount);
    }

    public function test_ambang_surat_peringatan_tidak_butuh_konfirmasi(): void
    {
        // alpha_sp_threshold hanya informatif — tidak memotong rupiah sepeser pun.
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $res = $this->actingAs($hrd, 'sanctum')
            ->putJson("/api/v1/dashboard/attendance/settings/{$office->id}", ['alpha_sp_threshold' => 5]);

        $res->assertOk();
        $this->assertSame([], $res->json('dangerous_changed_fields'));
        $this->assertSame(5, (int) $office->fresh()->alpha_sp_threshold);
    }

    public function test_nilai_sama_bukan_perubahan_berbahaya(): void
    {
        $office = $this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
        ]);
        $hrd = $this->hrd($office);

        $this->actingAs($hrd, 'sanctum')
            ->putJson("/api/v1/dashboard/attendance/settings/{$office->id}", [
                'early_leave_fine_mode'     => 'per_minute',
                'early_leave_fine_amount'   => '1000.00',
                'alpha_penalty_mode'        => 'prorata',
                'unpaid_leave_penalty_mode' => 'none',
                'night_shift_bonus_enabled' => false,
            ])->assertOk();
    }

    public function test_validasi_nilai_kebijakan_denda_bonus(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $this->ubah($hrd, $office, ['early_leave_fine_mode' => 'ngawur'])
            ->assertStatus(422)->assertJsonValidationErrors('early_leave_fine_mode');
        $this->ubah($hrd, $office, ['alpha_penalty_mode' => 'ngawur'])
            ->assertStatus(422)->assertJsonValidationErrors('alpha_penalty_mode');
        $this->ubah($hrd, $office, ['unpaid_leave_penalty_mode' => 'ngawur'])
            ->assertStatus(422)->assertJsonValidationErrors('unpaid_leave_penalty_mode');
        $this->ubah($hrd, $office, ['missed_checkout_penalty_mode' => 'ngawur'])
            ->assertStatus(422)->assertJsonValidationErrors('missed_checkout_penalty_mode');
        $this->ubah($hrd, $office, ['alpha_fine_flat' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('alpha_fine_flat');
        $this->ubah($hrd, $office, ['alpha_sp_threshold' => 99])
            ->assertStatus(422)->assertJsonValidationErrors('alpha_sp_threshold');
        $this->ubah($hrd, $office, ['night_shift_bonus_amount' => -5])
            ->assertStatus(422)->assertJsonValidationErrors('night_shift_bonus_amount');
    }

    public function test_mode_null_kembali_ke_default_netral(): void
    {
        $office = $this->office([
            'early_leave_fine_mode'        => 'flat',
            'early_leave_fine_amount'      => 50000,
            'alpha_penalty_mode'           => 'none',
            'unpaid_leave_penalty_mode'    => 'prorata',
            'missed_checkout_penalty_mode' => 'mark_early_leave',
        ]);
        $hrd = $this->hrd($office);

        $this->ubah($hrd, $office, [
            'early_leave_fine_mode'        => null,
            'alpha_penalty_mode'           => null,
            'unpaid_leave_penalty_mode'    => null,
            'missed_checkout_penalty_mode' => null,
        ])->assertOk();

        $office->refresh();
        $this->assertSame('none', $office->early_leave_fine_mode);
        // Default alpha = 'prorata' (perilaku potongan ketidakhadiran lama).
        $this->assertSame('prorata', $office->alpha_penalty_mode);
        $this->assertSame('none', $office->unpaid_leave_penalty_mode);
        $this->assertSame('none', $office->missed_checkout_penalty_mode);
    }

    public function test_nominal_null_mengosongkan_denda(): void
    {
        $office = $this->office([
            'early_leave_fine_mode'   => 'per_minute',
            'early_leave_fine_amount' => 1000,
            'early_leave_fine_max'    => 80000,
        ]);
        $hrd = $this->hrd($office);

        $this->ubah($hrd, $office, [
            'early_leave_fine_mode'   => 'none',
            'early_leave_fine_amount' => null,
            'early_leave_fine_max'    => null,
        ])->assertOk();

        $office->refresh();
        $this->assertNull($office->early_leave_fine_amount);
        $this->assertNull($office->early_leave_fine_max);
    }

    public function test_buat_kantor_menyimpan_kebijakan_denda_bonus(): void
    {
        $hrd = $this->hrd($this->office());

        $res = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/settings', [
            'office_name'                      => 'Cabang Malam',
            'office_latitude'                  => -6.9,
            'office_longitude'                 => 107.6,
            'radius_meters'                    => 150,
            'work_start_time'                  => '22:00',
            'work_end_time'                    => '06:00',
            'work_days'                        => [1, 2, 3, 4, 5],
            'default_leave_quota'              => 12,
            'early_leave_fine_mode'            => 'per_minute',
            'early_leave_fine_amount'          => 1000,
            'alpha_penalty_mode'               => 'prorata_plus_fine',
            'alpha_fine_flat'                  => 100000,
            'alpha_sp_threshold'               => 3,
            'unpaid_leave_penalty_mode'        => 'prorata',
            'missed_checkout_penalty_mode'     => 'flat_fine',
            'missed_checkout_fine_amount'      => 25000,
            'perfect_attendance_bonus_enabled' => true,
            'perfect_attendance_bonus_amount'  => 500000,
            'night_shift_bonus_enabled'        => true,
            'night_shift_bonus_amount'         => 75000,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('attendance_settings', [
            'id'                           => $res->json('setting.id'),
            'early_leave_fine_mode'        => 'per_minute',
            'alpha_penalty_mode'           => 'prorata_plus_fine',
            'unpaid_leave_penalty_mode'    => 'prorata',
            'missed_checkout_penalty_mode' => 'flat_fine',
            'night_shift_bonus_enabled'    => true,
        ]);
    }

    public function test_buat_kantor_dengan_bonus_aktif_tanpa_nominal_ditolak(): void
    {
        $hrd = $this->hrd($this->office());

        $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/settings', [
            'office_name'                      => 'Cabang Gagal',
            'office_latitude'                  => -6.9,
            'office_longitude'                 => 107.6,
            'radius_meters'                    => 150,
            'work_start_time'                  => '08:00',
            'work_end_time'                    => '17:00',
            'work_days'                        => [1, 2, 3, 4, 5],
            'default_leave_quota'              => 12,
            'perfect_attendance_bonus_enabled' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('perfect_attendance_bonus_amount');
    }
}
