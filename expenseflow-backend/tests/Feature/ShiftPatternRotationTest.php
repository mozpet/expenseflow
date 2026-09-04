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
            'name'        => 'Pola 4-2 Pabrik',
            'description' => '4 Hari Shift Pagi, 2 Hari Libur',
            'cycle_days'  => 6,
            'is_active'   => true,
            'items'       => [
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

        // 1. Buat Pola Global (Company-wide: null)
        $resGlobal = $this->postJson('/api/v1/dashboard/attendance/shift-patterns', [
            'name'                  => 'Pola Rotasi Global',
            'attendance_setting_id' => null,
            'cycle_days'            => 2,
            'items'                 => [
                ['day_order' => 1, 'is_off' => false, 'work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'break_minutes' => 60],
                ['day_order' => 2, 'is_off' => true],
            ],
        ]);
        $resGlobal->assertStatus(201);
        $patternGlobalId = $resGlobal->json('data.id');

        // 2. Buat Pola Khusus Surabaya
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

        // 3. Filter pola per cabang
        // Query untuk Pabrik Utama ($this->office->id): harus dapat Pola Global, TIDAK dapat Pola Khusus Surabaya
        $listPabrik = $this->getJson("/api/v1/dashboard/attendance/shift-patterns?attendance_setting_id={$this->office->id}");
        $listPabrik->assertStatus(200);
        $namesPabrik = collect($listPabrik->json('data'))->pluck('name');
        $this->assertTrue($namesPabrik->contains('Pola Rotasi Global'));
        $this->assertFalse($namesPabrik->contains('Pola Khusus Surabaya'));

        // Query untuk Surabaya: harus dapat Pola Global DAN Pola Khusus Surabaya
        $listSurabaya = $this->getJson("/api/v1/dashboard/attendance/shift-patterns?attendance_setting_id={$officeSurabaya->id}");
        $listSurabaya->assertStatus(200);
        $namesSurabaya = collect($listSurabaya->json('data'))->pluck('name');
        $this->assertTrue($namesSurabaya->contains('Pola Rotasi Global'));
        $this->assertTrue($namesSurabaya->contains('Pola Khusus Surabaya'));

        // 4. Proteksi Assign Tunggal:
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

        // Karyawan Pabrik Utama di-assign Pola Global -> BERHASIL 201
        $globalAssignRes = $this->postJson('/api/v1/dashboard/attendance/assign-shift', [
            'user_id'          => $this->employeeA->id,
            'shift_pattern_id' => $patternGlobalId,
            'anchor_day_order' => 1,
            'start_date'       => '2026-09-01',
        ]);
        $globalAssignRes->assertStatus(201);

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
}
