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
 * SNAPSHOT PENGATURAN KANTOR SAAT CHECK-IN (2026-08-26)
 *
 * Menjamin: perubahan pengaturan kantor (jam kerja, radius, dsb.) di tengah hari
 * TIDAK mengubah perhitungan presensi karyawan yang sudah check-in pagi hari itu.
 * Sekalian menguji gerbang konfirmasi "SIMPAN" pada updateSettings().
 *
 * CATATAN: gunakan actingAs() per request — Bearer token mentah terkena cache
 * RequestGuard Sanctum antar-request di dalam satu test (user pertama "lengket").
 * Produksi tidak terdampak (satu request = satu proses).
 */
class SettingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Test', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, bool $wfh = true, bool $radius = false): User
    {
        return User::factory()->create([
            'company_id'         => $this->company->id,
            'role'               => $role,
            'attendance_enabled' => true,
            'wfh_enabled'        => $wfh,
            'radius_enabled'     => $radius,
            'is_active'          => true,
        ]);
    }

    private function office(float $lat = -6.20, float $lng = 106.81666700, int $radius = 100): AttendanceSetting
    {
        return AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'HQ',
            'office_latitude'        => $lat,
            'office_longitude'       => $lng,
            'radius_meters'          => $radius,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
            // reminder 30 & grace 60 pakai default migration
        ]);
    }

    private function hrd(): User
    {
        return $this->user('hrd');
    }

    private function ubahKantor(User $hrd, AttendanceSetting $office, array $payload, bool $confirm)
    {
        if ($confirm) {
            $payload['confirm_dangerous'] = 'SIMPAN';
        }

        return $this->actingAs($hrd, 'sanctum')->putJson(
            "/api/v1/dashboard/attendance/settings/{$office->id}",
            $payload
        );
    }

    private function checkIn(User $emp, float $lat = -7.00, float $lng = 110.00)
    {
        return $this->actingAs($emp, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude'  => $lat,
            'longitude' => $lng,
        ]);
    }

    private function checkOut(User $emp, float $lat = -7.00, float $lng = 110.00)
    {
        return $this->actingAs($emp, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude'  => $lat,
            'longitude' => $lng,
        ]);
    }

    // ── 1. Check-in menulis snapshot ────────────────────────────────
    public function test_checkin_menulis_snapshot_pengaturan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', 'Asia/Jakarta')); // Jumat
        $office = $this->office();

        $emp = $this->user('employee');
        $this->checkIn($emp)->assertCreated();

        $this->assertDatabaseHas('attendances', [
            'user_id'               => $emp->id,
            'snap_source'           => 'office',
            'snap_work_start_time'  => '08:00:00',
            'snap_work_end_time'    => '17:00:00',
            'snap_grace_minutes'    => 60,
            'snap_reminder_minutes' => 30,
            'snap_office_id'        => $office->id,
        ]);
    }

    // ── 2. Perubahan jam pulang siang hari tidak mengubah checkout karyawan yang sudah masuk ──
    public function test_perubahan_jam_pulang_siang_tidak_mempengaruhi_checkout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', 'Asia/Jakarta'));
        $office = $this->office();
        $emp    = $this->user('employee');

        $this->checkIn($emp)->assertCreated();

        // Jam 12:00 HRD mempercepat jam pulang 17:00 → 15:00 (dengan konfirmasi SIMPAN)
        Carbon::setTestNow(Carbon::parse('2026-06-19 12:00:00', 'Asia/Jakarta'));
        $this->ubahKantor($this->hrd(), $office, ['work_end_time' => '15:00'], true)
            ->assertOk();

        // Karyawan checkout 17:30 — dengan aturan LAMA (17:00) lemburnya 30 menit.
        // Bila bug (baca setting live 15:00), lembur akan salah jadi 150 menit.
        Carbon::setTestNow(Carbon::parse('2026-06-19 17:30:00', 'Asia/Jakarta'));
        $this->checkOut($emp)
            ->assertOk()
            ->assertJsonPath('attendance.overtime_minutes', 30);

        $this->assertDatabaseHas('attendances', [
            'user_id'          => $emp->id,
            'overtime_minutes' => 30,
            'work_minutes'     => 570, // 08:00 → 17:30
        ]);
    }

    // ── 3. Radius checkout memakai radius snapshot saat check-in ────
    public function test_perubahan_radius_siang_tidak_menolak_checkout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', 'Asia/Jakarta'));
        // Titik presensi ±50 m dari kantor (masih dalam radius 100 m)
        $lat = -6.20045;
        $this->office(lat: $lat);
        $emp = $this->user('employee', wfh: true, radius: true); // radius_enabled → mode lapangan

        $this->checkIn($emp, $lat, 106.81666700)->assertCreated();

        // Siang hari HRD memperkecil radius 100 → 10 m (dengan konfirmasi)
        Carbon::setTestNow(Carbon::parse('2026-06-19 12:00:00', 'Asia/Jakarta'));
        $this->ubahKantor($this->hrd(), AttendanceSetting::first(), ['radius_meters' => 10], true)
            ->assertOk();

        // Checkout 17:30 dari titik sama — tetap LOLOS karena radius saat check-in 100 m.
        // Bila bug (baca radius live 10 m), checkout ditolak 403 out-of-radius.
        Carbon::setTestNow(Carbon::parse('2026-06-19 17:30:00', 'Asia/Jakarta'));
        $this->checkOut($emp, $lat, 106.81666700)
            ->assertOk()
            ->assertJsonPath('attendance.status', 'present');
    }

    // ── 4. Auto-checkout command memakai grace & jam pulang snapshot ─
    public function test_auto_checkout_command_memakai_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', 'Asia/Jakarta'));
        $office = $this->office();
        $emp    = $this->user('employee');

        $this->checkIn($emp)->assertCreated();

        // Siang hari: jam pulang dipercepat 15:00 & grace dipangkas 60 → 35 (tetap > reminder 30)
        Carbon::setTestNow(Carbon::parse('2026-06-19 12:00:00', 'Asia/Jakarta'));
        $this->ubahKantor($this->hrd(), $office, [
            'work_end_time'               => '15:00',
            'auto_checkout_grace_minutes' => 35,
        ], true)->assertOk();

        // Cron jalan 17:36 — dengan SNAPSHOT batas auto-checkout tetap 17:00 + 60 = 18:00,
        // sehingga BELUM ada yang ditutup. Bila bug (baca setting live), batas jadi
        // 15:00 + 35 = 15:35 dan presensi sudah tertutup.
        Carbon::setTestNow(Carbon::parse('2026-06-19 17:36:00', 'Asia/Jakarta'));
        Artisan::call('attendance:auto-checkout');

        $this->assertDatabaseMissing('attendances', [
            'user_id'          => $emp->id,
            'is_auto_checkout' => true,
        ]);

        // Lewat 18:01 (batas snapshot terlewati) → di-auto-checkout dengan jam pulang lama:
        // lembur dihitung dari jam pulang SNAPSHOT 17:00 (61 menit), bukan 15:00.
        Carbon::setTestNow(Carbon::parse('2026-06-19 18:01:00', 'Asia/Jakarta'));
        Artisan::call('attendance:auto-checkout');

        $att = Attendance::where('user_id', $emp->id)->first();
        $this->assertTrue((bool) $att->is_auto_checkout);
        $this->assertEquals(61, $att->overtime_minutes);
    }

    // ── 5. Field berbahaya tanpa konfirmasi "SIMPAN" → 422 ──────────
    public function test_ubah_field_berbahaya_tanpa_konfirmasi_ditolak(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd();

        // Nilai sama dengan tersimpan → BUKAN perubahan berbahaya → tidak minta konfirmasi
        $this->ubahKantor($hrd, $office, ['work_end_time' => '17:00'], false)
            ->assertOk();

        // Perubahan nyata tanpa konfirmasi → 422 + info konfirmasi
        $this->ubahKantor($hrd, $office, ['work_end_time' => '15:00'], false)
            ->assertStatus(422)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('confirmation_phrase', 'SIMPAN')
            ->assertJsonPath('dangerous_changed_fields.0', 'work_end_time');

        // Dengan konfirmasi "SIMPAN" → berhasil
        $this->ubahKantor($hrd, $office, ['work_end_time' => '15:00'], true)
            ->assertOk();

        $this->assertDatabaseHas('attendance_settings', [
            'id'            => $office->id,
            'work_end_time' => '15:00',
        ]);
    }

    // ── 6. Field aman (nama kantor) tidak butuh konfirmasi ──────────
    public function test_ubah_field_aman_tanpa_konfirmasi_berhasil(): void
    {
        $office = $this->office();

        $this->ubahKantor($this->hrd(), $office, ['office_name' => 'HQ Pusat'], false)
            ->assertOk()
            ->assertJsonPath('dangerous_changed_fields', []);

        $this->assertDatabaseHas('attendance_settings', [
            'id'          => $office->id,
            'office_name' => 'HQ Pusat',
        ]);
    }
}
