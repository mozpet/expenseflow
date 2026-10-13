<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxPeriodTotal;
use App\Models\EmployeeTaxProfile;
use App\Models\OvertimeApproval;
use App\Models\Payroll;
use App\Models\PayrollGroup;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\PayrollPeriodLock;
use Database\Seeders\PayrollStatutorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TANGGAL GAJIAN & CUT-OFF PRESENSI YANG FLEKSIBEL (2026-10-12)
 *
 * Yang dibuktikan di sini, berurutan:
 *   1. Grup payroll menyimpan cutoff_day / payment_day / payment_month_offset
 *      dan menolak nilai di luar jangkauan.
 *   2. Batch TANPA grup tetap jatuh ke bulan kalender penuh — regresi perilaku
 *      lama, keputusan produk #2.
 *   3. Rentang terisi otomatis dari grup, namun override manual HRD diterima,
 *      dengan seluruh guard (berpasangan, urutan, panjang, tumpang-tindih).
 *   4. INTI PERMINTAAN: agregat presensi (hari hadir, alpha, denda telat, jam
 *      lembur) ditarik sesuai rentang cut-off, BUKAN tanggal 1 s/d akhir bulan.
 *   5. Pajak TIDAK bergeser: tax_month & payslips.period_month tetap bulan
 *      periode walau jendela presensinya menyeberang bulan.
 *   6. Kunci periode gaji ikut rentang cut-off, bukan lagi bulan kalender.
 *
 * Gaji pokok 10.500.000 (divisor 21 → 500.000/hari) mengikuti konvensi
 * PenaltyBonusPayrollTest agar angka rupiahnya bulat dan bisa dibaca manual.
 */
class PayrollCutoffPeriodTest extends TestCase
{
    use RefreshDatabase;

    private const RUNS   = '/api/v1/dashboard/payroll/runs';
    private const GROUPS = '/api/v1/dashboard/payroll/groups';
    private const BASIC  = 10_500_000;

