<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceReminderPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $wfhUser;
    private User $dinasLuarUser;
    private User $pureOfficeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Sinergi Mandiri',
            'is_active' => true,
        ]);

        // Jam kantor: 08:00 - 17:00, cutoff telat 120 menit (pukul 10:00 WIB)
        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Pusat Sudirman',
            'office_latitude'             => -6.2000000,
            'office_longitude'            => 106.8166670,
            'radius_meters'               => 150,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'late_tolerance_minutes'      => 15,
            'late_checkin_cutoff_minutes' => 120, // cutoff jam 10:00 WIB
        ]);

        // 1. Karyawan WFH (wfh on, dinas luar off) -> BERLAKU
        $this->wfhUser = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'dinas_luar_enabled'    => false,
            'fcm_token'             => 'fake_fcm_token_wfh_123',
        ]);

        // 2. Karyawan Dinas Luar (wfh off, dinas luar on) -> BERLAKU
        $this->dinasLuarUser = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'wfh_enabled'           => false,
            'dinas_luar_enabled'    => true,
            'fcm_token'             => 'fake_fcm_token_field_456',
        ]);

        // 3. Karyawan Kantor Murni (wfh off, dinas luar off) -> TIDAK BERLAKU
        $this->pureOfficeUser = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'wfh_enabled'           => false,
            'dinas_luar_enabled'    => false,
            'fcm_token'             => 'fake_fcm_token_office_789',
        ]);
    }

    public function test_checkin_reminder_sent_15_minutes_before_work_start(): void
    {
        // 07:48 WIB = 12 menit sebelum jam masuk 08:00 WIB (masuk window [07:45 - 08:05])
        Carbon::setTestNow('2026-09-16 07:48:00');

        Artisan::call('attendance:send-reminders');

        // WFH user & Dinas Luar user menerima notifikasi jam masuk
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->wfhUser->id,
            'type'    => 'checkin_reminder',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->dinasLuarUser->id,
            'type'    => 'checkin_reminder',
        ]);

        // Karyawan kantor murni TIDAK menerima notifikasi
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->pureOfficeUser->id,
        ]);
    }

    public function test_checkin_reminder_not_sent_if_already_checked_in(): void
    {
        Carbon::setTestNow('2026-09-16 07:48:00');

        // WFH user sudah check in lebih awal pada jam 07:30
        Attendance::create([
            'user_id'       => $this->wfhUser->id,
            'company_id'    => $this->company->id,
            'date'          => '2026-09-16',
            'check_in_time' => '2026-09-16 07:30:00',
            'check_in_type' => 'wfh',
            'status'        => 'present',
        ]);

        Artisan::call('attendance:send-reminders');

        // Karena sudah check-in, wfhUser TIDAK menerima checkin_reminder
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->wfhUser->id,
            'type'    => 'checkin_reminder',
        ]);

        // Dinas Luar user yang belum check-in tetap menerima
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->dinasLuarUser->id,
            'type'    => 'checkin_reminder',
        ]);
    }

    public function test_checkout_reminder_sent_at_work_end_for_checked_in_employee(): void
    {
        // Karyawan sudah check in jam 08:05 pagi tadi
        $att = Attendance::create([
            'user_id'       => $this->wfhUser->id,
            'company_id'    => $this->company->id,
            'date'          => '2026-09-16',
            'check_in_time' => '2026-09-16 08:05:00',
            'check_in_type' => 'wfh',
            'status'        => 'present',
        ]);

        // Jam pulang kantor adalah 17:00 WIB. Test pada 17:05 WIB
        Carbon::setTestNow('2026-09-16 17:05:00');

        Artisan::call('attendance:send-reminders');

        // Menerima reminder jam kerja selesai / checkout
        $this->assertDatabaseHas('notifications', [
            'user_id'   => $this->wfhUser->id,
            'type'      => 'checkout_reminder',
            'entity_id' => $att->id,
        ]);
    }

    public function test_checkout_reminder_not_sent_if_already_checked_out(): void
    {
        // Karyawan sudah checkout pada 16:58
        Attendance::create([
            'user_id'        => $this->wfhUser->id,
            'company_id'     => $this->company->id,
            'date'           => '2026-09-16',
            'check_in_time'  => '2026-09-16 08:05:00',
            'check_out_time' => '2026-09-16 16:58:00',
            'check_in_type'  => 'wfh',
            'status'         => 'present',
        ]);

        Carbon::setTestNow('2026-09-16 17:05:00');

        Artisan::call('attendance:send-reminders');

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->wfhUser->id,
            'type'    => 'checkout_reminder',
        ]);
    }

    public function test_cutoff_alpha_warning_sent_15_minutes_before_cutoff(): void
    {
        // Jam masuk 08:00, cutoff 120 menit = 10:00 WIB
        // 15 menit sebelum cutoff = 09:45 WIB. Test pada 09:48 WIB
        Carbon::setTestNow('2026-09-16 09:48:00');

        Artisan::call('attendance:send-reminders');

        // Belum check-in dan mendekati cutoff -> menerima cutoff_warning
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->wfhUser->id,
            'type'    => 'cutoff_warning',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->dinasLuarUser->id,
            'type'    => 'cutoff_warning',
        ]);

        // Pure office user tetap tidak menerima
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->pureOfficeUser->id,
        ]);
    }

    public function test_reminders_not_sent_if_employee_is_on_approved_leave(): void
    {
        Carbon::setTestNow('2026-09-16 07:48:00');

        // WFH user sedang cuti tahunan yang disetujui untuk hari ini
        LeaveRequest::create([
            'user_id'    => $this->wfhUser->id,
            'company_id' => $this->company->id,
            'leave_type' => 'cuti',
            'start_date' => '2026-09-16',
            'end_date'   => '2026-09-16',
            'total_days' => 1,
            'status'     => 'approved',
            'reason'     => 'Cuti acara keluarga',
        ]);

        Artisan::call('attendance:send-reminders');

        // Tidak dikirimkan ke wfhUser karena sedang cuti
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->wfhUser->id,
        ]);

        // Dinas Luar user yang tidak cuti tetap menerima
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->dinasLuarUser->id,
            'type'    => 'checkin_reminder',
        ]);
    }

    public function test_reminders_not_sent_on_company_holiday(): void
    {
        Carbon::setTestNow('2026-09-16 07:48:00');

        // Hari ini adalah hari libur nasional / perusahaan
        Holiday::create([
            'company_id' => $this->company->id,
            'name'       => 'Libur Perusahaan Khusus',
            'date'       => '2026-09-16',
        ]);

        Artisan::call('attendance:send-reminders');

        // Tidak ada notifikasi karena hari ini libur
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->wfhUser->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->dinasLuarUser->id,
        ]);
    }

    public function test_deduplication_prevents_duplicate_notifications_on_subsequent_runs(): void
    {
        Carbon::setTestNow('2026-09-16 07:48:00');

        // Eksekusi pertama
        Artisan::call('attendance:send-reminders');
        $initialCount = DB::table('notifications')->where('user_id', $this->wfhUser->id)->count();
        $this->assertEquals(1, $initialCount);

        // Eksekusi kedua 5 menit kemudian (07:53 WIB, masih dalam window)
        Carbon::setTestNow('2026-09-16 07:53:00');
        Artisan::call('attendance:send-reminders');

        // Jumlah notifikasi tetap 1 (tidak diduplikasi)
        $newCount = DB::table('notifications')->where('user_id', $this->wfhUser->id)->count();
        $this->assertEquals(1, $newCount);
    }
}
