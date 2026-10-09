<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\OvertimeApproval;
use App\Models\Payroll;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KOREKSI PRESENSI MANUAL OLEH HRD — AttendanceController::correctAttendance()
 *
 * Fokus test ini bukan "endpoint-nya jalan", tapi tiga aturan yang kalau bocor
 * akan menyebabkan salah gaji tanpa jejak:
 *   1. Field turunan (work_minutes/overtime_minutes/is_holiday) SELALU dihitung
 *      server-side; request tidak pernah bisa menyetelnya.
 *   2. Hitung ulang memakai snapshot (aturan saat check-in), bukan setting live.
 *   3. Periode payroll approved/paid tidak bisa dikoreksi sama sekali.
 */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/dashboard/attendance/corrections';

    private Company $company;
    private AttendanceSetting $office;
    private User $hrd;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'      => 'PT Koreksi Presensi',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'                    => $this->company->id,
            'office_name'                   => 'Kantor Pusat',
            'office_latitude'               => -6.2000,
            'office_longitude'              => 106.8166,
            'radius_meters'                 => 100,
            'work_start_time'               => '08:00',
            'work_end_time'                 => '17:00',
            'break_minutes'                 => 60,
            'work_days'                     => [1, 2, 3, 4, 5],
            'late_tolerance_minutes'        => 15,
            'early_leave_tolerance_minutes' => 15,
            'overtime_enabled'              => true,
            'min_overtime_minutes'          => 30,
            'is_active'                     => true,
        ]);

        $this->hrd = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'hrd',
            'is_active'             => true,
            'name'                  => 'Rina HRD',
            'email'                 => 'rina.hrd@koreksi.test',
        ]);

        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'name'                  => 'Budi Karyawan',
            'employee_code'         => 'EMP-001',
            'department'            => 'Produksi',
            'email'                 => 'budi@koreksi.test',
        ]);
    }

    /** Tanggal kerja (Senin) yang masih dalam batas 90 hari koreksi. */
    private function workDate(int $daysAgo = 3): string
    {
        $d = now('Asia/Jakarta')->subDays($daysAgo);
        while ($d->isWeekend()) {
            $d->subDay();
        }

        return $d->toDateString();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'user_id'           => $this->employee->id,
            'date'              => $this->workDate(),
            'correction_reason' => 'Karyawan lupa check-out, dikoreksi berdasarkan log mesin absen.',
            'confirm_dangerous' => 'SIMPAN',
        ], $override);
    }

    private function seedAttendance(array $override = []): Attendance
    {
        $date = $override['date'] ?? $this->workDate();

        return Attendance::create(array_merge([
            'company_id'           => $this->company->id,
            'user_id'              => $this->employee->id,
            'date'                 => $date,
            'check_in_time'        => $date . ' 08:00:00',
            'check_out_time'       => null,
            'check_in_type'        => 'onsite',
            'status'               => 'present',
            'work_minutes'         => null,
            'overtime_minutes'     => 0,
            'is_holiday'           => false,
            // Snapshot aturan SAAT check-in — inilah yang wajib dipakai recompute
            'snap_source'          => 'office',
            'snap_work_start_time' => '08:00',
            'snap_work_end_time'   => '17:00',
            'snap_is_off'          => false,
            'snap_is_cross_day'    => false,
            'snap_office_id'       => $this->office->id,
            'snap_min_overtime_minutes'          => 30,
            'snap_early_leave_tolerance_minutes' => 15,
            'snap_overtime_enabled'              => true,
        ], $override));
    }

    // ───────────────────────────────────────────────────────────────
    // 1. Snapshot menang atas setting live
    // ───────────────────────────────────────────────────────────────
    public function test_recompute_memakai_snapshot_bukan_setting_kantor_yang_sudah_diubah(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        // HRD memundurkan jam pulang kantor SETELAH karyawan check-in.
        // Kalau recompute memakai setting live, 17:00→20:00 akan menghapus lembur.
        $this->office->update(['work_end_time' => '20:00']);

        $res = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '19:00',
            ]));

        $res->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        // Snapshot work_end_time = 17:00 → lembur 19:00-17:00 = 120 menit
        $this->assertSame(120, (int) $att->overtime_minutes, 'Lembur wajib dihitung dari snapshot 17:00, bukan setting live 20:00.');
    }

    // ───────────────────────────────────────────────────────────────
    // 2. Potongan istirahat UU 13/2003
    // ───────────────────────────────────────────────────────────────
    public function test_potongan_istirahat_dipakai_saat_span_minimal_lima_jam(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $res = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '17:00',
            ]));

        $res->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        // Span 540 menit >= 300 → potong break 60 → 480
        $this->assertSame(480, (int) $att->work_minutes);
    }

    public function test_span_di_bawah_lima_jam_tidak_dipotong_istirahat(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '12:00',
                'status'         => 'early_leave',
            ]))->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        $this->assertSame(240, (int) $att->work_minutes, 'Span 240 menit (<300) tidak boleh dipotong istirahat.');
    }

    // ───────────────────────────────────────────────────────────────
    // 3. Urutan recompute: early_leave menimpa status di akhir
    // ───────────────────────────────────────────────────────────────
    public function test_checkout_awal_tanpa_status_eksplisit_menjadi_early_leave(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '14:00',
            ]))->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        // 14:00 < (17:00 - 15 menit toleransi) → early_leave, BUKAN present.
        // Kalau hanya determineStatus() yang jalan, hasilnya akan 'present'.
        $this->assertSame('early_leave', $att->status);
    }

    public function test_status_eksplisit_hrd_menang_atas_hasil_hitungan_sistem(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        // HRD tahu konteksnya: pulang awal karena izin atasan → tetap 'present'.
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '14:00',
                'status'         => 'present',
            ]))->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        $this->assertSame('present', $att->status);
    }

    // ───────────────────────────────────────────────────────────────
    // 4. Backfill baris virtual (alpha / lupa check-in)
    // ───────────────────────────────────────────────────────────────
    public function test_input_manual_membuat_baris_baru_dengan_penanda_manual_dan_snapshot(): void
    {
        $date = $this->workDate();

        $this->assertDatabaseMissing('attendances', [
            'user_id' => $this->employee->id,
        ]);

        $res = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'              => $date,
                'check_in_time'     => '08:05',
                'check_out_time'    => '17:10',
                'correction_reason' => 'HP karyawan mati, kehadiran diverifikasi dari buku tamu satpam.',
            ]));

        $res->assertOk()->assertJsonPath('data.is_manual_entry', true);

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        $this->assertNotNull($att, 'Baris presensi baru wajib terbentuk.');
        $this->assertTrue((bool) $att->is_manual_entry);
        $this->assertNotNull($att->snap_source, 'Baris hasil backfill wajib punya snapshot agar hitung ulang berikutnya punya acuan.');
        $this->assertSame($this->hrd->id, (int) $att->corrected_by);
        $this->assertNotNull($att->corrected_at);
        // 08:05 -> 17:10 = 545 menit, potong istirahat 60 -> 485.
        // Titik awal = jam check-in aktual karena karyawan masuk SETELAH 08:00.
        $this->assertSame(485, (int) $att->work_minutes);
    }

    // ───────────────────────────────────────────────────────────────
    // 5 & 6. Urutan waktu, sadar shift lintas hari
    // ───────────────────────────────────────────────────────────────
    public function test_shift_lintas_hari_menerima_jam_pulang_lebih_kecil_dari_jam_masuk(): void
    {
        $date  = $this->workDate();
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam',
            'is_active'             => true,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $d,
                'work_start_time' => '22:00',
                'work_end_time'   => '06:00',
                'is_off'          => false,
                'is_cross_day'    => true,
                'effective_date'  => now('Asia/Jakarta')->subYear()->toDateString(),
            ]);
        }
        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shift->id,
            'start_date' => now('Asia/Jakarta')->subYear()->toDateString(),
            'end_date'   => null,
        ]);

        $this->seedAttendance([
            'date'                 => $date,
            'check_in_time'        => $date . ' 22:00:00',
            'snap_source'          => 'shift',
            'snap_shift_id'        => $shift->id,
            'snap_shift_name'      => 'Shift Malam',
            'snap_work_start_time' => '22:00',
            'snap_work_end_time'   => '06:00',
            'snap_is_cross_day'    => true,
        ]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '22:00',
                'check_out_time' => '06:00', // jam lebih kecil → hari berikutnya
            ]))->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        // Span 8 jam = 480 menit, potong break 60 → 420
        $this->assertSame(420, (int) $att->work_minutes);
        $this->assertTrue(
            $att->check_out_time->toDateString() > $date,
            'Jam pulang shift malam wajib dinaikkan ke hari berikutnya.'
        );
    }

    public function test_jam_pulang_sebelum_jam_masuk_pada_shift_biasa_ditolak(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $res = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '06:00',
            ]));

        $res->assertStatus(422);

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $this->assertNull($att->check_out_time, 'Baris tidak boleh tersentuh saat validasi urutan waktu gagal.');
        $this->assertNull($att->corrected_at);
    }

    public function test_durasi_presensi_melebihi_dua_puluh_jam_ditolak(): void
    {
        $date  = $this->workDate();
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam Panjang',
            'is_active'             => true,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $d,
                'work_start_time' => '22:00',
                'work_end_time'   => '06:00',
                'is_off'          => false,
                'is_cross_day'    => true,
                'effective_date'  => now('Asia/Jakarta')->subYear()->toDateString(),
            ]);
        }

        $this->seedAttendance([
            'date'                 => $date,
            'check_in_time'        => $date . ' 05:00:00',
            'snap_source'          => 'shift',
            'snap_shift_id'        => $shift->id,
            'snap_work_start_time' => '22:00',
            'snap_work_end_time'   => '06:00',
            'snap_is_cross_day'    => true,
        ]);

        // 05:00 → 04:00 hari berikutnya = 23 jam > batas 20 jam
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '05:00',
                'check_out_time' => '04:00',
            ]))->assertStatus(422)->assertJsonPath('span_minutes', 23 * 60);
    }

    // ───────────────────────────────────────────────────────────────
    // 7. Batas tanggal
    // ───────────────────────────────────────────────────────────────
    public function test_tanggal_masa_depan_ditolak(): void
    {
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'          => now('Asia/Jakarta')->addDay()->toDateString(),
                'check_in_time' => '08:00',
            ]))->assertStatus(422);
    }

    public function test_tanggal_lebih_tua_dari_sembilan_puluh_hari_ditolak(): void
    {
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'          => now('Asia/Jakarta')->subDays(120)->toDateString(),
                'check_in_time' => '08:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('max_age_days', 90);
    }

    // ───────────────────────────────────────────────────────────────
    // 8. Payroll terkunci = hard block
    // ───────────────────────────────────────────────────────────────
    public function test_periode_payroll_approved_menolak_koreksi_dan_tidak_mengubah_data(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        Payroll::create([
            'company_id'   => $this->company->id,
            'period_month' => (int) substr($date, 5, 2),
            'period_year'  => (int) substr($date, 0, 4),
            'period_label' => 'Periode Terkunci',
            'status'       => Payroll::STATUS_APPROVED,
            'run_type'     => Payroll::RUN_TYPE_REGULAR,
        ]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '09:30',
                'check_out_time' => '17:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('payroll_locked', true);

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $this->assertSame('08:00', $att->check_in_time->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertNull($att->corrected_at, 'Periode terkunci tidak boleh meninggalkan jejak koreksi apa pun.');
    }

    public function test_gate_simpan_tidak_bisa_melewati_kunci_payroll(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        Payroll::create([
            'company_id'   => $this->company->id,
            'period_month' => (int) substr($date, 5, 2),
            'period_year'  => (int) substr($date, 0, 4),
            'period_label' => 'Periode Dibayar',
            'status'       => Payroll::STATUS_PAID,
            'run_type'     => Payroll::RUN_TYPE_REGULAR,
        ]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'              => $date,
                'check_in_time'     => '09:30',
                'confirm_dangerous' => 'SIMPAN',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('payroll_locked', true);
    }

    // ───────────────────────────────────────────────────────────────
    // 9. Alasan koreksi wajib
    // ───────────────────────────────────────────────────────────────
    public function test_tanpa_alasan_koreksi_ditolak(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, [
                'user_id'       => $this->employee->id,
                'date'          => $date,
                'check_in_time' => '08:30',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['correction_reason']);
    }

    public function test_alasan_koreksi_kurang_dari_sepuluh_karakter_ditolak(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'              => $date,
                'check_in_time'     => '08:30',
                'correction_reason' => 'salah',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['correction_reason']);
    }

    // ───────────────────────────────────────────────────────────────
    // 10. Gerbang konfirmasi SIMPAN
    // ───────────────────────────────────────────────────────────────
    public function test_perubahan_berdampak_gaji_tanpa_konfirmasi_simpan_ditolak_lalu_berhasil_setelah_dikonfirmasi(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $body = [
            'user_id'           => $this->employee->id,
            'date'              => $date,
            'check_in_time'     => '08:00',
            'check_out_time'    => '18:00',
            'correction_reason' => 'Lembur sudah disetujui lisan, check-out tidak terekam sistem.',
        ];

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $body)
            ->assertStatus(422)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('confirmation_phrase', 'SIMPAN')
            ->assertJsonStructure(['dangerous_changed_fields']);

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $this->assertNull($att->check_out_time, 'Gate SIMPAN wajib menolak SEBELUM data ditulis.');

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $body + ['confirm_dangerous' => 'SIMPAN'])
            ->assertOk();

        $att->refresh();
        $this->assertSame('18:00', $att->check_out_time->setTimezone('Asia/Jakarta')->format('H:i'));
    }

    // ───────────────────────────────────────────────────────────────
    // 11. Isolasi tenant & cabang
    // ───────────────────────────────────────────────────────────────
    public function test_karyawan_perusahaan_lain_tidak_bisa_dikoreksi(): void
    {
        $otherCompany = Company::create(['name' => 'PT Tetangga', 'is_active' => true]);
        $otherEmployee = User::factory()->create([
            'company_id' => $otherCompany->id,
            'role'       => 'employee',
            'is_active'  => true,
            'email'      => 'orang.lain@tetangga.test',
        ]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'user_id'       => $otherEmployee->id,
                'check_in_time' => '08:00',
            ]))->assertStatus(422);

        $this->assertDatabaseMissing('attendances', ['user_id' => $otherEmployee->id]);
    }

    public function test_karyawan_di_luar_cabang_yang_diizinkan_ditolak(): void
    {
        $branchB = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang B',
            'office_latitude'  => -6.9,
            'office_longitude' => 107.6,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
            'work_days'        => [1, 2, 3, 4, 5],
            'is_active'        => true,
        ]);

        $employeeB = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $branchB->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'email'                 => 'budi.cabangb@koreksi.test',
        ]);

        // Supervisor cabang: allowedBranchIds() = [kantor pusat] saja
        $branchScopedActor = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'spv',
            'is_active'             => true,
            'email'                 => 'spv.pusat@koreksi.test',
        ]);

        $this->assertFalse($branchScopedActor->allowsBranch($branchB->id));

        $this->actingAs($branchScopedActor, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'user_id'       => $employeeB->id,
                'check_in_time' => '08:00',
            ]))->assertStatus(403);

        $this->assertDatabaseMissing('attendances', ['user_id' => $employeeB->id]);
    }

    public function test_karyawan_biasa_tidak_boleh_mengakses_endpoint_koreksi(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->postJson(self::URL, $this->payload(['check_in_time' => '08:00']))
            ->assertStatus(403);
    }

    // ───────────────────────────────────────────────────────────────
    // 12. Rekonsiliasi lembur
    // ───────────────────────────────────────────────────────────────
    public function test_pengajuan_lembur_pending_ikut_tersinkron_dengan_hasil_koreksi(): void
    {
        $date = $this->workDate();
        $att  = $this->seedAttendance([
            'date'             => $date,
            'check_out_time'   => $date . ' 18:00:00',
            'work_minutes'     => 540,
            'overtime_minutes' => 60,
        ]);

        $approval = OvertimeApproval::create([
            'attendance_id'    => $att->id,
            'user_id'          => $this->employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'hrd',
        ]);

        // Koreksi: check-out sebenarnya 19:00 → lembur jadi 120 menit
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '19:00',
            ]))->assertOk();

        $approval->refresh();
        $this->assertSame(120, (int) $approval->overtime_minutes, 'overtime_approvals wajib sinkron dengan attendances, karena PayrollCalculator membaca keduanya.');
    }

    public function test_pengajuan_lembur_pending_dihapus_saat_lembur_hilang_setelah_koreksi(): void
    {
        $date = $this->workDate();
        $att  = $this->seedAttendance([
            'date'             => $date,
            'check_out_time'   => $date . ' 18:00:00',
            'work_minutes'     => 540,
            'overtime_minutes' => 60,
        ]);

        $approvalId = OvertimeApproval::create([
            'attendance_id'    => $att->id,
            'user_id'          => $this->employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 60,
            'status'           => 'pending',
            'current_step'     => 'hrd',
        ])->id;

        // Ternyata karyawan pulang 17:00, bukan 18:00 → tidak ada lembur
        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '17:00',
            ]))->assertOk();

        $this->assertDatabaseMissing('overtime_approvals', ['id' => $approvalId]);
    }

    public function test_lembur_yang_sudah_disetujui_memblokir_koreksi(): void
    {
        $date = $this->workDate();
        $att  = $this->seedAttendance([
            'date'             => $date,
            'check_out_time'   => $date . ' 18:00:00',
            'work_minutes'     => 540,
            'overtime_minutes' => 60,
        ]);

        OvertimeApproval::create([
            'attendance_id'    => $att->id,
            'user_id'          => $this->employee->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => 60,
            'status'           => 'approved',
            'current_step'     => 'hrd',
            'reviewed_by'      => $this->hrd->id,
            'reviewed_at'      => now(),
        ]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '19:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('overtime_conflict', true);

        // Transaksi di-rollback: presensi tetap seperti semula
        $att->refresh();
        $this->assertSame('18:00', $att->check_out_time->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame(60, (int) $att->overtime_minutes);
        $this->assertNull($att->corrected_at);
    }

    // ───────────────────────────────────────────────────────────────
    // 13. Konflik cuti yang sudah disetujui
    // ───────────────────────────────────────────────────────────────
    public function test_tanggal_dengan_cuti_disetujui_menolak_koreksi(): void
    {
        $date = $this->workDate();

        LeaveRequest::create([
            'user_id'    => $this->employee->id,
            'company_id' => $this->company->id,
            'leave_type' => 'cuti',
            'start_date' => $date,
            'end_date'   => $date,
            'total_days' => 1,
            'reason'     => 'Acara keluarga',
            'status'     => 'approved',
        ]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'          => $date,
                'check_in_time' => '08:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('leave_conflict', true);

        $this->assertDatabaseMissing('attendances', [
            'user_id' => $this->employee->id,
        ]);
    }

    // ───────────────────────────────────────────────────────────────
    // 14. Field turunan tidak pernah berasal dari request
    // ───────────────────────────────────────────────────────────────
    public function test_field_turunan_yang_dikirim_request_diabaikan_total(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'             => $date,
                'check_in_time'    => '08:00',
                'check_out_time'   => '17:00',
                // Upaya menyuntik angka gaji langsung — wajib diabaikan
                'work_minutes'     => 9999,
                'overtime_minutes' => 9999,
                'is_holiday'       => true,
                'late_minutes'     => 999,
                'snap_work_end_time' => '23:00',
            ]))->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        $this->assertSame(480, (int) $att->work_minutes);
        $this->assertSame(0, (int) $att->overtime_minutes);
        $this->assertFalse((bool) $att->is_holiday);
        $this->assertSame('17:00', substr((string) $att->snap_work_end_time, 0, 5));
    }

    public function test_status_sintetis_laporan_tidak_bisa_ditulis_ke_kolom_status(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        foreach (['cuti', 'izin', 'sakit', 'libur', 'alpha', 'belum_hadir'] as $sintetis) {
            $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'          => $date,
                    'check_in_time' => '08:00',
                    'status'        => $sintetis,
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
        }
    }

    // ───────────────────────────────────────────────────────────────
    // 15. Jejak audit & notifikasi
    // ───────────────────────────────────────────────────────────────
    public function test_koreksi_mencatat_activity_log_dengan_diff_dan_mengirim_notifikasi(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'              => $date,
                'check_in_time'     => '08:00',
                'check_out_time'    => '17:00',
                'correction_reason' => 'Mesin absen offline saat jam pulang, dikoreksi dari rekaman CCTV.',
            ]))->assertOk();

        $log = DB::table('activity_logs')
            ->where('action', 'attendance_correction')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log, 'Koreksi presensi wajib meninggalkan satu entri activity_logs.');
        $this->assertSame('warning', $log->severity, 'Koreksi presensi adalah aksi sensitif → severity warning.');
        $this->assertNotNull($log->old_values);
        $this->assertNotNull($log->new_values);

        $notif = DB::table('notifications')
            ->where('user_id', $this->employee->id)
            ->where('type', 'attendance_corrected')
            ->first();

        $this->assertNotNull($notif, 'Karyawan wajib diberi tahu presensinya dikoreksi.');
        $this->assertStringContainsString('CCTV', (string) $notif->data);
    }

    // ───────────────────────────────────────────────────────────────
    // 16. Laporan menampilkan jejak koreksi + periode terkunci
    // ───────────────────────────────────────────────────────────────
    public function test_laporan_mengirim_jejak_koreksi_dan_daftar_periode_terkunci(): void
    {
        $date = $this->workDate();
        $this->seedAttendance(['date' => $date]);

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '17:00',
            ]))->assertOk();

        Payroll::create([
            'company_id'   => $this->company->id,
            'period_month' => (int) substr($date, 5, 2),
            'period_year'  => (int) substr($date, 0, 4),
            'period_label' => 'Periode Terkunci',
            'status'       => Payroll::STATUS_APPROVED,
            'run_type'     => Payroll::RUN_TYPE_REGULAR,
        ]);

        $res = $this->actingAs($this->hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/report?start_date=' . $date . '&end_date=' . $date);

        $res->assertOk()->assertJsonStructure(['locked_periods']);

        $this->assertContains(substr($date, 0, 7), $res->json('locked_periods'));

        $rows = collect($res->json('report.data'))
            ->where('user_id', $this->employee->id)
            ->values();

        $this->assertNotEmpty($rows, 'Baris presensi karyawan wajib muncul di laporan.');
        $this->assertTrue((bool) $rows[0]['is_corrected']);
        $this->assertSame($this->hrd->name, $rows[0]['corrected_by_name']);
        $this->assertNotEmpty($rows[0]['correction_reason']);
        $this->assertTrue((bool) $rows[0]['editable']);
    }

    // ───────────────────────────────────────────────────────────────
    // 17. Enterprise Guard: Time Inversion Block & Pemisahan Mode WFH
    // ───────────────────────────────────────────────────────────────
    public function test_checkout_ditolak_jika_jam_keluar_mendahului_jam_masuk_time_inversion(): void
    {
        $date = $this->workDate();

        // HRD mengoreksi / menginput jam masuk = 13:00 (misal siang hari)
        $this->seedAttendance([
            'date'           => $date,
            'check_in_time'  => $date . ' 13:00:00',
            'check_out_time' => null,
            'check_in_type'  => 'onsite',
        ]);

        // Simulasikan karyawan tidak sengaja klik check-out pada jam 11:00 (sebelum jam masuk)
        \Illuminate\Support\Carbon::setTestNow($date . ' 11:00:00');

        try {
            $response = $this->actingAs($this->employee, 'sanctum')
                ->postJson('/api/v1/attendance/check-out', [
                    'latitude'  => (float) $this->office->office_latitude,
                    'longitude' => (float) $this->office->office_longitude,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('time_inversion', true);

            $this->assertStringContainsString('tidak boleh mendahului atau sama dengan waktu check-in', $response->json('message'));

            // Pastikan check_out_time tidak tersimpan di database
            $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
            $this->assertNull($att->check_out_time);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_sesi_aktif_onsite_tidak_memaksa_wfh_enabled_menjadi_true(): void
    {
        $date = $this->workDate();
        \Illuminate\Support\Carbon::setTestNow($date . ' 09:00:00');

        try {
            // Karyawan kantor biasa (wfh_enabled = false di DB)
            $this->employee->update([
                'wfh_enabled' => false,
                'allow_wfh'   => false,
            ]);

            // HRD menginput jam masuk onsite saja (check_out_time masih null)
            $this->seedAttendance([
                'date'           => $date,
                'check_in_time'  => $date . ' 08:00:00',
                'check_out_time' => null,
                'check_in_type'  => 'onsite',
            ]);

            // Karyawan memanggil endpoint polling status mobile
            $res = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/attendance/status');

            $res->assertOk();
            $this->assertTrue($res->json('checked_in'));
            $this->assertFalse($res->json('checked_out'));
            // wfh_enabled harus tetap FALSE (tidak boleh dipaksa true hanya karena sesi aktif onsite)
            $this->assertFalse($res->json('wfh_enabled'), 'Sesi aktif onsite tidak boleh mengubah status wfh_enabled karyawan menjadi true');
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_koreksi_presensi_jam_masuk_di_masa_depan_pada_hari_ini_ditolak(): void
    {
        $date = '2026-10-06';
        \Illuminate\Support\Carbon::setTestNow($date . ' 10:00:00');

        try {
            $response = $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'          => $date,
                    'check_in_time' => '11:00', // 1 jam di masa depan
                ]));

            $response->assertStatus(422)
                ->assertJsonPath('future_time', true);

            $this->assertStringContainsString('belum terjadi', $response->json('message'));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_koreksi_presensi_jam_pulang_di_masa_depan_pada_hari_ini_ditolak(): void
    {
        $date = '2026-10-06';
        \Illuminate\Support\Carbon::setTestNow($date . ' 10:00:00');

        try {
            $response = $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'           => $date,
                    'check_in_time'  => '08:00',
                    'check_out_time' => '17:00', // 7 jam di masa depan
                ]));

            $response->assertStatus(422)
                ->assertJsonPath('future_time', true);

            $this->assertStringContainsString('Jam pulang', $response->json('message'));
            $this->assertStringContainsString('belum terjadi', $response->json('message'));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_sesi_aktif_onsite_untuk_karyawan_berhak_wfh_mengembalikan_radius_wajib_dan_wfh_nonaktif(): void
    {
        $date = $this->workDate();
        \Illuminate\Support\Carbon::setTestNow($date . ' 09:00:00');

        try {
            // Karyawan punya hak WFH (seperti profil Matius)
            $this->employee->update([
                'wfh_enabled' => true,
                'allow_wfh'   => true,
            ]);

            // Karyawan memiliki sesi aktif onsite di kantor (sudah check-in onsite, belum check-out)
            $this->seedAttendance([
                'date'           => $date,
                'check_in_time'  => $date . ' 08:00:00',
                'check_out_time' => null,
                'check_in_type'  => 'onsite',
            ]);

            // Request status dari mobile
            $res = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/attendance/status');

            $res->assertOk();
            $this->assertTrue($res->json('checked_in'));
            $this->assertFalse($res->json('checked_out'));

            // Karena sesi aktif onsite di kantor, respon status wajib mematikan mode WFH dan mewajibkan radius geofence
            $this->assertFalse($res->json('wfh_enabled'), 'Sesi aktif onsite wajib mematikan wfh_enabled agar banner WFH tidak muncul di mobile');
            $this->assertTrue($res->json('radius_enabled'), 'Sesi aktif onsite wajib menyalakan radius_enabled untuk validasi geofence saat checkout');
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    // ───────────────────────────────────────────────────────────────
    // 18. Guard 8: status kehadiran fisik wajib punya jam masuk
    //     (cegah "gaji fiktif" — hadir sehari penuh tanpa jam kerja)
    // ───────────────────────────────────────────────────────────────
    public function test_status_kehadiran_tanpa_jam_masuk_ditolak_dan_tidak_membuat_baris_baru(): void
    {
        $presenceStatuses = ['present', 'late', 'early_leave', 'wfh'];

        foreach ($presenceStatuses as $i => $status) {
            $date = $this->workDate(3 + $i * 7);

            $response = $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'   => $date,
                    'status' => $status,
                    // jam masuk SENGAJA tidak dikirim
                ]));

            $response->assertStatus(422)
                ->assertJsonPath('requires_check_in', true)
                ->assertJsonPath('status', $status);

            $this->assertStringContainsString('tanpa jam masuk', (string) $response->json('message'));

            // Transaksi harus rollback: tidak boleh ada baris presensi tersimpan
            $this->assertDatabaseMissing('attendances', [
                'user_id' => $this->employee->id,
                'date'    => $date,
            ]);
        }
    }

    public function test_status_alpha_tanpa_jam_masuk_tetap_diizinkan(): void
    {
        $date = $this->workDate();

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'   => $date,
                'status' => 'absent',
            ]))->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        $this->assertNotNull($att, 'Status Alpha tanpa jam masuk adalah kasus sah dan harus tersimpan.');
        $this->assertSame('absent', $att->status);
        $this->assertNull($att->check_in_time);
        $this->assertNull($att->work_minutes);
        $this->assertSame(0, (int) $att->overtime_minutes);
    }

    public function test_mengosongkan_jam_masuk_sambil_mempertahankan_status_hadir_ditolak(): void
    {
        $date = $this->workDate();
        $this->seedAttendance([
            'date'           => $date,
            'check_in_time'  => $date . ' 08:00:00',
            'check_out_time' => $date . ' 17:00:00',
            'status'         => 'present',
        ]);

        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => null, // dikirim eksplisit sebagai null = dikosongkan
                'check_out_time' => null,
                'status'         => 'present',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('requires_check_in', true);

        // Baris lama wajib utuh — transaksi tidak boleh commit sebagian
        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $this->assertNotNull($att->check_in_time);
        $this->assertSame('08:00', \Illuminate\Support\Carbon::parse($att->check_in_time)->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame('present', $att->status);
    }

    public function test_status_hadir_dengan_jam_masuk_tetap_tersimpan_normal(): void
    {
        $date = $this->workDate();

        $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'          => $date,
                'check_in_time' => '08:00',
                'status'        => 'present',
            ]))->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        $this->assertSame('present', $att->status);
        $this->assertSame('08:00', \Illuminate\Support\Carbon::parse($att->check_in_time)->setTimezone('Asia/Jakarta')->format('H:i'));
    }

    // ─── 19. Guard 9: proteksi sesi presensi AKTIF hari ini ────────────────
    //     Tanpa guard ini, mengisi jam pulang hari ini pada sesi yang masih
    //     berjalan (check-in ada, check-out belum) membuat mobile checkOut()
    //     (whereNull('check_out_time')) tidak menemukan baris itu lagi —
    //     karyawan tertolak check-out sendiri sore harinya.

    public function test_mengisi_jam_pulang_hari_ini_pada_sesi_aktif_ditolak_tanpa_konfirmasi(): void
    {
        $today = now('Asia/Jakarta')->toDateString();
        $this->seedAttendance([
            'date'           => $today,
            'check_in_time'  => $today . ' 08:00:00',
            'check_out_time' => null,
            'status'         => 'present',
        ]);

        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'            => $today,
                'check_in_time'   => '08:00',
                'check_out_time'  => '10:30',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('active_session_block', true)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('confirmation_field', 'override_active_session')
            ->assertJsonPath('check_in_time', '08:00');

        $this->assertStringContainsString('sesi presensi AKTIF', (string) $response->json('message'));

        // Baris lama wajib utuh: check_out_time tidak boleh ikut tertulis.
        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $today)->first();
        $this->assertNull($att->check_out_time);
    }

    public function test_mengisi_jam_pulang_hari_ini_pada_sesi_aktif_berhasil_dengan_override(): void
    {
        // Jam "now" dikunci jauh setelah 10:30 agar lolos Guard Enterprise
        // (waktu presensi tidak boleh mendahului waktu berjalan).
        $today = '2026-10-06';
        \Illuminate\Support\Carbon::setTestNow($today . ' 12:00:00');

        try {
            $this->seedAttendance([
                'date'           => $today,
                'check_in_time'  => $today . ' 08:00:00',
                'check_out_time' => null,
                'status'         => 'present',
            ]);

            $response = $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'                     => $today,
                    'check_in_time'            => '08:00',
                    'check_out_time'           => '10:30',
                    'override_active_session'  => true,
                ]));

            $response->assertOk();

            $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $today)->first();
            $this->assertSame('10:30', \Illuminate\Support\Carbon::parse($att->check_out_time)->setTimezone('Asia/Jakarta')->format('H:i'));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_koreksi_jam_pulang_pada_tanggal_lampau_tidak_terblokir_guard_sesi_aktif(): void
    {
        // Guard 9 hanya relevan untuk tanggal HARI INI — mobile checkOut() tidak
        // pernah mencari baris presensi di tanggal lampau, jadi koreksi tanggal
        // lampau tidak berisiko memblokir check-out karyawan dan tidak perlu
        // konfirmasi tambahan di luar gerbang confirm_dangerous biasa.
        $date = $this->workDate();
        $this->seedAttendance([
            'date'           => $date,
            'check_in_time'  => $date . ' 08:00:00',
            'check_out_time' => null,
            'status'         => 'present',
        ]);

        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '17:00',
            ]));

        $response->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $this->assertSame('17:00', \Illuminate\Support\Carbon::parse($att->check_out_time)->setTimezone('Asia/Jakarta')->format('H:i'));
    }

    public function test_sesi_hari_ini_yang_sudah_checkout_tidak_terkena_guard_sesi_aktif(): void
    {
        // Sesi sudah tidak "aktif" (check_out_time sudah ada) -> tidak lagi
        // berisiko memblokir mobile checkOut(), jadi koreksi lanjutan atasnya
        // tidak perlu override_active_session.
        $today = '2026-10-06';
        \Illuminate\Support\Carbon::setTestNow($today . ' 12:00:00');

        try {
            $this->seedAttendance([
                'date'           => $today,
                'check_in_time'  => $today . ' 08:00:00',
                'check_out_time' => $today . ' 10:30:00',
                'status'         => 'present',
            ]);

            $response = $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'           => $today,
                    'check_in_time'  => '08:00',
                    'check_out_time' => '11:00',
                ]));

            $response->assertOk();

            $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $today)->first();
            $this->assertSame('11:00', \Illuminate\Support\Carbon::parse($att->check_out_time)->setTimezone('Asia/Jakarta')->format('H:i'));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_koreksi_jam_masuk_saja_hari_ini_pada_sesi_aktif_tidak_terkena_guard_sesi_aktif(): void
    {
        // sendsCheckOut harus benar-benar true & bernilai — koreksi yang tidak
        // menyentuh jam pulang sama sekali tidak berisiko memblokir checkout
        // mobile, jadi tidak perlu guard ini.
        $today = '2026-10-06';
        \Illuminate\Support\Carbon::setTestNow($today . ' 12:00:00');

        try {
            $this->seedAttendance([
                'date'           => $today,
                'check_in_time'  => $today . ' 08:00:00',
                'check_out_time' => null,
                'status'         => 'present',
            ]);

            $response = $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'          => $today,
                    'check_in_time' => '07:45',
                ]));

            $response->assertOk();

            $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $today)->first();
            $this->assertNull($att->check_out_time);
            $this->assertSame('07:45', \Illuminate\Support\Carbon::parse($att->check_in_time)->setTimezone('Asia/Jakarta')->format('H:i'));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_check_in_dini_hari_pada_shift_lintas_hari_dioffset_ke_hari_berikutnya(): void
    {
        // Bug #17: karyawan shift 22:00–06:00 datang terlambat & baru check-in
        // 01:00 (dini hari, secara kalender sudah $date+1), lalu check-out 06:00
        // (juga $date+1). Sebelum perbaikan, kedua jam ini di-assemble sebagai
        // "$date HH:MM" sehingga jam masuk seolah terjadi ~21 jam SEBELUM shift
        // mulai. Setelah perbaikan, keduanya harus di-offset ke $date+1.
        $date  = $this->workDate();
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam',
            'is_active'             => true,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $d,
                'work_start_time' => '22:00',
                'work_end_time'   => '06:00',
                'is_off'          => false,
                'is_cross_day'    => true,
                'effective_date'  => now('Asia/Jakarta')->subYear()->toDateString(),
            ]);
        }
        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shift->id,
            'start_date' => now('Asia/Jakarta')->subYear()->toDateString(),
            'end_date'   => null,
        ]);

        $this->seedAttendance([
            'date'                 => $date,
            'check_in_time'        => null,
            'check_out_time'       => null,
            'snap_source'          => 'shift',
            'snap_shift_id'        => $shift->id,
            'snap_shift_name'      => 'Shift Malam',
            'snap_work_start_time' => '22:00',
            'snap_work_end_time'   => '06:00',
            'snap_is_cross_day'    => true,
        ]);

        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '01:00',
                'check_out_time' => '06:00',
            ]));

        $response->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $expectedNextDay = \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString();

        $this->assertSame('01:00', $att->check_in_time->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame($expectedNextDay, $att->check_in_time->setTimezone('Asia/Jakarta')->toDateString(),
            'Jam masuk dini hari pada shift lintas hari wajib di-offset ke hari berikutnya.');
        $this->assertSame('06:00', $att->check_out_time->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame($expectedNextDay, $att->check_out_time->setTimezone('Asia/Jakarta')->toDateString());

        // Span 5 jam = 300 menit, potong break 60 (gross >= 300) → 240
        $this->assertSame(240, (int) $att->work_minutes);
    }

    public function test_koreksi_hanya_jam_masuk_dini_hari_shift_lintas_hari_tidak_mengganggu_jam_pulang_tersimpan(): void
    {
        // HRD hanya mengoreksi jam masuk (tanpa mengirim ulang jam pulang) —
        // offset harus tetap berlaku pada jam masuk yang baru diketik, dan jam
        // pulang yang sudah tersimpan benar di $date+1 tidak boleh terganggu.
        $date      = $this->workDate();
        $nextDay   = \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString();
        $shift = Shift::create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'name'                  => 'Shift Malam',
            'is_active'             => true,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $d,
                'work_start_time' => '22:00',
                'work_end_time'   => '06:00',
                'is_off'          => false,
                'is_cross_day'    => true,
                'effective_date'  => now('Asia/Jakarta')->subYear()->toDateString(),
            ]);
        }
        UserShift::create([
            'user_id'    => $this->employee->id,
            'shift_id'   => $shift->id,
            'start_date' => now('Asia/Jakarta')->subYear()->toDateString(),
            'end_date'   => null,
        ]);

        $this->seedAttendance([
            'date'                 => $date,
            'check_in_time'        => $date . ' 00:30:00',
            'check_out_time'       => $nextDay . ' 06:00:00',
            'snap_source'          => 'shift',
            'snap_shift_id'        => $shift->id,
            'snap_shift_name'      => 'Shift Malam',
            'snap_work_start_time' => '22:00',
            'snap_work_end_time'   => '06:00',
            'snap_is_cross_day'    => true,
        ]);

        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'          => $date,
                'check_in_time' => '01:00',
            ]));

        $response->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();

        $this->assertSame('01:00', $att->check_in_time->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame($nextDay, $att->check_in_time->setTimezone('Asia/Jakarta')->toDateString());
        $this->assertSame('06:00', $att->check_out_time->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame($nextDay, $att->check_out_time->setTimezone('Asia/Jakarta')->toDateString(),
            'Jam pulang yang sudah benar tidak boleh terganggu oleh koreksi jam masuk.');
    }

    public function test_double_click_pada_baris_virtual_tidak_menyebabkan_500_duplicate_entry(): void
    {
        // Bug #19: baris virtual (belum ada di DB) + double-click cepat →
        // dua request paralel sama-sama membaca $existing = null lalu sama-sama
        // updateOrCreate() → request kedua menabrak unique(user_id, date) dan
        // melempar QueryException tak tertangkap (HTTP 500). Simulasikan
        // "request pertama masih berjalan" dengan menahan lock yang sama lebih
        // dulu, lalu pastikan request kedua ditolak rapi (409), bukan 500 —
        // dan tidak ada baris duplikat yang sempat tersimpan.
        $date = $this->workDate();
        $this->assertNull(
            Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first(),
            'Prasyarat skenario: baris presensi harus benar-benar virtual (belum ada).'
        );

        $heldLock = \Illuminate\Support\Facades\Cache::lock("correct_attendance_{$this->employee->id}_{$date}", 10);
        $this->assertTrue($heldLock->get(), 'Gagal menyimulasikan request pertama yang masih memegang lock.');

        try {
            $response = $this->actingAs($this->hrd, 'sanctum')
                ->postJson(self::URL, $this->payload([
                    'date'           => $date,
                    'check_in_time'  => '08:00',
                    'check_out_time' => '17:00',
                ]));

            $response->assertStatus(409);
            $this->assertNull(
                Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first(),
                'Request yang ditolak karena lock tidak boleh membuat baris apa pun.'
            );
        } finally {
            $heldLock->release();
        }

        // Setelah lock dilepas (request pertama "selesai"), request susulan yang
        // sah harus tetap berhasil normal — tidak ada duplikat, tidak ada 500.
        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '17:00',
            ]));

        $response->assertOk();
        $this->assertSame(
            1,
            Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->count(),
            'Harus tepat satu baris presensi, tidak ada duplikat.'
        );
    }

    // ───────────────────────────────────────────────────────────────
    // Status 'absent' wajib menghapus jam masuk/pulang lama (temuan audit
    // 2026-10-07): sebelumnya hanya work_minutes/overtime_minutes yang
    // di-null-kan, check_in_time/check_out_time tetap tersisa karena
    // AttendanceManagement.tsx selalu mengirim jam prefilled dari form.
    // ───────────────────────────────────────────────────────────────
    public function test_ubah_status_ke_alpha_menghapus_jam_masuk_dan_pulang_lama(): void
    {
        $date = $this->workDate();
        $this->seedAttendance([
            'date'           => $date,
            'check_in_time'  => $date . ' 08:00:00',
            'check_out_time' => $date . ' 17:00:00',
            'status'         => 'present',
        ]);

        // Simulasi perilaku frontend: form prefilled mengirim ulang jam yang
        // SAMA dengan baris lama, HRD hanya mengganti dropdown status.
        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, $this->payload([
                'date'           => $date,
                'check_in_time'  => '08:00',
                'check_out_time' => '17:00',
                'status'         => 'absent',
            ]));

        $response->assertOk();

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $this->assertSame('absent', $att->status);
        $this->assertNull($att->check_in_time, 'Jam masuk harus ikut terhapus saat status Alpha.');
        $this->assertNull($att->check_out_time, 'Jam pulang harus ikut terhapus saat status Alpha.');
        $this->assertNull($att->check_in_type, 'Tipe presensi harus ikut terhapus saat status Alpha.');
        $this->assertNull($att->work_minutes);
        $this->assertSame(0, $att->overtime_minutes);
    }

    public function test_ubah_status_ke_alpha_tanpa_konfirmasi_ditolak_dengan_alasan_jam_terhapus(): void
    {
        $date = $this->workDate();
        $this->seedAttendance([
            'date'           => $date,
            'check_in_time'  => $date . ' 08:00:00',
            'check_out_time' => $date . ' 17:00:00',
            'status'         => 'present',
        ]);

        $response = $this->actingAs($this->hrd, 'sanctum')
            ->postJson(self::URL, [
                'user_id'           => $this->employee->id,
                'date'              => $date,
                'check_in_time'     => '08:00',
                'check_out_time'    => '17:00',
                'status'            => 'absent',
                'correction_reason' => 'Karyawan ternyata tidak masuk, bukti absen mesin palsu.',
                // Sengaja tanpa confirm_dangerous agar gerbang konfirmasi menembak.
            ]);

        $response->assertStatus(422);
        $response->assertJson(['requires_confirmation' => true]);
        $dangerous = $response->json('dangerous_changed_fields');
        $this->assertContains('jam masuk/pulang dihapus (status menjadi Alpha)', $dangerous);

        $att = Attendance::where('user_id', $this->employee->id)->whereDate('date', $date)->first();
        $this->assertSame('present', $att->status, 'Gagal konfirmasi tidak boleh mengubah baris sama sekali.');
        $this->assertNotNull($att->check_in_time);
        $this->assertNotNull($att->check_out_time);
    }
}