    private Company $company;
    private AttendanceSetting $office;
    private User $finance;
    private User $employee;
    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PayrollStatutorySeeder::class);

        $this->company = Company::create(['name' => 'PT Cut Off', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Kantor Jakarta',
            'office_latitude'        => -6.2,
            'office_longitude'       => 106.8166,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'work_days'              => [1, 2, 3, 4, 5],
            'late_tolerance_minutes' => 15,
            'overtime_enabled'       => true,
            'min_overtime_minutes'   => 30,
            'is_active'              => true,
        ]);

        $this->finance  = $this->makeUser('Andi Finance', 'andi@cutoff.test', 'finance');
        $this->employee = $this->makeUser('Eko Biasa', 'eko@cutoff.test', 'employee', 'EMP-009');
        $this->budi     = $this->makeUser('Budi Karyawan', 'budi@cutoff.test', 'employee', 'EMP-001');

        EmployeeSalary::create([
            'company_id'     => $this->company->id,
            'user_id'        => $this->budi->id,
            'basic_salary'   => self::BASIC,
            'effective_date' => '2026-01-01',
            'is_active'      => true,
            'created_by'     => $this->finance->id,
        ]);
        EmployeeTaxProfile::create([
            'company_id'  => $this->company->id,
            'user_id'     => $this->budi->id,
            'ptkp_status' => 'TK/0',
            'has_npwp'    => true,
            'tax_method'  => 'gross',
        ]);
    }

    private function makeUser(string $name, string $email, string $role, ?string $code = null): User
    {
        $roleId = Role::whereNull('company_id')->where('slug', $role)->value('id');

        return User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $roleId,
            'name'                  => $name,
            'email'                 => $email,
            'password'              => bcrypt('password'),
            'role'                  => $role,
            'employee_code'         => $code,
            'department'            => 'Umum',
            'is_active'             => true,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
        ]);
    }

    /** Grup payroll dengan pengaturan tanggal, sekaligus memasukkan Budi ke dalamnya. */
    private function makeGroup(?int $cutoffDay = 20, ?int $paymentDay = 25, int $offset = 0): PayrollGroup
    {
        $group = PayrollGroup::create([
            'company_id'           => $this->company->id,
            'name'                 => 'Staf Bulanan',
            'code'                 => 'STAF',
            'cutoff_day'           => $cutoffDay,
            'payment_day'          => $paymentDay,
            'payment_month_offset' => $offset,
            'is_active'            => true,
        ]);

        $this->budi->update(['payroll_group_id' => $group->id]);
        $this->budi->refresh();

        return $group;
    }

    /** @param array<string, mixed> $payload */
    private function createRun(array $payload = [])
    {
        return $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::RUNS, array_merge([
                'period_month' => 10,
                'period_year'  => 2026,
            ], $payload));
    }

    /** @param array<string, mixed> $extra */
    private function attendance(string $date, string $status, array $extra = []): Attendance
    {
        return Attendance::create(array_merge([
            'company_id'    => $this->company->id,
            'user_id'       => $this->budi->id,
            'date'          => $date,
            'check_in_time' => $date . ' 08:00:00',
            'check_in_type' => 'onsite',
            'status'        => $status,
            'is_holiday'    => false,
        ], $extra));
    }

    /** Presensi + lembur approved (aturan pemerintah, bukan snapshot custom). */
    private function overtime(string $date, int $minutes): void
    {
        $att = $this->attendance($date, 'present', ['overtime_minutes' => $minutes]);

        OvertimeApproval::create([
            'attendance_id'    => $att->id,
            'user_id'          => $this->budi->id,
            'company_id'       => $this->company->id,
            'overtime_minutes' => $minutes,
            'status'           => 'approved',
            'current_step'     => 'hrd',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. Pengaturan grup payroll
    // ═══════════════════════════════════════════════════════════════════

    public function test_grup_payroll_menyimpan_tanggal_cutoff_dan_gajian(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')->postJson(self::GROUPS, [
            'name'                 => 'Harian Lepas',
            'code'                 => 'HRL',
            'cutoff_day'           => 20,
            'payment_day'          => 25,
            'payment_month_offset' => 1,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.cutoff_day', 20)
            ->assertJsonPath('data.payment_day', 25)
            ->assertJsonPath('data.payment_month_offset', 1);

        $this->assertDatabaseHas('payroll_groups', [
            'name'                 => 'Harian Lepas',
            'cutoff_day'           => 20,
            'payment_day'          => 25,
            'payment_month_offset' => 1,
        ]);
    }

    public function test_grup_tanpa_pengaturan_tanggal_tetap_null(): void
    {
        $res = $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::GROUPS, ['name' => 'Tanpa Tanggal']);

        $res->assertStatus(201)
            ->assertJsonPath('data.cutoff_day', null)
            ->assertJsonPath('data.payment_day', null)
            ->assertJsonPath('data.payment_month_offset', 0);
    }

    public function test_cutoff_day_di_luar_jangkauan_ditolak(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::GROUPS, ['name' => 'Salah 1', 'cutoff_day' => 32])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cutoff_day');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::GROUPS, ['name' => 'Salah 2', 'cutoff_day' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cutoff_day');
    }

    public function test_payment_month_offset_lebih_dari_satu_ditolak(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::GROUPS, ['name' => 'Salah 3', 'payment_month_offset' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_month_offset');
    }

    /**
     * Form grup lama (frontend belum diperbarui) tidak mengirim ketiga kolom
     * tanggal. Penyuntingan nama saja TIDAK BOLEH menghapus pengaturan HRD —
     * kalau ini bocor, jendela presensi seluruh batch berikutnya bergeser.
     */
    public function test_update_grup_tanpa_kirim_tanggal_tidak_menghapus_pengaturan(): void
    {
        $group = $this->makeGroup(20, 25, 1);

        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::GROUPS . "/{$group->id}", ['name' => 'Staf Bulanan (Revisi)'])
            ->assertOk();

        $group->refresh();
        $this->assertSame('Staf Bulanan (Revisi)', $group->name);
        $this->assertSame(20, $group->cutoff_day);
        $this->assertSame(25, $group->payment_day);
        $this->assertSame(1, $group->payment_month_offset);
    }

    /** Mengosongkan secara eksplisit tetap bisa (kirim null, bukan tidak kirim). */
    public function test_update_grup_dengan_null_eksplisit_mengosongkan_pengaturan(): void
    {
        $group = $this->makeGroup(20, 25, 1);

        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::GROUPS . "/{$group->id}", [
                'name'        => 'Staf Bulanan',
                'cutoff_day'  => null,
                'payment_day' => null,
            ])
            ->assertOk();

        $group->refresh();
        $this->assertNull($group->cutoff_day);
        $this->assertNull($group->payment_day);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. Pembuatan batch: auto-fill, override, guard
    // ═══════════════════════════════════════════════════════════════════

    /** REGRESI PERILAKU LAMA: tanpa grup → 1 s/d akhir bulan kalender. */
    public function test_batch_tanpa_grup_jatuh_ke_bulan_kalender_penuh(): void
    {
        $res = $this->createRun();

        $res->assertStatus(201)
            ->assertJsonPath('data.period_start_date', '2026-10-01')
            ->assertJsonPath('data.period_end_date', '2026-10-31')
            ->assertJsonPath('data.planned_payment_date', null)
            ->assertJsonPath('meta.period_source', 'calendar');
    }

    public function test_batch_dengan_grup_terisi_otomatis_dari_pengaturan_grup(): void
    {
        $group = $this->makeGroup(20, 25, 0);

        $res = $this->createRun(['payroll_group_id' => $group->id]);

        $res->assertStatus(201)
            ->assertJsonPath('data.period_start_date', '2026-09-21')
            ->assertJsonPath('data.period_end_date', '2026-10-20')
            ->assertJsonPath('data.planned_payment_date', '2026-10-25')
            ->assertJsonPath('meta.period_source', 'group');
    }

    public function test_offset_bulan_gajian_menggeser_rencana_gajian_ke_bulan_berikutnya(): void
    {
        $group = $this->makeGroup(20, 5, 1);

        $this->createRun(['payroll_group_id' => $group->id])
            ->assertStatus(201)
            ->assertJsonPath('data.period_end_date', '2026-10-20')
            ->assertJsonPath('data.planned_payment_date', '2026-11-05');
    }

    public function test_override_manual_hrd_menimpa_hasil_resolusi_grup(): void
    {
        $group = $this->makeGroup(20, 25, 0);

        $res = $this->createRun([
            'payroll_group_id'     => $group->id,
            'period_start_date'    => '2026-09-16',
            'period_end_date'      => '2026-10-15',
            'planned_payment_date' => '2026-10-20',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.period_start_date', '2026-09-16')
            ->assertJsonPath('data.period_end_date', '2026-10-15')
            ->assertJsonPath('data.planned_payment_date', '2026-10-20');
    }

    public function test_override_separuh_ditolak(): void
    {
        $this->createRun(['period_start_date' => '2026-09-21'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal mulai dan tanggal selesai cut-off presensi harus diisi berpasangan.');

        $this->createRun(['period_end_date' => '2026-10-20'])
            ->assertStatus(422);
    }

    public function test_tanggal_selesai_lebih_awal_dari_mulai_ditolak(): void
    {
        $this->createRun([
            'period_start_date' => '2026-10-20',
            'period_end_date'   => '2026-09-21',
        ])->assertStatus(422);
    }

    public function test_rentang_terlalu_panjang_ditolak(): void
    {
        $res = $this->createRun([
            'period_start_date' => '2026-07-01',
            'period_end_date'   => '2026-10-31',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('123 hari', $res->json('message'));
    }

    public function test_rencana_gajian_sebelum_rentang_mulai_ditolak(): void
    {
        $this->createRun([
            'period_start_date'    => '2026-10-01',
            'period_end_date'      => '2026-10-31',
            'planned_payment_date' => '2026-09-25',
        ])->assertStatus(422);
    }

    /** Gajian dimajukan = permintaan sah HRD → 201 dengan peringatan, bukan 422. */
    public function test_gajian_dimajukan_hanya_diperingatkan(): void
    {
        $res = $this->createRun([
            'period_start_date'    => '2026-10-01',
            'period_end_date'      => '2026-10-31',
            'planned_payment_date' => '2026-10-20',
        ]);

        $res->assertStatus(201);
        $this->assertNotEmpty($res->json('warnings'));
    }

    public function test_rentang_tumpang_tindih_ditolak_dan_menyebut_batch_penghalang(): void
    {
        $first = $this->createRun([
            'period_start_date' => '2026-09-21',
            'period_end_date'   => '2026-10-20',
        ]);
        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        $res = $this->createRun([
            'period_month'      => 11,
            'period_year'       => 2026,
            'period_start_date' => '2026-10-15', // menabrak 5 hari terakhir batch pertama
            'period_end_date'   => '2026-11-14',
        ]);

        $res->assertStatus(422);
        // Pesan WAJIB menyebut id batch penghalang agar HRD tahu mana yang harus diubah.
        $this->assertStringContainsString("#{$firstId}", $res->json('message'));
    }

    /** Lingkup berbeda (grup lain) bukan tumpang-tindih — jendela boleh sama. */
    public function test_rentang_sama_pada_grup_berbeda_diterima(): void
    {
        $groupA = $this->makeGroup(20, 25, 0);
        $groupB = PayrollGroup::create([
            'company_id' => $this->company->id,
            'name'       => 'Harian',
            'code'       => 'HRN',
            'cutoff_day' => 20,
            'is_active'  => true,
        ]);

        $this->createRun(['payroll_group_id' => $groupA->id])->assertStatus(201);
        $this->createRun(['payroll_group_id' => $groupB->id])
            ->assertStatus(201)
            ->assertJsonPath('data.period_start_date', '2026-09-21');
    }

    public function test_celah_antar_periode_hanya_diperingatkan(): void
    {
        $first = $this->createRun([
            'period_month'      => 9,
            'period_year'       => 2026,
            'period_start_date' => '2026-08-21',
            'period_end_date'   => '2026-09-10',
        ]);
        $first->assertStatus(201);

        $res = $this->createRun([
            'period_start_date' => '2026-09-21', // celah 10 hari (11-20 Sep)
            'period_end_date'   => '2026-10-20',
        ]);

        $res->assertStatus(201);
        $warnings = $res->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('celah', implode(' ', $warnings));
    }

    /** Periode berurutan tanpa celah: tidak ada peringatan sama sekali. */
    public function test_periode_berurutan_tanpa_celah_tanpa_peringatan(): void
    {
        $group = $this->makeGroup(20, 25, 0);

        $this->createRun(['period_month' => 9, 'payroll_group_id' => $group->id])
            ->assertStatus(201)
            ->assertJsonPath('data.period_end_date', '2026-09-20');

        $this->createRun(['payroll_group_id' => $group->id])
            ->assertStatus(201)
            ->assertJsonPath('warnings', []);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. Pratinjau untuk modal "Buat Periode Payroll"
    // ═══════════════════════════════════════════════════════════════════

    public function test_pratinjau_periode_mengembalikan_rentang_grup(): void
    {
        $group = $this->makeGroup(20, 25, 0);

        $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/period-preview?period_month=10&period_year=2026&payroll_group_id=' . $group->id)
            ->assertOk()
            ->assertJsonPath('data.period_start_date', '2026-09-21')
            ->assertJsonPath('data.period_end_date', '2026-10-20')
            ->assertJsonPath('data.planned_payment_date', '2026-10-25')
            ->assertJsonPath('data.source', 'group');
    }

    public function test_pratinjau_tanpa_grup_mengembalikan_bulan_kalender(): void
    {
        $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/period-preview?period_month=2&period_year=2026')
            ->assertOk()
            ->assertJsonPath('data.period_start_date', '2026-02-01')
            ->assertJsonPath('data.period_end_date', '2026-02-28')
            ->assertJsonPath('data.source', 'calendar');
    }

    public function test_pratinjau_ditolak_untuk_karyawan_tanpa_izin_payroll(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->getJson('/api/v1/dashboard/payroll/period-preview?period_month=10&period_year=2026')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. INTI PERMINTAAN: perhitungan gaji mengikuti rentang cut-off
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Jendela 21 Sep - 20 Okt 2026. Presensi disemai di EMPAT tanggal, dua di
     * dalam dan dua di luar jendela, masing-masing dengan denda telat & lembur
     * berbeda. Kalau kalkulator masih mengunci tanggal 1 s/d akhir bulan, angka
     * yang keluar akan berasal dari 2026-10-25 (di luar) dan mengabaikan
     * 2026-09-25 (di dalam) — jadi test ini gagal ke dua arah sekaligus.
     */
    public function test_agregat_presensi_mengikuti_rentang_cutoff_bukan_bulan_kalender(): void
    {
        $group = $this->makeGroup(20, 25, 0);

        // ── DI LUAR jendela (sehari sebelum mulai) ──
        $this->attendance('2026-09-20', 'late', ['late_minutes' => 60, 'late_fine_amount' => 60_000]);
        $this->attendance('2026-09-19', 'absent');

        // ── DI DALAM jendela ──
        $this->attendance('2026-09-25', 'late', ['late_minutes' => 30, 'late_fine_amount' => 30_000]);
        $this->attendance('2026-10-05', 'present');
        $this->attendance('2026-09-22', 'absent');
        $this->overtime('2026-09-28', 120); // 2 jam

        // ── DI LUAR jendela (setelah selesai) ──
        $this->attendance('2026-10-25', 'late', ['late_minutes' => 90, 'late_fine_amount' => 90_000]);
        $this->attendance('2026-10-28', 'absent');
        $this->overtime('2026-10-22', 180); // 3 jam, tidak boleh ikut

        $runId = $this->createRun(['payroll_group_id' => $group->id])
            ->assertStatus(201)
            ->json('data.id');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::RUNS . "/{$runId}/calculate")
            ->assertOk();

        $slip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();

        // Hari hadir: 25 Sep (late) + 5 Okt + 28 Sep (lembur, present) = 3.
        $this->assertSame(3, (int) $slip->present_days, 'Hari hadir harus hanya dari dalam jendela cut-off.');

        // Alpha: hanya 22 Sep. 19 Sep & 28 Okt di luar jendela.
        $this->assertSame(1, (int) $slip->absent_days, 'Alpha harus hanya dari dalam jendela cut-off.');

        // Jam lembur: hanya 28 Sep (2 jam). 22 Okt di luar jendela.
        $this->assertEqualsWithDelta(2.0, (float) $slip->overtime_hours, 0.01, 'Jam lembur harus hanya dari dalam jendela cut-off.');

        // Denda telat: hanya 30.000 (25 Sep). 60.000 & 90.000 di luar jendela.
        $lateItem = PayslipItem::where('payslip_id', $slip->id)
            ->where('label', 'Potongan Keterlambatan')->first();
        $this->assertNotNull($lateItem, 'Denda telat 25 Sep harus masuk slip.');
        $this->assertEqualsWithDelta(30_000, (float) $lateItem->amount, 0.01, 'Denda telat harus hanya dari dalam jendela cut-off.');
    }

    /**
     * Cermin dari test di atas memakai batch TANPA grup (bulan kalender penuh):
     * angka yang keluar harus berasal dari presensi Oktober, bukan dari jendela
     * cut-off. Dua test ini bersama-sama membuktikan rentangnya benar-benar
     * dibaca dari batch, bukan konstanta yang kebetulan cocok.
     */
    public function test_batch_bulan_kalender_tetap_memakai_presensi_bulan_itu(): void
    {
        $this->attendance('2026-09-25', 'late', ['late_minutes' => 30, 'late_fine_amount' => 30_000]);
        $this->attendance('2026-10-25', 'late', ['late_minutes' => 90, 'late_fine_amount' => 90_000]);
        $this->attendance('2026-10-05', 'present');

        $runId = $this->createRun()->assertStatus(201)->json('data.id');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::RUNS . "/{$runId}/calculate")
            ->assertOk();

        $slip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();

        $this->assertSame(2, (int) $slip->present_days); // 5 Okt & 25 Okt
        $lateItem = PayslipItem::where('payslip_id', $slip->id)
            ->where('label', 'Potongan Keterlambatan')->first();
        $this->assertEqualsWithDelta(90_000, (float) $lateItem->amount, 0.01);
    }

    /**
     * PAJAK TIDAK IKUT BERGESER. Ini pemisahan paling kritis: jendela presensi
     * menyeberang September, tetapi masa pajak & identitas slip wajib tetap
     * Oktober — kalau tidak, akumulasi PPh 21 tahunan karyawan jadi salah bulan.
     */
    public function test_masa_pajak_dan_identitas_slip_tidak_bergeser_oleh_cutoff(): void
    {
        $group = $this->makeGroup(20, 25, 0);
        $this->attendance('2026-09-25', 'present');

        $runId = $this->createRun(['payroll_group_id' => $group->id])
            ->assertStatus(201)->json('data.id');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::RUNS . "/{$runId}/calculate")
            ->assertOk();

        $slip = Payslip::where('payroll_id', $runId)->where('user_id', $this->budi->id)->firstOrFail();
        $this->assertSame(10, (int) $slip->period_month, 'Slip harus tetap beridentitas bulan periode.');
        $this->assertSame(2026, (int) $slip->period_year);

        $total = EmployeeTaxPeriodTotal::where('user_id', $this->budi->id)
            ->where('tax_year', 2026)->where('tax_month', 10)->first();
        $this->assertNotNull($total, 'Masa pajak wajib tetap bulan periode (10), bukan bulan awal jendela (9).');
        $this->assertSame(0, EmployeeTaxPeriodTotal::where('user_id', $this->budi->id)
            ->where('tax_month', 9)->count(), 'Tidak boleh ada masa pajak September yang terbentuk.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. Koreksi periode batch (PUT /runs/{id})
    // ═══════════════════════════════════════════════════════════════════

    public function test_batch_draft_bisa_dikoreksi_rentangnya(): void
    {
        $runId = $this->createRun()->assertStatus(201)->json('data.id');

        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::RUNS . "/{$runId}", [
                'period_start_date'    => '2026-09-21',
                'period_end_date'      => '2026-10-20',
                'planned_payment_date' => '2026-10-25',
            ])
            ->assertOk()
            ->assertJsonPath('data.period_start_date', '2026-09-21')
            ->assertJsonPath('data.period_end_date', '2026-10-20')
            ->assertJsonPath('data.status', Payroll::STATUS_DRAFT);
    }

    /**
     * PENGAMAN TERPENTING: slip yang sudah dihitung memakai rentang LAMA.
     * submit() hanya mensyaratkan status `calculated`, jadi tanpa reset ini
     * batch bisa langsung diajukan & disetujui — menerbitkan gaji dari jendela
     * presensi yang sudah tidak berlaku.
     */
    public function test_batch_calculated_dikembalikan_ke_draft_saat_rentang_berubah(): void
    {
        $runId = $this->createRun()->assertStatus(201)->json('data.id');
        $this->attendance('2026-10-05', 'present');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::RUNS . "/{$runId}/calculate")->assertOk();
        $this->assertSame(Payroll::STATUS_CALCULATED, Payroll::find($runId)->status);

        $res = $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::RUNS . "/{$runId}", [
                'period_start_date' => '2026-09-21',
                'period_end_date'   => '2026-10-20',
            ]);

        $res->assertOk()->assertJsonPath('data.status', Payroll::STATUS_DRAFT);
        $this->assertStringContainsString('dihitung ulang', $res->json('message'));

        $payroll = Payroll::find($runId);
        $this->assertNull($payroll->calculated_at);
        // Slip TIDAK dihapus (mungkin sedang ditinjau); calculate() idempoten.
        $this->assertTrue($payroll->payslips()->exists());
    }

    /** Mengubah hanya rencana gajian tidak menyentuh status — rentang tetap sama. */
    public function test_batch_calculated_tetap_calculated_bila_hanya_rencana_gajian_berubah(): void
    {
        $runId = $this->createRun()->assertStatus(201)->json('data.id');
        $this->attendance('2026-10-05', 'present');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson(self::RUNS . "/{$runId}/calculate")->assertOk();

        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::RUNS . "/{$runId}", ['planned_payment_date' => '2026-10-28'])
            ->assertOk()
            ->assertJsonPath('data.status', Payroll::STATUS_CALCULATED)
            ->assertJsonPath('data.planned_payment_date', '2026-10-28');
    }

    public function test_batch_approved_tidak_bisa_diubah_periodenya(): void
    {
        $payroll = Payroll::create([
            'company_id'        => $this->company->id,
            'period_month'      => 10,
            'period_year'       => 2026,
            'period_start_date' => '2026-10-01',
            'period_end_date'   => '2026-10-31',
            'period_label'      => 'Oktober 2026',
            'status'            => Payroll::STATUS_APPROVED,
            'run_type'          => Payroll::RUN_TYPE_REGULAR,
        ]);

        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::RUNS . "/{$payroll->id}", [
                'period_start_date' => '2026-09-21',
                'period_end_date'   => '2026-10-20',
            ])
            ->assertStatus(422);

        $payroll->refresh();
        $this->assertSame('2026-10-01', $payroll->period_start_date->toDateString());
    }

    public function test_karyawan_biasa_tidak_bisa_mengubah_periode_batch(): void
    {
        $runId = $this->createRun()->assertStatus(201)->json('data.id');

        $this->actingAs($this->employee, 'sanctum')
            ->putJson(self::RUNS . "/{$runId}", [
                'period_start_date' => '2026-09-21',
                'period_end_date'   => '2026-10-20',
            ])
            ->assertStatus(403);
    }

    /**
     * Batch perusahaan lain tidak bisa diubah periodenya. Yang menolak adalah
     * CompanyMiddleware (403) karena route-model {payroll} ber-company_id beda —
     * guard 404 di update() adalah pertahanan berlapis bila middleware dicabut.
     * Pola & status ini mengikuti preseden PayrollPhase4Test:217-221.
     */
    public function test_batch_perusahaan_lain_tidak_bisa_diubah(): void
    {
        $other = Company::create(['name' => 'PT Lain', 'is_active' => true]);
        $payroll = Payroll::create([
            'company_id'        => $other->id,
            'period_month'      => 10,
            'period_year'       => 2026,
            'period_start_date' => '2026-10-01',
            'period_end_date'   => '2026-10-31',
            'period_label'      => 'Oktober 2026',
            'status'            => Payroll::STATUS_DRAFT,
            'run_type'          => Payroll::RUN_TYPE_REGULAR,
        ]);

        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::RUNS . "/{$payroll->id}", [
                'period_start_date' => '2026-09-21',
                'period_end_date'   => '2026-10-20',
            ])
            ->assertStatus(403);

        // Yang penting: rentangnya benar-benar tidak bergerak.
        $payroll->refresh();
        $this->assertSame('2026-10-01', $payroll->period_start_date->toDateString());
        $this->assertSame('2026-10-31', $payroll->period_end_date->toDateString());
    }

    /** Guard tumpang-tindih juga berlaku di update(), tanpa menabrak dirinya sendiri. */
    public function test_koreksi_periode_menolak_tumpang_tindih_dengan_batch_lain(): void
    {
        $sep = $this->createRun([
            'period_month'      => 9,
            'period_start_date' => '2026-08-21',
            'period_end_date'   => '2026-09-20',
        ])->assertStatus(201);

        $okt = $this->createRun([
            'period_start_date' => '2026-09-21',
            'period_end_date'   => '2026-10-20',
        ])->assertStatus(201);

        // Menarik mulai Oktober ke 15 Sep akan menabrak batch September.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::RUNS . '/' . $okt->json('data.id'), [
                'period_start_date' => '2026-09-15',
                'period_end_date'   => '2026-10-20',
            ])
            ->assertStatus(422);

        // Mengirim rentang yang sama persis (tidak berubah) tetap diterima:
        // dirinya sendiri harus dikecualikan dari pemeriksaan tumpang-tindih.
        $this->actingAs($this->finance, 'sanctum')
            ->putJson(self::RUNS . '/' . $okt->json('data.id'), [
                'period_start_date' => '2026-09-21',
                'period_end_date'   => '2026-10-20',
            ])
            ->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. Kunci periode gaji ikut rentang cut-off
    // ═══════════════════════════════════════════════════════════════════

    /**
     * BAHAYA KOREKTNESS NYATA yang diperbaiki fitur ini: dengan jendela
     * 21 Ags - 20 Sep, presensi 15 Sep DIBAYAR batch September (terkunci),
     * tetapi presensi 25 Sep BELUM dibayar siapa pun — dulu pencocokan bulan
     * mengunci keduanya, sehingga 25 Sep tidak bisa dikoreksi padahal boleh,
     * dan jika jendelanya bergeser ke arah lain justru sebaliknya.
     */
    public function test_findlocking_mengikuti_jendela_cutoff_bukan_bulan_kalender(): void
    {
        Payroll::create([
            'company_id'        => $this->company->id,
            'period_month'      => 9,
            'period_year'       => 2026,
            'period_start_date' => '2026-08-21',
            'period_end_date'   => '2026-09-20',
            'period_label'      => 'September 2026',
            'status'            => Payroll::STATUS_APPROVED,
            'run_type'          => Payroll::RUN_TYPE_REGULAR,
        ]);

        $lock = app(PayrollPeriodLock::class);

        $this->assertNotNull(
            $lock->findLocking($this->budi, '2026-09-15'),
            '15 Sep berada di dalam jendela batch terkunci → wajib terkunci.'
        );
        $this->assertNotNull(
            $lock->findLocking($this->budi, '2026-08-25'),
            '25 Ags berada di dalam jendela walau beda bulan kalender → wajib terkunci.'
        );
        $this->assertNull(
            $lock->findLocking($this->budi, '2026-09-25'),
            '25 Sep di LUAR jendela batch terkunci → harus masih bisa dikoreksi.'
        );
        $this->assertNull(
            $lock->findLocking($this->budi, '2026-08-20'),
            '20 Ags sehari sebelum jendela mulai → harus masih bisa dikoreksi.'
        );
    }

    /** Baris lama tanpa kolom rentang (seeder) tetap terkunci per bulan kalender. */
    public function test_batch_tanpa_kolom_rentang_tetap_terkunci_per_bulan(): void
    {
        $payroll = Payroll::create([
            'company_id'   => $this->company->id,
            'period_month' => 9,
            'period_year'  => 2026,
            'period_label' => 'September 2026',
            'status'       => Payroll::STATUS_PAID,
            'run_type'     => Payroll::RUN_TYPE_REGULAR,
        ]);
        // Dipaksa NULL seperti baris seeder / data pra-migrasi.
        Payroll::where('id', $payroll->id)->update([
            'period_start_date' => null,
            'period_end_date'   => null,
        ]);

        $lock = app(PayrollPeriodLock::class);

        $this->assertNotNull($lock->findLocking($this->budi, '2026-09-25'));
        $this->assertNull($lock->findLocking($this->budi, '2026-10-01'));
    }

    public function test_locked_ranges_melaporkan_jendela_nyata_batch_terkunci(): void
    {
        $payroll = Payroll::create([
            'company_id'        => $this->company->id,
            'period_month'      => 9,
            'period_year'       => 2026,
            'period_start_date' => '2026-08-21',
            'period_end_date'   => '2026-09-20',
            'period_label'      => 'September 2026',
            'status'            => Payroll::STATUS_APPROVED,
            'run_type'          => Payroll::RUN_TYPE_REGULAR,
        ]);

        $ranges = app(PayrollPeriodLock::class)
            ->lockedRanges($this->company->id, '2026-09-01', '2026-09-30');

        $this->assertCount(1, $ranges);
        $this->assertSame('2026-08-21', $ranges[0]['start']);
        $this->assertSame('2026-09-20', $ranges[0]['end']);
        $this->assertSame((int) $payroll->id, $ranges[0]['payroll_id']);
        $this->assertSame(Payroll::STATUS_APPROVED, $ranges[0]['status']);
    }

    /**
     * locked_periods hanya memuat bulan yang TERKUNCI PENUH. Satu batch
     * 21 Ags - 20 Sep tidak menutup bulan mana pun secara penuh, jadi daftarnya
     * kosong — under-report yang disengaja & aman (server tetap menolak keras
     * lewat findLocking()).
     */
    public function test_locked_periods_hanya_memuat_bulan_terkunci_penuh(): void
    {
        Payroll::create([
            'company_id'        => $this->company->id,
            'period_month'      => 9,
            'period_year'       => 2026,
            'period_start_date' => '2026-08-21',
            'period_end_date'   => '2026-09-20',
            'period_label'      => 'September 2026',
            'status'            => Payroll::STATUS_APPROVED,
            'run_type'          => Payroll::RUN_TYPE_REGULAR,
        ]);

        $lock = app(PayrollPeriodLock::class);
        $this->assertSame([], $lock->lockedPeriods($this->company->id, '2026-08-01', '2026-09-30'));

        // Dua batch berurutan menutup September penuh → bulan itu baru muncul.
        Payroll::create([
            'company_id'        => $this->company->id,
            'period_month'      => 10,
            'period_year'       => 2026,
            'period_start_date' => '2026-09-21',
            'period_end_date'   => '2026-10-20',
            'period_label'      => 'Oktober 2026',
            'status'            => Payroll::STATUS_APPROVED,
            'run_type'          => Payroll::RUN_TYPE_REGULAR,
        ]);

        $this->assertSame(
            ['2026-09'],
            $lock->lockedPeriods($this->company->id, '2026-08-01', '2026-10-31')
        );
    }
}
