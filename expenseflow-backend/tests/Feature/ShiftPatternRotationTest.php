<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ShiftController;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftPatternRotationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;
    private User $admin;
    private User $employeeA;
    private User $employeeB;
    private Shift $shiftPagi;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 07:00:00');

        $this->company = Company::create(['name' => 'PT Manufaktur Presisi', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Pabrik Utama',
            'office_latitude'             => -6.20000000,
            'office_longitude'            => 106.81666700,
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

        $this->employeeA = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Budi Operator A',
            'birth_date'            => '1996-03-10',
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        $this->employeeB = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Joko Operator B',
            'birth_date'            => '1998-07-20',
            'attendance_enabled'    => true,
            'wfh_enabled'           => true,
            'attendance_setting_id' => $this->office->id,
        ]);

        $this->shiftPagi = Shift::create([
            'company_id' => $this->company->id,
            'name'       => 'Shift Pagi (07:00 - 15:00)',
            'color'      => '#3b82f6',
            'is_active'  => true,
        ]);

        // Shift schedule untuk shift pagi
        for ($dow = 0; $dow <= 6; $dow++) {
            ShiftSchedule::create([
                'shift_id'        => $this->shiftPagi->id,
                'day_of_week'     => $dow,
                'effective_date'  => '2026-01-01',
                'work_start_time' => '07:00:00',
                'work_end_time'   => '15:00:00',
                'break_minutes'   => 60,
                'is_off'          => false,
            ]);
        }
    }

    /**
     * Skenario 1: Admin dapat membuat, melihat, memperbarui, dan menghapus Pola Rotasi (CRUD).
     */
    public function test_admin_can_create_and_manage_shift_pattern_with_cycle_items(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. Buat Pola 4-2 (Siklus 6 Hari: 4 Hari Kerja, 2 Hari Libur)
        $payload = [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola 4-2 Pabrik',
            'description'           => '4 Hari Shift Pagi, 2 Hari Libur',
            'cycle_days'            => 6,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'shift_id' => $this->shiftPagi->id, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60, 'is_cross_day' => false],
                ['day_order' => 2, 'shift_id' => $this->shiftPagi->id, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60, 'is_cross_day' => false],
                ['day_order' => 3, 'shift_id' => $this->shiftPagi->id, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60, 'is_cross_day' => false],
                ['day_order' => 4, 'shift_id' => $this->shiftPagi->id, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60, 'is_cross_day' => false],
                ['day_order' => 5, 'shift_id' => null, 'is_off' => true, 'work_start_time' => null, 'work_end_time' => null, 'break_minutes' => 60, 'is_cross_day' => false],
                ['day_order' => 6, 'shift_id' => null, 'is_off' => true, 'work_start_time' => null, 'work_end_time' => null, 'break_minutes' => 60, 'is_cross_day' => false],
            ],
        ];

        $response = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', $payload);
        $response->assertStatus(201);
        $patternId = $response->json('data.id');

        $this->assertDatabaseHas('shift_patterns', [
            'id'         => $patternId,
            'company_id' => $this->company->id,
            'name'       => 'Pola 4-2 Pabrik',
            'cycle_days' => 6,
        ]);

        $this->assertEquals(6, \App\Models\ShiftPatternItem::where('shift_pattern_id', $patternId)->count());

        // 2. Ambil list pola rotasi
        $listRes = $this->getJson('/api/v1/dashboard/attendance/shift-patterns');
        $listRes->assertStatus(200);
        $this->assertCount(1, $listRes->json('data'));

        // 3. Update pola
        $updatePayload = $payload;
        $updatePayload['description'] = 'Deskripsi diperbarui';
        $updateRes = $this->putJson("/api/v1/dashboard/attendance/shift-patterns/{$patternId}", $updatePayload);
        $updateRes->assertStatus(200);
        $this->assertDatabaseHas('shift_patterns', [
            'id'          => $patternId,
            'description' => 'Deskripsi diperbarui',
        ]);
    }

    /**
     * Skenario 2: Resolusi jadwal siklis modulo O(1) bekerja tepat menembus minggu-minggu berikutnya
     * tanpa HRD harus re-assign manual!
     */
    public function test_rotation_schedule_resolves_accurately_across_weeks_without_manual_reassign(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat Pola 4-2
        $pattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola 4-2 Pabrik',
            'cycle_days' => 6,
            'is_active'  => true,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $isOff = $i >= 5;
            \App\Models\ShiftPatternItem::create([
                'shift_pattern_id' => $pattern->id,
                'day_order'        => $i,
                'shift_id'         => $isOff ? null : $this->shiftPagi->id,
                'is_off'           => $isOff,
                'work_start_time'  => $isOff ? null : '07:00:00',
                'work_end_time'    => $isOff ? null : '15:00:00',
                'break_minutes'    => 60,
                'is_cross_day'     => false,
            ]);
        }

        // Tugaskan Budi ke Pola 4-2 mulai 2026-09-01 (Hari 1 Siklus)
        $assignRes = $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'notes'            => 'Penugasan pola 4-2 permanen',
        ]);
        $assignRes->assertStatus(201);

        // Verifikasi Siklus 1:
        // H1 (2026-09-01): Shift Pagi
        $h1 = ShiftController::resolveSchedule($this->employeeA, '2026-09-01');
        $this->assertEquals('shift', $h1['source']);
        $this->assertEquals($pattern->id, $h1['pattern_id']);
        $this->assertEquals(1, $h1['cycle_day']);
        $this->assertFalse($h1['is_off']);
        $this->assertEquals('07:00', $h1['work_start_time']);

        // H4 (2026-09-04): Hari ke-4 kerja
        $h4 = ShiftController::resolveSchedule($this->employeeA, '2026-09-04');
        $this->assertEquals(4, $h4['cycle_day']);
        $this->assertFalse($h4['is_off']);

        // H5 (2026-09-05): Libur hari pertama siklus
        $h5 = ShiftController::resolveSchedule($this->employeeA, '2026-09-05');
        $this->assertEquals(5, $h5['cycle_day']);
        $this->assertTrue($h5['is_off']);

        // H6 (2026-09-06): Libur hari kedua siklus
        $h6 = ShiftController::resolveSchedule($this->employeeA, '2026-09-06');
        $this->assertEquals(6, $h6['cycle_day']);
        $this->assertTrue($h6['is_off']);

        // Verifikasi Siklus 2 (Berputar Otomatis):
        // H7 (2026-09-07): Berputar kembali ke Hari 1 (Shift Pagi)!
        $h7 = ShiftController::resolveSchedule($this->employeeA, '2026-09-07');
        $this->assertEquals(1, $h7['cycle_day']);
        $this->assertFalse($h7['is_off']);
        $this->assertEquals('07:00', $h7['work_start_time']);

        // H11 & H12 (2026-09-11 & 2026-09-12): Hari ke-5 & 6 di siklus kedua (Libur lagi secara alami!)
        $h11 = ShiftController::resolveSchedule($this->employeeA, '2026-09-11');
        $this->assertEquals(5, $h11['cycle_day']);
        $this->assertTrue($h11['is_off']);

        $h12 = ShiftController::resolveSchedule($this->employeeA, '2026-09-12');
        $this->assertEquals(6, $h12['cycle_day']);
        $this->assertTrue($h12['is_off']);
    }

    /**
     * Skenario 3: Dukungan Posisi Awal Siklus Berbeda (anchor_day_order) untuk Tim Berfase.
     * Tim A mulai Hari 1 (Kerja), Tim B mulai Hari 5 (Libur).
     */
    public function test_anchor_day_order_supports_phased_teams(): void
    {
        Sanctum::actingAs($this->admin);

        $pattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola 4-2 Pabrik',
            'cycle_days' => 6,
            'is_active'  => true,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $isOff = $i >= 5;
            \App\Models\ShiftPatternItem::create([
                'shift_pattern_id' => $pattern->id,
                'day_order'        => $i,
                'shift_id'         => $isOff ? null : $this->shiftPagi->id,
                'is_off'           => $isOff,
                'work_start_time'  => $isOff ? null : '07:00:00',
                'work_end_time'    => $isOff ? null : '15:00:00',
                'break_minutes'    => 60,
                'is_cross_day'     => false,
            ]);
        }

        // Budi (Tim A): mulai hari 1
        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ])->assertStatus(201);

        // Joko (Tim B): mulai hari 5 (fase libur terlebih dahulu)
        $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employeeB->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 5,
            'start_date'       => '2026-09-01',
        ])->assertStatus(201);

        // Pada tanggal 2026-09-01 (Hari yang sama):
        // Budi sedang KERJA (Hari 1 Siklus)
        $schedA = ShiftController::resolveSchedule($this->employeeA, '2026-09-01');
        $this->assertEquals(1, $schedA['cycle_day']);
        $this->assertFalse($schedA['is_off']);

        // Joko sedang LIBUR (Hari 5 Siklus)
        $schedB = ShiftController::resolveSchedule($this->employeeB, '2026-09-01');
        $this->assertEquals(5, $schedB['cycle_day']);
        $this->assertTrue($schedB['is_off']);

        // Pada tanggal 2026-09-05:
        // Budi sekarang LIBUR (Hari 5 Siklus)
        $schedA5 = ShiftController::resolveSchedule($this->employeeA, '2026-09-05');
        $this->assertEquals(5, $schedA5['cycle_day']);
        $this->assertTrue($schedA5['is_off']);

        // Joko sekarang KERJA (Hari 3 Siklus)
        // (diff = 4; anchor = 5; (5 - 1 + 4) % 6 = 8 % 6 = 2; + 1 = Hari 3)
        $schedB5 = ShiftController::resolveSchedule($this->employeeB, '2026-09-05');
        $this->assertEquals(3, $schedB5['cycle_day']);
        $this->assertFalse($schedB5['is_off']);
    }

    /**
     * Skenario 4: Karyawan check-in dan checkout dengan pola rotasi menghasilkan snapshot presensi yang benar.
     */
    public function test_checkin_and_checkout_on_rotation_pattern_saves_snapshot_and_calculates_work_duration(): void
    {
        $pattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola 4-2 Pabrik',
            'cycle_days' => 6,
            'is_active'  => true,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $isOff = $i >= 5;
            \App\Models\ShiftPatternItem::create([
                'shift_pattern_id' => $pattern->id,
                'day_order'        => $i,
                'shift_id'         => $isOff ? null : $this->shiftPagi->id,
                'is_off'           => $isOff,
                'work_start_time'  => $isOff ? null : '07:00:00',
                'work_end_time'    => $isOff ? null : '15:00:00',
                'break_minutes'    => 60,
                'is_cross_day'     => false,
            ]);
        }

        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);

        Sanctum::actingAs($this->employeeA);

        // 1. Cek status presensi mobile
        Carbon::setTestNow(Carbon::parse('2026-09-01 06:55:00', 'Asia/Jakarta'));
        $statusRes = $this->getJson('/api/v1/attendance/status');
        $statusRes->assertStatus(200);
        $this->assertEquals($this->shiftPagi->id, $statusRes->json('active_shift.shift_id'));
        $this->assertEquals('07:00', $statusRes->json('active_shift.work_start_time'));

        // 2. Check-in
        $checkInRes = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20000000,
            'longitude' => 106.81666700,
        ]);
        $checkInRes->assertStatus(201);

        // Verifikasi snapshot tersimpan
        $attendance = \App\Models\Attendance::where('user_id', $this->employeeA->id)->first();
        $this->assertNotNull($attendance);
        $this->assertEquals($this->shiftPagi->id, $attendance->snap_shift_id);
        $this->assertStringContainsString('Shift Pagi', $attendance->snap_shift_name);

        // 3. Checkout pada jam 15:00 (tepat waktu)
        Carbon::setTestNow(Carbon::parse('2026-09-01 15:00:00', 'Asia/Jakarta'));
        $checkOutRes = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20000000,
            'longitude' => 106.81666700,
        ]);
        $checkOutRes->assertStatus(200);

        $attendance->refresh();
        $this->assertNotNull($attendance->check_out_time);
        // Durasi 8 jam kotor (480 menit) - 60 menit istirahat = 420 menit bersih
        $this->assertEquals(420, $attendance->work_minutes);
    }

    /**
     * Skenario 5: Pola rotasi tidak dapat dihapus jika masih digunakan oleh karyawan aktif.
     */
    public function test_cannot_delete_pattern_if_in_active_use_by_employees(): void
    {
        Sanctum::actingAs($this->admin);

        $pattern = ShiftPattern::create([
            'company_id' => $this->company->id,
            'name'       => 'Pola Terpakai',
            'cycle_days' => 6,
            'is_active'  => true,
        ]);

        \App\Models\ShiftPatternItem::create([
            'shift_pattern_id' => $pattern->id,
            'day_order'        => 1,
            'shift_id'         => $this->shiftPagi->id,
            'is_off'           => false,
            'work_start_time'  => '07:00:00',
            'work_end_time'    => '15:00:00',
            'break_minutes'    => 60,
        ]);

        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);

        Carbon::setTestNow('2026-09-01 10:00:00');
        $deleteRes = $this->deleteJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}");
        $deleteRes->assertStatus(422);
        $deleteRes->assertJsonFragment([
            'message' => "Pola rotasi 'Pola Terpakai' tidak dapat dihapus karena sedang aktif digunakan oleh karyawan.",
        ]);
    }

    /**
     * Test dukungan per cabang (attendance_setting_id):
     * 1. Pembuatan pola khusus cabang vs pola company-wide.
     * 2. Filter pola berdasarkan cabang.
     * 3. Proteksi assign: karyawan cabang A ditolak saat di-assign pola khusus cabang B.
     * 4. Bulk assign: karyawan beda cabang dilewati (dilewati array).
     */
    public function test_branch_specific_shift_pattern_filtering_and_cross_branch_protection(): void
    {
        $this->actingAs($this->admin);

        $officeSurabaya = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Cabang Surabaya',
            'office_latitude'             => -7.250445,
            'office_longitude'            => 112.768845,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'break_minutes'               => 60,
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);

        $employeeSurabaya = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Doni Surabaya',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $officeSurabaya->id,
        ]);

        // 1. Uji penolakan Pola Tanpa Cabang (attendance_setting_id null wajib ditolak 422)
        $resNull = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Rotasi Tanpa Cabang',
            'attendance_setting_id' => null,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resNull->assertStatus(422)
            ->assertJsonValidationErrors(['attendance_setting_id']);
        $this->assertEquals('Cabang / lokasi kantor wajib dipilih untuk pola rotasi shift.', $resNull->json('errors.attendance_setting_id.0'));

        // 2. Buat Pola Khusus Pabrik Utama
        $resPabrik = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Khusus Pabrik',
            'attendance_setting_id' => $this->office->id,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resPabrik->assertStatus(201);
        $patternPabrikId = $resPabrik->json('data.id');

        // 3. Buat Pola Khusus Surabaya
        $resSurabaya = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Khusus Surabaya',
            'attendance_setting_id' => $officeSurabaya->id,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00:00', 'work_end_time' => '15:00:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resSurabaya->assertStatus(201);
        $patternSurabayaId = $resSurabaya->json('data.id');
        $this->assertEquals($officeSurabaya->id, $resSurabaya->json('data.attendance_setting_id'));
        $this->assertEquals('Cabang Surabaya', $resSurabaya->json('data.office.office_name'));

        // 4. Filter pola per cabang
        // Query untuk Pabrik Utama ($this->office->id): harus dapat Pola Khusus Pabrik, TIDAK dapat Pola Khusus Surabaya
        $listPabrik = $this->getJson("/api/v1/dashboard/attendance/shift-patterns?attendance_setting_id={$this->office->id}");
        $listPabrik->assertStatus(200);
        $namesPabrik = collect($listPabrik->json('data'))->pluck('name');
        $this->assertTrue($namesPabrik->contains('Pola Khusus Pabrik'));
        $this->assertFalse($namesPabrik->contains('Pola Khusus Surabaya'));

        // Query untuk Surabaya: harus dapat Pola Khusus Surabaya, TIDAK dapat Pola Khusus Pabrik
        $listSurabaya = $this->getJson("/api/v1/dashboard/attendance/shift-patterns?attendance_setting_id={$officeSurabaya->id}");
        $listSurabaya->assertStatus(200);
        $namesSurabaya = collect($listSurabaya->json('data'))->pluck('name');
        $this->assertTrue($namesSurabaya->contains('Pola Khusus Surabaya'));
        $this->assertFalse($namesSurabaya->contains('Pola Khusus Pabrik'));

        // 5. Proteksi Assign Tunggal:
        // Karyawan Pabrik Utama ($this->employeeA) mencoba di-assign Pola Khusus Surabaya -> DITOLAK 422
        $crossAssignRes = $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternSurabayaId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);
        $crossAssignRes->assertStatus(422);
        $this->assertStringContainsString('dikhususkan untuk Cabang Surabaya, tidak dapat ditugaskan ke karyawan cabang lain', $crossAssignRes->json('message'));

        // Karyawan Surabaya di-assign Pola Khusus Surabaya -> BERHASIL 201
        $surabayaAssignRes = $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $employeeSurabaya->id,
            'shift_pattern_id' => $patternSurabayaId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);
        $surabayaAssignRes->assertStatus(201);

        // Karyawan Pabrik Utama di-assign Pola Khusus Pabrik -> BERHASIL 201
        $pabrikAssignRes = $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternPabrikId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);
        $pabrikAssignRes->assertStatus(201);

        // 5. Proteksi Bulk Assign:
        // Coba bulk assign Pola Khusus Surabaya ke [$this->employeeB (Pabrik Utama), $employeeSurabaya (Surabaya)]
        // Buat karyawan Surabaya kedua
        $employeeSurabaya2 = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Rudi Surabaya 2',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $officeSurabaya->id,
        ]);

        $bulkRes = $this->postJson('/api/v1/dashboard/attendance/bulk-assign', [
            'user_ids'         => [$this->employeeB->id, $employeeSurabaya2->id],
            'shift_pattern_id' => $patternSurabayaId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-02',
        ]);
        $bulkRes->assertStatus(201);

        // Employee B (Pabrik) harus masuk ke dilewati karena beda cabang
        $dilewati = collect($bulkRes->json('dilewati'));
        $this->assertTrue($dilewati->pluck('user_id')->contains($this->employeeB->id));
        $this->assertStringContainsString('dikhususkan untuk Cabang Surabaya', $dilewati->firstWhere('user_id', $this->employeeB->id)['reason']);

        // Employee Surabaya 2 harus masuk ke assigned
        $assigned = collect($bulkRes->json('assigned'));
        $this->assertTrue($assigned->pluck('user_id')->contains($employeeSurabaya2->id));
    }

    /**
     * Uji validasi template shift lintas cabang:
     * Pola rotasi cabang Surabaya tidak boleh memakai template shift cabang Pabrik Utama.
     */
    public function test_pola_rotasi_menolak_shift_dari_cabang_lain(): void
    {
        Sanctum::actingAs($this->admin);

        $officeSurabaya = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Cabang Surabaya Barat',
            'office_latitude'             => -7.25000000,
            'office_longitude'            => 112.75000000,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'break_minutes'               => 60,
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);

        // Shift Pabrik Utama ($this->office)
        $shiftPabrik = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Khusus Pabrik',
            'is_active'             => true,
        ]);

        // Coba buat Pola Rotasi untuk Surabaya, tapi menggunakan shift dari Pabrik
        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Rotasi Surabaya',
            'attendance_setting_id' => $officeSurabaya->id,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'shift_id' => $shiftPabrik->id, 'work_start_time' => '08:00', 'work_end_time' => '17:00'],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment([
                'message' => "Shift '{$shiftPabrik->name}' berasal dari cabang lain dan tidak dapat digunakan pada pola rotasi cabang ini.",
            ]);
    }

    /**
     * Uji validasi kewajiban minimal 1 hari libur dalam siklus (UU No. 13/2003).
     */
    public function test_pola_rotasi_wajib_memiliki_minimal_satu_hari_libur(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Tanpa Libur',
            'cycle_days'            => 3,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '08:00', 'work_end_time' => '16:00'],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '08:00', 'work_end_time' => '16:00'],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '08:00', 'work_end_time' => '16:00'],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Pola rotasi wajib memiliki minimal 1 hari libur dalam siklus (UU No. 13/2003 Pasal 79).',
            ]);
    }

    /**
     * Uji batas maksimal 6 hari kerja berturut-turut tanpa libur (UU No. 13/2003 Pasal 79).
     */
    public function test_pola_rotasi_menolak_lebih_dari_enam_hari_kerja_berturut_turut(): void
    {
        Sanctum::actingAs($this->admin);

        // Siklus 8 hari: 7 hari kerja berturut-turut, 1 hari libur
        $items = [];
        for ($i = 1; $i <= 7; $i++) {
            $items[] = ['day_order' => $i, 'is_off' => false, 'work_start_time' => '08:00', 'work_end_time' => '16:00'];
        }
        $items[] = ['day_order' => 8, 'is_off' => true];

        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Kerja 7 Hari Berturut-turut',
            'cycle_days'            => 8,
            'items'                 => $items,
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Pola rotasi tidak boleh memiliki lebih dari 6 hari kerja berturut-turut tanpa hari libur (UU No. 13/2003 Pasal 79).',
            ]);
    }

    /**
     * Uji validasi jam masuk dan pulang wajib diisi untuk hari kerja.
     */
    public function test_pola_rotasi_wajib_mengisi_jam_kerja(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Tanpa Jam',
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => null, 'work_end_time' => null],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Hari ke-1: jam masuk & pulang wajib diisi (atau tandai libur).',
            ]);
    }

    /**
     * Uji validasi jeda K3 kritis (< 8 jam) ditolak.
     */
    public function test_pola_rotasi_menolak_jeda_k3_kurang_dari_delapan_jam(): void
    {
        Sanctum::actingAs($this->admin);

        // H1 pulang jam 23:00, H2 masuk jam 06:00 -> jeda hanya 7 jam (< 8 jam K3)
        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Bahaya K3',
            'cycle_days'            => 3,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '15:00', 'work_end_time' => '23:00'],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '06:00', 'work_end_time' => '14:00'],
                ['day_order' => 3, 'is_off' => true],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('minimum wajib 8 jam K3', $res->json('message'));
    }

    /**
     * Uji peringatan jeda K3 antara 8-11 jam berhasil disimpan dengan warnings.
     */
    public function test_pola_rotasi_memberikan_warning_jeda_k3_delapan_sampai_sebelas_jam(): void
    {
        Sanctum::actingAs($this->admin);

        // H1 pulang 22:00, H2 masuk 07:00 -> jeda 9 jam (warning 8-11 jam)
        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Warning K3',
            'cycle_days'            => 3,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '14:00', 'work_end_time' => '22:00'],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00'],
                ['day_order' => 3, 'is_off' => true],
            ],
        ]);

        $res->assertStatus(201);
        $this->assertNotEmpty($res->json('warnings'));
        $this->assertStringContainsString('disarankan minimal 11 jam', $res->json('warnings.0'));
    }

    /**
     * Uji pencegahan bug perpindahan cabang pola rotasi saat ada 10 karyawan yang ter-assign di cabang awal.
     */
    public function test_cannot_change_pattern_branch_when_active_users_are_assigned(): void
    {
        Sanctum::actingAs($this->admin);

        $officePusat = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Pusat Jakarta',
            'office_latitude'  => -6.2088,
            'office_longitude' => 106.8456,
            'radius_meters'    => 100,
        ]);

        $officeLapangan = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Cabang Lapangan',
            'office_latitude'  => -7.2575,
            'office_longitude' => 112.7521,
            'radius_meters'    => 150,
        ]);

        // Buat 10 karyawan di Kantor Cabang Lapangan
        $fieldEmployees = [];
        for ($i = 1; $i <= 10; $i++) {
            $fieldEmployees[] = User::factory()->create([
                'company_id'            => $this->company->id,
                'role'                  => 'employee',
                'name'                  => "Karyawan Lapangan {$i}",
                'attendance_enabled'    => true,
                'attendance_setting_id' => $officeLapangan->id,
            ]);
        }

        // Buat pola rotasi untuk Kantor Cabang Lapangan
        $pattern = ShiftPattern::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $officeLapangan->id,
            'name'                  => 'Pola 4-2 Cabang Lapangan',
            'cycle_days'            => 6,
            'is_active'             => true,
        ]);

        for ($d = 1; $d <= 6; $d++) {
            $pattern->items()->create([
                'day_order'       => $d,
                'is_off'          => $d >= 5,
                'work_start_time' => $d < 5 ? '07:00:00' : null,
                'work_end_time'   => $d < 5 ? '15:00:00' : null,
                'break_minutes'   => $d < 5 ? 60 : 0,
            ]);
        }

        // Assign 10 karyawan lapangan ke pola rotasi ini
        foreach ($fieldEmployees as $emp) {
            UserShift::create([
                'user_id'          => $emp->id,
                'shift_pattern_id' => $pattern->id,
                'start_date'       => '2026-09-01',
                'end_date'         => null,
                'is_active'        => true,
            ]);
        }

        // 1. Coba ubah cabang pola rotasi menjadi Kantor Pusat Jakarta -> HARUS DITOLAK 422
        $payloadJakarta = [
            'name'                  => 'Pola 4-2 Cabang Lapangan',
            'attendance_setting_id' => $officePusat->id,
            'cycle_days'            => 6,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 5, 'is_off' => true],
                ['day_order' => 6, 'is_off' => true],
            ],
        ];

        $resFail = $this->putJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}", $payloadJakarta);
        $resFail->assertStatus(422);
        $this->assertStringContainsString('Cabang kantor pola rotasi bersifat permanen', $resFail->json('message'));

        // Cabang di database harus tetap Kantor Cabang Lapangan (tidak berubah)
        $this->assertEquals($officeLapangan->id, $pattern->fresh()->attendance_setting_id);

        // 2. Edit nama / jam kerja tanpa memindahkan cabang -> HARUS BERHASIL 200
        $payloadSameBranch = $payloadJakarta;
        $payloadSameBranch['attendance_setting_id'] = $officeLapangan->id;
        $payloadSameBranch['name'] = 'Pola 4-2 Lapangan Diperbarui';

        $resSuccess = $this->putJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}", $payloadSameBranch);
        $resSuccess->assertStatus(200);
        $this->assertEquals('Pola 4-2 Lapangan Diperbarui', $pattern->fresh()->name);
        $this->assertEquals($officeLapangan->id, $pattern->fresh()->attendance_setting_id);

        // 3. Bahkan jika penugasan karyawan berakhir, cabang tetap bersifat permanen (persis seperti Template Shift)
        UserShift::where('shift_pattern_id', $pattern->id)->update(['end_date' => '2026-08-31']);

        $resStillBlocked = $this->putJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}", $payloadJakarta);
        $resStillBlocked->assertStatus(422);
        $this->assertStringContainsString('Cabang kantor pola rotasi bersifat permanen', $resStillBlocked->json('message'));
    }

    /**
     * Uji keunikan warna lintas entitas per cabang:
     * 1 cabang jika warna itu sudah dipakai oleh template shift, maka pola rotasi di cabang tersebut
     * tidak bisa memakainya lagi, dan sebaliknya. Dua cabang berbeda boleh memakai warna yang sama.
     */
    public function test_cross_entity_color_uniqueness_between_shifts_and_patterns_in_same_branch(): void
    {
        Sanctum::actingAs($this->admin);

        $officeLapangan = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Cabang Lapangan Minyak',
            'office_latitude'             => -6.20000000,
            'office_longitude'            => 106.81666600,
            'radius_meters'               => 150,
            'work_start_time'             => '07:00:00',
            'work_end_time'               => '15:00:00',
            'break_minutes'               => 60,
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);

        $officeJakarta = AttendanceSetting::create([
            'company_id'                  => $this->company->id,
            'office_name'                 => 'Kantor Jakarta Pusat',
            'office_latitude'             => -6.18000000,
            'office_longitude'            => 106.82000000,
            'radius_meters'               => 100,
            'work_start_time'             => '08:00:00',
            'work_end_time'               => '17:00:00',
            'break_minutes'               => 60,
            'late_tolerance_minutes'      => 15,
            'checkout_reminder_minutes'   => 30,
            'auto_checkout_grace_minutes' => 60,
        ]);

        $redColor = '#f43f5e';

        // 1. Buat Template Shift di Kantor Cabang Lapangan dengan warna merah ($redColor)
        $shiftLapangan = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $officeLapangan->id,
            'name'                  => 'Shift Lapangan Merah',
            'color'                 => $redColor,
            'is_active'             => true,
        ]);

        // 2. Coba buat Pola Rotasi di Kantor Cabang Lapangan dengan warna merah yang sama -> HARUS DITOLAK 422
        $resPatternClash = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Rotasi Lapangan',
            'attendance_setting_id' => $officeLapangan->id,
            'color'                 => $redColor,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resPatternClash->assertStatus(422);
        $this->assertStringContainsString("Warna {$redColor} sudah dipakai oleh template shift '{$shiftLapangan->name}'", $resPatternClash->json('message'));

        // 3. Buat Pola Rotasi di Kantor Jakarta Pusat dengan warna merah yang sama -> HARUS BERHASIL 201 (karena beda cabang)
        $resPatternJakarta = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Rotasi Jakarta',
            'attendance_setting_id' => $officeJakarta->id,
            'color'                 => $redColor,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '08:00', 'work_end_time' => '17:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resPatternJakarta->assertStatus(201);
        $patternJakartaId = $resPatternJakarta->json('data.id');

        // 4. Sebaliknya: Pola Rotasi di Lapangan memakai warna biru (#0284c7).
        // Coba buat Template Shift di Lapangan dengan warna biru -> HARUS DITOLAK 422
        $blueColor = '#0284c7';
        $resPatternBlue = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Rotasi Lapangan Biru',
            'attendance_setting_id' => $officeLapangan->id,
            'color'                 => $blueColor,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resPatternBlue->assertStatus(201);
        $patternBlueId = $resPatternBlue->json('data.id');

        // Coba buat template shift di Lapangan dengan warna biru $blueColor
        $resShiftClash = $this->postJson('/api/v1/dashboard/attendance/shifts', [
            'attendance_setting_id' => $officeLapangan->id,
            'name'                  => 'Shift Lapangan Biru Bentrok',
            'color'                 => $blueColor,
            'schedules'             => array_map(function ($d) {
                return [
                    'day_of_week'     => $d,
                    'is_off'          => $d === 0,
                    'work_start_time' => $d === 0 ? null : '07:00',
                    'work_end_time'   => $d === 0 ? null : '15:00',
                    'break_minutes'   => $d === 0 ? 0 : 60,
                ];
            }, range(0, 6)),
        ]);
        $resShiftClash->assertStatus(422);
        $this->assertStringContainsString("Warna {$blueColor} sudah dipakai oleh pola rotasi 'Pola Rotasi Lapangan Biru'", $resShiftClash->json('message'));

        // 5. Coba update shiftLapangan menjadi warna biru ($blueColor) -> DITOLAK 422
        $resUpdateShiftClash = $this->putJson("/api/v1/dashboard/attendance/shifts/{$shiftLapangan->id}", [
            'color' => $blueColor,
        ]);
        $resUpdateShiftClash->assertStatus(422);

        // 6. Update shiftLapangan dengan warna dirinya sendiri ($redColor) -> BERHASIL 200
        $resUpdateShiftSelf = $this->putJson("/api/v1/dashboard/attendance/shifts/{$shiftLapangan->id}", [
            'color' => $redColor,
        ]);
        $resUpdateShiftSelf->assertStatus(200);

        // 7. Update patternBlue dengan warnanya sendiri ($blueColor) -> BERHASIL 200
        $resUpdatePatternSelf = $this->putJson("/api/v1/dashboard/attendance/shift-patterns/{$patternBlueId}", [
            'name'       => 'Pola Rotasi Lapangan Biru Diperbarui',
            'color'      => $blueColor,
            'cycle_days' => 2,
            'items'      => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resUpdatePatternSelf->assertStatus(200);
    }

    /**
     * Uji toggle aktif/nonaktif pola rotasi shift:
     * - Pola tanpa penugasan aktif bisa di-toggle aktif <-> nonaktif.
     * - Pola yang sedang aktif digunakan karyawan DIBLOKIR 409 saat akan dinonaktifkan.
     */
    public function test_pola_rotasi_toggle_active_and_blocked_if_used_by_active_employee(): void
    {
        Sanctum::actingAs($this->admin);

        $pattern = ShiftPattern::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Toggle Test',
            'cycle_days'            => 2,
            'is_active'             => true,
        ]);
        $pattern->items()->create(['day_order' => 1, 'is_off' => false, 'work_start_time' => '08:00', 'work_end_time' => '17:00']);
        $pattern->items()->create(['day_order' => 2, 'is_off' => true]);

        // 1. Toggle nonaktifkan saat belum ada karyawan -> BERHASIL 200
        $resDeactivate = $this->postJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}/toggle-active");
        $resDeactivate->assertStatus(200);
        $this->assertFalse($pattern->fresh()->is_active);

        // 2. Toggle aktifkan kembali -> BERHASIL 200
        $resActivate = $this->postJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}/toggle-active");
        $resActivate->assertStatus(200);
        $this->assertTrue($pattern->fresh()->is_active);

        // 3. Assign karyawan ke pola rotasi ini (aktif hari ini)
        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        // 4. Coba nonaktifkan saat sedang dipakai -> DIBLOKIR 409
        $resBlock = $this->postJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}/toggle-active");
        $resBlock->assertStatus(409);
        $this->assertStringContainsString('Pola rotasi tidak bisa dinonaktifkan karena masih digunakan', $resBlock->json('message'));
        $this->assertTrue($pattern->fresh()->is_active);
    }

    /**
     * Uji endpoint daftar karyawan yang ter-assign pada pola rotasi (patternUsers):
     * GET /api/v1/dashboard/attendance/shift-patterns/{id}/users
     */
    public function test_pola_rotasi_users_returns_assigned_employees_with_cycle_day_and_status(): void
    {
        Sanctum::actingAs($this->admin);

        $pattern = ShiftPattern::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Users Test',
            'cycle_days'            => 4,
            'is_active'             => true,
        ]);

        // Karyawan A: Aktif (mulai 2026-09-01, anchor H1)
        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        // Karyawan B: Expired (sudah berakhir Agustus)
        UserShift::create([
            'user_id'          => $this->employeeB->id,
            'shift_pattern_id' => $pattern->id,
            'anchor_day_order' => 2,
            'start_date'       => '2026-08-01',
            'end_date'         => '2026-08-31',
        ]);

        $res = $this->getJson("/api/v1/dashboard/attendance/shift-patterns/{$pattern->id}/users");
        $res->assertStatus(200);
        $this->assertEquals(2, $res->json('total'));

        $data = collect($res->json('data'));
        $userA = $data->firstWhere('user_id', $this->employeeA->id);
        $userB = $data->firstWhere('user_id', $this->employeeB->id);

        $this->assertNotNull($userA);
        $this->assertEquals('active', $userA['status']);
        $this->assertEquals(1, $userA['anchor_day_order']);
        $this->assertNotNull($userA['current_cycle_day']);

        $this->assertNotNull($userB);
        $this->assertEquals('expired', $userB['status']);
        $this->assertEquals(2, $userB['anchor_day_order']);
    }

    /**
     * Uji 1: Admin dapat menyimpan dan memperbarui pola rotasi dengan day_overrides.
     */
    public function test_pattern_store_and_update_with_day_overrides(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. Store dengan day_overrides (Jumat / dow 5 istirahat 90 menit)
        $storePayload = [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Override Test',
            'cycle_days'            => 4,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => true],
            ],
            'day_overrides' => [
                [
                    'day_of_week'    => 5, // Jumat
                    'break_minutes'  => 90,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', $storePayload);
        $res->assertStatus(201);
        $patternId = $res->json('data.id');

        $this->assertDatabaseHas('shift_pattern_day_overrides', [
            'shift_pattern_id' => $patternId,
            'day_of_week'      => 5,
            'break_minutes'    => 90,
        ]);

        // 2. Update day_overrides (ubah Jumat break 100m dan tambah Selasa / dow 2 jam masuk 08:00)
        $updatePayload = [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Override Test Updated',
            'cycle_days'            => 4,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => true],
            ],
            'day_overrides' => [
                [
                    'day_of_week'   => 5,
                    'break_minutes' => 100,
                ],
                [
                    'day_of_week'     => 2, // Selasa
                    'work_start_time' => '08:00',
                    'work_end_time'   => '16:00',
                ],
            ],
        ];

        $resUpdate = $this->putJson("/api/v1/dashboard/attendance/shift-patterns/{$patternId}", $updatePayload);
        $resUpdate->assertStatus(200);

        $this->assertDatabaseHas('shift_pattern_day_overrides', [
            'shift_pattern_id' => $patternId,
            'day_of_week'      => 5,
            'break_minutes'    => 100,
        ]);
        $this->assertDatabaseHas('shift_pattern_day_overrides', [
            'shift_pattern_id' => $patternId,
            'day_of_week'      => 2,
            'work_start_time'  => '08:00',
            'work_end_time'    => '16:00',
        ]);
    }

    /**
     * Uji 2: resolveSchedule menerapkan Calendar Day Override saat hari kerja jatuh pada hari override.
     */
    public function test_resolve_schedule_applies_calendar_day_override(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat pattern 6 hari: H1-H4 kerja (07:00-15:00, break 60), H5-H6 libur
        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola 4-2 Jumat 90 Menit',
            'cycle_days'            => 6,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 5, 'is_off' => true],
                ['day_order' => 6, 'is_off' => true],
            ],
            'day_overrides' => [
                [
                    'day_of_week'   => 5, // Jumat
                    'break_minutes' => 90, // Override break 90 menit
                ],
            ],
        ]);
        $res->assertStatus(201);
        $patternId = $res->json('data.id');

        // Mulai 2026-09-01 (Selasa), anchor H1
        // 2026-09-01 (Selasa): H1 (kerja) -> Kamis 2026-09-03: H3 -> Jumat 2026-09-04: H4 (kerja!)
        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        // Cek hari Kamis (2026-09-03) -> bukan Jumat -> break_minutes tetap 60
        $schedKamis = ShiftController::resolveSchedule($this->employeeA, '2026-09-03');
        $this->assertFalse($schedKamis['is_off']);
        $this->assertEquals(60, $schedKamis['break_minutes']);
        $this->assertEquals('07:00', $schedKamis['work_start_time']);

        // Cek hari Jumat (2026-09-04) -> hari Jumat kalender -> break_minutes menjadi 90!
        $schedJumat = ShiftController::resolveSchedule($this->employeeA, '2026-09-04');
        $this->assertFalse($schedJumat['is_off']);
        $this->assertEquals(90, $schedJumat['break_minutes']);
        $this->assertEquals('07:00', $schedJumat['work_start_time']); // start_time tetap 07:00
        $this->assertEquals('07:00', $schedJumat['work_start_time']);
    }

    /**
     * Uji 3: Day override diabaikan jika hari siklus pada tanggal tersebut berstatus libur (OFF).
     */
    public function test_resolve_schedule_day_override_ignored_on_off_day(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat pattern dengan anchor sehingga hari Jumat jatuh pada hari libur
        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Libur Jumat',
            'cycle_days'            => 4,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => true], // H4 = libur
            ],
            'day_overrides' => [
                [
                    'day_of_week'     => 5, // Jumat
                    'work_start_time' => '08:00',
                    'work_end_time'   => '16:00',
                    'break_minutes'   => 90,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $patternId = $res->json('data.id');

        // Mulai Selasa 2026-09-01 dengan anchor H2:
        // Selasa 2026-09-01 = H2
        // Rabu   2026-09-02 = H3
        // Kamis  2026-09-03 = H4 (Libur)
        // Jumat  2026-09-04 = H1 (Kerja)
        // Jika kita set anchor H1 pada Rabu 2026-09-02:
        // Rabu  2026-09-02 = H1
        // Kamis 2026-09-03 = H2
        // Jumat 2026-09-04 = H3
        // Sabtu 2026-09-05 = H4
        // Mari set anchor H4 tepat pada Jumat 2026-09-04:
        // start_date = 2026-09-04, anchor = 4 (H4 = libur)
        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternId,
            'anchor_day_order' => 4,
            'start_date'       => '2026-09-04',
            'end_date'         => null,
        ]);

        $schedJumat = ShiftController::resolveSchedule($this->employeeA, '2026-09-04');
        // Harus tetap LIBUR! Override tidak boleh mengubah hari libur menjadi hari kerja
        $this->assertTrue($schedJumat['is_off']);
        $this->assertNull($schedJumat['work_start_time']);
    }

    /**
     * Uji 4: Partial override dan recalculation is_cross_day.
     */
    public function test_resolve_schedule_partial_override_and_cross_day(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Cross Day Override',
            'cycle_days'            => 4,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => true],
            ],
            'day_overrides' => [
                [
                    'day_of_week'     => 2, // Selasa
                    'work_start_time' => '20:00',
                    'work_end_time'   => '04:00', // Cross-day malam
                ],
            ],
        ]);
        $res->assertStatus(201);
        $patternId = $res->json('data.id');

        // Mulai Selasa 2026-09-01 (Selasa), anchor H1
        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        $schedSelasa = ShiftController::resolveSchedule($this->employeeA, '2026-09-01');
        $this->assertFalse($schedSelasa['is_off']);
        $this->assertEquals('20:00', $schedSelasa['work_start_time']);
        $this->assertEquals('04:00', $schedSelasa['work_end_time']);
        $this->assertTrue($schedSelasa['is_cross_day']);
        // break_minutes tidak di-override, tetap 60 dari pattern item
        $this->assertEquals(60, $schedSelasa['break_minutes']);
    }

    /**
     * Uji 5: resolveSchedulesBulk juga menerapkan calendar day override secara konsisten.
     */
    public function test_resolve_schedules_bulk_applies_day_override(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Bulk Test',
            'cycle_days'            => 5,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 5, 'is_off' => true],
            ],
            'day_overrides' => [
                [
                    'day_of_week'   => 5, // Jumat
                    'break_minutes' => 90,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $patternId = $res->json('data.id');

        // Karyawan A & B di-assign
        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);
        UserShift::create([
            'user_id'          => $this->employeeB->id,
            'shift_pattern_id' => $patternId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        $users = collect([$this->employeeA, $this->employeeB]);
        // 2026-09-04 adalah hari Jumat
        $bulkResults = ShiftController::resolveSchedulesBulk($users, '2026-09-04');

        $this->assertEquals(90, $bulkResults[$this->employeeA->id]['break_minutes']);
        $this->assertEquals(90, $bulkResults[$this->employeeB->id]['break_minutes']);
    }

    /**
     * Uji 6: Proteksi Snapshot Presensi Sesi Aktif saat HRD mengubah Day Override di Tengah Hari.
     * Jika karyawan sudah check-in pada jam kerja lama (misal pulang 15:00), lalu di siang hari
     * HRD mengubah jam pulang lewat Calendar Day Override menjadi 17:00:
     * 1. Status presensi mobile tetap menampilkan jadwal snapshot saat check-in (15:00).
     * 2. Saat karyawan checkout jam 15:00, karyawan TIDAK dianggap early_leave (pulang cepat).
     */
    public function test_midday_day_override_change_preserves_employee_snapshot_until_checkout(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat pattern 6 hari: H1-H4 kerja (07:00-15:00), H5-H6 libur. Awalnya TANPA override Jumat.
        $res = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Midday Test',
            'cycle_days'            => 6,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 5, 'is_off' => true],
                ['day_order' => 6, 'is_off' => true],
            ],
        ]);
        $res->assertStatus(201);
        $patternId = $res->json('data.id');

        // Tugaskan karyawan mulai Selasa 2026-09-01 (anchor 1)
        // Sehingga Jumat 2026-09-04 adalah H4 (Kerja: 07:00 - 15:00)
        UserShift::create([
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
            'end_date'         => null,
        ]);

        // 1. Karyawan check-in pada hari Jumat jam 07:00 pagi
        Carbon::setTestNow(Carbon::parse('2026-09-04 07:00:00', 'Asia/Jakarta'));
        Sanctum::actingAs($this->employeeA);

        $checkInRes = $this->postJson('/api/v1/attendance/check-in', [
            'latitude'  => -6.20000000,
            'longitude' => 106.81666700,
        ]);
        $checkInRes->assertStatus(201);

        $att = \App\Models\Attendance::where('user_id', $this->employeeA->id)
            ->whereDate('date', '2026-09-04')
            ->first();
        $this->assertNotNull($att);
        $this->assertEquals('07:00', substr((string) $att->snap_work_start_time, 0, 5));
        $this->assertEquals('15:00', substr((string) $att->snap_work_end_time, 0, 5));

        // 2. Di tengah hari (jam 11:30), HRD mengubah pola rotasi:
        //    Menambahkan Calendar Day Override untuk hari Jumat: jam pulang diundur jadi 17:00!
        Sanctum::actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2026-09-04 11:30:00', 'Asia/Jakarta'));

        $updateRes = $this->putJson("/api/v1/dashboard/attendance/shift-patterns/{$patternId}", [
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Pola Midday Test',
            'cycle_days'            => 6,
            'is_active'             => true,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 3, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 4, 'is_off' => false, 'work_start_time' => '07:00', 'work_end_time' => '15:00', 'break_minutes' => 60],
                ['day_order' => 5, 'is_off' => true],
                ['day_order' => 6, 'is_off' => true],
            ],
            'day_overrides' => [
                [
                    'day_of_week'     => 5, // Jumat
                    'work_end_time'   => '17:00', // Diubah jam pulang 17:00
                ],
            ],
        ]);
        $updateRes->assertStatus(200);

        // Jadwal live sekarang menunjukkan jam pulang 17:00
        $liveSchedule = ShiftController::resolveSchedule($this->employeeA, '2026-09-04');
        $this->assertEquals('17:00', $liveSchedule['work_end_time']);

        // 3. Namun, ketika karyawan mengecek status di aplikasi HP (jam 12:00 siang):
        //    Sistem mendeteksi sesi aktif dan mengambil snapshot: jam pulang tetap 15:00!
        Sanctum::actingAs($this->employeeA);
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00', 'Asia/Jakarta'));

        $statusRes = $this->getJson('/api/v1/attendance/status');
        $statusRes->assertStatus(200);
        $this->assertTrue($statusRes->json('is_snapshot_session'));
        $this->assertEquals('15:00', $statusRes->json('active_shift.work_end_time'));

        // 4. Karyawan checkout pada jam 15:00 (sesuai jadwal awal saat check-in)
        Carbon::setTestNow(Carbon::parse('2026-09-04 15:00:00', 'Asia/Jakarta'));
        $checkoutRes = $this->postJson('/api/v1/attendance/check-out', [
            'latitude'  => -6.20000000,
            'longitude' => 106.81666700,
        ]);
        $checkoutRes->assertStatus(200);

        $att->refresh();
        $this->assertNotNull($att->check_out_time);
        // Status tetap 'present' (hadir tepat waktu), TIDAK menjadi 'early_leave' (pulang cepat)!
        $this->assertEquals('present', $att->status);
    }
}


