<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NightShiftK3ProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private Shift $nightShift;
    private Shift $dayShift;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-04 08:00:00');

        $this->company = Company::create(['name' => 'PT K3 Berjaya', 'is_active' => true]);
        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Utama',
            'office_latitude'             => -6.20,
            'office_longitude'            => 106.816667,
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

        // Shift Malam: 23:00 - 07:00 (cross-day)
        $this->nightShift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam Pabrik',
            'is_active'             => true,
            'color'                 => '#6366f1',
        ]);
        for ($day = 0; $day <= 6; $day++) {
            ShiftSchedule::create([
                'shift_id'        => $this->nightShift->id,
                'effective_date'  => '2026-09-01',
                'day_of_week'     => $day,
                'work_start_time' => '23:00:00',
                'work_end_time'   => '07:00:00',
                'break_minutes'   => 60,
                'is_off'          => $day === 0, // libur Minggu
                'is_cross_day'    => true,
            ]);
        }

        // Shift Siang: 08:00 - 17:00 (bukan shift malam)
        $this->dayShift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Normal Siang',
            'is_active'             => true,
            'color'                 => '#10b981',
        ]);
        for ($day = 0; $day <= 6; $day++) {
            ShiftSchedule::create([
                'shift_id'        => $this->dayShift->id,
                'effective_date'  => '2026-09-01',
                'day_of_week'     => $day,
                'work_start_time' => '08:00:00',
                'work_end_time'   => '17:00:00',
                'break_minutes'   => 60,
                'is_off'          => $day === 0,
                'is_cross_day'    => false,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_underage_worker_below_17_is_blocked_from_night_shift(): void
    {
        // Pekerja berusia 16 tahun (lahir 2010-01-01, start 2026-09-05)
        $minor = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Budi Minor',
            'birth_date'            => '2010-01-01',
            'gender'                => 'Laki-laki',
            'attendance_setting_id' => $this->office->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $minor->id,
            'shift_id'   => $this->nightShift->id,
            'start_date' => '2026-09-05',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => "Penugasan shift malam (23:00–07:00) ditolak. Karyawan Budi Minor masih berusia 16 tahun (di bawah batas usia minimum 17 tahun). Sesuai standar perlindungan K3, pekerja di bawah umur dilarang ditugaskan pada shift malam.",
            ]);
    }

    public function test_worker_aged_17_is_allowed_night_shift_with_advisory_warning(): void
    {
        // Pekerja berusia 17 tahun (lahir 2009-01-01, start 2026-09-05 -> umur 17)
        $youngAdult = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Dedi Muda',
            'birth_date'            => '2009-01-01',
            'gender'                => 'Laki-laki',
            'attendance_setting_id' => $this->office->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $youngAdult->id,
            'shift_id'   => $this->nightShift->id,
            'start_date' => '2026-09-05',
        ]);

        $response->assertStatus(201);
        $this->assertStringContainsString("berhasil di-assign", $response->json('message'));
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertStringContainsString("berusia 17 tahun", $response->json('warnings.0'));
    }

    public function test_pregnant_worker_is_blocked_from_night_shift(): void
    {
        // Pekerja perempuan dewasa hamil (usia 25 tahun, is_pregnant = true)
        $pregnantWorker = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Siti Hamil',
            'birth_date'            => '2000-01-01',
            'gender'                => 'Perempuan',
            'is_pregnant'           => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $pregnantWorker->id,
            'shift_id'   => $this->nightShift->id,
            'start_date' => '2026-09-05',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => "Penugasan shift malam (23:00–07:00) ditolak. Karyawan Siti Hamil tercatat dalam kondisi hamil. Sesuai UU No. 13/2003 Pasal 76 ayat (1), pekerja perempuan hamil dilarang dipekerjakan pada shift malam.",
            ]);
    }

    public function test_female_non_pregnant_worker_gets_k3_amenity_warning_on_night_shift(): void
    {
        // Pekerja perempuan dewasa tidak hamil (usia 24 tahun)
        $femaleWorker = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Rina Dewasa',
            'birth_date'            => '2001-05-10',
            'gender'                => 'Perempuan',
            'is_pregnant'           => false,
            'attendance_setting_id' => $this->office->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $femaleWorker->id,
            'shift_id'   => $this->nightShift->id,
            'start_date' => '2026-09-05',
        ]);

        $response->assertStatus(201);
        $warnings = $response->json('warnings');
        $this->assertTrue(collect($warnings)->some(fn ($w) => str_contains($w, 'makanan/minuman bergizi')));
    }

    public function test_worker_without_birth_date_gets_missing_profile_warning_on_night_shift(): void
    {
        // Karyawan belum ada birth_date
        $workerNoBirthDate = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Joko Anon',
            'birth_date'            => null,
            'gender'                => 'Laki-laki',
            'attendance_setting_id' => $this->office->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $workerNoBirthDate->id,
            'shift_id'   => $this->nightShift->id,
            'start_date' => '2026-09-05',
        ]);

        $response->assertStatus(201);
        $warnings = $response->json('warnings');
        $this->assertTrue(collect($warnings)->some(fn ($w) => str_contains($w, 'Data tanggal lahir')));
    }

    public function test_underage_worker_can_still_work_day_shift(): void
    {
        // Pekerja 16 tahun boleh bekerja di shift siang (08:00 - 17:00)
        $minor = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Agus Siang',
            'birth_date'            => '2010-01-01',
            'gender'                => 'Laki-laki',
            'attendance_setting_id' => $this->office->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'    => $minor->id,
            'shift_id'   => $this->dayShift->id,
            'start_date' => '2026-09-05',
        ]);

        $response->assertStatus(201);
    }

    public function test_bulk_assign_skips_underage_and_pregnant_workers_with_k3_reason(): void
    {
        $minor = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Doni Anak',
            'birth_date'            => '2011-03-01',
            'attendance_setting_id' => $this->office->id,
        ]);

        $pregnant = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Dewi Hamil',
            'birth_date'            => '1998-07-07',
            'gender'                => 'Perempuan',
            'is_pregnant'           => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        $adult = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Bambang Dewasa',
            'birth_date'            => '1995-10-10',
            'gender'                => 'Laki-laki',
            'attendance_setting_id' => $this->office->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/dashboard/attendance/bulk-assign', [
            'user_ids'   => [$minor->id, $pregnant->id, $adult->id],
            'shift_id'   => $this->nightShift->id,
            'start_date' => '2026-09-05',
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertEquals(1, $data['total_success']);
        $this->assertEquals(2, $data['total_skipped']);

        // Pastikan minor dan pregnant masuk di array dilewati
        $dilewati = collect($data['dilewati']);
        $this->assertTrue($dilewati->contains('user_id', $minor->id));
        $this->assertTrue($dilewati->contains('user_id', $pregnant->id));
    }
}
