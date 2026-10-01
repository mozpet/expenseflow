<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Division;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeApproval;
use App\Models\Position;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Perbaikan bug alur persetujuan BERTINGKAT (2 tahap SPV -> HRD) untuk LEMBUR & CUTI.
 *
 * Setiap uji ditulis LEBIH DAHULU untuk membuktikan cacat nyata sebelum diperbaiki,
 * sehingga tiap perbaikan punya pengaman regresi sendiri.
 */
class MultiApprovalBugFixTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $branchA;
    private AttendanceSetting $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PT Uji Approval Bertingkat', 'is_active' => true]);

        $this->branchA = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Jakarta',
            'office_latitude'  => -6.1754,
            'office_longitude' => 106.8272,
            'radius_meters'    => 100,
        ]);

        $this->branchB = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Surabaya',
            'office_latitude'  => -7.2575,
            'office_longitude' => 112.7521,
            'radius_meters'    => 100,
        ]);
    }

    private function makeUser(string $role, array $attrs = []): User
    {
        static $counter = 0;
        $counter++;

        return User::create(array_merge([
            'company_id'            => $this->company->id,
            'name'                  => "Pengguna {$counter}",
            'email'                 => "bugfix{$counter}@ujiapproval.test",
            'password'              => bcrypt('secret123'),
            'role'                  => $role,
            'attendance_setting_id' => $this->branchA->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
        ], $attrs));
    }

    private function attendanceWithOvertime(User $user, int $minutes = 120): Attendance
    {
        return Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $user->id,
            'date'             => now()->toDateString(),
            'check_in_time'    => now()->subHours(10),
            'check_out_time'   => now(),
            'status'           => 'present',
            'overtime_minutes' => $minutes,
        ]);
    }

    /** Hari kerja (Sen-Jum) ke-N dari hari ini - hindari weekend agar lolos hitungan hari efektif. */
    private function workdayAhead(int $n): Carbon
    {
        $d = Carbon::now('Asia/Jakarta');
        for ($i = 0; $i < $n; $i++) {
            $d->addDay();
            while ($d->isSaturday() || $d->isSunday()) {
                $d->addDay();
            }
        }

        return $d;
    }

    private function seedSpvNotification(int $spvId, int $approvalId): void
    {
        DB::table('notifications')->insert([
            'id'              => (string) Str::uuid(),
            'type'            => 'overtime_pending_spv',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id'   => $spvId,
            'user_id'         => $spvId,
            'data'            => json_encode(['overtime_id' => $approvalId]),
            'entity_type'     => 'overtime_approval',
            'entity_id'       => $approvalId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ── BUG 1 — declineOvertime() tanpa pengaman status: karyawan bisa menghapus
    // approval yang SUDAH disetujui HRD dan menihilkan menit lembur payroll. ──

    public function test_karyawan_tidak_dapat_membatalkan_lembur_yang_sudah_disetujui(): void
    {
        $employee = $this->makeUser('employee');
        $hrd      = $this->makeUser('hrd');

        $attendance = $this->attendanceWithOvertime($employee, 120);

        $approval = OvertimeApproval::create([
            'attendance_id'    => $attendance->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 120,
            'status'           => 'approved',
            'current_step'     => 'hrd',
            'reviewed_by'      => $hrd->id,
            'reviewed_at'      => now(),
        ]);

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/v1/attendance/{$attendance->id}/decline-overtime")
            ->assertStatus(422);

        $this->assertDatabaseHas('overtime_approvals', [
            'id'     => $approval->id,
            'status' => 'approved',
        ]);
        $this->assertEquals(120, $attendance->fresh()->overtime_minutes);
    }

    public function test_karyawan_tidak_dapat_membatalkan_lembur_yang_sudah_ditolak(): void
    {
        $employee   = $this->makeUser('employee');
        $attendance = $this->attendanceWithOvertime($employee, 90);

        $approval = OvertimeApproval::create([
            'attendance_id'    => $attendance->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 90,
            'status'           => 'rejected',
            'current_step'     => 'spv',
        ]);

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/v1/attendance/{$attendance->id}/decline-overtime")
            ->assertStatus(422);

        $this->assertDatabaseHas('overtime_approvals', ['id' => $approval->id, 'status' => 'rejected']);
    }

    public function test_karyawan_masih_dapat_membatalkan_lembur_yang_masih_pending(): void
    {
        $employee   = $this->makeUser('employee');
        $attendance = $this->attendanceWithOvertime($employee, 60);

        $approval = OvertimeApproval::create([
            'attendance_id'    => $attendance->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/v1/attendance/{$attendance->id}/decline-overtime")
            ->assertOk();

        $this->assertDatabaseMissing('overtime_approvals', ['id' => $approval->id]);
        $this->assertEquals(0, $attendance->fresh()->overtime_minutes);
    }

    // ── BUG 2 — spvPendingOvertimeCount() tanpa scoping cabang: badge SPV
    // membocorkan jumlah pengajuan dari cabang yang tidak boleh diaksesnya. ──

    public function test_badge_pending_lembur_spv_menghormati_scoping_cabang(): void
    {
        $spvRole = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'Supervisor Cabang',
            'slug'         => 'spv_cabang',
            'branch_scope' => 'specific',
            'platform'     => 'both',
        ]);
        RolePermission::create([
            'role_id'      => $spvRole->id,
            'module'       => Role::MODULE_OVERTIME,
            'access_level' => 'spv',
        ]);

        $spv = $this->makeUser('employee', [
            'role_id'               => $spvRole->id,
            'attendance_setting_id' => $this->branchA->id,
        ]);

        $bawahanA = $this->makeUser('employee', [
            'manager_id'            => $spv->id,
            'attendance_setting_id' => $this->branchA->id,
        ]);
        $bawahanB = $this->makeUser('employee', [
            'manager_id'            => $spv->id,
            'attendance_setting_id' => $this->branchB->id,
        ]);

        foreach ([$bawahanA, $bawahanB] as $emp) {
            $att = $this->attendanceWithOvertime($emp, 120);
            OvertimeApproval::create([
                'attendance_id'    => $att->id,
                'user_id'          => $emp->id,
                'company_id'       => $this->company->id,
                'overtime_minutes' => 120,
                'status'           => 'pending',
                'current_step'     => 'spv',
            ]);
        }

        // Batasi role hanya ke cabang A.
        $spvRole->branches()->sync([$this->branchA->id]);
        $spv->refresh();

        $resCount = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/attendance/spv/overtime-approvals/count')
            ->assertOk();

        // Endpoint daftar sudah menerapkan scoping cabang -> dipakai sebagai acuan.
        $resList = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/attendance/spv/overtime-approvals')
            ->assertOk();

        $this->assertEquals(
            $resList->json('pending_count'),
            $resCount->json('pending_count'),
            'Badge count wajib konsisten dengan pending_count endpoint daftar.',
        );
        $this->assertEquals(1, $resCount->json('pending_count'));
    }

    // ── BUG 3 — Pengaman HRD pada rejectLeave() lebih longgar daripada
    // approveLeave(): peran yang TIDAK boleh menyetujui tahap 2 masih bisa
    // MENOLAK tahap 2 hanya karena namanya memuat "hr". ──

    public function test_peran_tanpa_wewenang_hrd_tidak_dapat_menolak_cuti_tahap_2(): void
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => 'HRGA Support',
            'slug'         => 'hrga_support',
            'branch_scope' => 'all',
            'platform'     => 'both',
        ]);

        $palsu    = $this->makeUser('employee', ['role_id' => $role->id]);
        $employee = $this->makeUser('employee');

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin keperluan pribadi',
            'status'       => 'pending',
            'current_step' => 'hrd',
        ]);

        // Tidak boleh MENYETUJUI (sudah benar sejak awal)...
        $this->actingAs($palsu, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", ['notes' => 'oke'])
            ->assertStatus(403);

        // ...maka juga tidak boleh MENOLAK.
        $this->actingAs($palsu, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/reject", [
                'rejection_reason' => 'Ditolak oleh pihak tak berwenang',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'HRD_REQUIRED');

        $this->assertEquals('pending', $leave->fresh()->status);
    }

    // ── BUG 4 — Penolakan tahap SPV menulis approved_by/approved_at sehingga
    // cuti yang DITOLAK terlihat seolah pernah "disetujui". ──

    public function test_penolakan_spv_tidak_menandai_cuti_sebagai_disetujui(): void
    {
        $spv      = $this->makeUser('employee');
        $employee = $this->makeUser('employee', ['manager_id' => $spv->id]);

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin urusan keluarga',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        $this->actingAs($spv, 'sanctum')
            ->postJson("/api/v1/attendance/spv/leave-approvals/{$leave->id}/reject", [
                'notes' => 'Beban kerja tim sedang tinggi',
            ])->assertOk();

        $leave->refresh();
        $this->assertEquals('rejected', $leave->status);
        $this->assertEquals($spv->id, $leave->spv_id);
        $this->assertNotNull($leave->spv_approved_at);

        // Penolakan BUKAN persetujuan.
        $this->assertNull($leave->approved_by, 'approved_by tidak boleh terisi pada penolakan tahap SPV.');
        $this->assertNull($leave->approved_at, 'approved_at tidak boleh terisi pada penolakan tahap SPV.');
        $this->assertEquals('spv', $leave->current_step);
    }

    // ── BUG 5 — Notifikasi 'overtime_pending_spv' tidak pernah dibersihkan saat
    // approve/reject, sehingga SPV terus melihat notifikasi basi. ──

    public function test_notifikasi_spv_lembur_dibersihkan_setelah_persetujuan_final(): void
    {
        $spv      = $this->makeUser('employee');
        $employee = $this->makeUser('employee', ['manager_id' => $spv->id]);
        $hrd      = $this->makeUser('hrd');

        $attendance = $this->attendanceWithOvertime($employee, 120);

        $claim = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/v1/attendance/{$attendance->id}/claim-overtime", [
                'reason' => 'Menyelesaikan migrasi basis data klien',
            ])->assertOk();

        $approvalId = $claim->json('approval.id');
        $this->seedSpvNotification($spv->id, $approvalId);

        $this->actingAs($spv, 'sanctum')
            ->postJson("/api/v1/attendance/spv/overtime-approvals/{$approvalId}/approve", ['notes' => 'Disetujui atasan'])
            ->assertOk();

        $this->actingAs($hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/overtime-approvals/{$approvalId}/approve", ['notes' => 'Disetujui HRD'])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'entity_type' => 'overtime_approval',
            'entity_id'   => $approvalId,
            'type'        => 'overtime_pending_spv',
        ]);
    }

    public function test_notifikasi_spv_lembur_dibersihkan_setelah_penolakan(): void
    {
        $spv      = $this->makeUser('employee');
        $employee = $this->makeUser('employee', ['manager_id' => $spv->id]);

        $attendance = $this->attendanceWithOvertime($employee, 120);

        $approval = OvertimeApproval::create([
            'attendance_id'    => $attendance->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 120,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        $this->seedSpvNotification($spv->id, $approval->id);

        $this->actingAs($spv, 'sanctum')
            ->postJson("/api/v1/attendance/spv/overtime-approvals/{$approval->id}/reject", [
                'notes' => 'Lembur tidak terverifikasi',
            ])->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'entity_type' => 'overtime_approval',
            'entity_id'   => $approval->id,
            'type'        => 'overtime_pending_spv',
        ]);
    }

    // ── BUG 6 — Daftar web tidak mengecualikan pengaju = SPV itu sendiri,
    // berbeda dari endpoint mobile. ──

    public function test_daftar_lembur_web_tidak_memuat_pengajuan_milik_spv_sendiri(): void
    {
        $div    = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS']);
        $spvPos = Position::create([
            'company_id'    => $this->company->id,
            'division_id'   => $div->id,
            'name'          => 'SPV Operasional',
            'is_supervisor' => true,
        ]);

        $spv     = $this->makeUser('employee', ['division_id' => $div->id, 'position_id' => $spvPos->id]);
        $bawahan = $this->makeUser('employee', ['division_id' => $div->id, 'manager_id' => $spv->id]);

        foreach ([$spv, $bawahan] as $person) {
            $att = $this->attendanceWithOvertime($person, 120);
            OvertimeApproval::create([
                'attendance_id'    => $att->id,
                'user_id'          => $person->id,
                'company_id'       => $this->company->id,
                'overtime_minutes' => 120,
                'status'           => 'pending',
                'current_step'     => 'spv',
            ]);
        }

        $res = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/overtime-approvals')
            ->assertOk();

        $userIds = collect($res->json('approvals.data') ?? $res->json('data') ?? [])->pluck('user_id')->all();
        $this->assertNotContains($spv->id, $userIds, 'SPV tidak boleh melihat pengajuan lemburnya sendiri.');
        $this->assertContains($bawahan->id, $userIds);
    }

    public function test_daftar_cuti_web_tidak_memuat_pengajuan_milik_spv_sendiri(): void
    {
        $div    = Division::create(['company_id' => $this->company->id, 'name' => 'Keuangan', 'code' => 'FIN']);
        $spvPos = Position::create([
            'company_id'    => $this->company->id,
            'division_id'   => $div->id,
            'name'          => 'SPV Keuangan',
            'is_supervisor' => true,
        ]);

        $spv     = $this->makeUser('employee', ['division_id' => $div->id, 'position_id' => $spvPos->id]);
        $bawahan = $this->makeUser('employee', ['division_id' => $div->id, 'manager_id' => $spv->id]);

        foreach ([$spv, $bawahan] as $person) {
            LeaveRequest::create([
                'company_id'   => $this->company->id,
                'user_id'      => $person->id,
                'leave_type'   => 'izin',
                'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
                'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
                'total_days'   => 1,
                'reason'       => 'Izin keperluan pribadi',
                'status'       => 'pending',
                'current_step' => 'spv',
            ]);
        }

        $res = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/leaves')
            ->assertOk();

        $userIds = collect($res->json('leaves.data') ?? $res->json('data') ?? [])->pluck('user_id')->all();
        $this->assertNotContains($spv->id, $userIds, 'SPV tidak boleh melihat pengajuan cutinya sendiri.');
        $this->assertContains($bawahan->id, $userIds);
    }

    // BUG 7 - rejectLeave() tidak menjalankan autoRejectExpiredLeaves() seperti
    // approveLeave(), sehingga pengajuan yang sudah lewat hari H masih bisa
    // ditolak manual dengan alasan yang keliru.

    public function test_penolakan_cuti_menjalankan_auto_reject_hari_h_lebih_dahulu(): void
    {
        $hrd      = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');

        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->subDays(2)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->subDays(2)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin lampau',
            'status'       => 'pending',
            'current_step' => 'hrd',
        ]);

        $this->actingAs($hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/reject", [
                'rejection_reason' => 'Alasan manual HRD',
            ])->assertOk();

        $leave->refresh();
        $this->assertEquals('rejected', $leave->status);
        $this->assertStringContainsString('otomatis ditolak oleh sistem', (string) $leave->rejection_reason);
    }

    // BUG 8 - approveLeave() tahap HRD memanggil splitLeaveAroundReset() TANPA
    // daftar tanggal efektif, sehingga pembaginya memakai seluruh hari kerja dalam
    // rentang - termasuk tanggal yang sudah dipakai pengajuan cuti LAIN dan karena
    // itu TIDAK ikut dihitung pada total_days. Akibatnya days_before bisa melebihi
    // sisa saldo dan persetujuan ditolak 422 secara keliru.

    public function test_persetujuan_hrd_tidak_menolak_karena_tanggal_bentrok_milik_cuti_lain(): void
    {
        $hrd      = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');

        $year = Carbon::now('Asia/Jakarta')->year;
        $balance = LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 3,
            'used'       => 0,
        ]);

        // Tanggal reset saldo berada DI DALAM rentang pengajuan, sehingga
        // splitLeaveAroundReset() memakai daftar hari kerja (bukan total_days)
        // untuk memvalidasi days_before/days_after.
        $this->branchA->update([
            'leave_reset_date'    => $this->workdayAhead(5)->format('m-d'),
            'default_leave_quota' => 12,
        ]);

        // Cuti lain yang sudah disetujui menutup 3 hari kerja pertama.
        LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => $this->workdayAhead(1)->toDateString(),
            'end_date'     => $this->workdayAhead(3)->toDateString(),
            'total_days'   => 3,
            'reason'       => 'Izin keperluan mendesak',
            'status'       => 'approved',
            'current_step' => 'hrd',
        ]);

        // Pengajuan cuti ini membentang menimpa rentang di atas; hari efektifnya
        // hanya 2 (hari kerja ke-4 & ke-5) karena 3 hari pertama sudah terpakai.
        $leave = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'cuti',
            'start_date'   => $this->workdayAhead(1)->toDateString(),
            'end_date'     => $this->workdayAhead(5)->toDateString(),
            'total_days'   => 2,
            'reason'       => 'Cuti tahunan',
            'status'       => 'pending',
            'current_step' => 'hrd',
        ]);

        // Sisa saldo 3 hari >= total_days 2 hari, jadi harus LOLOS. Tanpa daftar
        // tanggal efektif, backend menghitung 5 hari kerja > 3 dan menolak 422.
        $this->actingAs($hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/leaves/{$leave->id}/approve", ['notes' => 'Disetujui'])
            ->assertOk();

        $this->assertEquals('approved', $leave->fresh()->status);
        $this->assertEquals(2, (int) $balance->fresh()->used);
    }

    // BUG 9 - Daftar SPV mobile memuat semua tahap sedangkan pending_count hanya
    // menghitung tahap 'spv'; tidak ada penanda item mana yang benar-benar
    // menjadi wewenang SPV saat ini.

    public function test_daftar_lembur_spv_menandai_item_yang_dapat_diproses(): void
    {
        $spv      = $this->makeUser('employee');
        $employee = $this->makeUser('employee', ['manager_id' => $spv->id]);

        $attSpvStep = $this->attendanceWithOvertime($employee, 60);
        $ownStep = OvertimeApproval::create([
            'attendance_id'    => $attSpvStep->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'spv',
        ]);

        $attHrdStep = Attendance::create([
            'company_id'       => $this->company->id,
            'user_id'          => $employee->id,
            'date'             => now()->subDay()->toDateString(),
            'check_in_time'    => now()->subDay()->subHours(10),
            'check_out_time'   => now()->subDay(),
            'status'           => 'present',
            'overtime_minutes' => 90,
        ]);
        $escalated = OvertimeApproval::create([
            'attendance_id'    => $attHrdStep->id,
            'user_id'          => $employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 90,
            'status'           => 'pending',
            'current_step'     => 'hrd',
            'spv_id'           => $spv->id,
            'spv_approved_at'  => now()->subHour(),
        ]);

        $res = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/attendance/spv/overtime-approvals')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertEquals(1, $res->json('pending_count'));

        $rows = collect($res->json('data'))->keyBy('id');
        $this->assertTrue($rows[$ownStep->id]['can_action'], 'Item tahap SPV harus dapat diproses.');
        $this->assertFalse($rows[$escalated->id]['can_action'], 'Item yang sudah di HRD tidak dapat diproses SPV.');
    }

    public function test_daftar_cuti_spv_menandai_item_yang_dapat_diproses(): void
    {
        $spv      = $this->makeUser('employee');
        $employee = $this->makeUser('employee', ['manager_id' => $spv->id]);

        $ownStep = LeaveRequest::create([
            'company_id'   => $this->company->id,
            'user_id'      => $employee->id,
            'leave_type'   => 'izin',
            'start_date'   => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'end_date'     => Carbon::now('Asia/Jakarta')->addDays(5)->toDateString(),
            'total_days'   => 1,
            'reason'       => 'Izin A',
            'status'       => 'pending',
            'current_step' => 'spv',
        ]);

        $escalated = LeaveRequest::create([
            'company_id'      => $this->company->id,
            'user_id'         => $employee->id,
            'leave_type'      => 'izin',
            'start_date'      => Carbon::now('Asia/Jakarta')->addDays(7)->toDateString(),
            'end_date'        => Carbon::now('Asia/Jakarta')->addDays(7)->toDateString(),
            'total_days'      => 1,
            'reason'          => 'Izin B',
            'status'          => 'pending',
            'current_step'    => 'hrd',
            'spv_id'          => $spv->id,
            'spv_approved_at' => now()->subHour(),
        ]);

        $res = $this->actingAs($spv, 'sanctum')
            ->getJson('/api/v1/attendance/spv/leave-approvals')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertEquals(1, $res->json('pending_count'));

        $rows = collect($res->json('data'))->keyBy('id');
        $this->assertTrue($rows[$ownStep->id]['can_action']);
        $this->assertFalse($rows[$escalated->id]['can_action']);
    }

}
