<?php

namespace Tests\Unit;

use App\Services\Attendance\LeaveCarryOverCalculator as Calc;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Uji kalkulator murni carry-over & pencairan sisa cuti (2026-10-13).
 *
 * Kelas yang diuji tidak membaca DB maupun jam sistem, jadi SELURUH matriks
 * kebijakan bisa dibuktikan di sini tanpa menyiapkan basis data — termasuk
 * invarian yang paling penting:
 *
 *     remaining = carried_over_days + encashed_days + forfeited_days
 *
 * Invarian itu yang menjamin sisa cuti karyawan tidak menguap dan tidak
 * berganda. Karena itu ia diperiksa pada SETIAP kasus di bawah lewat
 * assertInvarian(), bukan hanya pada satu tes khusus.
 */
class LeaveCarryOverCalculatorTest extends TestCase
{
    private const RESET = '2027-01-01';

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function config(array $override = []): array
    {
        return array_merge([
            'carry_over_enabled'       => false,
            'carry_over_max_days'      => 0,
            'carry_over_expiry_months' => null,
            'encashment_enabled'       => false,
            'encashment_rate_per_day'  => 0.0,
            'encashment_max_days'      => null,
        ], $override);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function decide(int $remaining, array $config, string $reset = self::RESET): array
    {
        $res = Calc::decide($remaining, $config, Carbon::parse($reset, 'Asia/Jakarta'));

        // Invarian diperiksa di sini, bukan di tes terpisah: setiap pemanggilan
        // dalam berkas ini otomatis ikut membuktikannya.
        $this->assertSame(
            max(0, $remaining),
            $res['carried_over_days'] + $res['encashed_days'] + $res['forfeited_days'],
            'Invarian dilanggar: sisa cuti tidak sama dengan dibawa + dicairkan + hangus.'
        );

        return $res;
    }

    // ═══════════════ Matriks 4 kombinasi checkbox ═══════════════

    public function test_kedua_checkbox_mati_semua_sisa_hangus(): void
    {
        $res = $this->decide(10, $this->config());

        $this->assertSame(0, $res['carried_over_days']);
        $this->assertSame(0, $res['encashed_days']);
        $this->assertSame(10, $res['forfeited_days']);
        $this->assertSame(0.0, $res['encashment_amount']);
        $this->assertNull($res['carry_over_expired_at']);
    }

    public function test_carry_over_saja_kelebihan_batas_hangus(): void
    {
        $res = $this->decide(10, $this->config([
            'carry_over_enabled'  => true,
            'carry_over_max_days' => 6,
        ]));

        $this->assertSame(6, $res['carried_over_days']);
        $this->assertSame(0, $res['encashed_days']);
        $this->assertSame(4, $res['forfeited_days']);
    }

    public function test_pencairan_tanpa_carry_over_tidak_menghasilkan_uang(): void
    {
        // Uang pencairan BERSUMBER dari hari bawaan; tanpa carry-over tidak ada
        // hari bawaan sehingga tidak ada yang bisa dicairkan. Guard 422 di
        // lapisan HTTP mencegah kombinasi ini tersimpan, tetapi kalkulator tetap
        // harus netral bila data lama memilikinya.
        $res = $this->decide(8, $this->config([
            'encashment_enabled'      => true,
            'encashment_rate_per_day' => 150_000,
        ]));

        $this->assertSame(0, $res['carried_over_days']);
        $this->assertSame(0, $res['encashed_days']);
        $this->assertSame(8, $res['forfeited_days']);
        $this->assertSame(0.0, $res['encashment_amount']);
    }

    public function test_masa_berlaku_berjalan_menunda_pencairan_sampai_kedaluwarsa(): void
    {
        // Permintaan eksplisit HRD: 12 kuota, 2 terpakai, sisa 10, batas bawa 7.
        // Di tanggal reset 7 dibawa & 3 hangus. TIDAK ada rupiah yang terbit
        // sekarang — karyawan masih memegang 7 hari itu sebagai hak cuti selama
        // 3 bulan. Pencairannya dikerjakan LeavePeriodCloser::expireCarryOver()
        // bila sampai tanggal kedaluwarsa hari itu belum terpakai.
        $res = $this->decide(10, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 7,
            'carry_over_expiry_months' => 3,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 200_000,
        ]));

