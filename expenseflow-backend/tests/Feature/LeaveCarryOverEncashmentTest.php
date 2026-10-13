<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceHistory;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Services\Attendance\LeavePeriodCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * CARRY-OVER & PENCAIRAN SISA CUTI TAHUNAN — JALUR TULIS (2026-10-13).
 *
 * Matriksnya sendiri sudah diuji di tests/Unit/LeaveCarryOverCalculatorTest.
 * Yang diuji di sini adalah hal-hal yang hanya bisa dibuktikan dengan DB:
 *
 *   gerbang HTTP        → enam guard validateLeavePolicyAmounts() + "SIMPAN"
 *   reset manual HRD    → saldo baru, baris riwayat, penyesuaian gaji terbit
 *   reset otomatis      → jalur command memberi angka yang SAMA
 *   anti-pencairan ganda→ klik kedua tidak menerbitkan uang kedua
 *   pengguguran         → hari bawaan gugur pada tanggalnya, yang terpakai tidak
 *   pelonggaran clamp   → tiga jalur baca/tulis saldo tidak menghapus hari bawaan
 */
class LeaveCarryOverEncashmentTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Jakarta';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Cuti Bawaan', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $override */
    private function office(array $override = []): AttendanceSetting
    {
        return AttendanceSetting::create(array_merge([
            'company_id'                   => $this->company->id,
            'office_name'                  => 'Kantor Pusat',
            'office_latitude'              => -6.2,
            'office_longitude'             => 106.8166,
            'radius_meters'                => 200,
            'work_start_time'              => '08:00:00',
            'work_end_time'                => '17:00:00',
            'work_days'                    => [1, 2, 3, 4, 5],
            'default_leave_quota'          => 12,
            'leave_multi_approval_enabled' => false,
        ], $override));
    }

    private function employee(AttendanceSetting $office): User
    {
        return User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'allow_leave'           => true,
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

    private function balance(User $user, int $quota, int $used, array $extra = []): LeaveBalance
    {
        return LeaveBalance::create(array_merge([
            'company_id' => $this->company->id,
            'user_id'    => $user->id,
            'year'       => (int) now(self::TZ)->year,
            'leave_type' => 'cuti',
            'quota'      => $quota,
            'used'       => $used,
        ], $extra));
    }

    /** @param array<string, mixed> $payload */
    private function simpan(User $hrd, AttendanceSetting $office, array $payload, bool $konfirmasi = true)
    {
        if ($konfirmasi) {
            $payload['confirm_dangerous'] = 'SIMPAN';
        }

        return $this->actingAs($hrd, 'sanctum')
            ->putJson("/api/v1/dashboard/attendance/settings/{$office->id}", $payload);
    }

    private function resetManual(User $hrd, AttendanceSetting $office)
    {
        return $this->actingAs($hrd, 'sanctum')
            ->postJson("/api/v1/dashboard/attendance/settings/{$office->id}/reset-leave-balances");
    }

    // ═══════════════ 1. Gerbang validasi kebijakan ═══════════════

    public function test_carry_over_aktif_wajib_mengisi_batas_hari(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $this->simpan($hrd, $office, ['leave_carry_over_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('leave_carry_over_max_days');
    }

    public function test_pencairan_aktif_wajib_mengisi_nominal_per_hari(): void
    {
        // Guard terpenting: tanpa nominal, sisa cuti karyawan tetap hangus
        // padahal HRD yakin sudah membuka opsi pencairan.
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $res = $this->simpan($hrd, $office, ['leave_encashment_enabled' => true]);

        $res->assertStatus(422)->assertJsonValidationErrors('leave_encashment_rate_per_day');
        $this->assertStringContainsString('hangus', (string) $res->json('message'));
    }

    public function test_nominal_terisi_tanpa_checkbox_pencairan_ditolak(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $this->simpan($hrd, $office, ['leave_encashment_rate_per_day' => 200_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('leave_encashment_enabled');
    }

    public function test_batas_hari_terisi_tanpa_checkbox_carry_over_ditolak(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $this->simpan($hrd, $office, ['leave_carry_over_max_days' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('leave_carry_over_enabled');
    }

    public function test_batas_hari_melebihi_kuota_kantor_ditolak(): void
    {
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);

        $this->simpan($hrd, $office, [
            'leave_carry_over_enabled'  => true,
            'leave_carry_over_max_days' => 20,
        ])->assertStatus(422)->assertJsonValidationErrors('leave_carry_over_max_days');
    }

    public function test_batas_carry_over_setara_kuota_kini_konfigurasi_yang_sah(): void
    {
        // Guard lama menolak batas carry-over sebesar kuota dengan alasan "tidak
        // akan ada sisa yang tidak dibawa untuk dicairkan". Premis itu habis
        // bersama perubahan 2026-10-14: uang pencairan tidak lagi berasal dari
        // sisa yang TIDAK dibawa, melainkan dari hari BAWAAN yang masa
        // berlakunya habis. "Bawa seluruh sisa, lalu cairkan yang tak terpakai"
        // justru konfigurasi paling wajar dan harus bisa disimpan.
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);

        $this->simpan($hrd, $office, [
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 12,
            'leave_carry_over_expiry_months' => 3,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 150_000,
        ])->assertOk();

        $office->refresh();
        $this->assertSame(12, (int) $office->leave_carry_over_max_days);
        $this->assertTrue((bool) $office->leave_encashment_enabled);
    }

    public function test_pencairan_tanpa_carry_over_ditolak(): void
    {
        // Guard 2b. Yang dicairkan adalah hari BAWAAN; tanpa carry-over tidak ada
        // hari bawaan, sehingga kebijakan pencairannya mati total — HRD
        // mencentangnya dan seluruh sisa cuti karyawan tetap hangus tanpa tanda.
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);

        $res = $this->simpan($hrd, $office, [
            'leave_encashment_enabled'      => true,
            'leave_encashment_rate_per_day' => 150_000,
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors('leave_carry_over_enabled');
        $this->assertStringContainsString('carry-over', (string) $res->json('message'));
    }

    public function test_pencairan_tanpa_masa_berlaku_ditolak(): void
    {
        // Guard 2c. Pemicu pencairan adalah peristiwa kedaluwarsa hari bawaan.
        // Masa berlaku NULL = hari bawaan tidak pernah kedaluwarsa, jadi uangnya
        // tidak akan pernah terbit. Pesannya wajib menyebut jalan keluarnya:
        // isi 0 bulan bila kantor memang ingin mencairkan di tanggal reset.
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);

        $res = $this->simpan($hrd, $office, [
            'leave_carry_over_enabled'      => true,
            'leave_carry_over_max_days'     => 6,
            'leave_encashment_enabled'      => true,
            'leave_encashment_rate_per_day' => 150_000,
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors('leave_carry_over_expiry_months');
        $this->assertStringContainsString('0 bulan', (string) $res->json('message'));
    }

    public function test_masa_berlaku_nol_boleh_disimpan(): void
    {
        // 0 dan NULL adalah dua arti berbeda di kolom ini: 0 = cairkan di tanggal
        // reset, NULL = tidak pernah kedaluwarsa. Validasi 'min:1' pada versi
        // sebelumnya membuat "cairkan segera" mustahil dikonfigurasi.
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);

        $this->simpan($hrd, $office, [
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 0,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 150_000,
        ])->assertOk();

        $office->refresh();
        $this->assertSame(0, (int) $office->leave_carry_over_expiry_months);
        $this->assertNotNull($office->leave_carry_over_expiry_months);
    }

    public function test_masa_berlaku_tanpa_carry_over_ditolak(): void
    {
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $this->simpan($hrd, $office, ['leave_carry_over_expiry_months' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('leave_carry_over_expiry_months');
    }

    // ═══════════════ 2. Gerbang konfirmasi "SIMPAN" ═══════════════

    public function test_enam_field_kebijakan_cuti_butuh_konfirmasi_simpan(): void
    {
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);

        $payload = [
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 250_000,
            'leave_encashment_max_days'      => 4,
        ];

        $res = $this->simpan($hrd, $office, $payload, konfirmasi: false);
        $res->assertStatus(422)->assertJsonPath('requires_confirmation', true);
        foreach (array_keys($payload) as $field) {
            $this->assertContains($field, $res->json('dangerous_changed_fields'));
        }
        // Peringatan harus menyebut konsekuensi konkretnya, bukan hanya nama field.
        $this->assertStringContainsString('penyesuaian gaji', (string) $res->json('message'));

        $this->simpan($hrd, $office, $payload)->assertOk();

        $office->refresh();
        $this->assertTrue((bool) $office->leave_carry_over_enabled);
        $this->assertSame(6, (int) $office->leave_carry_over_max_days);
        $this->assertSame(3, (int) $office->leave_carry_over_expiry_months);
        $this->assertTrue((bool) $office->leave_encashment_enabled);
        $this->assertEquals(250_000, (float) $office->leave_encashment_rate_per_day);
        $this->assertSame(4, (int) $office->leave_encashment_max_days);
    }

    public function test_kedua_fitur_nonaktif_diperingatkan_sisa_cuti_hangus(): void
    {
        $office = $this->office([
            'leave_carry_over_enabled'  => true,
            'leave_carry_over_max_days' => 6,
        ]);
        $hrd = $this->hrd($office);

        $res = $this->simpan($hrd, $office, [
            'leave_carry_over_enabled'  => false,
            'leave_carry_over_max_days' => null,
        ], konfirmasi: false);

        $res->assertStatus(422);
        $this->assertStringContainsString('HANGUS', (string) $res->json('message'));
    }

    public function test_null_dan_nol_pada_batas_hari_bukan_perubahan_berbahaya(): void
    {
        // settingValueEquals() membandingkan NULL vs 0 sebagai string ('' vs '0')
        // sehingga tanpa cabang integer khusus, gerbang "SIMPAN" akan menuntut
        // konfirmasi untuk perubahan yang sebenarnya tidak ada.
        $office = $this->office();
        $hrd    = $this->hrd($office);

        $res = $this->actingAs($hrd, 'sanctum')->putJson(
            "/api/v1/dashboard/attendance/settings/{$office->id}",
            ['leave_carry_over_max_days' => 0, 'leave_encashment_max_days' => 0]
        );

        $res->assertOk();
        $this->assertSame([], $res->json('dangerous_changed_fields'));
    }

    public function test_hrd_bisa_mengosongkan_nominal_pencairan(): void
    {
        // array_filter membuang NULL, jadi tanpa pemulihan eksplisit permintaan
        // mengosongkan tarif akan diabaikan diam-diam.
        $office = $this->office([
            'leave_encashment_enabled'      => true,
            'leave_encashment_rate_per_day' => 200_000,
        ]);
        $hrd = $this->hrd($office);

        $this->simpan($hrd, $office, [
            'leave_encashment_enabled'      => false,
            'leave_encashment_rate_per_day' => null,
        ])->assertOk();

        $office->refresh();
        $this->assertFalse((bool) $office->leave_encashment_enabled);
        $this->assertNull($office->leave_encashment_rate_per_day);
    }

    // ═══════════════ 3. Reset manual HRD ═══════════════

    public function test_reset_manual_membawa_hari_dan_menunda_pencairan_sampai_kedaluwarsa(): void
    {
        // Kasus yang diminta HRD secara eksplisit: kuota 12, terpakai 2, sisa 10,
        // batas bawa 6, masa berlaku 3 bulan, pencairan aktif.
        //
        // Di tanggal reset: 6 dibawa, 4 HANGUS (kelebihan di atas batas tidak
        // pernah menjadi uang), dan TIDAK ADA rupiah yang terbit — karyawan masih
        // memegang 6 hari itu sebagai hak cuti selama 3 bulan.
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 200_000,
        ]);
        $hrd = $this->hrd($office);
        $emp = $this->employee($office);

        $this->balance($emp, 12, 2);

        $res = $this->resetManual($hrd, $office);
        $res->assertOk()
            ->assertJsonPath('carry_over_summary.carried_over_days', 6)
            ->assertJsonPath('carry_over_summary.encashed_days', 0);
        $this->assertEquals(0, (float) $res->json('carry_over_summary.encashment_amount'));

        // Saldo periode baru: standar kantor + hari bawaan, used kembali 0.
        $saldo = LeaveBalance::where('user_id', $emp->id)->where('leave_type', 'cuti')->first();
        $this->assertSame(18, (int) $saldo->quota);
        $this->assertSame(0, (int) $saldo->used);
        $this->assertSame(6, (int) $saldo->carried_over_quota);
        $this->assertNotNull($saldo->carry_over_expired_at);

        // Riwayat: tiga takdir berjumlah tepat sebesar sisa.
        $riwayat = LeaveBalanceHistory::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(10, (int) $riwayat->cuti_remaining);
        $this->assertSame(6, (int) $riwayat->carried_over_days);
        $this->assertSame(0, (int) $riwayat->encashed_days);
        $this->assertSame(4, (int) $riwayat->forfeited_days);
        $this->assertNull($riwayat->encashment_amount);
        $this->assertNull($riwayat->encashment_adjustment_id);
        $this->assertTrue((bool) $riwayat->policy_snapshot['carry_over_enabled']);

        // Tidak ada uang yang terbit di tanggal reset.
        $this->assertSame(0, PayrollAdjustment::where('user_id', $emp->id)->count());
    }

    public function test_masa_berlaku_nol_mencairkan_hari_bawaan_di_tanggal_reset(): void
    {
        // Masa berlaku 0 bulan: hari bawaan tidak diberi waktu untuk dipakai,
        // jadi takdirnya selesai di tanggal reset — langsung dicairkan. Inilah
        // jalan bagi kantor yang ingin "sisa cuti langsung jadi uang". Kelebihan
        // di atas batas carry-over tetap hangus, bukan ikut dibayar.
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 0,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 200_000,
        ]);
        $hrd = $this->hrd($office);
        $emp = $this->employee($office);

        // Sisa 10 → 6 hari bawaan dicairkan = Rp1.200.000, 4 kelebihan hangus.
        $this->balance($emp, 12, 2);

        $res = $this->resetManual($hrd, $office);
        $res->assertOk()
            ->assertJsonPath('carry_over_summary.carried_over_days', 0)
            ->assertJsonPath('carry_over_summary.encashed_days', 6);
        $this->assertEquals(1_200_000, (float) $res->json('carry_over_summary.encashment_amount'));

        // Hari bawaan sudah ditukar uang, jadi tidak boleh tetap dipegang sebagai
        // hak cuti: kuota periode baru kembali ke standar kantor saja.
        $saldo = LeaveBalance::where('user_id', $emp->id)->where('leave_type', 'cuti')->first();
        $this->assertSame(12, (int) $saldo->quota);
        $this->assertSame(0, (int) $saldo->carried_over_quota);
        $this->assertNull($saldo->carry_over_expired_at);

        $riwayat = LeaveBalanceHistory::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(10, (int) $riwayat->cuti_remaining);
        $this->assertSame(0, (int) $riwayat->carried_over_days);
        $this->assertSame(6, (int) $riwayat->encashed_days);
        $this->assertSame(4, (int) $riwayat->forfeited_days);
        $this->assertEquals(1_200_000, (float) $riwayat->encashment_amount);
        $this->assertNotNull($riwayat->encashment_adjustment_id);

        // Penyesuaian gaji: menunggu batch (payroll_id NULL + approved).
        $adj = PayrollAdjustment::findOrFail($riwayat->encashment_adjustment_id);
        $this->assertNull($adj->payroll_id);
        $this->assertSame(PayrollAdjustment::STATUS_APPROVED, $adj->status);
        $this->assertSame(PayrollAdjustment::TYPE_EARNING, $adj->type);
        $this->assertSame(PayrollAdjustment::NAME_LEAVE_ENCASHMENT, $adj->name);
        $this->assertTrue($adj->isLeaveEncashment());
        $this->assertTrue((bool) $adj->is_taxable);
        $this->assertEquals(1_200_000, (float) $adj->amount);
        $this->assertSame($emp->id, $adj->user_id);
    }

    public function test_masa_berlaku_nol_tanpa_pencairan_menghanguskan_seluruh_sisa(): void
    {
        // Permintaan eksplisit HRD: bila fitur pencairan TIDAK diaktifkan, hari
        // bawaan benar-benar hangus. 0 bulan tanpa pencairan karena itu tidak
        // boleh menyisakan hari bawaan tanpa tanggal kedaluwarsa — hari itu akan
        // menjadi abadi, dan "tanpa masa berlaku" justru berarti selamanya.
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 0,
        ]);
        $hrd = $this->hrd($office);
        $emp = $this->employee($office);

        $this->balance($emp, 12, 2); // sisa 10

        $this->resetManual($hrd, $office)->assertOk();

        $saldo = LeaveBalance::where('user_id', $emp->id)->where('leave_type', 'cuti')->first();
        $this->assertSame(12, (int) $saldo->quota);
        $this->assertSame(0, (int) $saldo->carried_over_quota);
        $this->assertNull($saldo->carry_over_expired_at);

        $riwayat = LeaveBalanceHistory::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(0, (int) $riwayat->carried_over_days);
        $this->assertSame(0, (int) $riwayat->encashed_days);
        $this->assertSame(10, (int) $riwayat->forfeited_days);

        $this->assertSame(0, PayrollAdjustment::where('user_id', $emp->id)->count());
    }

    public function test_kedua_fitur_nonaktif_seluruh_sisa_hangus_tanpa_uang(): void
    {
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);
        $emp    = $this->employee($office);

        $this->balance($emp, 12, 3); // sisa 9

        $this->resetManual($hrd, $office)->assertOk();

        $saldo = LeaveBalance::where('user_id', $emp->id)->where('leave_type', 'cuti')->first();
        $this->assertSame(12, (int) $saldo->quota);
        $this->assertSame(0, (int) $saldo->carried_over_quota);

        $riwayat = LeaveBalanceHistory::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(9, (int) $riwayat->forfeited_days);
        $this->assertSame(0, (int) $riwayat->carried_over_days);
        $this->assertSame(0, (int) $riwayat->encashed_days);
        $this->assertNull($riwayat->encashment_adjustment_id);

        $this->assertSame(0, PayrollAdjustment::where('user_id', $emp->id)->count());
    }

    public function test_reset_kedua_pada_tanggal_sama_tidak_membayar_dua_kali(): void
    {
        // Masa berlaku 0 bulan dipakai di sini karena itulah satu-satunya jalur
        // yang menerbitkan uang DI TANGGAL RESET, sehingga penjagaan anti-dobel
        // reset bisa diuji. Pencairan saat kedaluwarsa punya penjagaannya sendiri
        // (carried_over_quota dinolkan, jadi baris tidak terpilih dua kali).
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 12,
            'leave_carry_over_expiry_months' => 0,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 100_000,
        ]);
        $hrd = $this->hrd($office);
        $emp = $this->employee($office);

        $this->balance($emp, 12, 7); // sisa 5 → Rp500.000

        $this->resetManual($hrd, $office)->assertOk();

        // Saldo sudah di-reset (sisa 12 penuh), jadi klik kedua akan mencoba
        // mencairkan 12 hari. Penjagaan anti-dobel harus menahannya sepenuhnya.
        $this->resetManual($hrd, $office)->assertOk();

        $this->assertSame(1, PayrollAdjustment::where('user_id', $emp->id)->count());
        $this->assertEquals(
            500_000,
            (float) PayrollAdjustment::where('user_id', $emp->id)->sum('amount')
        );

        // Baris riwayat kedua mencatat nol pencairan dengan alasannya, dan
        // invarian penjumlahannya tetap utuh.
        $kedua = LeaveBalanceHistory::where('user_id', $emp->id)->latest('id')->firstOrFail();
        $this->assertSame(0, (int) $kedua->encashed_days);
        $this->assertSame(12, (int) $kedua->forfeited_days);
        $this->assertTrue((bool) $kedua->policy_snapshot['duplicate_encashment_blocked']);
        $this->assertSame(
            (int) $kedua->cuti_remaining,
            (int) $kedua->carried_over_days + (int) $kedua->encashed_days + (int) $kedua->forfeited_days
        );
    }

    public function test_saldo_belum_diaktifkan_tidak_ikut_reset(): void
    {
        // KEBIJAKAN 2026-08-25: saldo cuti non-aktif secara default; reset tidak
        // boleh mengaktifkannya — termasuk lewat jalur carry-over.
        $office = $this->office([
            'default_leave_quota'           => 12,
            'leave_encashment_enabled'      => true,
            'leave_encashment_rate_per_day' => 100_000,
        ]);
        $hrd = $this->hrd($office);
        $emp = $this->employee($office);

        $this->balance($emp, 0, 0);

        $this->resetManual($hrd, $office)->assertOk();

        $this->assertSame(0, (int) LeaveBalance::where('user_id', $emp->id)->value('quota'));
        $this->assertSame(0, LeaveBalanceHistory::where('user_id', $emp->id)->count());
        $this->assertSame(0, PayrollAdjustment::where('user_id', $emp->id)->count());
    }

    // ═══════════════ 4. Reset otomatis (command) ═══════════════

    public function test_reset_otomatis_memberi_angka_sama_dengan_reset_manual(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 01:00:00', self::TZ));

        // Masa berlaku 0 bulan: uangnya terbit di tanggal reset, sehingga jalur
        // command bisa dibandingkan langsung dengan jalur manual (keduanya lewat
        // LeavePeriodCloser dan karena itu wajib menghasilkan angka identik).
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_reset_date'               => '01-01',
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 0,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 200_000,
        ]);
        $emp = $this->employee($office);

        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $emp->id,
            'year'       => 2027,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 2,
        ]);

        Artisan::call('attendance:reset-leave-balances');

        $saldo = LeaveBalance::where('user_id', $emp->id)->where('leave_type', 'cuti')->firstOrFail();
        $this->assertSame(12, (int) $saldo->quota);
        $this->assertSame(0, (int) $saldo->carried_over_quota);

        $riwayat = LeaveBalanceHistory::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(0, (int) $riwayat->carried_over_days);
        $this->assertSame(6, (int) $riwayat->encashed_days);
        $this->assertSame(4, (int) $riwayat->forfeited_days);
        $this->assertEquals(1_200_000, (float) $riwayat->encashment_amount);

        // Reset otomatis tidak punya aktor manusia; penyesuaiannya tetap approved.
        $adj = PayrollAdjustment::findOrFail($riwayat->encashment_adjustment_id);
        $this->assertNull($adj->created_by);
        $this->assertSame(PayrollAdjustment::STATUS_APPROVED, $adj->status);
        $this->assertStringContainsString('otomatis', (string) $adj->reason);

        // Anti-dobel: jalan kedua di jam yang sama tidak menambah apa pun.
        Artisan::call('attendance:reset-leave-balances');
        $this->assertSame(1, PayrollAdjustment::where('user_id', $emp->id)->count());
        $this->assertSame(1, LeaveBalanceHistory::where('user_id', $emp->id)->count());
    }

    // ═══════════════ 5. Pengguguran hari bawaan ═══════════════

    public function test_hari_bawaan_gugur_setelah_tanggal_kedaluwarsa(): void
    {
        // Kantor tanpa pencairan: hari bawaan yang kedaluwarsa benar-benar hangus.
        $office = $this->office([
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 18, 0, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        // Hari terakhir masa berlaku: masih sah dipakai.
        $belum = LeavePeriodCloser::expireCarryOver(Carbon::parse('2027-03-31', self::TZ));
        $this->assertSame(0, $belum['processed']);
        $this->assertSame(18, (int) LeaveBalance::where('user_id', $emp->id)->value('quota'));

        // Hari berikutnya: gugur, tanpa uang karena pencairan tidak aktif.
        $gugur = LeavePeriodCloser::expireCarryOver(Carbon::parse('2027-04-01', self::TZ));
        $this->assertSame(1, $gugur['processed']);
        $this->assertSame(0, $gugur['encashed_days']);
        $this->assertSame(6, $gugur['forfeited_days']);
        $this->assertEquals(0.0, $gugur['encashed_amount']);

        $saldo = LeaveBalance::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(12, (int) $saldo->quota);
        $this->assertSame(0, (int) $saldo->carried_over_quota);
        $this->assertNull($saldo->carry_over_expired_at);

        // Baris riwayat TERSENDIRI: HRD harus bisa melihat peristiwa ini di tab
        // Riwayat Saldo Cuti, bukan hanya menduganya dari saldo yang menyusut.
        $riwayat = LeaveBalanceHistory::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(
            LeavePeriodCloser::EVENT_CARRY_OVER_EXPIRY,
            $riwayat->policy_snapshot['event']
        );
        $this->assertSame(6, (int) $riwayat->cuti_remaining);
        $this->assertSame(0, (int) $riwayat->encashed_days);
        $this->assertSame(6, (int) $riwayat->forfeited_days);
        $this->assertNull($riwayat->encashment_adjustment_id);

        $this->assertSame(0, PayrollAdjustment::where('user_id', $emp->id)->count());
    }

    public function test_hari_bawaan_tak_terpakai_dicairkan_saat_masa_berlakunya_habis(): void
    {
        // INILAH peristiwa yang menerbitkan uang pada model ini. Karyawan lebih
        // dulu diberi 3 bulan untuk memakai hari bawaannya sebagai CUTI; hanya
        // yang tidak terpakai sampai kesempatan itu habis yang ditukar uang.
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 150_000,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 18, 2, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        $hasil = LeavePeriodCloser::expireCarryOver(Carbon::parse('2027-04-01', self::TZ));

        $this->assertSame(1, $hasil['processed']);
        $this->assertSame(6, $hasil['encashed_days']);
        $this->assertSame(0, $hasil['forfeited_days']);
        $this->assertEquals(900_000, $hasil['encashed_amount']);

        // Hari yang sudah ditukar uang TIDAK boleh tetap dipegang sebagai hak
        // cuti — kalau tidak, satu hari dibayar dua kali.
        $saldo = LeaveBalance::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(12, (int) $saldo->quota);
        $this->assertSame(2, (int) $saldo->used, 'Pencairan tidak boleh menyentuh kolom used.');
        $this->assertSame(0, (int) $saldo->carried_over_quota);
        $this->assertNull($saldo->carry_over_expired_at);

        $riwayat = LeaveBalanceHistory::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(
            LeavePeriodCloser::EVENT_CARRY_OVER_EXPIRY,
            $riwayat->policy_snapshot['event']
        );
        $this->assertSame(6, (int) $riwayat->encashed_days);
        $this->assertSame(0, (int) $riwayat->forfeited_days);
        $this->assertEquals(150_000, (float) $riwayat->encashment_rate_per_day);
        $this->assertEquals(900_000, (float) $riwayat->encashment_amount);
        $this->assertNotNull($riwayat->encashment_adjustment_id);

        // Penyesuaian gaji memakai mekanisme yang sama: menggantung menunggu
        // batch payroll berikutnya, kena pajak, tanpa aktor manusia.
        $adj = PayrollAdjustment::findOrFail($riwayat->encashment_adjustment_id);
        $this->assertNull($adj->payroll_id);
        $this->assertNull($adj->created_by);
        $this->assertSame(PayrollAdjustment::STATUS_APPROVED, $adj->status);
        $this->assertSame(PayrollAdjustment::NAME_LEAVE_ENCASHMENT, $adj->name);
        $this->assertTrue((bool) $adj->is_taxable);
        $this->assertEquals(900_000, (float) $adj->amount);
        // Alasannya harus menyebut peristiwa kedaluwarsa agar karyawan bisa
        // mencocokkannya dengan masa berlaku yang diumumkan kantor.
        $this->assertStringContainsString('kedaluwarsa', (string) $adj->reason);

        // Sweep kedua di hari yang sama tidak boleh membayar ulang: baris sudah
        // kehilangan carried_over_quota-nya dan tidak terpilih lagi.
        $ulang = LeavePeriodCloser::expireCarryOver(Carbon::parse('2027-04-01', self::TZ));
        $this->assertSame(0, $ulang['processed']);
        $this->assertSame(1, PayrollAdjustment::where('user_id', $emp->id)->count());
        $this->assertSame(1, LeaveBalanceHistory::where('user_id', $emp->id)->count());
    }

    public function test_pagu_pencairan_membatasi_hari_bawaan_yang_kedaluwarsa(): void
    {
        // Pagu kantor berlaku pada kedua peristiwa pencairan dengan cara yang
        // sama (LeaveCarryOverCalculator::capEncashable), sehingga karyawan tidak
        // menerima nominal berbeda hanya karena kantornya memilih "cair segera"
        // ketimbang "cair nanti". Sisa di atas pagu hangus.
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 150_000,
            'leave_encashment_max_days'      => 4,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 18, 0, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        $hasil = LeavePeriodCloser::expireCarryOver(Carbon::parse('2027-04-01', self::TZ));

        $this->assertSame(4, $hasil['encashed_days']);
        $this->assertSame(2, $hasil['forfeited_days']);
        $this->assertEquals(600_000, $hasil['encashed_amount']);

        // Seluruh 6 hari bawaan tetap keluar dari kuota: 4 ditukar uang, 2 hangus.
        $this->assertSame(12, (int) LeaveBalance::where('user_id', $emp->id)->value('quota'));
    }

    public function test_hari_bawaan_yang_sudah_terpakai_tidak_ditarik_kembali(): void
    {
        // Kuota 18 (12 + 6 bawaan), terpakai 14 → 2 dari hari bawaan sudah
        // dipakai. Cutinya sudah diambil, jadi hanya 4 hari yang bisa gugur.
        $office = $this->office();
        $emp    = $this->employee($office);

        $this->balance($emp, 18, 14, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        LeavePeriodCloser::expireCarryOver(Carbon::parse('2027-04-01', self::TZ));

        $saldo = LeaveBalance::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame(14, (int) $saldo->quota);
        $this->assertSame(14, (int) $saldo->used, 'Pengguguran tidak boleh menyentuh kolom used.');
        $this->assertSame(0, (int) $saldo->quota - (int) $saldo->used);
    }

    public function test_command_menggugurkan_meski_kantor_mencabut_jadwal_reset(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-04-01 02:00:00', self::TZ));

        // Kantor tanpa leave_reset_date: tidak ada peristiwa reset yang bisa
        // menangani hari bawaan yang kedaluwarsa, jadi sweep harus berjalan
        // sebelum early return "tidak ada kantor berjadwal".
        $office = $this->office(['leave_reset_date' => null]);
        $emp    = $this->employee($office);

        $this->balance($emp, 18, 0, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        Artisan::call('attendance:reset-leave-balances');

        $this->assertSame(12, (int) LeaveBalance::where('user_id', $emp->id)->value('quota'));
    }

    // ═══════════════ 6. Pelonggaran clamp kuota ═══════════════

    public function test_hrd_boleh_menyimpan_kuota_di_atas_standar_sebesar_hari_bawaan(): void
    {
        // Tanpa pelonggaran, HRD ditolak 422 atas angka yang justru ditulis
        // sistem sendiri saat reset.
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);
        $emp    = $this->employee($office);

        $this->balance($emp, 18, 0, ['carried_over_quota' => 6]);

        $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'    => $emp->id,
            'year'       => (int) now(self::TZ)->year,
            'leave_type' => 'cuti',
            'quota'      => 18,
        ])->assertOk();

        $this->assertSame(18, (int) LeaveBalance::where('user_id', $emp->id)->value('quota'));
    }

    public function test_kuota_di_atas_standar_plus_bawaan_tetap_ditolak(): void
    {
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);
        $emp    = $this->employee($office);

        $this->balance($emp, 18, 0, ['carried_over_quota' => 6]);

        $res = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'    => $emp->id,
            'year'       => (int) now(self::TZ)->year,
            'leave_type' => 'cuti',
            'quota'      => 19,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('bawaan periode lalu', (string) $res->json('message'));
    }

    public function test_daftar_saldo_hrd_tidak_memangkas_hari_bawaan(): void
    {
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);
        $emp    = $this->employee($office);

        $this->balance($emp, 18, 0, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        $res = $this->actingAs($hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/leave-balances');

        $res->assertOk();
        // Respons memulangkan satu baris DATAR per (user, leave_type), bukan
        // satu baris per karyawan — pencarian wajib menyaring leave_type juga,
        // kalau tidak baris 'izin' yang terambil.
        $baris = collect($res->json('balances'))
            ->first(fn ($b) => (int) $b['user_id'] === $emp->id && $b['leave_type'] === 'cuti');

        $this->assertNotNull($baris, 'Baris saldo cuti karyawan tidak ditemukan di respons.');
        $this->assertSame(18, (int) $baris['quota']);
        $this->assertSame(6, (int) $baris['carried_over_quota']);
        $this->assertSame(12, (int) $baris['office_default_quota']);
    }

    public function test_saldo_mobile_menyertakan_hari_bawaan_dan_label_kebijakan(): void
    {
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_reset_date'               => '01-01',
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 200_000,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 18, 0, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        $res = $this->actingAs($emp, 'sanctum')->getJson('/api/v1/attendance/leave-balance');
        $res->assertOk();

        $cuti = collect($res->json('balances'))
            ->first(fn ($b) => $b['leave_type'] === 'cuti');

        $this->assertNotNull($cuti, 'Baris saldo cuti tidak ada di respons mobile.');
        $this->assertSame(18, (int) $cuti['quota']);
        $this->assertSame(6, (int) $cuti['carried_over_quota']);
        $this->assertSame('2027-03-31', substr((string) $cuti['carry_over_expired_at'], 0, 10));
        $this->assertTrue((bool) $res->json('reset_info.carry_over.enabled'));
        $this->assertSame(6, (int) $res->json('reset_info.carry_over.max_days'));
        $this->assertTrue((bool) $res->json('reset_info.encashment.enabled'));
        $this->assertEquals(200_000, (float) $res->json('reset_info.encashment.rate_per_day'));

        // Label dirakit server-side agar web & mobile membaca matriks yang sama.
        // Urutan ceritanya harus mengikuti urutan peristiwanya: dibawa dulu,
        // kelebihannya hangus, baru yang tak terpakai dicairkan setelah 3 bulan.
        $label = (string) $res->json('reset_info.remaining_leave_policy_label');
        $this->assertStringContainsString('maksimal 6 hari', $label);
        $this->assertStringContainsString('kelebihannya hangus', $label);
        $this->assertStringContainsString('3 bulan', $label);
        $this->assertStringContainsString('dicairkan', $label);
    }

    public function test_label_kebijakan_masa_berlaku_nol_menyebut_pencairan_di_tanggal_reset(): void
    {
        // Kalimat "dibawa ke periode berikutnya" akan menyesatkan di kasus ini:
        // harinya tidak pernah sampai ke periode berikutnya, ia langsung ditukar
        // uang di tanggal reset.
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_reset_date'               => '01-01',
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 0,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 200_000,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 12, 0);

        $res = $this->actingAs($emp, 'sanctum')->getJson('/api/v1/attendance/leave-balance');
        $res->assertOk();

        $label = (string) $res->json('reset_info.remaining_leave_policy_label');
        $this->assertStringContainsString('pada tanggal reset', $label);
        $this->assertStringContainsString('dicairkan', $label);
        $this->assertStringNotContainsString('dibawa ke periode berikutnya', $label);
    }

    public function test_label_kebijakan_tanpa_pencairan_menyebut_hangus(): void
    {
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_reset_date'               => '01-01',
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 12, 0);

        $label = (string) $this->actingAs($emp, 'sanctum')
            ->getJson('/api/v1/attendance/leave-balance')
            ->json('reset_info.remaining_leave_policy_label');

        $this->assertStringContainsString('3 bulan', $label);
        $this->assertStringContainsString('hangus', $label);
        $this->assertStringNotContainsString('dicairkan', $label);
    }

    // ═══════════════ 7. Riwayat: snapshot & agregat ═══════════════

    public function test_riwayat_mengembalikan_snapshot_dan_agregat_pencairan(): void
    {
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 0,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 200_000,
        ]);
        $hrd = $this->hrd($office);
        $a   = $this->employee($office);
        $b   = $this->employee($office);

        // Masa berlaku 0 bulan: 6 hari bawaan A langsung cair = Rp1.200.000,
        // 4 kelebihannya hangus. B hanya punya 3 hari → cair Rp600.000.
        $this->balance($a, 12, 2); // sisa 10 → 6 cair, 4 hangus
        $this->balance($b, 12, 9); // sisa 3  → 3 cair, 0 hangus

        $this->resetManual($hrd, $office)->assertOk();

        $res = $this->actingAs($hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/leave-balance-history');
        $res->assertOk();

        $this->assertSame(0, (int) $res->json('stats.total_carried_over_days'));
        $this->assertSame(9, (int) $res->json('stats.total_encashed_days'));
        $this->assertEquals(1_800_000, (float) $res->json('stats.total_encashment_amount'));
        $this->assertSame(4, (int) $res->json('stats.total_forfeited_days'));

        $barisA = collect($res->json('histories'))->firstWhere('user_id', $a->id);
        $this->assertSame(0, (int) $barisA['carried_over_days']);
        $this->assertSame(6, (int) $barisA['encashed_days']);
        $this->assertSame(4, (int) $barisA['forfeited_days']);
        $this->assertEquals(200_000, (float) $barisA['encashment_rate_per_day']);
        $this->assertNotNull($barisA['encashment_adjustment_id']);
        $this->assertIsArray($barisA['policy_snapshot']);
        $this->assertStringContainsString('6 hari dicairkan', $barisA['remaining_outcome_label']);
        $this->assertStringContainsString('4 hari hangus', $barisA['remaining_outcome_label']);
    }

    public function test_riwayat_pengguguran_masa_berlaku_bercerita_tentang_hari_bawaan(): void
    {
        // Baris pengguguran harus dibedakan dari baris reset tahunan: angkanya
        // bicara tentang hari BAWAAN, bukan sisa kuota tahunan. Memakai kalimat
        // yang sama akan menyatakan "sisa 6 hari" yang artinya berbeda.
        $office = $this->office([
            'default_leave_quota'            => 12,
            'leave_carry_over_enabled'       => true,
            'leave_carry_over_max_days'      => 6,
            'leave_carry_over_expiry_months' => 3,
            'leave_encashment_enabled'       => true,
            'leave_encashment_rate_per_day'  => 150_000,
        ]);
        $hrd = $this->hrd($office);
        $emp = $this->employee($office);

        $this->balance($emp, 18, 0, [
            'carried_over_quota'    => 6,
            'carry_over_expired_at' => '2027-03-31',
        ]);

        LeavePeriodCloser::expireCarryOver(Carbon::parse('2027-04-01', self::TZ));

        $res = $this->actingAs($hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/leave-balance-history');
        $res->assertOk();

        $baris = collect($res->json('histories'))->firstWhere('user_id', $emp->id);
        $this->assertNotNull($baris);
        $this->assertStringContainsString('cuti bawaan', $baris['remaining_outcome_label']);
        $this->assertStringContainsString('masa berlakunya habis', $baris['remaining_outcome_label']);
        $this->assertStringContainsString('Rp900.000', $baris['remaining_outcome_label']);
        $this->assertStringContainsString('Masa berlaku cuti bawaan', (string) $baris['period_label']);

        // Agregatnya ikut terbaca HRD: inilah biaya pencairan yang harus cocok
        // dengan baris "Pencairan Sisa Cuti Tahunan" di payroll.
        $this->assertSame(6, (int) $res->json('stats.total_encashed_days'));
        $this->assertEquals(900_000, (float) $res->json('stats.total_encashment_amount'));
    }

    public function test_riwayat_tanpa_sisa_cuti_diberi_label_yang_sesuai(): void
    {
        $office = $this->office(['default_leave_quota' => 12]);
        $hrd    = $this->hrd($office);
        $emp    = $this->employee($office);

        $this->balance($emp, 12, 12); // sisa 0

        $this->resetManual($hrd, $office)->assertOk();

        $res = $this->actingAs($hrd, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance/leave-balance-history');

        $baris = collect($res->json('histories'))->firstWhere('user_id', $emp->id);
        $this->assertStringContainsString('Tidak ada sisa cuti', $baris['remaining_outcome_label']);
    }

    // ═══════════════ 8. Pengajuan melintasi tanggal reset ═══════════════

    /**
     * Pengajuan yang melintasi anniversary dinilai dua kali: hari sebelum pivot
     * vs saldo berjalan, hari pada/setelah pivot vs kuota periode BARU. Kuota
     * baru itu harus sudah memperhitungkan hari bawaan yang akan dibawa reset,
     * jika tidak karyawan ditolak 422 atas hari yang beberapa hari kemudian
     * benar-benar ia miliki.
     */
    private function ajukanCuti(User $emp, string $start, string $end)
    {
        return $this->actingAs($emp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Uji pengajuan melintasi tanggal reset saldo cuti.',
        ]);
    }

    public function test_pengajuan_melintasi_reset_memakai_kuota_baru_termasuk_hari_bawaan(): void
    {
        // Jum'at 2027-06-04 … Kamis 2027-06-10 (reset 2027-06-09).
        // Hari kerja: 4, 7, 8 sebelum pivot (3 hari) + 9, 10 sejak pivot (2 hari).
        Carbon::setTestNow(Carbon::parse('2027-06-01 08:00:00', self::TZ));

        $office = $this->office([
            'default_leave_quota'       => 1,   // kuota baru sendiri HANYA 1 hari
            'leave_reset_date'          => '06-09',
            'leave_carry_over_enabled'  => true,
            'leave_carry_over_max_days' => 6,
        ]);
        $emp = $this->employee($office);

        // Sisa 5 hari sekarang. 3 hari terpakai sebelum pivot → sisa saat reset 2,
        // seluruhnya terbawa (batas 6) → kuota baru = 1 + 2 = 3 ≥ 2 hari diminta.
        $this->balance($emp, 5, 0);

        $this->ajukanCuti($emp, '2027-06-04', '2027-06-10')->assertStatus(201);
    }

    public function test_pengajuan_melintasi_reset_ditolak_bila_kuota_baru_tetap_kurang(): void
    {
        // Konfigurasi sama, tetapi carry-over DIMATIKAN: tidak ada hari bawaan,
        // kuota baru tetap 1 hari sementara 2 hari diminta setelah pivot.
        Carbon::setTestNow(Carbon::parse('2027-06-01 08:00:00', self::TZ));

        $office = $this->office([
            'default_leave_quota'      => 1,
            'leave_reset_date'         => '06-09',
            'leave_carry_over_enabled' => false,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 5, 0);

        $res = $this->ajukanCuti($emp, '2027-06-04', '2027-06-10');
        $res->assertStatus(422);
        $this->assertStringContainsString('setelah tanggal reset', (string) $res->json('message'));
    }

    public function test_hari_bawaan_tidak_dijanjikan_bila_habis_dipakai_sebelum_reset(): void
    {
        // Sisa 3 hari, SELURUHNYA terpakai oleh 3 hari sebelum pivot → sisa saat
        // reset 0, tidak ada yang dibawa, kuota baru tetap 1 hari. Proyeksi yang
        // lupa mengurangi hari sebelum pivot akan menjanjikan 3 hari bawaan yang
        // justru habis dipakai pengajuan yang sedang dinilai ini sendiri.
        Carbon::setTestNow(Carbon::parse('2027-06-01 08:00:00', self::TZ));

        $office = $this->office([
            'default_leave_quota'       => 1,
            'leave_reset_date'          => '06-09',
            'leave_carry_over_enabled'  => true,
            'leave_carry_over_max_days' => 6,
        ]);
        $emp = $this->employee($office);

        $this->balance($emp, 3, 0);

        $res = $this->ajukanCuti($emp, '2027-06-04', '2027-06-10');
        $res->assertStatus(422);
        $this->assertStringContainsString('setelah tanggal reset', (string) $res->json('message'));
        $this->assertStringContainsString('Tersedia 1 hari', (string) $res->json('message'));
    }
}