        $this->assertSame(7, $res['carried_over_days']);
        $this->assertSame(0, $res['encashed_days']);
        $this->assertSame(3, $res['forfeited_days']);
        $this->assertSame(0.0, $res['encashment_amount']);
        $this->assertSame('2027-04-01', $res['carry_over_expired_at']);
    }

    public function test_masa_berlaku_nol_bulan_mencairkan_di_tanggal_reset(): void
    {
        // 0 bulan = hari bawaan tidak diberi waktu untuk dipakai, jadi takdirnya
        // selesai hari ini: dicairkan. Inilah jalan bagi kantor yang ingin sisa
        // cuti langsung menjadi uang.
        $res = $this->decide(10, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 7,
            'carry_over_expiry_months' => 0,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 200_000,
        ]));

        $this->assertSame(0, $res['carried_over_days']);
        $this->assertSame(7, $res['encashed_days']);
        $this->assertSame(3, $res['forfeited_days']);
        $this->assertEqualsWithDelta(1_400_000, $res['encashment_amount'], 0.01);
        // Harinya sudah ditukar uang; tidak ada saldo bawaan yang perlu diawasi.
        $this->assertNull($res['carry_over_expired_at']);
    }

    public function test_masa_berlaku_nol_tanpa_pencairan_menghanguskan_hari_bawaan(): void
    {
        // Tanpa pencairan, 0 bulan berarti hari bawaan habis masa berlakunya
        // seketika. Menyisakannya sebagai hari bawaan akan membuatnya ABADI:
        // tidak ada tanggal kedaluwarsa yang bisa menggugurkannya nanti.
        $res = $this->decide(10, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 7,
            'carry_over_expiry_months' => 0,
        ]));

        $this->assertSame(0, $res['carried_over_days']);
        $this->assertSame(0, $res['encashed_days']);
        $this->assertSame(10, $res['forfeited_days']);
        $this->assertNull($res['carry_over_expired_at']);
    }

    public function test_hari_di_atas_batas_carry_over_hangus_bukan_dicairkan(): void
    {
        // Pembeda inti model ini: hari yang melampaui batas carry-over TIDAK
        // pernah menjadi uang. Kantor sudah memutuskan tidak mengakui hari-hari
        // itu, jadi membayarnya berarti membayar justru yang ia tolak bawa.
        $res = $this->decide(10, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 7,
            'carry_over_expiry_months' => 0,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 200_000,
        ]));

        // 3 hari kelebihan hangus; hanya 7 hari bawaan yang dibayar.
        $this->assertSame(3, $res['forfeited_days']);
        $this->assertEqualsWithDelta(7 * 200_000, $res['encashment_amount'], 0.01);
    }

    // ═══════════════ Pagu & nilai batas ═══════════════

    public function test_pagu_pencairan_membatasi_hari_selebihnya_hangus(): void
    {
        // Pagu bekerja atas hari BAWAAN. 12 sisa, batas bawa 10 → 2 hangus
        // sebagai kelebihan; dari 10 hari bawaan hanya 5 yang boleh dicairkan,
        // 5 sisanya hangus bersama. Total hangus 7.
        $res = $this->decide(12, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 10,
            'carry_over_expiry_months' => 0,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 100_000,
            'encashment_max_days'      => 5,
        ]));

        $this->assertSame(5, $res['encashed_days']);
        $this->assertSame(7, $res['forfeited_days']);
        $this->assertEqualsWithDelta(500_000, $res['encashment_amount'], 0.01);
    }

    public function test_pagu_keras_kelas_membatasi_data_rusak(): void
    {
        // Kuota 365 hari tak terpakai (batas atas default_leave_quota) tidak
        // boleh menjadi tagihan pencairan 365 hari sekaligus. Pagu keras
        // memotong carry-over lebih dulu, dan pencairan tidak bisa melampaui
        // hari bawaan yang sudah terpotong itu.
        $res = $this->decide(365, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 999,
            'carry_over_expiry_months' => 0,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 100_000,
        ]));

        $this->assertSame(0, $res['carried_over_days']);
        $this->assertSame(Calc::MAX_POLICY_DAYS, $res['encashed_days']);
        $this->assertSame(365 - Calc::MAX_POLICY_DAYS, $res['forfeited_days']);
    }

    public function test_tarif_nol_membuat_hari_hangus_bukan_dicairkan_rp0(): void
    {
        // Baris penyesuaian Rp0 di slip gaji hanya membingungkan; harinya
        // diperlakukan hangus. Guard HTTP mencegah kombinasi ini tersimpan,
        // tetapi kalkulator tetap tidak boleh menghasilkan uang nol rupiah.
        $res = $this->decide(7, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 7,
            'carry_over_expiry_months' => 0,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 0,
        ]));

        $this->assertSame(0, $res['encashed_days']);
        $this->assertSame(7, $res['forfeited_days']);
        $this->assertSame(0.0, $res['encashment_amount']);
    }

    public function test_batas_bawa_nol_diperlakukan_sama_dengan_checkbox_mati(): void
    {
        $res = $this->decide(4, $this->config([
            'carry_over_enabled'  => true,
            'carry_over_max_days' => 0,
        ]));

        $this->assertSame(0, $res['carried_over_days']);
        $this->assertSame(4, $res['forfeited_days']);
    }

    public function test_sisa_nol_dan_negatif_tidak_menghasilkan_apa_pun(): void
    {
        foreach ([0, -3] as $sisa) {
            $res = $this->decide($sisa, $this->config([
                'carry_over_enabled'      => true,
                'carry_over_max_days'     => 6,
                'encashment_enabled'      => true,
                'encashment_rate_per_day' => 100_000,
            ]));

            $this->assertSame(0, $res['remaining']);
            $this->assertSame(0, $res['carried_over_days']);
            $this->assertSame(0, $res['encashed_days']);
            $this->assertSame(0.0, $res['encashment_amount']);
        }
    }

    public function test_pagu_pencairan_nol_bukan_null_meniadakan_pencairan(): void
    {
        // 0 dan NULL punya arti berbeda: NULL = tanpa pagu, 0 = tidak ada hari
        // yang boleh dicairkan. Keduanya tidak boleh tertukar.
        $base = [
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 6,
            'carry_over_expiry_months' => 0,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 100_000,
        ];

        $tanpaPagu = $this->decide(6, $this->config($base + ['encashment_max_days' => null]));
        $paguNol   = $this->decide(6, $this->config(array_merge($base, ['encashment_max_days' => 0])));

        $this->assertSame(6, $tanpaPagu['encashed_days']);
        $this->assertSame(0, $paguNol['encashed_days']);
        $this->assertSame(6, $paguNol['forfeited_days']);
    }

    // ═══════════════ Kedaluwarsa hari bawaan ═══════════════

    public function test_kedaluwarsa_dihitung_dari_tanggal_reset(): void
    {
        $res = $this->decide(10, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 6,
            'carry_over_expiry_months' => 3,
        ]));

        $this->assertSame('2027-04-01', $res['carry_over_expired_at']);
    }

    public function test_kedaluwarsa_tidak_meluber_ke_bulan_berikutnya(): void
    {
        // 31 Januari + 1 bulan harus 28/29 Februari, bukan 2/3 Maret.
        $res = $this->decide(5, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 5,
            'carry_over_expiry_months' => 1,
        ]), '2027-01-31');

        $this->assertSame('2027-02-28', $res['carry_over_expired_at']);
    }

    public function test_tanpa_hari_bawaan_tidak_ada_tanggal_kedaluwarsa(): void
    {
        // Tanggal kedaluwarsa tanpa saldo bawaan yang diawasinya akan membuat
        // baris saldo terus terpilih oleh query pengguguran setiap jam.
        $res = $this->decide(0, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 6,
            'carry_over_expiry_months' => 3,
        ]));

        $this->assertNull($res['carry_over_expired_at']);
    }

    public function test_tanpa_masa_berlaku_hari_bawaan_tidak_kedaluwarsa(): void
    {
        $res = $this->decide(6, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 6,
            'carry_over_expiry_months' => null,
        ]));

        $this->assertSame(6, $res['carried_over_days']);
        $this->assertNull($res['carry_over_expired_at']);
    }

    public function test_is_carry_over_expired_memakai_perbandingan_tanggal_kalender(): void
    {
        $tgl = '2027-03-31';

        // Hari terakhir masa berlaku masih SAH dipakai — perbandingan '>' bukan '>='.
        $this->assertFalse(Calc::isCarryOverExpired($tgl, Carbon::parse('2027-03-31 23:00', 'Asia/Jakarta')));
        $this->assertTrue(Calc::isCarryOverExpired($tgl, Carbon::parse('2027-04-01 00:30', 'Asia/Jakarta')));
        $this->assertFalse(Calc::isCarryOverExpired(null, Carbon::parse('2030-01-01', 'Asia/Jakarta')));
    }

    // ═══════════════ Snapshot kebijakan ═══════════════

    public function test_snapshot_membekukan_kebijakan_yang_berlaku_saat_itu(): void
    {
        $res = $this->decide(10, $this->config([
            'carry_over_enabled'       => true,
            'carry_over_max_days'      => 6,
            'carry_over_expiry_months' => 3,
            'encashment_enabled'       => true,
            'encashment_rate_per_day'  => 250_000,
            'encashment_max_days'      => 4,
        ]));

        $snap = $res['policy_snapshot'];
        $this->assertTrue($snap['carry_over_enabled']);
        $this->assertSame(6, $snap['carry_over_max_days']);
        $this->assertSame(3, $snap['carry_over_expiry_months']);
        $this->assertTrue($snap['encashment_enabled']);
        $this->assertEqualsWithDelta(250_000, $snap['encashment_rate_per_day'], 0.01);
        $this->assertSame(4, $snap['encashment_max_days']);
        $this->assertSame(Calc::MAX_POLICY_DAYS, $snap['hard_cap_days']);
    }

    // ═══════════════ Kuota periode baru ═══════════════

    public function test_kuota_periode_baru_menjumlah_standar_kantor_dan_hari_bawaan(): void
    {
        $this->assertSame(18, Calc::newPeriodQuota(12, 6));
        $this->assertSame(12, Calc::newPeriodQuota(12, 0));
        // Nilai negatif (data rusak) tidak boleh mengurangi kuota karyawan.
        $this->assertSame(12, Calc::newPeriodQuota(12, -5));
        $this->assertSame(6, Calc::newPeriodQuota(-12, 6));
    }

    // ═══════════════ Kantor tanpa konfigurasi ═══════════════

    public function test_kantor_null_netral_sehingga_perilaku_lama_dipertahankan(): void
    {
        // Karyawan tanpa penempatan kantor: kedua checkbox dianggap mati, sisa
        // cutinya hangus persis seperti sebelum fitur ini ada.
        $config = Calc::configFromOffice(null);

        $this->assertFalse($config['carry_over_enabled']);
        $this->assertFalse($config['encashment_enabled']);
        $this->assertSame(0, $config['carry_over_max_days']);
        $this->assertNull($config['carry_over_expiry_months']);
        $this->assertSame(0.0, $config['encashment_rate_per_day']);
        $this->assertNull($config['encashment_max_days']);

        $res = $this->decide(9, $config);
        $this->assertSame(9, $res['forfeited_days']);
    }
}
